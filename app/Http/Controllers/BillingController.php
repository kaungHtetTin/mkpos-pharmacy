<?php

namespace App\Http\Controllers;

use App\Billing\{SubscriptionAccess, SubscriptionService};
use App\Models\{SubscriptionInvoice, SubscriptionPayment, SubscriptionPlan, PlatformBillingSetting};
use App\Support\Spa;
use App\Tenancy\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BillingController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless($request->user()->hasRole('Owner') || $request->user()->hasRole('Root'),403);
    }

    public function index(Request $request, SubscriptionAccess $access)
    {
        $this->owner($request);
        $business = app(BusinessContext::class)->business();
        $data = [
            'subscription'=>$access->summary($business),'usage'=>$access->usage($business->id),
            'plans'=>SubscriptionPlan::where('is_active',true)->where('is_public',true)->where('is_trial',false)->orderBy('amount_minor')->get(),
            'invoices'=>SubscriptionInvoice::where('business_id',$business->id)->with(['payments'=>fn($q)=>$q->latest()])->latest()->paginate(15)->through(fn($invoice)=>$this->invoicePayload($invoice)),
            'payment_methods'=>\App\Models\PlatformPaymentMethod::where('is_active',true)->orderBy('sort_order')->orderBy('name')->get(['id','name','type','account_name','account_number','instructions']),
            'payment_settings'=>PlatformBillingSetting::findOrFail(1)->only('payment_instructions','support_email'),
        ];
        return $request->is('api/*') ? response()->json($data) : Spa::render('Billing/Index',$data);
    }

    public function status(SubscriptionAccess $access)
    {
        return response()->json($access->summary(app(BusinessContext::class)->business()));
    }

    public function invoice(Request $request, SubscriptionService $service)
    {
        $this->owner($request);
        $data=$request->validate(['plan_id'=>'required|uuid','request_key'=>'required|uuid']);
        $invoice=$service->invoice(app(BusinessContext::class)->business(),SubscriptionPlan::findOrFail($data['plan_id']),$data['request_key'],$request->user());
        return $request->is('api/*') ? response()->json($this->invoicePayload($invoice),201) : back()->with('success','Subscription invoice ready.');
    }

    private function ownInvoice(string $id): SubscriptionInvoice
    {
        return SubscriptionInvoice::where('business_id',app(BusinessContext::class)->requireId())->findOrFail($id);
    }

    public function submit(Request $request, string $invoice, SubscriptionService $service)
    {
        $this->owner($request);
        $data=$request->validate([
            'request_key'=>'required|uuid','method'=>'required|in:bank_transfer,mobile_wallet,cash','reference'=>'required|string|max:160',
            'payment_date'=>'required|date|before_or_equal:today','receipt'=>'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'amount_minor'=>'prohibited','currency'=>'prohibited','business_id'=>'prohibited',
        ]);
        $payment=$service->submit($this->ownInvoice($invoice),$request->user(),$data,$request->file('receipt'));
        return $request->is('api/*') ? response()->json($payment,201) : back()->with('success','Payment submitted for platform-office review. Access changes only after approval.');
    }

    public function void(Request $request, string $invoice, SubscriptionService $service)
    {
        $this->owner($request);
        $data=$request->validate(['reason'=>'required|string|max:500']);
        $service->void($this->ownInvoice($invoice),$request->user(),$data['reason']);
        return $request->is('api/*') ? response()->json(['message'=>'Invoice voided.']) : back()->with('success','Invoice voided.');
    }

    public function renewal(Request $request, SubscriptionService $service)
    {
        $this->owner($request);
        $data=$request->validate(['auto_renew'=>'required|boolean']);
        $service->cancel(app(BusinessContext::class)->business(),$request->user(),$data['auto_renew']);
        return $request->is('api/*') ? response()->json(['message'=>'Renewal preference updated.']) : back()->with('success','Renewal preference updated. Paid access continues through its covered period.');
    }

    public function receipt(Request $request, string $payment)
    {
        $record=SubscriptionPayment::findOrFail($payment);
        if (!$request->user()->is_platform_admin) {
            $this->owner($request);
            abort_unless($record->business_id===app(BusinessContext::class)->requireId(),404);
        }
        abort_unless($record->receipt_path && Storage::disk('local')->exists($record->receipt_path),404);
        return Storage::disk('local')->download($record->receipt_path,'payment-receipt.'.pathinfo($record->receipt_path,PATHINFO_EXTENSION),['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function invoicePayload(SubscriptionInvoice $invoice): array
    {
        $invoice->loadMissing('payments');
        $data=$invoice->toArray();
        $data['payments']=$invoice->payments->map(fn($p)=>array_merge($p->toArray(),['has_receipt'=>(bool)$p->receipt_path]))->all();
        return $data;
    }
}
