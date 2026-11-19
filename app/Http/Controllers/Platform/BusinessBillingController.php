<?php

namespace App\Http\Controllers\Platform;

use App\Billing\{Money, SubscriptionService};
use App\Http\Controllers\Controller;
use App\Models\{Business, BusinessSubscription, SubscriptionInvoice, SubscriptionPayment, SubscriptionPlan, User};
use App\Operations\OfficeAccess;
use App\Support\PlatformAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessBillingController extends Controller
{
    private function authorizeBilling(Request $request): void
    {
        abort_unless(OfficeAccess::allows($request->user(), 'billing'), 403);
    }

    public function complimentary(Request $request, Business $business, SubscriptionService $service)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['plan_id' => 'required|uuid|exists:subscription_plans,id', 'request_key' => 'required|uuid', 'months' => 'required|integer|min:1|max:36', 'note' => 'required|string|max:500', 'confirmed' => 'required|accepted']);
        $service->grantComplimentary($business, SubscriptionPlan::findOrFail($data['plan_id']), $request->user(), $data['request_key'], (int) $data['months'], $data['note']);
        return back()->with('success', 'FOC plan granted. No payment or revenue was recorded. Automatic renewal is disabled.');
    }

    public function renewal(Request $request, Business $business, SubscriptionService $service)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['auto_renew' => 'required|boolean', 'note' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $business, $service, $data) {
            $service->cancel($business, $request->user(), (bool) $data['auto_renew']);
            PlatformAudit::record('subscription.office_control_updated', $request->user(), $business->id, ['auto_renew' => $data['auto_renew'], 'note' => $data['note']]);
        });
        return back()->with('success', 'Subscription renewal updated. Existing paid periods are preserved.');
    }

    public function storeInvoice(Request $request, Business $business, SubscriptionService $service)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['plan_id' => 'required|uuid|exists:subscription_plans,id', 'request_key' => 'required|uuid', 'due_at' => 'required|date_format:Y-m-d', 'note' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $business, $service, $data) {
            $service->invoice($business, SubscriptionPlan::findOrFail($data['plan_id']), $data['request_key'], $request->user(), $data['due_at']);
            PlatformAudit::record('billing.invoice_terms_set', $request->user(), $business->id, ['request_key' => $data['request_key'], 'due_at' => $data['due_at'], 'note' => $data['note']]);
        });
        return back()->with('success', 'Billing invoice created.');
    }

    public function updateInvoice(Request $request, Business $business, string $invoice)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['price' => 'required|string', 'due_at' => 'required|date_format:Y-m-d', 'note' => 'required|string|max:500']);
        $amount = Money::minor($data['price']);
        if ($amount < 1) throw ValidationException::withMessages(['price' => 'An edited invoice must have a positive amount. Use a free plan for complimentary access.']);
        DB::transaction(function () use ($request, $business, $invoice, $data, $amount) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $record = SubscriptionInvoice::where('business_id', $business->id)->lockForUpdate()->findOrFail($invoice);
            if ($record->status !== 'open' || SubscriptionPayment::where('invoice_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Only open invoices without payment history can be edited.']);
            }
            $before = $record->only('amount_minor', 'due_at');
            $snapshot = $record->plan_snapshot;
            $snapshot['amount_minor'] = $amount;
            $record->update(['amount_minor' => $amount, 'due_at' => $data['due_at'] . ' 23:59:59', 'plan_snapshot' => $snapshot]);
            PlatformAudit::record('billing.invoice_updated', $request->user(), $business->id, ['invoice_id' => $record->id, 'before' => $before, 'amount_minor' => $amount, 'due_at' => $data['due_at'], 'note' => $data['note']]);
        });
        return back()->with('success', 'Billing invoice updated.');
    }

    public function voidInvoice(Request $request, Business $business, string $invoice, SubscriptionService $service)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $service->void(SubscriptionInvoice::where('business_id', $business->id)->findOrFail($invoice), $request->user(), $data['reason']);
        return back()->with('success', 'Invoice voided and retained in billing history.');
    }

    public function storePayment(Request $request, Business $business, string $invoice, SubscriptionService $service)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['request_key' => 'required|uuid', 'method' => 'required|in:bank_transfer,mobile_wallet,cash,other', 'reference' => 'required|string|max:150', 'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'approve' => 'required|boolean', 'confirmed' => 'required_if:approve,true|boolean', 'note' => 'required|string|max:500']);
        if ($data['approve'] && !$request->boolean('confirmed')) throw ValidationException::withMessages(['confirmed' => 'Confirm that the funds were received before approving.']);
        DB::transaction(function () use ($request, $business, $invoice, $service, $data) {
            $record = SubscriptionInvoice::where('business_id', $business->id)->findOrFail($invoice);
            // Payment membership remains tied to the customer; audit attribution records the office operator.
            $member = DB::table('business_memberships')->where('business_id', $business->id)->orderBy('created_at')->first();
            if (!$member) throw ValidationException::withMessages(['payment' => 'This business has no account to associate with the payment.']);
            $customer = User::withoutGlobalScopes()->where('is_platform_admin', false)->findOrFail($member->user_id);
            $payment = $service->submit($record, $customer, $data, null, $request->user());
            if ($data['approve']) $service->review($payment, $request->user(), true, $data['note']);
            PlatformAudit::record('billing.office_payment_recorded', $request->user(), $business->id, ['payment_id' => $payment->id, 'note' => $data['note']]);
        });
        return back()->with('success', $data['approve'] ? 'Payment approved, receipt posted and subscription activated.' : 'Payment recorded for review.');
    }

    public function updatePayment(Request $request, Business $business, string $payment)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['method' => 'required|in:bank_transfer,mobile_wallet,cash,other', 'reference' => 'required|string|max:150', 'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'note' => 'required|string|max:500']);
        DB::transaction(function () use ($request, $business, $payment, $data) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $record = SubscriptionPayment::where('business_id', $business->id)->lockForUpdate()->findOrFail($payment);
            if ($record->status !== 'pending') throw ValidationException::withMessages(['payment' => 'Only pending payments can be edited. Reviewed records are preserved.']);
            $hash = hash('sha256', $data['method'] . ':' . mb_strtolower(trim($data['reference'])));
            if (SubscriptionPayment::where('business_id', $business->id)->whereKeyNot($record->id)->where('reference_hash', $hash)->whereIn('status', ['pending', 'approved'])->exists()) throw ValidationException::withMessages(['reference' => 'This payment reference has already been used.']);
            $before = $record->only('method', 'reference', 'payment_date');
            $record->update(['method' => $data['method'], 'reference' => trim($data['reference']), 'reference_hash' => $hash, 'payment_date' => $data['payment_date']]);
            PlatformAudit::record('billing.payment_updated', $request->user(), $business->id, ['payment_id' => $record->id, 'before' => $before, 'note' => $data['note']]);
        });
        return back()->with('success', 'Pending payment updated.');
    }

    public function reviewPayment(Request $request, Business $business, string $payment, SubscriptionService $service)
    {
        $this->authorizeBilling($request);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'confirmed' => 'exclude_unless:decision,approve|required|accepted', 'note' => 'required|string|max:500']);
        $service->review(SubscriptionPayment::where('business_id', $business->id)->findOrFail($payment), $request->user(), $data['decision'] === 'approve', $data['note']);
        return back()->with('success', $data['decision'] === 'approve' ? 'Payment approved and subscription activated.' : 'Payment rejected and retained in billing history.');
    }
}
