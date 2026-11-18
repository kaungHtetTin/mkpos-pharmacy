<?php

namespace App\Billing;

use App\Models\{Business, BusinessSubscription, SubscriptionPlan, SubscriptionInvoice, SubscriptionPayment, SubscriptionPeriod, User};
use App\Support\PlatformAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function startTrial(Business $business): BusinessSubscription
    {
        $plan = SubscriptionPlan::firstOrCreate(['code'=>'system-trial'], array_merge([
            'name'=>config('billing.trial_days').'-day trial','amount_minor'=>0,'currency'=>'USD','interval_months'=>1,'grace_days'=>0,
            'features'=>array_fill_keys(config('billing.features'),true),'is_active'=>true,'is_public'=>false,'is_trial'=>true,
        ],config('billing.trial_limits')));
        $subscription = BusinessSubscription::firstOrCreate(['business_id'=>$business->id], [
            'plan_id'=>$plan->id,'plan_snapshot'=>$plan->snapshot(),'trial_started_at'=>now(),
            'trial_ends_at'=>now()->addDays(config('billing.trial_days')),'auto_renew'=>true,
        ]);
        app(SubscriptionAccess::class)->forget();
        return $subscription;
    }

    public function invoice(Business $business, SubscriptionPlan $plan, string $requestKey, ?User $actor = null): SubscriptionInvoice
    {
        return DB::transaction(function () use ($business,$plan,$requestKey,$actor) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $existing = SubscriptionInvoice::where('business_id',$business->id)->where('request_key',$requestKey)->first();
            if ($existing) {
                if ($existing->plan_id !== $plan->id) $this->invalid('request_key','This request key was already used for another plan.');
                return $existing;
            }
            $plan = SubscriptionPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if (!$plan->is_active || $plan->is_trial || ($actor && !$actor->is_platform_admin && !$plan->is_public)) $this->invalid('plan_id','This plan is not available for subscription.');
            if (SubscriptionInvoice::where('business_id',$business->id)->where('status','open')->exists()) $this->invalid('invoice','Resolve or void the existing open invoice before requesting another.');
            if (SubscriptionPeriod::where('business_id',$business->id)->where('starts_at','>',now())->exists()) $this->invalid('invoice','The next subscription period is already covered.');
            $invoice = SubscriptionInvoice::create([
                'business_id'=>$business->id,'plan_id'=>$plan->id,'number'=>'SUB-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                'request_key'=>$requestKey,'amount_minor'=>$plan->amount_minor,'currency'=>$plan->currency,'plan_snapshot'=>$plan->snapshot(),
                'status'=>'open','due_at'=>now()->addDays(config('billing.renewal_notice_days')),
            ]);
            PlatformAudit::record('billing.invoice_created',$actor,$business->id,['invoice_id'=>$invoice->id,'amount_minor'=>$invoice->amount_minor,'currency'=>$invoice->currency]);
            if ($invoice->amount_minor === 0) $this->settle($invoice,$actor);
            return $invoice->fresh();
        });
    }

    public function submit(SubscriptionInvoice $invoice, User $user, array $data, ?UploadedFile $receipt = null): SubscriptionPayment
    {
        $uploaded = null;
        try {
            return DB::transaction(function () use ($invoice,$user,$data,$receipt,&$uploaded) {
                Business::whereKey($invoice->business_id)->lockForUpdate()->firstOrFail();
                $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $existing = SubscriptionPayment::where('business_id',$invoice->business_id)->where('request_key',$data['request_key'])->first();
                if ($existing) {
                    if ($existing->invoice_id !== $invoice->id || $existing->method !== $data['method'] || $existing->reference !== trim($data['reference'])) $this->invalid('request_key','This request key was already used for another payment.');
                    return $existing;
                }
                if ($invoice->status !== 'open') $this->invalid('invoice','Only open invoices accept payment submissions.');
                if (SubscriptionPayment::where('invoice_id',$invoice->id)->where('status','pending')->exists()) $this->invalid('payment','A payment for this invoice is already awaiting review.');
                $hash = hash('sha256',$data['method'].':'.mb_strtolower(trim($data['reference'])));
                if (SubscriptionPayment::where('business_id',$invoice->business_id)->where('reference_hash',$hash)->whereIn('status',['pending','approved'])->exists()) $this->invalid('reference','This payment reference has already been used.');
                if ($receipt) $uploaded = $receipt->store('billing/'.$invoice->business_id.'/receipts','local');
                $payment = SubscriptionPayment::create([
                    'business_id'=>$invoice->business_id,'invoice_id'=>$invoice->id,'submitted_by'=>$user->id,'request_key'=>$data['request_key'],
                    'amount_minor'=>$invoice->amount_minor,'currency'=>$invoice->currency,'method'=>$data['method'],'reference'=>trim($data['reference']),
                    'reference_hash'=>$hash,'payment_date'=>$data['payment_date'],'receipt_path'=>$uploaded,'status'=>'pending',
                ]);
                PlatformAudit::record('billing.payment_submitted',$user,$invoice->business_id,['invoice_id'=>$invoice->id,'payment_id'=>$payment->id]);
                return $payment;
            });
        } catch (\Throwable $e) {
            if ($uploaded) Storage::disk('local')->delete($uploaded);
            throw $e;
        }
    }

    public function review(SubscriptionPayment $payment, User $reviewer, bool $approve, ?string $note): SubscriptionPayment
    {
        abort_unless($reviewer->is_platform_admin && $reviewer->getRawOriginal('status') === 'Active',403);
        return DB::transaction(function () use ($payment,$reviewer,$approve,$note) {
            Business::whereKey($payment->business_id)->lockForUpdate()->firstOrFail();
            $invoice = SubscriptionInvoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $payment = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'approved' && $approve) return $payment;
            if ($payment->status !== 'pending') $this->invalid('payment','This payment has already been reviewed.');
            if ($invoice->status !== 'open') $this->invalid('invoice','This invoice is no longer open.');
            $payment->update(['status'=>$approve?'approved':'rejected','reviewed_by'=>$reviewer->id,'reviewed_at'=>now(),'review_note'=>$note]);
            if ($approve) $this->settle($invoice,$reviewer);
            PlatformAudit::record($approve?'billing.payment_approved':'billing.payment_rejected',$reviewer,$payment->business_id,['invoice_id'=>$invoice->id,'payment_id'=>$payment->id]);
            return $payment->fresh();
        });
    }

    private function settle(SubscriptionInvoice $invoice, ?User $actor): void
    {
        if ($invoice->status === 'paid') return;
        $subscription = BusinessSubscription::where('business_id',$invoice->business_id)->lockForUpdate()->firstOrFail();
        $last = SubscriptionPeriod::where('business_id',$invoice->business_id)->latest('ends_at')->first();
        $start = $last && $last->ends_at->isFuture() ? $last->ends_at->copy() : now();
        $end = $start->copy()->addMonthsNoOverflow((int)$invoice->plan_snapshot['interval_months']);
        SubscriptionPeriod::create(['business_id'=>$invoice->business_id,'invoice_id'=>$invoice->id,'plan_snapshot'=>$invoice->plan_snapshot,'starts_at'=>$start,'ends_at'=>$end]);
        $invoice->update(['status'=>'paid','paid_at'=>now(),'period_starts_at'=>$start,'period_ends_at'=>$end]);
        $subscription->update(['plan_id'=>$invoice->plan_id,'plan_snapshot'=>$invoice->plan_snapshot]);
        app(SubscriptionAccess::class)->forget();
        PlatformAudit::record('billing.period_granted',$actor,$invoice->business_id,['invoice_id'=>$invoice->id,'starts_at'=>$start->toIso8601String(),'ends_at'=>$end->toIso8601String()]);
    }

    public function void(SubscriptionInvoice $invoice, User $actor, string $reason): void
    {
        DB::transaction(function () use ($invoice,$actor,$reason) {
            Business::whereKey($invoice->business_id)->lockForUpdate()->firstOrFail();
            $invoice = SubscriptionInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'void') return;
            if ($invoice->status !== 'open') $this->invalid('invoice','Paid invoices cannot be voided.');
            if (SubscriptionPayment::where('invoice_id',$invoice->id)->where('status','pending')->exists()) $this->invalid('invoice','Review the pending payment before voiding this invoice.');
            $invoice->update(['status'=>'void','void_reason'=>$reason]);
            PlatformAudit::record('billing.invoice_voided',$actor,$invoice->business_id,['invoice_id'=>$invoice->id]);
        });
    }

    public function cancel(Business $business, User $actor, bool $resume = false): void
    {
        DB::transaction(function () use ($business,$actor,$resume) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $subscription = BusinessSubscription::where('business_id',$business->id)->lockForUpdate()->firstOrFail();
            $subscription->update(['auto_renew'=>$resume,'canceled_at'=>$resume?null:now()]);
            app(SubscriptionAccess::class)->forget();
            PlatformAudit::record($resume?'billing.renewal_resumed':'billing.renewal_canceled',$actor,$business->id);
        });
    }

    public function process(Business $business): bool
    {
        return DB::transaction(function () use ($business) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $subscription=BusinessSubscription::where('business_id',$business->id)->lockForUpdate()->firstOrFail();
            $access=app(SubscriptionAccess::class);$access->forget();$state=$access->summary($business)['state'];
            if($subscription->last_state!==$state) {
                PlatformAudit::record('billing.state_changed',null,$business->id,['from'=>$subscription->last_state,'to'=>$state]);
                $subscription->update(['last_state'=>$state]);
            }
            if(!$subscription->auto_renew || $business->status!=='Active') return false;
            $last=SubscriptionPeriod::where('business_id',$business->id)->latest('ends_at')->first();
            $plan=SubscriptionPlan::find($subscription->plan_id);
            if(!$last || !$plan?->is_active || $plan->is_trial || $last->ends_at->gt(now()->addDays(config('billing.renewal_notice_days')))) return false;
            $key='auto-'.$last->id;
            if(SubscriptionInvoice::where('business_id',$business->id)->where(fn($q)=>$q->where('status','open')->orWhere('request_key',$key))->exists()) return false;
            $this->invoice($business,$plan,$key);
            return true;
        });
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field=>$message]); }
}
