<?php
namespace Tests\Feature;

use App\Models\{Branch, Business, CashSession, Customer, Inventory, InventoryBatch, PricingRule, Product, ProductUnit, Sale, SaleItem, Unit, User};
use App\Services\{AutomaticPricingService, PriceTypes, ProductUnitPriceCsvService};
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessPriceTypesTest extends TestCase
{
    use RefreshDatabase;
    private Branch $branch;
    private User $user;
    private Product $product;
    private ProductUnit $unit;
    private PricingRule $type;
    protected function setUp(): void
    {
        parent::setUp();
        $this->branch=Branch::create(['name'=>'Pricing branch','address'=>'Test','status'=>'Active']);
        $this->user=User::factory()->create(['branch_id'=>$this->branch->id,'active_branch_id'=>$this->branch->id]);
        $this->grantPermissions($this->user,['manage_settings','manage_products','manage_inventory','process_sale']);
        $this->actingAs($this->user);
        $this->product=Product::factory()->create(['pricing_base_cost'=>100,'discount_percentage'=>0,'tax_id'=>\App\Models\Tax::factory()->create(['rate'=>0])->id]);
        $unit=Unit::create(['name'=>'Piece','short_name'=>'pcs']);
        $this->unit=$this->product->product_units()->create(['unit_id'=>$unit->id,'conversion_factor'=>1,'selling_price'=>100,'wholesale_price'=>80,'is_base_unit'=>true,'is_default_selling_unit'=>true]);
        Inventory::create(['branch_id'=>$this->branch->id,'product_id'=>$this->product->id,'quantity'=>20]);
        InventoryBatch::create(['branch_id'=>$this->branch->id,'product_id'=>$this->product->id,'batch_number'=>'PRICE-1','expiry_date'=>today()->addYear(),'quantity'=>20,'purchase_price'=>50,'selling_price'=>100]);
        CashSession::create(['branch_id'=>$this->branch->id,'user_id'=>$this->user->id,'opening_amount'=>0,'opened_at'=>now(),'status'=>'open']);
        $this->type=PricingRule::forceCreate(['code'=>'type_member','name'=>'Member','sort_order'=>2]);
        $this->unit->update(['custom_prices'=>['type_member'=>['amount'=>'95.00','mode'=>'manual','status'=>'manual']]]);
    }
    private function payload(string $type='type_member'): array
    {
        return ['payment_method'=>'Cash','payment_status'=>'Paid','amount_received'=>1000,'items'=>[['product_id'=>$this->product->id,'product_unit_id'=>$this->unit->id,'unit_id'=>$this->unit->unit_id,'quantity'=>1,'unit_price'=>1,'price_type'=>$type]]];
    }
    public function test_price_type_crud_keeps_defaults_permanent_and_name_unique(): void
    {
        $this->post('/settings/price-types',['name'=>'Clinic'])->assertStatus(303)->assertSessionHasNoErrors();
        $clinic=PricingRule::where('name','Clinic')->sole();
        $this->assertStringStartsWith('type_',$clinic->code);
        $this->postJson('/settings/price-types',['name'=>'clinic'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/settings/price-types/selling_price',['name'=>'Other','is_active'=>false,'sort_order'=>3])->assertUnprocessable();
        $this->patch('/settings/price-types/'.$clinic->code,['name'=>'Distributor','is_active'=>false,'sort_order'=>8])->assertStatus(303);
        $this->assertSame(['retail','wholesale','type_member'],array_column(PriceTypes::choices(),'code'));
    }
    public function test_checkout_uses_server_custom_price_and_preserves_name_after_rename_and_deactivation(): void
    {
        $this->postJson('/api/cashier/sales',$this->payload())->assertSuccessful();
        $line=SaleItem::sole();
        $this->assertSame('95.00',$line->original_unit_price);
        $this->assertSame('Member',$line->price_type_name);
        $this->assertSame('type_member',$line->price_type);
        $this->type->update(['name'=>'New Member','is_active'=>false]);
        $this->unit->update(['custom_prices'=>['type_member'=>['amount'=>'777.00','mode'=>'manual']]]);
        $this->assertSame('Member',$line->fresh()->price_type_name);
        $this->assertSame('95.00',$line->fresh()->original_unit_price);
        $this->postJson('/api/cashier/sales',$this->payload())->assertUnprocessable();
        $this->assertSame(1,Sale::count());
    }
    public function test_web_checkout_accepts_custom_channel_and_ignores_client_amount(): void
    {
        $this->post('/pos/checkout',[...$this->payload(),'sale_channel'=>'type_member'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_items',['price_type'=>'type_member','price_type_name'=>'Member','unit_price'=>95]);
    }
    public function test_missing_and_zero_custom_prices_are_blocked_without_retail_fallback(): void
    {
        foreach ([[],['type_member'=>['amount'=>null]],['type_member'=>['amount'=>0]]] as $prices) {
            $this->unit->update(['custom_prices'=>$prices]);
            $this->postJson('/api/cashier/sales',$this->payload())->assertUnprocessable()->assertJsonValidationErrors('items');
        }
        $this->assertSame(0,Sale::count());
        $this->postJson('/api/cashier/sales',$this->payload('retail'))->assertSuccessful();
        $this->assertSame('100.00',SaleItem::sole()->original_unit_price);
    }
    public function test_unknown_and_foreign_business_type_cannot_be_used(): void
    {
        $business=Business::create(['name'=>'Other','slug'=>'other-prices','status'=>'Active']);
        app(\App\Billing\SubscriptionService::class)->startTrial($business);
        app(BusinessContext::class)->run($business, fn()=>PricingRule::forceCreate(['code'=>'type_private','name'=>'Private']));
        $this->postJson('/api/cashier/sales',$this->payload('type_private'))->assertUnprocessable();
        $this->assertNotContains('type_private',array_column(PriceTypes::choices(),'code'));
        $this->patchJson('/settings/price-types/type_private',['name'=>'Hijacked','is_active'=>true,'sort_order'=>2])->assertNotFound();
    }
    public function test_customer_preferences_are_scoped_and_exposed_in_pos_search(): void
    {
        $this->post('/customers',['name'=>'Clinic customer','preferred_price_type'=>'type_member'])->assertRedirect()->assertSessionHasNoErrors();
        $customer=Customer::sole();$this->assertSame('type_member',$customer->preferred_price_type);
        $this->getJson('/pos/customers?search=Clinic')->assertOk()->assertJsonFragment(['preferred_price_type'=>'type_member']);
        $this->getJson('/api/cashier/customers?search=Clinic')->assertOk()->assertJsonFragment(['preferred_price_type'=>'type_member']);
        $this->patchJson('/customers/'.$customer->id,['name'=>'Clinic customer','preferred_price_type'=>'other'])->assertUnprocessable();
    }
    public function test_custom_automatic_rule_preview_enrolls_and_uses_unit_conversion(): void
    {
        $box=Unit::create(['name'=>'Box','short_name'=>'box']);
        $boxUnit=$this->product->product_units()->create(['unit_id'=>$box->id,'conversion_factor'=>10,'selling_price'=>1000,'wholesale_price'=>800]);
        $values=['pricing_mode'=>'automatic','markup_percent'=>20,'minimum_profit'=>0,'rounding'=>10,'version'=>1,'apply_to_existing'=>true];
        $preview=$this->postJson('/settings/prices/type_member/preview',$values)->assertOk()->json();
        $this->assertSame(2,$preview['prices']);
        $this->patch('/settings/prices/type_member',[...$values,'preview_token'=>$preview['token']])->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertSame('120.00',PriceTypes::amount($this->unit->fresh(),'type_member'));
        $this->assertSame('1200.00',PriceTypes::amount($boxUnit->fresh(),'type_member'));
        $this->assertSame('100.00',$this->unit->fresh()->selling_price);
        $this->assertDatabaseHas('price_changes',['price_type'=>'type_member','new_price'=>120]);
    }
    public function test_unit_manual_override_survives_custom_automatic_refresh(): void
    {
        $this->type->update(['pricing_mode'=>'automatic','markup_percent'=>20]);
        DB::transaction(fn()=>app(AutomaticPricingService::class)->refreshProduct($this->product,'test',null,$this->type->fresh(),false));
        $this->assertSame('95.00',PriceTypes::amount($this->unit->fresh(),'type_member'));
        $this->assertSame('manual',PriceTypes::mode($this->unit->fresh(),'type_member'));
    }
    public function test_offline_sale_sync_keeps_custom_type_and_is_idempotent(): void
    {
        $payload=[...$this->payload(),'client_reference'=>'custom-price-offline-1','sale_date'=>now()->toIso8601String()];
        $payload['items'][0]['unit_price']=95;
        $service=app(\App\Services\Api\SaleSyncService::class);
        $service->sync($payload,$this->user);
        $this->type->update(['is_active'=>false]);
        $service->sync($payload,$this->user);
        $this->assertSame(1,Sale::count());$this->assertSame(1,SaleItem::count());
        $this->assertSame('Member',SaleItem::sole()->price_type_name);
        $this->assertSame(19,Inventory::sole()->quantity);
    }
    public function test_changed_offline_custom_price_requires_review_without_repricing_a_receipt(): void
    {
        $payload=[...$this->payload(),'client_reference'=>'changed-offline-price'];
        $payload['items'][0]['unit_price']=90;
        try { app(\App\Services\Api\SaleSyncService::class)->sync($payload,$this->user); $this->fail('A changed custom price must be reviewed.'); }
        catch (ValidationException $e) { $this->assertStringContainsString('will not be repriced automatically',$e->errors()['items'][0]); }
        $this->assertSame(0,Sale::count());
        $this->assertSame(20,Inventory::sole()->quantity);
    }

    public function test_new_units_follow_custom_automatic_rules_and_missing_cost_is_explicit(): void
    {
        $this->type->update(['pricing_mode'=>'automatic','markup_percent'=>20]);
        $product=Product::factory()->create(['pricing_base_cost'=>100]);
        $service=app(AutomaticPricingService::class);
        DB::transaction(function () use ($service,$product) {
            $service->saveUnits($product,[['unit_id'=>$this->unit->unit_id,'conversion_factor'=>1,'selling_price'=>50,'wholesale_price'=>40,'is_base_unit'=>true,'is_default_selling_unit'=>true]]);
            $service->refreshProduct($product,'test');
        });
        $unit=$product->product_units()->sole();
        $this->assertSame('automatic',PriceTypes::mode($unit,'type_member'));
        $this->assertSame('120.00',PriceTypes::amount($unit,'type_member'));
        $this->assertSame('50.00',$unit->selling_price);
        $product->update(['pricing_base_cost'=>null]);
        DB::transaction(fn()=>$service->refreshProduct($product,'test'));
        $this->assertSame('cost_required',$unit->fresh()->custom_prices['type_member']['status']);
        $this->expectException(ValidationException::class);
        PriceTypes::quote($unit->fresh(),'type_member');
    }

    public function test_foreign_custom_unit_payload_is_rejected_without_modifying_saved_prices(): void
    {
        try {
            DB::transaction(fn()=>PriceTypes::saveCustom($this->unit,['type_foreign'=>['amount'=>'1.00','mode'=>'manual']],PricingRule::all()->keyBy('code'),$this->user->id));
            $this->fail('Foreign type should fail.');
        } catch (ValidationException $e) { $this->assertArrayHasKey('custom_prices',$e->errors()); }
        $this->assertSame('95.00',PriceTypes::amount($this->unit->fresh(),'type_member'));
    }

    public function test_csv_round_trip_imports_custom_price_as_manual_and_preserves_missing_columns(): void
    {
        $columns=ProductUnitPriceCsvService::headers();
        $this->assertContains('price: member',$columns);
        $stream=fopen('php://temp','r+');fputcsv($stream,$columns);
        fputcsv($stream,[$this->product->id,$this->product->barcode,$this->product->name,'Piece','pcs',1,'yes','yes',100,80,'88.25']);rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
        app(ProductUnitPriceCsvService::class)->import(UploadedFile::fake()->createWithContent('prices.csv',$csv),false);
        $this->assertSame('88.25',PriceTypes::amount($this->unit->fresh(),'type_member'));
        $this->assertSame('manual',PriceTypes::mode($this->unit->fresh(),'type_member'));
    }
}
