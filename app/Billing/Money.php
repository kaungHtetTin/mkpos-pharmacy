<?php
namespace App\Billing;
use Illuminate\Validation\ValidationException;
final class Money
{
    public static function minor(string $amount): int
    {
        if (!preg_match('/^(0|[1-9]\d{0,8})(?:\.(\d{1,2}))?$/D', $amount, $m)) throw ValidationException::withMessages(['price'=>'Enter a nonnegative price with at most two decimal places.']);
        return ((int)$m[1])*100 + (int)str_pad($m[2]??'',2,'0');
    }
}
