<?php
namespace Tests\Feature;

use App\Billing\SubscriptionService;
use App\Models\{Business, BusinessSubscription, SubscriptionInvoice, SubscriptionPayment, SubscriptionPlan, PlatformFinanceReceipt, User};
use App\Services\Businesses\ProvisionBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnifiedBusinessOfficeTest extends TestCase
{
    use RefreshDatabase;
    private User $office;
    private User $owner;
    private Business $business;
    private Business $other;
    private SubscriptionPlan $plan;
    protected function setUp(): void
    {
        parent::setUp();config(['operations.mfa_required'=>false]);
        $this->office=User::factory()->create();$this->office->forceFill(['is_platform_admin'=>true,'platform_role'=>'owner'])->save();
        $this->owner=User::factory()->create();
        $this->business=app(ProvisionBusiness::class)->create(['name'=>'Unified Alpha','slug'=>'unified-alpha'],$this->owner);
        $this->other=app(ProvisionBusiness::class)->create(['name'=>'Unified Beta','slug'=>'unified-beta'],User::factory()->create());
        $this->plan=SubscriptionPlan::create(['code'=>'unified-paid','name'=>'Unified Paid','amount_minor'=>2500,'currency'=>'MMK','interval_months'=>1,'grace_days'=>3,'features'=>['cashier'=>true,'offline_sync'=>true,'reports'=>true],'is_active'=>true,'is_public'=>true,'is_trial'=>false]);
        $this->actingAs($this->office);
    }
    private function url(string $p='',?Business $b=null):string {return '/platform/businesses/'.($b??$this->business)->id.($p?'/'.$p:'');}
    private function invoice(?Business $b=null):SubscriptionInvoice{return app(SubscriptionService::class)->invoice($b??$this->business,$this->plan,(string)Str::uuid(),$this->office);}
    private function payment(array $v=[]):array{return array_replace(['request_key'=>(string)Str::uuid(),'method'=>'cash','reference'=>'UNIFIED-'.Str::random(10),'payment_date'=>today()->toDateString(),'approve'=>false,'confirmed'=>false,'note'=>'Office test'],$v);}
    public function test_directory_searches_accounts_and_redirects_old_directory():void
    {
        $this->get('/platform/businesses?q='.urlencode($this->owner->email),['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.businesses.total',1)->assertJsonPath('props.businesses.data.0.accounts.0.email',$this->owner->email);
        $directory = $this->get('/platform/businesses', ['X-SPA'=>'true'])->assertOk()->json('props.businesses.data');
        $this->assertCount(2, $directory); foreach($directory as $row) $this->assertNotEmpty($row['accounts']);
        $this->get('/platform/users')->assertRedirect(route('platform.businesses.index'));
        $this->get('/platform/users?business_id='.$this->business->id)->assertRedirect(route('platform.businesses.show',['business'=>$this->business->id,'tab'=>'accounts']));
    }
    public function test_invoice_create_is_idempotent_due_date_and_edit_void_work():void
    {
        $d=['plan_id'=>$this->plan->id,'request_key'=>(string)Str::uuid(),'due_at'=>today()->addDays(4)->toDateString(),'note'=>'Agreed date'];
        $this->post($this->url('invoices'),$d)->assertStatus(302)->assertSessionHasNoErrors();
        $this->post($this->url('invoices'),array_replace($d,['due_at'=>today()->toDateString()]))->assertStatus(302)->assertSessionHasNoErrors();
        $i=SubscriptionInvoice::where('business_id',$this->business->id)->sole();$this->assertSame($d['due_at'],$i->due_at->toDateString());
        $this->put($this->url('invoices/'.$i->id),['price'=>'20.12','due_at'=>today()->toDateString(),'note'=>'Discount'])->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertSame(2012,$i->fresh()->amount_minor);$this->assertSame(2012,$i->fresh()->plan_snapshot['amount_minor']);
        $this->post($this->url('invoices/'.$i->id.'/void'),['reason'=>'Duplicate'])->assertStatus(302)->assertSessionHasNoErrors();$this->assertSame('void',$i->fresh()->status);$this->assertDatabaseCount('subscription_invoices',1);
    }
    public function test_pending_edit_and_approval_post_one_receipt_and_period():void
    {
        $i=$this->invoice();$d=$this->payment();$this->post($this->url('invoices/'.$i->id.'/payments'),$d)->assertStatus(302)->assertSessionHasNoErrors();$p=SubscriptionPayment::sole();
        $this->put($this->url('payments/'.$p->id),array_replace($d,['reference'=>'CORRECTED']))->assertStatus(302)->assertSessionHasNoErrors();$this->assertSame('CORRECTED',$p->fresh()->reference);
        $this->put($this->url('invoices/'.$i->id),['price'=>'50','due_at'=>today()->toDateString(),'note'=>'Blocked'])->assertSessionHasErrors('invoice');
        for($n=0;$n<2;$n++)$this->post($this->url('payments/'.$p->id.'/review'),['decision'=>'approve','confirmed'=>true,'note'=>'Verified'])->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertSame('paid',$i->fresh()->status);$this->assertDatabaseCount('subscription_periods',1);$this->assertDatabaseCount('platform_finance_receipts',1);$this->assertSame(2500,PlatformFinanceReceipt::sole()->amount_minor);
        $this->put($this->url('payments/'.$p->id),$d)->assertSessionHasErrors('payment');
        $this->post($this->url('invoices/'.$i->id.'/void'),['reason'=>'Blocked'])->assertSessionHasErrors('invoice');
    }
    public function test_immediate_approval_requires_confirmation_and_is_idempotent():void
    {
        $i=$this->invoice();$d=$this->payment(['approve'=>true]);
        $this->post($this->url('invoices/'.$i->id.'/payments'),$d)->assertSessionHasErrors('confirmed');$this->assertDatabaseCount('subscription_payments',0);
        $d['confirmed']=true;for($n=0;$n<2;$n++)$this->post($this->url('invoices/'.$i->id.'/payments'),$d)->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_payments',1);$this->assertDatabaseCount('subscription_periods',1);$this->assertDatabaseCount('platform_finance_receipts',1);
    }
    public function test_rejection_preserves_record_without_granting_paid_access():void
    {
        $i=$this->invoice();$this->post($this->url('invoices/'.$i->id.'/payments'),$this->payment())->assertStatus(302)->assertSessionHasNoErrors();$p=SubscriptionPayment::sole();
        $this->post($this->url('payments/'.$p->id.'/review'),['decision'=>'reject','note'=>'Not received'])->assertStatus(302)->assertSessionHasNoErrors();$this->assertSame('rejected',$p->fresh()->status);
        $this->assertDatabaseCount('subscription_periods',0);$this->assertDatabaseCount('platform_finance_receipts',0);
        $this->post($this->url('invoices/'.$i->id.'/void'),['reason'=>'Canceled'])->assertStatus(302)->assertSessionHasNoErrors();
    }
    public function test_cross_business_mutations_are_denied():void
    {
        $i=$this->invoice($this->other);$d=$this->payment();
        $this->post($this->url('invoices/'.$i->id.'/payments'),$d)->assertNotFound();
        $this->put($this->url('invoices/'.$i->id),['price'=>'10','due_at'=>today()->toDateString(),'note'=>'Wrong'])->assertNotFound();
        $this->post($this->url('invoices/'.$i->id.'/void'),['reason'=>'Wrong'])->assertNotFound();
        $this->post($this->url('invoices/'.$i->id.'/payments',$this->other),$d)->assertStatus(302)->assertSessionHasNoErrors();$p=SubscriptionPayment::sole();
        $this->put($this->url('payments/'.$p->id),$d)->assertNotFound();
        $this->post($this->url('payments/'.$p->id.'/review'),['decision'=>'approve','confirmed'=>true,'note'=>'Wrong'])->assertNotFound();
    }
    public function test_office_roles_separate_identity_and_billing_controls():void
    {
        foreach(['admin','support'] as $role){$this->office->forceFill(['platform_role'=>$role])->save();$this->post($this->url('invoices'),[])->assertForbidden();$this->put($this->url('subscription'),['auto_renew'=>false,'note'=>'Denied'])->assertForbidden();$this->post($this->url('trial'),['days'=>7,'note'=>'Denied'])->assertForbidden();}
        $this->office->forceFill(['platform_role'=>'billing'])->save();
        $this->get($this->url(),['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.billing_visible',true)->assertJsonPath('props.accounts_manageable',false);
        $this->put($this->url('details'),['name'=>'Bad','slug'=>'bad','status'=>'Suspended'])->assertForbidden();$this->put($this->url(),['status'=>'Suspended'])->assertForbidden();$this->post('/platform/businesses',[])->assertForbidden();
        $this->put($this->url('subscription'),['auto_renew'=>false,'note'=>'Owner request'])->assertStatus(302)->assertSessionHasNoErrors();$this->assertFalse(BusinessSubscription::where('business_id',$this->business->id)->sole()->auto_renew);
        $this->actingAs($this->owner)->post($this->url('invoices'),[])->assertForbidden();
    }
    public function test_detail_paginates_history_and_hides_billing_from_admin():void
    {
        $i=$this->invoice();$this->get($this->url(),['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.invoices_page.total',1)->assertJsonPath('props.members_page.total',1)->assertJsonPath('props.owner_contacts.0.email',$this->owner->email);
        $this->office->forceFill(['platform_role'=>'admin'])->save();$r=$this->get($this->url(),['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.invoices_page',null)->assertJsonPath('props.payments_page',null);$this->assertStringNotContainsString($i->number,$r->getContent());
    }
    public function test_foc_grants_access_without_payment_revenue_or_changing_plan_price(): void
    {
        $this->travelTo(now()->startOfSecond());
        $data = ['plan_id' => $this->plan->id, 'request_key' => (string) Str::uuid(), 'months' => 3, 'note' => 'Partner promotion', 'confirmed' => true];
        $this->post($this->url('complimentary'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $invoice = SubscriptionInvoice::sole();
        $this->assertSame(0, $invoice->amount_minor);
        $this->assertSame('paid', $invoice->status);
        $this->assertStringStartsWith('FOC-', $invoice->number);
        $this->assertTrue($invoice->period_starts_at->equalTo(now()));
        $this->assertTrue($invoice->period_ends_at->equalTo(now()->addMonthsNoOverflow(3)));
        $this->assertSame(2500, $this->plan->fresh()->amount_minor);
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->assertDatabaseCount('platform_finance_receipts', 0);
        $this->assertDatabaseCount('platform_finance_entries', 0);
        $summary = app(\App\Billing\SubscriptionAccess::class)->summary($this->business);
        $this->assertTrue($summary['can_operate']);$this->assertTrue($summary['is_complimentary']);$this->assertFalse($summary['auto_renew']);
        $this->assertSame($this->plan->features, $summary['plan']['features']);
        $this->assertArrayNotHasKey('note', $summary['plan']);
        $this->assertDatabaseHas('platform_activity_logs', ['business_id' => $this->business->id, 'actor_id' => $this->office->id, 'action' => 'billing.complimentary_plan_granted']);
        $this->post($this->url('complimentary'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_invoices', 1);$this->assertDatabaseCount('subscription_periods', 1);
        $this->post($this->url('complimentary'), array_replace($data, ['months' => 6]))->assertSessionHasErrors('request_key');
        $this->travelTo($invoice->period_ends_at->copy()->addSecond());
        app(\App\Billing\SubscriptionAccess::class)->forget();
        $this->assertFalse(app(\App\Billing\SubscriptionAccess::class)->summary($this->business)['can_operate']);
        $this->assertFalse(app(SubscriptionService::class)->process($this->business));
        $this->assertDatabaseCount('subscription_invoices', 1);
    }
    public function test_foc_preserves_current_paid_period_and_does_not_duplicate_future_grants(): void
    {
        $this->travelTo(now()->startOfSecond());$invoice = $this->invoice();$data = $this->payment(['approve'=>true,'confirmed'=>true]);
        $this->post($this->url('invoices/'.$invoice->id.'/payments'),$data)->assertRedirect()->assertSessionHasNoErrors();
        $end = $invoice->fresh()->period_ends_at->copy();
        $grant = ['plan_id'=>$this->plan->id,'request_key'=>(string)Str::uuid(),'months'=>2,'note'=>'Retention grant','confirmed'=>true];
        $this->post($this->url('complimentary'),$grant)->assertRedirect()->assertSessionHasNoErrors();
        $foc = SubscriptionInvoice::where('number','like','FOC-%')->sole();
        $this->assertTrue($foc->period_starts_at->equalTo($end));
        $this->assertTrue($foc->period_ends_at->equalTo($end->copy()->addMonthsNoOverflow(2)));
        $this->assertDatabaseCount('platform_finance_receipts',1);
        $this->assertSame(2500,PlatformFinanceReceipt::sole()->amount_minor);
        $this->post($this->url('complimentary'),array_replace($grant,['request_key'=>(string)Str::uuid()]))->assertSessionHasErrors('subscription');
        $this->assertDatabaseCount('subscription_periods',2);
    }
    public function test_foc_requires_confirmation_valid_duration_and_authorized_office_role(): void
    {
        $data=['plan_id'=>$this->plan->id,'request_key'=>(string)Str::uuid(),'months'=>1,'note'=>'Promotion','confirmed'=>true];
        foreach([0,37] as $months)$this->post($this->url('complimentary'),array_replace($data,['months'=>$months]))->assertSessionHasErrors('months');
        $this->post($this->url('complimentary'),array_replace($data,['confirmed'=>false]))->assertSessionHasErrors('confirmed');
        $this->post($this->url('complimentary'),array_replace($data,['note'=>'']))->assertSessionHasErrors('note');
        foreach(['admin','support'] as $role){$this->office->forceFill(['platform_role'=>$role])->save();$this->post($this->url('complimentary'),$data)->assertForbidden();}
        $this->actingAs($this->owner)->post($this->url('complimentary'),$data)->assertForbidden();
        $this->assertDatabaseCount('subscription_invoices',0);
        $this->actingAs($this->office);$this->office->forceFill(['platform_role'=>'billing'])->save();
        $this->post($this->url('complimentary'),$data)->assertRedirect()->assertSessionHasNoErrors();
    }
    public function test_foc_cannot_bypass_unresolved_invoice_or_grant_an_inactive_or_trial_plan(): void
    {
        $data=['plan_id'=>$this->plan->id,'request_key'=>(string)Str::uuid(),'months'=>1,'note'=>'Promotion','confirmed'=>true];
        $this->plan->update(['is_active'=>false]);$this->post($this->url('complimentary'),$data)->assertSessionHasErrors('plan_id');
        $this->plan->update(['is_active'=>true,'is_trial'=>true]);$this->post($this->url('complimentary'),$data)->assertSessionHasErrors('plan_id');
        $this->plan->update(['is_trial'=>false]);$i=$this->invoice();
        $this->post($this->url('complimentary'),$data)->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('subscription_invoices',1);$this->assertDatabaseCount('subscription_periods',0);
    }
    public function test_foc_business_scope_and_suspension_are_preserved(): void
    {
        $this->business->update(['status'=>'Suspended']);
        $data=['plan_id'=>$this->plan->id,'request_key'=>(string)Str::uuid(),'months'=>1,'note'=>'Suspended partner grant','confirmed'=>true];
        $this->post($this->url('complimentary'),$data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->business->id,SubscriptionInvoice::sole()->business_id);
        $summary=app(\App\Billing\SubscriptionAccess::class)->summary($this->business);
        $this->assertSame('suspended',$summary['state']);$this->assertFalse($summary['can_operate']);
        $this->assertTrue(BusinessSubscription::where('business_id',$this->other->id)->sole()->auto_renew);
    }
    public function test_platform_currency_defaults_to_mmk_and_foreign_finance_writes_fail(): void
    {
        $this->assertSame('MMK', BusinessSubscription::where('business_id',$this->business->id)->sole()->plan_snapshot['currency']);
        foreach(['/platform/finance','/platform/founders','/platform/bank-books'] as $path){
            $this->get($path,['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.summary.currency','MMK')->assertJsonPath('props.currencies',['MMK']);
            $this->get($path.'?currency=USD')->assertSessionHasErrors('currency');
        }
        $data=['name'=>'MMK Cash','currency'=>'USD','account_reference'=>'TEST','is_active'=>true,'is_default'=>true];
        $this->post('/platform/bank-books',$data)->assertSessionHasErrors('currency');
        $this->assertDatabaseCount('platform_bank_books',0);
        $data['currency']='MMK';$this->post('/platform/bank-books',$data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('platform_bank_books',['name'=>'MMK Cash','currency'=>'MMK']);
        $this->post('/platform/finance/entries',['currency'=>'USD'])->assertSessionHasErrors('currency');
        $this->assertDatabaseCount('platform_finance_entries',0);
    }
    public function test_new_invoices_and_foc_grants_cannot_use_historical_foreign_plans(): void
    {
        $this->plan->update(['currency'=>'USD']);
        $data=['plan_id'=>$this->plan->id,'request_key'=>(string)Str::uuid(),'due_at'=>today()->toDateString(),'note'=>'Must not relabel'];
        $this->post($this->url('invoices'),$data)->assertSessionHasErrors('currency');
        $this->post($this->url('complimentary'),$data+['months'=>1,'confirmed'=>true])->assertSessionHasErrors('currency');
        $this->assertDatabaseCount('subscription_invoices',0);
        $this->assertSame('USD',$this->plan->fresh()->currency);$this->assertSame(2500,$this->plan->fresh()->amount_minor);
        $this->get($this->url(),['X-SPA'=>'true'])->assertOk()->assertJsonCount(0,'props.plans');
        $this->post('/platform/plans',['currency'=>'USD'])->assertSessionHasErrors('currency');
    }
    public function test_trial_currency_normalization_changes_no_amounts_or_subscription_dates(): void
    {
        $s=BusinessSubscription::where('business_id',$this->business->id)->sole();
        $plan=SubscriptionPlan::findOrFail($s->plan_id);$plan->update(['currency'=>'USD']);
        $snapshot=$s->plan_snapshot;$snapshot['currency']='USD';$s->update(['plan_snapshot'=>$snapshot]);
        $before=$s->only('trial_started_at','trial_ends_at','auto_renew');
        $this->artisan('platform:normalize-trial-currency')->assertSuccessful();
        $this->assertSame('MMK',$s->fresh()->plan_snapshot['currency']);
        $this->assertEquals($before,$s->fresh()->only('trial_started_at','trial_ends_at','auto_renew'));
        $this->assertSame(0,$plan->fresh()->amount_minor);
        $this->assertDatabaseCount('subscription_invoices',0);$this->assertDatabaseCount('subscription_payments',0);
        $this->artisan('platform:normalize-trial-currency')->assertSuccessful();
    }
    private function screenshot(): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent('payment.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }
    private function destination(): \App\Models\PlatformPaymentMethod
    {
        return \App\Models\PlatformPaymentMethod::create(['name'=>'Example Wallet','type'=>'other','account_name'=>'Office','account_number'=>'TEST-001','instructions'=>'Send screenshot','is_active'=>true,'sort_order'=>1]);
    }
    private function directRequest(array $overrides=[]): array
    {
        return array_replace(['plan_id'=>$this->plan->id,'payment_method_id'=>$this->destination()->id,'request_key'=>(string)Str::uuid(),'reference'=>'TX-'.Str::random(10),'payment_date'=>today()->toDateString(),'receipt'=>$this->screenshot()],$overrides);
    }
    public function test_owner_submits_screenshot_in_one_step_then_office_approves_once(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');$data=$this->directRequest();
        $this->actingAs($this->owner)->post('/billing/requests',$data)->assertRedirect()->assertSessionHasNoErrors();
        $invoice=SubscriptionInvoice::sole();$payment=SubscriptionPayment::sole();
        $this->assertSame('open',$invoice->status);$this->assertSame('pending',$payment->status);$this->assertSame(2500,$payment->amount_minor);
        $this->assertSame('Example Wallet',$payment->payment_method_snapshot['name']);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($payment->receipt_path);
        $this->assertDatabaseCount('subscription_periods',0);$this->assertDatabaseCount('platform_finance_receipts',0);
        $data['receipt']=$this->screenshot();$this->post('/billing/requests',$data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_payments',1);$this->assertCount(1,\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
        $this->actingAs($this->office)->post($this->url('payments/'.$payment->id.'/review'),['decision'=>'approve','confirmed'=>true,'note'=>'Screenshot checked'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid',$invoice->fresh()->status);$this->assertDatabaseCount('subscription_periods',1);$this->assertDatabaseCount('platform_finance_receipts',1);
    }
    public function test_owner_cannot_submit_missing_pdf_or_oversized_screenshot_or_hidden_method(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');$data=$this->directRequest();unset($data['receipt']);$this->actingAs($this->owner);
        $this->post('/billing/requests',$data)->assertSessionHasErrors('receipt');
        $this->post('/billing/requests',$data+['receipt'=>\Illuminate\Http\UploadedFile::fake()->create('proof.pdf',1,'application/pdf')])->assertSessionHasErrors('receipt');
        $this->post('/billing/requests',$data+['receipt'=>\Illuminate\Http\UploadedFile::fake()->create('huge.png',5121,'image/png')])->assertSessionHasErrors('receipt');
        \App\Models\PlatformPaymentMethod::findOrFail($data['payment_method_id'])->update(['is_active'=>false]);
        $this->post('/billing/requests',$data+['receipt'=>$this->screenshot()])->assertSessionHasErrors('payment_method_id');
        $this->assertDatabaseCount('subscription_invoices',0);$this->assertDatabaseCount('subscription_payments',0);$this->assertCount(0,\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }
    public function test_existing_open_renewal_is_reused_and_pending_request_cannot_duplicate(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');$invoice=$this->invoice();$data=$this->directRequest();
        $this->actingAs($this->owner)->post('/billing/requests',$data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($invoice->id,SubscriptionPayment::sole()->invoice_id);$this->assertDatabaseCount('subscription_invoices',1);
        $data['request_key']=(string)Str::uuid();$data['receipt']=$this->screenshot();$this->post('/billing/requests',$data)->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('subscription_payments',1);$this->assertCount(1,\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }
    public function test_request_is_owner_scoped_and_amounts_cannot_be_supplied_by_client(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');$data=$this->directRequest();
        $this->actingAs($this->owner)->post('/billing/requests',$data+['business_id'=>$this->other->id,'amount_minor'=>1,'status'=>'approved'])->assertSessionHasErrors(['business_id','amount_minor','status']);
        $this->actingAs($this->office)->post('/billing/requests',$data)->assertRedirect(route('platform.dashboard'));
        $this->assertDatabaseCount('subscription_invoices',0);
    }
    public function test_payment_methods_do_not_require_type_and_names_are_snapshotted(): void
    {
        $this->post('/platform/payment-methods',['name'=>'Simple Bank','account_name'=>'Office','account_number'=>'123','instructions'=>'Upload screenshot','is_active'=>true,'sort_order'=>0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('platform_payment_methods',['name'=>'Simple Bank','type'=>'other']);
        \Illuminate\Support\Facades\Storage::fake('local');$data=$this->directRequest();$this->actingAs($this->owner)->post('/billing/requests',$data)->assertRedirect()->assertSessionHasNoErrors();
        \App\Models\PlatformPaymentMethod::findOrFail($data['payment_method_id'])->update(['name'=>'Renamed destination']);
        $this->assertSame('Example Wallet',SubscriptionPayment::sole()->payment_method_snapshot['name']);
        $this->actingAs($this->other->users()->withoutGlobalScopes()->first())->get('/billing/payments/'.SubscriptionPayment::sole()->id.'/receipt')->assertNotFound();
    }
}
