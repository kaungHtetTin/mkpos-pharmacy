<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductUnitPriceCsvService
{
    public const HEADERS = [
        'product_id', 'barcode', 'product_name', 'unit_name', 'unit_short_name',
        'conversion_factor', 'is_base_unit', 'is_default_selling_unit', 'selling_price', 'wholesale_price',
    ];

    public static function customColumns(): array
    {
        return PriceTypes::rules()->filter(fn ($rule) => PriceTypes::custom($rule->code))
            ->mapWithKeys(fn ($rule) => [CatalogCsv::key('price: '.$rule->name) => $rule->code])->all();
    }
    public static function headers(): array { return [...self::HEADERS, ...array_keys(self::customColumns())]; }

    public function import(UploadedFile $file, bool $createUnits): array
    {
        [$headers, $rows, $skipped] = CatalogCsv::readRows($file, 'unit_price_file', 50000);
        $columns=self::customColumns();
        if (array_diff(self::HEADERS,$headers) || array_diff($headers,[...self::HEADERS,...array_keys($columns)])) CatalogCsv::fail('unit_price_file',['Use the current unit-price template. Unknown or missing columns were found.']);
        $errors = [];
        foreach ($rows as &$row) {
            $row['product_id'] = strtolower($row['product_id']);
            foreach (['is_base_unit', 'is_default_selling_unit'] as $flag) $row[$flag] = CatalogCsv::boolean($row[$flag]);
            $validator = Validator::make($row, [
                'product_id' => 'required|uuid', 'unit_name' => 'required|string|max:255',
                'unit_short_name' => 'required|string|max:50',
                'conversion_factor' => 'required|integer|min:1|max:2147483647',
                'is_base_unit' => 'required|boolean', 'is_default_selling_unit' => 'required|boolean',
                'selling_price' => CatalogCsv::moneyRules(), 'wholesale_price' => CatalogCsv::moneyRules(),
            ]);
            foreach ($columns as $column=>$code) if (isset($row[$column]) && $row[$column]!=='' && (!is_numeric($row[$column]) || !preg_match('/^\d+(\.\d{1,2})?$/',$row[$column]) || bccomp($row[$column],'0',2)<=0 || bccomp($row[$column],AutomaticPricingService::MAX_PRICE,2)>0)) $errors[]="Row {$row['_line']}: custom prices must be positive amounts with at most two decimal places.";
            foreach ($validator->errors()->all() as $error) $errors[] = "Row {$row['_line']}: {$error}";
            if ($row['is_base_unit'] && $row['conversion_factor'] !== '1') $errors[] = "Row {$row['_line']}: base unit conversion factor must be 1.";
        }
        unset($row);
        CatalogCsv::fail('unit_price_file', $errors);

        return DB::transaction(function () use ($rows, $skipped, $createUnits, $columns) {
            $pricing = app(AutomaticPricingService::class);
            $pricing->lock();
            $units = Unit::query()->get();
            $errors = [];
            $stats = ['products' => 0, 'units_created' => 0, 'units_updated' => 0, 'skipped_rows' => $skipped];
            foreach (collect($rows)->groupBy('product_id')->sortKeys() as $productId => $productRows) {
                $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
                if (!$product) {
                    $errors[] = "Row {$productRows->first()['_line']}: product ID '{$productId}' does not match an existing medicine.";
                    continue;
                }
                $existing = $product->product_units()->lockForUpdate()->get();
                if ($existing->pluck('unit_id')->duplicates()->isNotEmpty()) {
                    $errors[] = "{$product->name}: duplicate existing units must be corrected before importing.";
                    continue;
                }
                $finalUnits = $existing->keyBy('unit_id')->map(fn ($unit) => $unit->toArray())->all();
                $changes = [];
                $productHasRowErrors = false;
                foreach ($productRows as $row) {
                    try {
                        $nameMatches = $existing->filter(fn ($candidate) => CatalogCsv::key($candidate->unit->name) === CatalogCsv::key($row['unit_name']));
                        $codeMatches = $existing->filter(fn ($candidate) => CatalogCsv::key((string) $candidate->unit->short_name) === CatalogCsv::key($row['unit_short_name']));
                        if (($nameMatches->isNotEmpty() && $codeMatches->isEmpty()) || ($nameMatches->isEmpty() && $codeMatches->isNotEmpty())) {
                            CatalogCsv::fail('unit_price_file', ['The unit name or short name matches another unit on this medicine. Both must match the same unit.']);
                        }
                        if ($nameMatches->isNotEmpty() && $codeMatches->isNotEmpty() && $nameMatches->first()->id !== $codeMatches->first()->id) {
                            CatalogCsv::fail('unit_price_file', ['The unit name and short name match different units on this medicine.']);
                        }
                        $unit = $nameMatches->isNotEmpty() && $codeMatches->isNotEmpty()
                            ? $nameMatches->first()->unit
                            : CatalogCsv::resolveUnit($units, $row['unit_name'], $row['unit_short_name'], $createUnits, 'unit_price_file');
                        if (isset($changes[$unit->id])) CatalogCsv::fail('unit_price_file', ['Duplicate product and unit row.']);
                        $current = $existing->firstWhere('unit_id', $unit->id);
                        if ($current && ((int) $row['conversion_factor'] !== $current->conversion_factor
                            || $row['is_base_unit'] !== $current->is_base_unit)) {
                            CatalogCsv::fail('unit_price_file', ['Existing unit conversion factors and base-unit selection cannot be changed by CSV import.']);
                        }
                        $payload = collect($row)->only(['conversion_factor', 'is_base_unit', 'is_default_selling_unit', 'selling_price', 'wholesale_price'])->all();
                        $payload += ['selling_price_mode' => 'manual', 'wholesale_price_mode' => 'manual', 'selling_price_status' => 'manual', 'wholesale_price_status' => 'manual'];
                        $payload['custom_prices']=$current?->custom_prices ?? [];
                        foreach ($columns as $column=>$code) if (isset($row[$column]) && $row[$column]!=='') $payload['custom_prices'][$code]=['amount'=>bcadd($row[$column],'0',2),'mode'=>'manual','status'=>'manual'];
                        $changes[$unit->id] = $payload;
                        $finalUnits[$unit->id] = $payload;
                    } catch (ValidationException $exception) {
                        $productHasRowErrors = true;
                        $errors[] = "Row {$row['_line']}: ".str_replace("Import failed. No changes were saved.\n", '', $exception->validator->errors()->first('unit_price_file'));
                    }
                }
                if ($productHasRowErrors) continue;
                if (collect($finalUnits)->where('is_base_unit', true)->count() !== 1
                    || collect($finalUnits)->where('is_default_selling_unit', true)->count() !== 1) {
                    $errors[] = "{$product->name}: the final units must have exactly one base unit and one default selling unit. Include both affected rows when changing the default.";
                    continue;
                }
                foreach ($changes as $unitId => $payload) {
                    $current = $existing->firstWhere('unit_id', $unitId);
                    if ($current) {
                        foreach ($payload['custom_prices'] as $code=>$price) if (($price['amount'] ?? null)!==PriceTypes::amount($current,$code)) $pricing->record($product,$current,$code,(string)(PriceTypes::amount($current,$code)??'0'),(string)($price['amount']??'0'),null,'unit_price_import',auth()->id());
                        $current->update($payload);
                        $stats['units_updated']++;
                    } else {
                        $created=$product->product_units()->make(['unit_id' => $unitId] + $payload);
                        PriceTypes::saveCustom($created,$payload['custom_prices'] ?? [], \App\Models\PricingRule::all()->keyBy('code'),auth()->id());
                        $created->save();
                        $stats['units_created']++;
                    }
                }
                $pricing->refreshProduct($product, 'unit_price_import', auth()->id());
                $stats['products']++;
            }
            CatalogCsv::fail('unit_price_file', $errors);
            return $stats;
        });
    }
}
