<?php

namespace Tests\Feature;

use App\Inventory\StockLedger;
use App\Models\{Branch, Business, Inventory, InventoryAdjustment, InventoryBatch, Product, StockMovement, User};
use App\Tenancy\BusinessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockMovementHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'Main branch', 'address' => 'Yangon', 'status' => 'Active']);
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->grantPermissions($this->user, ['manage_inventory', 'adjust_stock']);
        $this->actingAs($this->user);
        $this->product = Product::factory()->create(['name' => 'Tracked medicine']);
    }

    private function stock(int $quantity = 10, ?Branch $branch = null): Inventory
    {
        return Inventory::create(['branch_id' => ($branch ?? $this->branch)->id, 'product_id' => $this->product->id, 'quantity' => $quantity]);
    }

    private function adjustment(): InventoryAdjustment
    {
        return InventoryAdjustment::create(['branch_id' => $this->branch->id, 'product_id' => $this->product->id,
            'user_id' => $this->user->id, 'adjustment_type' => 'Correction', 'quantity' => 3,
            'adjustment_date' => today(), 'reason' => 'Count checked']);
    }

    public function test_adjustment_endpoint_records_actor_reason_and_base_unit_balances(): void
    {
        $inventory = $this->stock();
        $this->post('/inventory/adjustments', ['branch_id' => $this->branch->id, 'product_id' => $this->product->id,
            'inventory_batch_id' => null, 'adjustment_type' => 'Damage', 'quantity' => -3,
            'adjustment_date' => today()->toDateString(), 'reason' => 'Broken packaging'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $m = StockMovement::sole();
        $this->assertSame([10, -3, 7], [$m->before_quantity, $m->quantity, $m->after_quantity]);
        $this->assertSame(7, $inventory->fresh()->quantity);
        $this->assertSame($this->user->id, $m->actor_id);
        $this->assertSame($this->user->name, $m->actor_name);
        $this->assertSame('adjustment', $m->action);
        $this->assertSame('Damage: Broken packaging', $m->note);
        $this->assertSame('InventoryAdjustment', $m->source_type);
    }

    public function test_stale_inventory_instances_use_locked_current_balance_and_clamping_is_explicit(): void
    {
        $inventory = $this->stock(); $stale = $inventory->fresh(); $source = $this->adjustment();
        StockLedger::change($inventory, 5, 'adjustment', $source);
        $m = StockLedger::change($stale, -20, 'sale', $source, clamp: true);
        $this->assertSame([15, -15, 0, -20], [$m->before_quantity, $m->quantity, $m->after_quantity, $m->requested_quantity]);
        $this->assertStringContainsString('Requested stock change -20', $m->note);
        $this->assertSame(0, $inventory->fresh()->quantity);
    }

    public function test_transaction_failure_rolls_back_both_balance_and_history(): void
    {
        $inventory = $this->stock(); $source = $this->adjustment();
        try {
            DB::transaction(function () use ($inventory, $source) {
                StockLedger::change($inventory, -3, 'adjustment', $source);
                throw new \RuntimeException('Simulated later batch failure');
            });
            $this->fail('Transaction should throw.');
        } catch (\RuntimeException $e) { $this->assertSame('Simulated later batch failure', $e->getMessage()); }
        $this->assertSame(10, $inventory->fresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_opening_snapshot_is_idempotent_and_never_recreates_past_events(): void
    {
        $inventory = $this->stock(24);
        $other = Branch::create(['name' => 'Empty branch', 'address' => 'Test', 'status' => 'Active']);
        $this->stock(0, $other);
        $this->artisan('inventory:initialize-movements')->assertExitCode(0);
        $this->artisan('inventory:initialize-movements')->assertExitCode(0);
        $m = StockMovement::sole();
        $this->assertSame('opening_balance', $m->action);
        $this->assertNull($m->before_quantity);
        $this->assertSame(24, $m->after_quantity);
        $this->assertSame(24, $inventory->fresh()->quantity);
        StockLedger::change($inventory, 3, 'adjustment', $this->adjustment());
        $this->artisan('inventory:initialize-movements')->assertExitCode(0);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_existing_movements_prevent_late_opening_snapshot(): void
    {
        StockLedger::change($this->stock(), 3, 'adjustment', $this->adjustment());
        $this->artisan('inventory:initialize-movements')->assertExitCode(0);
        $this->assertSame(['adjustment'], StockMovement::pluck('action')->all());
    }

    public function test_transfer_writes_equal_opposite_movements_at_both_branches(): void
    {
        $inventory = $this->stock();
        $other = Branch::create(['name' => 'Destination', 'address' => 'Test', 'status' => 'Active']);
        $batch = InventoryBatch::create(['product_id' => $this->product->id, 'branch_id' => $this->branch->id,
            'batch_number' => 'TRACK-BATCH', 'expiry_date' => today()->addYear(), 'quantity' => 10, 'purchase_price' => 1, 'selling_price' => 2]);
        $this->post('/inventory/transfers', ['from_branch_id' => $this->branch->id, 'to_branch_id' => $other->id,
            'transfer_date' => today()->toDateString(), 'notes' => 'Branch refill', 'items' => [
                ['product_id' => $this->product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 4],
            ]])->assertRedirect()->assertSessionHasNoErrors();
        $out = StockMovement::where('action', 'transfer_out')->sole();
        $in = StockMovement::where('action', 'transfer_in')->sole();
        $this->assertSame([10, -4, 6], [$out->before_quantity, $out->quantity, $out->after_quantity]);
        $this->assertSame([0, 4, 4], [$in->before_quantity, $in->quantity, $in->after_quantity]);
        $this->assertSame($out->source_id, $in->source_id);
        $this->assertSame('TRACK-BATCH', $out->batch_number);
        $this->assertSame(6, $inventory->fresh()->quantity);
    }

    public function test_history_filters_paginate_before_render_and_product_detail_includes_history(): void
    {
        $inventory = $this->stock(0); $source = $this->adjustment();
        for ($i = 0; $i < 27; $i++) StockLedger::change($inventory, 1, 'adjustment', $source);
        $this->get('/inventory/movements', ['X-SPA' => 'true'])->assertOk()
            ->assertJsonPath('props.movements.total', 27)->assertJsonCount(25, 'props.movements.data');
        $this->get('/inventory/movements?page=2', ['X-SPA' => 'true'])->assertOk()->assertJsonCount(2, 'props.movements.data');
        $this->get('/inventory/movements?action=sale', ['X-SPA' => 'true'])->assertOk()->assertJsonPath('props.movements.total', 0);
        $this->get('/inventory/movements?search=Tracked', ['X-SPA' => 'true'])->assertOk()->assertJsonPath('props.movements.total', 27);
        $this->get('/inventory/'.$this->product->id, ['X-SPA' => 'true'])->assertOk()->assertJsonPath('props.movements.total', 27);
    }

    public function test_date_filter_uses_myanmar_day_boundaries(): void
    {
        $inventory = $this->stock(0); $source = $this->adjustment();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-20 17:29:59', 'UTC'));
        StockLedger::change($inventory, 1, 'adjustment', $source);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-20 17:30:00', 'UTC'));
        StockLedger::change($inventory, 1, 'adjustment', $source);
        $this->get('/inventory/movements?from=2026-11-20&to=2026-11-20', ['X-SPA' => 'true'])
            ->assertOk()->assertJsonPath('props.movements.total', 1);
        $this->travelBack();
    }

    public function test_movements_cannot_be_changed_or_removed_and_keep_source_snapshots(): void
    {
        $source = $this->adjustment();
        $m = StockLedger::change($this->stock(), 3, 'adjustment', $source);
        $source->delete();
        $this->assertSame($this->product->name, $m->fresh()->product_name);
        try { $m->update(['quantity' => 99]); $this->fail('Update should be blocked'); }
        catch (\LogicException $e) { $this->assertStringContainsString('cannot be edited', $e->getMessage()); }
        try { $m->delete(); $this->fail('Delete should be blocked'); }
        catch (\LogicException $e) { $this->assertStringContainsString('cannot be deleted', $e->getMessage()); }
        $this->assertSame(3, $m->fresh()->quantity);
    }

    public function test_deleted_purchase_retains_reversal_history_and_reference(): void
    {
        $this->grantPermissions($this->user, ['manage_purchases']);
        $inventory = $this->stock();
        $supplier = \App\Models\Supplier::create(['name' => 'Tracked supplier', 'phone' => '123', 'balance' => 3]);
        $unit = \App\Models\Unit::create(['name' => 'Piece']);
        $purchase = \App\Models\Purchase::create(['supplier_id' => $supplier->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->user->id, 'invoice_number' => 'DELETE-TRACK-001', 'purchase_date' => today(),
            'total_amount' => 3, 'paid_amount' => 0, 'due_amount' => 3, 'payment_status' => 'Due']);
        $purchase->items()->create(['product_id' => $this->product->id, 'unit_id' => $unit->id,
            'batch_number' => 'DELETE-BATCH', 'expiry_date' => today()->addYear(), 'quantity' => 3,
            'base_quantity' => 3, 'unit_price' => 1, 'total_price' => 3, 'created_at' => now()]);
        InventoryBatch::create(['branch_id' => $this->branch->id, 'product_id' => $this->product->id,
            'batch_number' => 'DELETE-BATCH', 'expiry_date' => today()->addYear(), 'quantity' => 3,
            'purchase_price' => 1, 'selling_price' => 2]);
        $this->delete('/purchases/'.$purchase->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($purchase->fresh());
        $m = StockMovement::sole();
        $this->assertSame('purchase_delete', $m->action);
        $this->assertSame('DELETE-TRACK-001', $m->reference);
        $this->assertSame([10, -3, 7], [$m->before_quantity, $m->quantity, $m->after_quantity]);
        $this->assertSame(7, $inventory->fresh()->quantity);
    }

    public function test_other_business_movements_are_not_visible(): void
    {
        StockLedger::change($this->stock(), 3, 'adjustment', $this->adjustment());
        $business = Business::create(['name' => 'Other pharmacy', 'slug' => 'other-history', 'status' => 'Active']);
        app(\App\Billing\SubscriptionService::class)->startTrial($business);
        $businessId = app(BusinessContext::class)->requireId();
        $row = DB::table('stock_movements')->where('business_id', $businessId)->first();
        $other = (array) $row; $other['id'] = (string) \Illuminate\Support\Str::uuid();
        $other['business_id'] = $business->id; $other['product_name'] = 'Private other pharmacy';
        DB::table('stock_movements')->insert($other);
        $response = $this->get('/inventory/movements', ['X-SPA' => 'true'])->assertOk()->assertJsonPath('props.movements.total', 1);
        $this->assertSame('Tracked medicine', $response->json('props.movements.data.0.product_name'));
        $this->assertSame(1, StockMovement::count());
    }
}
