<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Validation\Rule;

/** Business-scoped names are stored on each payment as historical snapshots. */
final class BusinessPaymentMethods
{
    public const DEFAULTS = ['Cash', 'Banking'];

    public static function custom(): array
    {
        return json_decode(Setting::get('pos.payment_methods', '[]'), true) ?: [];
    }

    public static function active(): array
    {
        return array_merge(self::DEFAULTS, array_column(array_filter(self::custom(), fn ($method) => $method['is_active']), 'name'));
    }

    public static function validation(): array
    {
        return ['required', 'string', Rule::in(self::active())];
    }

    public static function default(): string
    {
        $name = Setting::get('pos.default_payment_method', 'Cash');
        return in_array($name, self::active(), true) ? $name : 'Cash';
    }

    public static function history(): array
    {
        return array_values(array_unique(array_merge(self::active(), Sale::query()->distinct()->pluck('payment_method')->all())));
    }
}
