<?php
namespace App\Tenancy;
use Illuminate\Support\Facades\DB;
final class BusinessQuery
{
    public static function table(string $table)
    {
        $alias = preg_split('/\s+(?:as\s+)?/i', $table);
        $name = $alias[count($alias)-1];
        return DB::table($table)->where($name.'.business_id', app(BusinessContext::class)->requireId());
    }
}
