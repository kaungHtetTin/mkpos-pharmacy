<?php
namespace App\Services;

use App\Models\{PricingRule, ProductUnit};
use Illuminate\Validation\ValidationException;

final class PriceTypes
{
    public const DEFAULTS = ['retail'=>'selling_price', 'wholesale'=>'wholesale_price'];
    public static function custom(string $code): bool { return !in_array($code, self::DEFAULTS, true); }
    public static function rules() { return PricingRule::where('is_active', true)->orderByRaw("CASE code WHEN 'selling_price' THEN 0 WHEN 'wholesale_price' THEN 1 ELSE 2 END")->orderBy('sort_order')->orderBy('name')->get(); }
    public static function choices(): array
    {
        return self::rules()->map(fn ($r) => ['code'=>array_search($r->code, self::DEFAULTS, true) ?: $r->code,
            'name'=>$r->code==='selling_price' ? 'Retail' : ($r->code==='wholesale_price' ? 'Wholesale' : $r->name)])->all();
    }
    public static function rule(string $type): PricingRule
    {
        if (in_array($type,self::DEFAULTS,true)) throw ValidationException::withMessages(['price_type'=>'Select a public price type.']);
        $rule = PricingRule::where('code', self::DEFAULTS[$type] ?? $type)->where('is_active', true)->first();
        if (!$rule) throw ValidationException::withMessages(['price_type'=>'This price type is unavailable. Refresh and select an available type.']);
        return $rule;
    }
    public static function name(string $type): string { return ['retail'=>'Retail','wholesale'=>'Wholesale'][$type] ?? self::rule($type)->name; }
    public static function amount(ProductUnit $unit, string $code): ?string
    {
        return self::custom($code) ? ($unit->custom_prices[$code]['amount'] ?? null) : ($code==='wholesale_price' ? ($unit->wholesale_price ?? $unit->selling_price) : $unit->$code);
    }
    public static function mode(ProductUnit $unit, string $code): string
    {
        return self::custom($code) ? ($unit->custom_prices[$code]['mode'] ?? 'manual') : $unit->{$code.'_mode'};
    }
    public static function set(ProductUnit $unit, string $code, string $part, mixed $value): void
    {
        if (!self::custom($code)) { $unit->{$part==='amount' ? $code : $code.'_'.$part} = $value; return; }
        $prices = $unit->custom_prices ?? [];
        $prices[$code] = array_replace(['amount'=>null, 'mode'=>'manual', 'status'=>'missing'], $prices[$code] ?? [], [$part=>$value]);
        $unit->custom_prices = $prices;
    }
    public static function quote(ProductUnit $unit, string $type): float
    {
        $rule = self::rule($type);
        $amount = self::amount($unit, $rule->code);
        if ($amount===null || (self::custom($rule->code) && bccomp((string)$amount, '0', 2)<=0)) {
            throw ValidationException::withMessages(['items'=>'Price not set for '.$rule->name.'. Set this unit price or explicitly select Retail.']);
        }
        if (self::custom($rule->code) && ($unit->custom_prices[$rule->code]['status'] ?? null)==='cost_required') throw ValidationException::withMessages(['items'=>'This price needs a buying cost. Set a cost or a manual price.']);
        if (self::mode($unit,$rule->code)==='automatic' && bccomp((string)$amount,'0',2)<=0) {
            throw ValidationException::withMessages(['items'=>'This automatic price needs a buying cost. Set the medicine cost or a manual price before selling.']);
        }
        return (float) $amount;
    }
    public static function saveCustom(ProductUnit $unit, array $submitted, $rules, ?string $actor = null): void
    {
        foreach ($submitted as $code=>$value) {
            if (!isset($rules[$code]) || !self::custom($code)) throw ValidationException::withMessages(['custom_prices'=>'Unknown custom price type. Reload this form.']);
        }
        foreach ($rules as $code=>$rule) {
            if (!self::custom($code) || !$rule->is_active) continue;
            $row = $submitted[$code] ?? null;
            if ($row===null && $unit->exists) continue;
            $mode = $row['mode'] ?? $rule->pricing_mode;
            if (!in_array($mode,['manual','automatic'],true) || ($mode==='automatic' && $rule->pricing_mode!=='automatic')) {
                throw ValidationException::withMessages(['custom_prices'=>'Enable the automatic rule in Settings before using it.']);
            }
            self::set($unit,$code,'mode',$mode);
            if ($mode==='manual') {
                $amount = $row['amount'] ?? null;
                if ($amount!==null && $amount!=='') {
                    if (!is_numeric($amount) || !preg_match('/^\d+(\.\d{1,2})?$/',(string)$amount) || bccomp((string)$amount,'0',2)<=0 || bccomp((string)$amount,AutomaticPricingService::MAX_PRICE,2)>0) {
                        throw ValidationException::withMessages(['custom_prices'=>'Custom prices must be positive amounts with at most two decimal places, or blank.']);
                    }
                    $amount = bcadd((string)$amount,'0',2);
                } else $amount=null;
                $old=self::amount($unit,$code);
                if ($unit->exists && $old!==$amount) app(AutomaticPricingService::class)->record($unit->product,$unit,$code,(string)($old??'0'),(string)($amount??'0'),null,'product_manual',$actor);
                self::set($unit,$code,'amount',$amount);
                self::set($unit,$code,'status',$amount===null?'missing':'manual');
            }
        }
    }
}
