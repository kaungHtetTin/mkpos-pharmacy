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
        $this->plan=SubscriptionPlan::create(['code'=>'unified-paid','name'=>'Unified Paid','amount_minor'=>2500,'currency'=>'USD','interval_months'=>1,'grace_days'=>3,'features'=>['cashier'=>true,'offline_sync'=>true,'reports'=>true],'is_active'=>true,'is_public'=>true,'is_trial'=>false]);
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
}
