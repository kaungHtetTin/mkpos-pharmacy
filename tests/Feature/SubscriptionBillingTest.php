<?php

namespace Tests\Feature;

use App\Billing\{SubscriptionService, SubscriptionAccess};
use App\Models\{Business, BusinessSubscription, SubscriptionPlan, SubscriptionInvoice, SubscriptionPayment, SubscriptionPeriod, User, Branch, Role, Product};
use App\Services\Businesses\ProvisionBusiness;
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private User $other;
    private User $office;
    private Business $a;
    private Business $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-31 12:00:00'));
        $this->owner=User::factory()->create();$this->other=User::factory()->create();$this->office=User::factory()->create();
        $this->office->forceFill(['is_platform_admin'=>true])->save();
        $this->a=app(ProvisionBusiness::class)->create(['name'=>'Subscription Alpha','slug'=>'subscription-alpha'],$this->owner);
        $this->b=app(ProvisionBusiness::class)->create(['name'=>'Subscription Beta','slug'=>'subscription-beta'],$this->other);
    }
    private function within(Business $business, callable $callback) { return app(BusinessContext::class)->run($business,$callback(...)); }
    private function plan(array $values=[]): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_replace(['code'=>'plan-'.Str::lower(Str::random(8)),'name'=>'Standard','amount_minor'=>1999,'currency'=>'MMK','interval_months'=>1,'grace_days'=>3,'max_branches'=>2,'max_users'=>3,'max_products'=>10,'features'=>['cashier'=>true,'offline_sync'=>true,'reports'=>true],'is_active'=>true,'is_public'=>true,'is_trial'=>false],$values));
    }
    private function invoice(?SubscriptionPlan $plan=null, ?Business $business=null, ?User $actor=null): SubscriptionInvoice
    {
        return app(SubscriptionService::class)->invoice($business??$this->a,$plan??$this->plan(),(string)Str::uuid(),$actor??$this->owner);
    }
    private function submission(SubscriptionInvoice $invoice, array $values=[], ?UploadedFile $receipt=null): SubscriptionPayment
    {
        return app(SubscriptionService::class)->submit($invoice,$invoice->business_id===$this->a->id?$this->owner:$this->other,array_replace(['request_key'=>(string)Str::uuid(),'method'=>'bank_transfer','reference'=>'BANK-'.Str::random(10),'payment_date'=>today()->toDateString()],$values),$receipt);
    }
    private function approve(SubscriptionInvoice $invoice): SubscriptionPayment
    {
        return app(SubscriptionService::class)->review($this->submission($invoice),$this->office,true,null);
    }
    private function expire(): void
    {
        BusinessSubscription::where('business_id',$this->a->id)->firstOrFail()->update(['trial_ends_at'=>now()->subSecond()]);
        app(SubscriptionAccess::class)->forget();
    }
    private function token(?User $user=null, ?Business $business=null): string
    {
        $token=($user??$this->owner)->createToken('billing-test');$token->accessToken->forceFill(['business_id'=>($business??$this->a)->id])->save();return $token->plainTextToken;
    }
    private function cashier(): User
    {
        return $this->within($this->a,function(){ $user=User::factory()->create(['branch_id'=>Branch::firstOrFail()->id]);$user->roles()->attach(Role::where('name','Cashier')->firstOrFail()->id);return $user; });
    }

    public function test_businesses_receive_one_trial_with_independent_usage(): void
    {
        $summary=app(SubscriptionAccess::class)->summary($this->a);
        $this->assertSame('trialing',$summary['state']);$this->assertTrue($summary['can_operate']);
        $this->assertSame(['branches'=>1,'users'=>1,'products'=>0],app(SubscriptionAccess::class)->usage($this->a->id));
        $before=BusinessSubscription::where('business_id',$this->a->id)->firstOrFail()->trial_ends_at;
        $this->travel(2)->days();app(SubscriptionService::class)->startTrial($this->a);
        $this->assertTrue($before->eq(BusinessSubscription::where('business_id',$this->a->id)->firstOrFail()->trial_ends_at));
        $this->assertSame(2,BusinessSubscription::count());
    }
    public function test_expiry_blocks_operations_but_owner_can_reach_billing_and_profile(): void
    {
        $this->expire();
        $this->actingAs($this->owner)->get('/products')->assertRedirect(route('billing.index'));
        $this->get('/billing')->assertOk();$this->get('/profile')->assertOk();
        $this->withToken($this->token())->getJson('/api/cashier/products')->assertStatus(402)->assertJsonPath('code','subscription_required');
        $this->getJson('/api/account/profile')->assertOk()->assertJsonPath('subscription.can_operate',false);
        $this->getJson('/api/billing')->assertOk()->assertJsonCount(0,'invoices.data');
    }
    public function test_staff_cannot_view_billing_or_review_payments_and_expired_status_is_readable(): void
    {
        $staff=$this->cashier();$this->expire();
        $this->withToken($this->token($staff))->getJson('/api/billing')->assertForbidden();
        $this->getJson('/api/account/subscription')->assertOk()->assertJsonPath('can_operate',false);
        $this->getJson('/api/cashier/customers')->assertStatus(402);
        $this->actingAs($staff)->get('/platform/billing')->assertForbidden();
    }
    public function test_invoice_snapshots_survive_plan_edits_and_only_approval_restores_access(): void
    {
        $plan=$this->plan();$invoice=$this->invoice($plan);$this->expire();
        $plan->update(['amount_minor'=>5000,'max_users'=>1,'features'=>['cashier'=>false,'offline_sync'=>false,'reports'=>false]]);
        $payment=$this->submission($invoice);
        $this->assertFalse(app(SubscriptionAccess::class)->summary($this->a)['can_operate']);
        app(SubscriptionService::class)->review($payment,$this->office,true,'Verified bank receipt');
        $summary=app(SubscriptionAccess::class)->summary($this->a);
        $this->assertTrue($summary['can_operate']);$this->assertSame(1999,$summary['plan']['amount_minor']);$this->assertSame(3,$summary['plan']['max_users']);$this->assertTrue($summary['plan']['features']['cashier']);
        $this->assertSame('paid',$invoice->fresh()->status);$this->assertSame('2027-02-28',$invoice->fresh()->period_ends_at->toDateString());
    }
    public function test_owner_submits_payment_after_expiry_and_office_approval_is_explicit(): void
    {
        $invoice=$this->invoice();$this->expire();
        $this->actingAs($this->owner)->postJson('/billing/invoices/'.$invoice->id.'/payments',['request_key'=>(string)Str::uuid(),'method'=>'cash','reference'=>'CASH-001','payment_date'=>today()->toDateString()])->assertRedirect();
        $payment=SubscriptionPayment::firstOrFail();
        $this->actingAs($this->office)->postJson('/platform/billing/payments/'.$payment->id.'/review',['decision'=>'approve','confirmed'=>false])->assertUnprocessable();
        $this->postJson('/platform/billing/payments/'.$payment->id.'/review',['decision'=>'approve','confirmed'=>true])->assertRedirect();
        $this->actingAs($this->owner)->get('/products')->assertOk();
    }
    public function test_invoice_and_approval_idempotency_never_grants_two_periods(): void
    {
        $plan=$this->plan();$key=(string)Str::uuid();$service=app(SubscriptionService::class);
        $invoice=$service->invoice($this->a,$plan,$key,$this->owner);
        $this->assertSame($invoice->id,$service->invoice($this->a,$plan,$key,$this->owner)->id);
        $data=['request_key'=>(string)Str::uuid(),'method'=>'bank_transfer','reference'=>'IDEMPOTENT','payment_date'=>today()->toDateString()];
        $payment=$this->submission($invoice,$data);$this->assertSame($payment->id,$this->submission($invoice,$data)->id);
        $service->review($payment,$this->office,true,null);$end=$invoice->fresh()->period_ends_at;
        $service->review($payment,$this->office,true,null);
        $this->assertSame(1,SubscriptionPeriod::where('business_id',$this->a->id)->count());$this->assertTrue($end->eq($invoice->fresh()->period_ends_at));
    }
    public function test_paid_reference_cannot_be_reused_for_another_invoice(): void
    {
        $plan=$this->plan();$invoice=$this->invoice($plan);$payment=$this->submission($invoice,['reference'=>'ONE-TRANSFER']);app(SubscriptionService::class)->review($payment,$this->office,true,null);
        $next=$this->invoice($plan);
        $this->expectException(ValidationException::class);$this->submission($next,['reference'=>'one-transfer']);
    }
    public function test_rejection_allows_corrected_submission_without_granting_access(): void
    {
        $invoice=$this->invoice();$payment=$this->submission($invoice);$this->expire();
        $this->actingAs($this->office)->postJson('/platform/billing/payments/'.$payment->id.'/review',['decision'=>'reject','note'=>'Reference not received'])->assertRedirect();
        $this->assertSame('rejected',$payment->fresh()->status);$this->assertFalse(app(SubscriptionAccess::class)->summary($this->a)['can_operate']);
        $corrected=$this->submission($invoice);$this->assertSame('pending',$corrected->status);$this->assertSame('open',$invoice->fresh()->status);
    }
    public function test_paid_period_grace_and_expiry_boundaries_do_not_depend_on_cron(): void
    {
        $invoice=$this->invoice();$this->approve($invoice);$end=$invoice->fresh()->period_ends_at;
        $this->travelTo($end);$this->assertSame('grace',app(SubscriptionAccess::class)->summary($this->a)['state']);
        $this->travelTo($end->copy()->addDays(3));$summary=app(SubscriptionAccess::class)->summary($this->a);$this->assertSame('expired',$summary['state']);$this->assertFalse($summary['can_operate']);
        $this->withToken($this->token())->getJson('/api/cashier/products')->assertStatus(402);
    }
    public function test_renewal_and_plan_changes_queue_without_losing_paid_time(): void
    {
        $invoice=$this->invoice();$this->approve($invoice);$end=$invoice->fresh()->period_ends_at;
        $new=$this->plan(['name'=>'Inventory only','features'=>['cashier'=>false,'offline_sync'=>false,'reports'=>false]]);$next=$this->invoice($new);$this->approve($next);
        $this->assertTrue($next->fresh()->period_starts_at->eq($end));$this->assertSame('Standard',app(SubscriptionAccess::class)->summary($this->a)['plan']['name']);
        $this->travelTo($end);$this->assertSame('Inventory only',app(SubscriptionAccess::class)->summary($this->a)['plan']['name']);
        $this->withToken($this->token())->getJson('/api/cashier/products')->assertForbidden()->assertJsonPath('code','feature_unavailable');
        $this->getJson('/api/sync/sales')->assertStatus(405);
        $this->postJson('/api/sync/sales',['sales'=>[]])->assertForbidden()->assertJsonPath('code','feature_unavailable');
    }
    public function test_cancellation_keeps_paid_time_and_removes_grace_after_its_end(): void
    {
        $invoice=$this->invoice();$this->approve($invoice);app(SubscriptionService::class)->cancel($this->a,$this->owner);
        $this->assertSame('canceling',app(SubscriptionAccess::class)->summary($this->a)['state']);
        $this->travelTo($invoice->fresh()->period_ends_at);$this->assertSame('canceled',app(SubscriptionAccess::class)->summary($this->a)['state']);
        $this->artisan('subscriptions:process')->assertSuccessful();$this->assertSame(1,SubscriptionInvoice::where('business_id',$this->a->id)->count());
    }
    public function test_quota_enforcement_rolls_back_new_accounts_and_blocks_reactivation(): void
    {
        $staff=$this->cashier();$this->approve($this->invoice($this->plan(['max_users'=>2])));
        $this->actingAs($this->owner)->delete('/staff/'.$staff->id)->assertRedirect();
        $this->cashier();$role=$this->within($this->a,fn()=>Role::where('name','Cashier')->firstOrFail()->id);$branch=$this->within($this->a,fn()=>Branch::firstOrFail()->id);
        $this->putJson('/business-members/'.$staff->id,['status'=>'Active','role_id'=>$role,'branch_id'=>$branch])->assertUnprocessable()->assertJsonValidationErrors('subscription');
        $this->postJson('/staff',['name'=>'Too many','email'=>'over-limit@example.test','password'=>'test-password','password_confirmation'=>'test-password','branch_id'=>$branch,'role_id'=>$role])->assertUnprocessable();
        $this->assertDatabaseMissing('users',['email'=>'over-limit@example.test']);$this->assertSame(2,app(SubscriptionAccess::class)->usage($this->a->id)['users']);
    }
    public function test_branch_and_product_limits_apply_to_models_and_csv_imports_atomically(): void
    {
        $this->approve($this->invoice($this->plan(['max_branches'=>1,'max_products'=>1])));
        $this->actingAs($this->owner)->postJson('/branches',['name'=>'Overflow','address'=>'Test'])->assertUnprocessable()->assertJsonValidationErrors('subscription');
        $headers=\App\Services\ProductCsvImportService::HEADERS;$stream=fopen('php://temp','w+');fputcsv($stream,$headers,',','"','');
        foreach(['One','Two'] as $name){$row=array_replace(array_fill_keys($headers,''),['generic_name'=>$name,'category'=>'Medicine','base_unit_name'=>'Tablet','base_unit_short_name'=>'Tab','buying_cost'=>'1','selling_price'=>'2']);fputcsv($stream,array_values($row),',','"','');}rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
        $this->post('/products/import',['file'=>UploadedFile::fake()->createWithContent('products.csv',$csv),'create_missing_categories'=>true,'create_missing_units'=>true])->assertSessionHasErrors();
        $this->assertSame(0,Product::withoutGlobalScopes()->where('business_id',$this->a->id)->count());
    }
    public function test_receipts_and_billing_records_cannot_be_read_by_another_business(): void
    {
        Storage::fake('local');$invoice=$this->invoice();$payment=$this->submission($invoice,[],UploadedFile::fake()->create('receipt.pdf',1,'application/pdf'));
        $this->actingAs($this->other)->get('/billing/payments/'.$payment->id.'/receipt')->assertNotFound();
        $this->postJson('/billing/invoices/'.$invoice->id.'/payments',['request_key'=>(string)Str::uuid(),'method'=>'cash','reference'=>'FOREIGN','payment_date'=>today()->toDateString()])->assertNotFound();
        $this->actingAs($this->owner)->get('/billing/payments/'.$payment->id.'/receipt')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $this->actingAs($this->office)->get('/platform/billing/payments/'.$payment->id.'/receipt')->assertOk();
        $this->actingAs($this->other)->withHeader('X-SPA','true')->get('/billing')->assertOk()->assertJsonCount(0,'props.invoices.data');
    }
    public function test_pending_payments_prevent_voiding_and_paid_invoices_are_immutable(): void
    {
        $invoice=$this->invoice();$payment=$this->submission($invoice);
        $this->actingAs($this->owner)->postJson('/billing/invoices/'.$invoice->id.'/void',['reason'=>'Changed my mind'])->assertUnprocessable();
        app(SubscriptionService::class)->review($payment,$this->office,true,null);
        $this->postJson('/billing/invoices/'.$invoice->id.'/void',['reason'=>'Already paid'])->assertUnprocessable();$this->assertSame('paid',$invoice->fresh()->status);
    }
    public function test_renewal_processing_is_repeatable_and_issues_one_invoice(): void
    {
        $invoice=$this->invoice();$this->approve($invoice);$this->travelTo($invoice->fresh()->period_ends_at->copy()->subDays(7));
        $this->artisan('subscriptions:process')->assertSuccessful();$this->artisan('subscriptions:process')->assertSuccessful();
        $this->assertSame(1,SubscriptionInvoice::where('business_id',$this->a->id)->where('status','open')->count());
    }
    public function test_only_office_can_edit_plans_and_prices_use_exact_minor_units(): void
    {
        $data=['code'=>'starter','name'=>'Starter','price'=>'0.10','currency'=>'MMK','interval_months'=>1,'grace_days'=>2,'max_branches'=>1,'max_users'=>2,'max_products'=>10,'features'=>['cashier'=>true,'offline_sync'=>false,'reports'=>false],'is_active'=>true,'is_public'=>true];
        $this->actingAs($this->owner)->postJson('/platform/plans',$data)->assertForbidden();
        $this->actingAs($this->office)->postJson('/platform/plans',$data)->assertRedirect();$this->assertDatabaseHas('subscription_plans',['code'=>'starter','amount_minor'=>10]);
        $this->postJson('/platform/plans',array_replace($data,['code'=>'bad-price','price'=>'19.995']))->assertUnprocessable()->assertJsonValidationErrors('price');
    }
    public function test_unpublished_plans_cannot_be_requested_by_owners(): void
    {
        $plan=$this->plan(['is_public'=>false]);$this->actingAs($this->owner)->postJson('/billing/invoices',['plan_id'=>$plan->id,'request_key'=>(string)Str::uuid()])->assertUnprocessable();
        $this->actingAs($this->office)->postJson('/platform/billing/invoices',['business_id'=>$this->a->id,'plan_id'=>$plan->id,'request_key'=>(string)Str::uuid()])->assertRedirect();
    }
    public function test_free_public_plan_is_explicitly_activated_without_fake_payment(): void
    {
        $invoice=$this->invoice($this->plan(['amount_minor'=>0]));$this->assertSame('paid',$invoice->status);$this->assertSame(0,SubscriptionPayment::count());$this->assertSame('active',app(SubscriptionAccess::class)->summary($this->a)['state']);
    }
    public function test_suspension_still_blocks_access_after_approved_payment(): void
    {
        $invoice=$this->invoice();$this->a->update(['status'=>'Suspended']);$this->approve($invoice);
        $this->withToken($this->token())->getJson('/api/cashier/products')->assertForbidden();$this->assertSame('Suspended',$this->a->fresh()->status);
    }
    public function test_manual_payment_does_not_undo_a_cancelled_renewal_preference(): void
    {
        $invoice=$this->invoice();app(SubscriptionService::class)->cancel($this->a,$this->owner);$this->approve($invoice);
        $summary=app(SubscriptionAccess::class)->summary($this->a);
        $this->assertTrue($summary['can_operate']);$this->assertFalse($summary['auto_renew']);$this->assertSame('canceling',$summary['state']);
    }

    public function test_client_cannot_set_payment_amount_or_approval_status(): void
    {
        $invoice=$this->invoice();$data=['request_key'=>(string)Str::uuid(),'method'=>'bank_transfer','reference'=>'SERVER-PRICE','payment_date'=>today()->toDateString(),'amount_minor'=>1,'status'=>'approved'];
        $this->actingAs($this->owner)->postJson('/billing/invoices/'.$invoice->id.'/payments',$data)->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
        unset($data['amount_minor']);$this->postJson('/billing/invoices/'.$invoice->id.'/payments',$data)->assertRedirect();
        $payment=SubscriptionPayment::firstOrFail();$this->assertSame('pending',$payment->status);$this->assertSame($invoice->amount_minor,$payment->amount_minor);
    }

    public function test_online_checkout_is_idempotent_when_offline_sync_is_not_included(): void
    {
        $this->approve($this->invoice($this->plan(['features'=>['cashier'=>true,'offline_sync'=>false,'reports'=>true]])));
        $payload=$this->within($this->a,function(){
            $branch=Branch::firstOrFail();$product=Product::factory()->create(['tax_id'=>\App\Models\Tax::firstOrFail()->id,'tax_method'=>'Exclusive']);$unit=\App\Models\Unit::firstOrFail();
            $pu=\App\Models\ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_factor'=>1,'selling_price'=>100,'is_base_unit'=>true]);
            \App\Models\Inventory::create(['branch_id'=>$branch->id,'product_id'=>$product->id,'quantity'=>20]);
            \App\Models\InventoryBatch::create(['branch_id'=>$branch->id,'product_id'=>$product->id,'quantity'=>20,'batch_number'=>'ONLINE','expiry_date'=>today()->addYear(),'purchase_price'=>50,'selling_price'=>100]);
            $session=\App\Models\CashSession::create(['branch_id'=>$branch->id,'user_id'=>$this->owner->id,'opening_amount'=>0,'opened_at'=>now(),'status'=>'open']);
            return ['client_reference'=>'online-plan-checkout','branch_id'=>$branch->id,'cash_session_id'=>$session->id,'payment_method'=>'Cash','payment_status'=>'Paid','amount_received'=>100,'items'=>[['product_id'=>$product->id,'product_unit_id'=>$pu->id,'quantity'=>1,'unit_price'=>100]]];
        });
        $this->withToken($this->token())->postJson('/api/cashier/checkout',$payload)->assertCreated()->assertJsonPath('created',true);
        $this->postJson('/api/cashier/checkout',$payload)->assertOk()->assertJsonPath('created',false);
        $this->assertSame(1,DB::table('sales')->where('business_id',$this->a->id)->count());
        $this->assertSame(19,(int)DB::table('inventories')->where('business_id',$this->a->id)->value('quantity'));
        $this->postJson('/api/sync/sales',['sales'=>[$payload]])->assertForbidden()->assertJsonPath('feature','offline_sync');
    }

    public function test_office_can_define_each_duration_in_months_and_reject_invalid_terms(): void
    {
        $this->actingAs($this->office);
        $data=['code'=>'months-plan','name'=>'Flexible months','price'=>'25000','currency'=>'MMK','interval_months'=>2,'grace_days'=>0,'max_branches'=>1,'max_users'=>2,'max_products'=>10,'features'=>['cashier'=>true,'offline_sync'=>true,'reports'=>true],'is_active'=>true,'is_public'=>true];
        foreach([1,2,3,12,36] as $months){
            $this->post('/platform/plans',array_replace($data,['code'=>'months-'.$months,'interval_months'=>$months]))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertDatabaseHas('subscription_plans',['code'=>'months-'.$months,'interval_months'=>$months,'amount_minor'=>2500000,'currency'=>'MMK']);
        }
        foreach([0,37,2.5,'bad'] as $months)$this->post('/platform/plans',array_replace($data,['interval_months'=>$months]))->assertSessionHasErrors('interval_months');
    }
    public function test_multi_month_payment_expiry_and_plan_edits_preserve_invoice_snapshot(): void
    {
        $plan=$this->plan(['interval_months'=>2]);$invoice=$this->invoice($plan);
        $plan->update(['interval_months'=>3]);$this->approve($invoice);
        $invoice=$invoice->fresh();$this->assertTrue($invoice->period_ends_at->equalTo($invoice->period_starts_at->copy()->addMonthsNoOverflow(2)));
        $this->assertSame(2,$invoice->plan_snapshot['interval_months']);
        $next=$this->invoice($plan);$this->approve($next);$next=$next->fresh();
        $this->assertTrue($next->period_starts_at->equalTo($invoice->period_ends_at));
        $this->assertTrue($next->period_ends_at->equalTo($next->period_starts_at->copy()->addMonthsNoOverflow(3)));
    }
}
