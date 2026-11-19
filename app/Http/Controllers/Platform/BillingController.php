<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Billing\{SubscriptionService, SubscriptionAccess, Money};
use App\Models\{Business, BusinessSubscription, SubscriptionPlan, SubscriptionInvoice, SubscriptionPayment, PlatformBillingSetting};
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function index(Request $request, SubscriptionAccess $access)
    {
        $filters=$request->validate(['business_id'=>'nullable|uuid','payment_status'=>['nullable',Rule::in(['pending','approved','rejected'])],'invoice_status'=>['nullable',Rule::in(['open','paid','void'])]]);
        $businessId=$filters['business_id']??null;
        return Spa::render('Platform/Billing',[
            'subscriptions'=>BusinessSubscription::with('business:id,name,status')->when($businessId,fn($q)=>$q->where('business_id',$businessId))->latest()->paginate(20,['*'],'subscriptions_page')->withQueryString()->through(fn($s)=>['id'=>$s->id,'business'=>$s->business,'subscription'=>$access->summary($s->business),'usage'=>$access->usage($s->business_id)]),
            'invoices'=>SubscriptionInvoice::with('business:id,name')->when($businessId,fn($q)=>$q->where('business_id',$businessId))->when($filters['invoice_status']??null,fn($q,$status)=>$q->where('status',$status))->latest()->paginate(20,['*'],'invoices_page')->withQueryString(),
            'payments'=>SubscriptionPayment::with(['business:id,name','invoice:id,number,status','reviewer:id,name'])->when($businessId,fn($q)=>$q->where('business_id',$businessId))->when($filters['payment_status']??null,fn($q,$status)=>$q->where('status',$status))->latest()->paginate(20,['*'],'payments_page')->withQueryString()->through(fn($p)=>array_merge($p->toArray(),['has_receipt'=>(bool)$p->receipt_path])),
            'plans'=>SubscriptionPlan::where('currency', 'MMK')->where('is_active',true)->where('is_trial',false)->orderBy('name')->get(),
            'selected_business'=>$businessId?Business::findOrFail($businessId)->only('id','name'):null,
            'filters'=>$filters,'payment_settings'=>PlatformBillingSetting::findOrFail(1),
            'stats'=>['open_invoices'=>SubscriptionInvoice::where('status','open')->count(),'pending_payments'=>SubscriptionPayment::where('status','pending')->count(),'approved_payments'=>SubscriptionPayment::where('status','approved')->count()],
        ]);
    }

    public function businesses(Request $request)
    {
        $data=$request->validate(['q'=>'nullable|string|max:100']);$search=trim($data['q']??'');
        return response()->json(Business::when($search!=='',fn($q)=>$q->where(fn($q)=>$q->where('name','like','%'.$search.'%')->orWhere('slug','like','%'.$search.'%')))->orderBy('name')->limit(20)->get(['id','name','slug']));
    }

    public function invoice(Request $request, SubscriptionService $service)
    {
        $data=$request->validate(['business_id'=>'required|uuid|exists:businesses,id','plan_id'=>'required|uuid|exists:subscription_plans,id','request_key'=>'required|uuid']);
        $service->invoice(Business::findOrFail($data['business_id']),SubscriptionPlan::findOrFail($data['plan_id']),$data['request_key'],$request->user());
        return back()->with('success','Subscription invoice created.');
    }

    public function review(Request $request, string $payment, SubscriptionService $service)
    {
        $data=$request->validate(['decision'=>'required|in:approve,reject','confirmed'=>'exclude_unless:decision,approve|required|accepted','note'=>'required_if:decision,reject|nullable|string|max:1000']);
        $service->review(SubscriptionPayment::findOrFail($payment),$request->user(),$data['decision']==='approve',$data['note']??null);
        return back()->with('success',$data['decision']==='approve'?'Payment approved and subscription period granted.':'Payment rejected; the owner can submit corrected payment details.');
    }

    public function void(Request $request, string $invoice, SubscriptionService $service)
    {
        $data=$request->validate(['reason'=>'required|string|max:500']);
        $service->void(SubscriptionInvoice::findOrFail($invoice),$request->user(),$data['reason']);
        return back()->with('success','Invoice voided.');
    }

    public function settings(Request $request)
    {
        $data=$request->validate(['payment_instructions'=>'required|string|max:3000','support_email'=>'nullable|email|max:255']);
        PlatformBillingSetting::findOrFail(1)->update($data);
        PlatformAudit::record('billing.instructions_updated',$request->user());
        return back()->with('success','Payment instructions updated.');
    }

    public function plans()
    {
        return Spa::render('Platform/Plans',['plans'=>SubscriptionPlan::orderByDesc('is_trial')->orderBy('name')->get()]);
    }

    public function savePlan(Request $request, ?string $plan = null)
    {
        $record=$plan?SubscriptionPlan::findOrFail($plan):new SubscriptionPlan();
        if ($record->exists && $record->currency !== 'MMK') throw \Illuminate\Validation\ValidationException::withMessages(['currency'=>'Create a new MMK plan; historical foreign-currency prices are preserved.']);
        abort_if($record->is_trial,422,'The system trial is configured by the deployment, not the paid plan editor.');
        $data=$request->validate([
            'code'=>['required','string','max:60','regex:/^[a-z][a-z0-9-]*$/',Rule::notIn(['system-trial']),Rule::unique('subscription_plans','code')->ignore($record->id)],
            'name'=>'required|string|max:120','price'=>'required|string','currency'=>'required|in:MMK',
            'interval_months'=>['required',Rule::in([1,12])],'grace_days'=>'required|integer|min:0|max:30',
            'max_branches'=>'nullable|integer|min:1|max:1000000','max_users'=>'nullable|integer|min:1|max:1000000','max_products'=>'nullable|integer|min:1|max:10000000',
            'features'=>'required|array:cashier,offline_sync,reports','features.cashier'=>'required|boolean','features.offline_sync'=>'required|boolean','features.reports'=>'required|boolean',
            'is_active'=>'required|boolean','is_public'=>'required|boolean',
        ]);
        $data['amount_minor']=Money::minor($data['price']);unset($data['price']);$data['is_trial']=false;
        $record->fill($data)->save();
        PlatformAudit::record($plan?'billing.plan_updated':'billing.plan_created',$request->user(),null,['plan_id'=>$record->id]);
        return back()->with('success','Plan saved. Existing periods and invoices keep their original terms.');
    }
}
