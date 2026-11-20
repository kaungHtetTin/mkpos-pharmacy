<?php

namespace Tests\Feature;

use App\Models\{Branch, Business, CashSession, Inventory, InventoryBatch, Product, Sale, Setting, Unit, User};
use App\Services\BusinessPaymentMethods;
use App\Services\Api\SaleSyncService;
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;
    private User $user;
    private $unit;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name'=>'Payments branch','address'=>'Test','status'=>'Active']);
        $this->user = User::factory()->create(['branch_id'=>$this->branch->id,'active_branch_id'=>$this->branch->id]);
        $this->grantPermissions($this->user, ['manage_settings','process_sale','view_reports','view_finance','view_financial_reports','manage_payments','approve_returns']);
        $this->actingAs($this->user);
        $product = Product::factory()->create(['discount_percentage'=>0,'tax_id'=>\App\Models\Tax::factory()->create(['rate'=>0])->id]);
        $unit = Unit::create(['name'=>'Piece','short_name'=>'pcs']);
        $this->unit = $product->product_units()->create(['unit_id'=>$unit->id,'conversion_factor'=>1,'selling_price'=>100,'wholesale_price'=>80,'is_base_unit'=>true,'is_default_selling_unit'=>true]);
        Inventory::create(['branch_id'=>$this->branch->id,'product_id'=>$product->id,'quantity'=>20]);
        InventoryBatch::create(['branch_id'=>$this->branch->id,'product_id'=>$product->id,'batch_number'=>'PAYMENT-1','expiry_date'=>today()->addYear(),'quantity'=>20,'purchase_price'=>50,'selling_price'=>100]);
        CashSession::create(['branch_id'=>$this->branch->id,'user_id'=>$this->user->id,'opening_amount'=>0,'opened_at'=>now(),'status'=>'open']);
    }

    private function payload(string $method): array
    {
        return ['branch_id'=>$this->branch->id,'payment_method'=>$method,'payment_status'=>'Paid','amount_received'=>0,'items'=>[['product_id'=>$this->unit->product_id,'product_unit_id'=>$this->unit->id,'unit_id'=>$this->unit->unit_id,'quantity'=>1,'unit_price'=>100,'price_type'=>'retail']]];
    }

    private function configure(array $methods): void
    {
        $this->patchJson('/settings/payment-methods',['methods'=>$methods])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_defaults_configuration_validation_and_tenant_isolation(): void
    {
        $this->assertSame(['Cash','Banking'], BusinessPaymentMethods::active());
        $this->configure([['name'=>' KBZ Pay ','is_active'=>true],['name'=>'Wave','is_active'=>false]]);
        $this->assertSame(['Cash','Banking','KBZ Pay'], BusinessPaymentMethods::active());
        foreach (['cash','BANKING','KBZ Pay'] as $name) {
            $rows=[['name'=>'KBZ Pay','is_active'=>true],['name'=>$name,'is_active'=>true]];
            $this->patchJson('/settings/payment-methods',['methods'=>$rows])->assertUnprocessable();
        }
        $this->patchJson('/settings/payment-methods',['methods'=>[['name'=>"bad\nname",'is_active'=>true]]])->assertUnprocessable();
        $this->patchJson('/settings/payment-methods', ['methods'=>[['name'=>['invalid'], 'is_active'=>true]]])->assertUnprocessable();
        $this->patchJson('/settings/payment-methods', ['methods'=>['key'=>['name'=>'Invalid list', 'is_active'=>true]]])->assertUnprocessable();
        $other=Business::create(['name'=>'Other business','slug'=>'other-payments','status'=>'Active']);
        app(\App\Billing\SubscriptionService::class)->startTrial($other);
        app(BusinessContext::class)->run($other, function () {
            $this->assertSame(['Cash','Banking'], BusinessPaymentMethods::active());
            Setting::set('pos.payment_methods',json_encode([['name'=>'Other bank','is_active'=>true]]));
            $this->assertSame(['Cash','Banking','Other bank'], BusinessPaymentMethods::active());
        });
        $this->assertSame(['Cash','Banking','KBZ Pay'], BusinessPaymentMethods::active());
    }

    public function test_form_encoded_empty_configuration_keeps_defaults_and_default_fallback(): void
    {
        $this->configure([['name'=>'KBZ Pay','is_active'=>true]]);
        Setting::set('pos.default_payment_method','KBZ Pay');
        $this->configure([['name'=>'KBZ Pay','is_active'=>false]]);
        $this->assertSame('Cash', BusinessPaymentMethods::default());
        $this->patch('/settings/payment-methods',['methods'=>'[]'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([],BusinessPaymentMethods::custom());
        $this->patchJson('/settings/payment-methods',['methods'=>'not json'])->assertUnprocessable();
    }

    public function test_checkout_api_sync_and_receipt_settings_use_business_methods_without_cash_movement(): void
    {
        $this->configure([['name'=>'KBZ Pay','is_active'=>true]]);
        $this->post('/pos/checkout',$this->payload('Banking'))->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/api/cashier/sales',$this->payload('KBZ Pay'))->assertSuccessful();
        $sync=app(SaleSyncService::class)->sync([...$this->payload('KBZ Pay'),'client_reference'=>'payment-queue-1'],$this->user);
        $this->assertTrue($sync['created']);
        $this->assertSame(3,Sale::count());
        $this->assertEquals(300,Sale::sum('grand_total'));
        $this->assertEquals(300,Sale::sum('amount_received'));
        $this->getJson('/api/cashier/receipt-settings')->assertOk()->assertJsonPath('payment_methods',['Cash','Banking','KBZ Pay']);
        $this->get('/pos',['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.paymentMethods',['Cash','Banking','KBZ Pay'])->assertJsonPath('props.activeSession.expected_amount',0)->assertJsonPath('props.activeSession.payment_totals.KBZ Pay',200);
        $this->get('/reports/cash-sessions',['X-SPA'=>'true'])->assertOk()->assertJsonPath('props.summary.non_cash_sales_total',300)->assertJsonPath('props.paymentBreakdown.KBZ Pay','200.00');
        $this->configure([['name'=>'KBZ Pay','is_active'=>false]]);
        $this->assertFalse(app(SaleSyncService::class)->sync([...$this->payload('KBZ Pay'),'client_reference'=>'payment-queue-1'],$this->user)['created']);
    }

    public function test_disabled_or_foreign_methods_are_rejected_and_history_keeps_original_names(): void
    {
        $this->configure([['name'=>'KBZ Pay','is_active'=>true]]);
        $this->post('/pos/checkout',$this->payload('KBZ Pay'))->assertRedirect()->assertSessionHasNoErrors();
        $this->configure([['name'=>'New name','is_active'=>true]]);
        $this->assertSame('KBZ Pay',Sale::sole()->payment_method);
        $this->assertContains('KBZ Pay',BusinessPaymentMethods::history());
        foreach (['KBZ Pay','Other business bank','Card'] as $method) {
            $this->postJson('/pos/checkout',$this->payload($method))->assertUnprocessable()->assertJsonValidationErrors('payment_method');
            $this->postJson('/api/cashier/sales',$this->payload($method))->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        }
        $this->assertSame(1,Sale::count());
        $this->assertEquals(19,Inventory::sole()->quantity);
    }

    public function test_custom_method_records_repayments_and_refunds_with_historical_names(): void
    {
        $this->configure([['name'=>'KBZ Pay','is_active'=>true]]);
        $customer=\App\Models\Customer::create(['name'=>'Payment customer']);
        $this->post('/pos/checkout',[...$this->payload('Banking'),'customer_id'=>$customer->id,'payment_status'=>'Credit','due_date'=>today()->addWeek()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $sale=Sale::sole();
        $this->post('/finance/amount-receivable/'.$sale->id.'/receive',['amount'=>100,'payment_method'=>'KBZ Pay'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('KBZ Pay',\App\Models\CustomerPayment::sole()->payment_method);
        $return=app(\App\Services\ReturnService::class)->create(['type'=>'Customer','reference_id'=>$sale->id,'reason'=>'Return medicine','items'=>[['reference_item_id'=>$sale->items()->sole()->id,'quantity'=>1,'foc_quantity'=>0]]],collect([$this->branch->id]));
        app(\App\Services\ReturnService::class)->decide($return,'Approved');
        $this->postJson('/returns/'.$return->id.'/settlements',['amount'=>100,'payment_method'=>'KBZ Pay','reference_number'=>'QA-REFUND'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('KBZ Pay',\App\Models\ReturnSettlement::sole()->payment_method);
        $this->assertNull(\App\Models\ReturnSettlement::sole()->cash_session_id);
        $this->configure([['name'=>'New name','is_active'=>false]]);
        $this->assertSame('KBZ Pay',\App\Models\CustomerPayment::sole()->payment_method);
        $this->assertSame('KBZ Pay',\App\Models\ReturnSettlement::sole()->payment_method);
    }

    public function test_configuration_requires_settings_permission(): void
    {
        $user=User::factory()->create(['branch_id'=>$this->branch->id]);
        $this->actingAs($user)->patchJson('/settings/payment-methods',['methods'=>[]])->assertForbidden();
    }
}
