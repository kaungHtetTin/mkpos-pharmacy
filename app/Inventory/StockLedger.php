<?php

namespace App\Inventory;

use App\Models\{Branch, Inventory, Product, ProductUnit, StockMovement, User};
use App\Tenancy\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StockLedger
{
    public const ACTIONS = [
        'opening_balance' => 'Opening balance', 'purchase' => 'Purchase received',
        'purchase_edit_reversal' => 'Purchase edit: reversal', 'purchase_edit' => 'Purchase edit: received',
        'purchase_delete' => 'Purchase deleted', 'sale' => 'POS sale', 'sale_void' => 'Sale voided',
        'mobile_sale' => 'Cashier sale', 'offline_sale' => 'Offline sale synced',
        'mobile_purchase' => 'Mobile purchase', 'offline_purchase' => 'Offline purchase synced',
        'supplier_return' => 'Supplier return', 'customer_return' => 'Customer return restocked',
        'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'adjustment' => 'Stock adjustment',
    ];

    /** Change aggregate stock and append its history under the same row lock/transaction. */
    public static function change(Inventory $inventory, int $delta, string $action, Model $source,
        ?string $actorId = null, ?string $note = null, ?string $batch = null, bool $clamp = false): StockMovement
    {
        return DB::transaction(function () use ($inventory, $delta, $action, $source, $actorId, $note, $batch, $clamp) {
            $businessId = app(BusinessContext::class)->requireId();
            if (!isset(self::ACTIONS[$action]) || $source->business_id !== $businessId
                || ($inventory->business_id && $inventory->business_id !== $businessId)) {
                throw new LogicException('Invalid stock movement context.');
            }
            if (!$inventory->exists) {
                $inventory->quantity = 0;
                $inventory->save();
            }
            $locked = Inventory::whereKey($inventory->id)->lockForUpdate()->firstOrFail();
            $before = (int) $locked->quantity;
            $after = $clamp ? max(0, $before + $delta) : $before + $delta;
            $locked->update(['quantity' => $after]);
            $inventory->setRawAttributes($locked->getAttributes(), true);

            $actorId ??= auth()->id() ?? $source->user_id;
            $product = Product::findOrFail($locked->product_id);
            $baseUnit = ProductUnit::with('unit')->where('product_id', $product->id)->where('is_base_unit', true)->first()?->unit?->name;
            $documentDate = $source->purchase_date ?? $source->sale_date ?? $source->transfer_date ?? $source->adjustment_date ?? $source->approved_at;
            $reference = $source->invoice_number ?? $source->reference_number ?? strtoupper(class_basename($source)).'-'.$source->id;
            if ($after - $before !== $delta) {
                $note = trim(($note ? $note.' ' : '').'Requested stock change '.$delta.'; aggregate stock applied '.($after - $before).'.');
            }
            return StockMovement::create([
                'product_id' => $product->id, 'branch_id' => $locked->branch_id,
                'product_name' => $product->name, 'branch_name' => Branch::findOrFail($locked->branch_id)->name,
                'base_unit' => $baseUnit, 'actor_id' => $actorId,
                'actor_name' => $actorId ? (User::find($actorId)?->name ?? 'Unavailable user') : 'System',
                'action' => $action, 'source_type' => class_basename($source), 'source_id' => $source->id,
                'reference' => $reference, 'before_quantity' => $before, 'quantity' => $after - $before,
                'requested_quantity' => $delta, 'after_quantity' => $after,
                'batch_number' => $batch, 'note' => $note ?? $source->reason ?? $source->notes,
                'document_date' => $documentDate, 'occurred_at' => now('UTC'),
            ]);
        });
    }
}
