<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitializeStockMovements extends Command
{
    protected $signature = 'inventory:initialize-movements';
    protected $description = 'Record an idempotent opening snapshot; run during deployment maintenance.';

    public function handle(): int
    {
        $count = 0;
        DB::table('inventories')->orderBy('id')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$count) {
                    $stock = DB::table('inventories')->where('id', $row->id)->lockForUpdate()->first();
                    if (!$stock || (int) $stock->quantity === 0 || DB::table('stock_movements')
                        ->where('business_id', $stock->business_id)->where('product_id', $stock->product_id)
                        ->where('branch_id', $stock->branch_id)->exists()) return;
                    $product = DB::table('products')->where('business_id', $stock->business_id)->where('id', $stock->product_id)->first();
                    $branch = DB::table('branches')->where('business_id', $stock->business_id)->where('id', $stock->branch_id)->first();
                    $baseUnit = DB::table('product_units')->join('units', 'units.id', '=', 'product_units.unit_id')
                        ->where('product_units.business_id', $stock->business_id)->where('product_units.product_id', $stock->product_id)
                        ->where('product_units.is_base_unit', true)->value('units.name');
                    DB::table('stock_movements')->insert([
                        'id' => (string) Str::uuid(), 'business_id' => $stock->business_id,
                        'product_id' => $stock->product_id, 'branch_id' => $stock->branch_id,
                        'product_name' => $product?->name ?? 'Deleted product', 'branch_name' => $branch?->name ?? 'Deleted branch',
                        'base_unit' => $baseUnit, 'actor_name' => 'System', 'action' => 'opening_balance',
                        'before_quantity' => null, 'quantity' => (int) $stock->quantity, 'after_quantity' => (int) $stock->quantity,
                        'note' => 'Balance when movement tracking began. Earlier event details are unavailable.',
                        'occurred_at' => now('UTC'), 'event_key' => 'opening:'.$stock->id,
                    ]);
                    $count++;
                });
            }
        });
        $this->info('Opening balances recorded: '.$count);
        return self::SUCCESS;
    }
}
