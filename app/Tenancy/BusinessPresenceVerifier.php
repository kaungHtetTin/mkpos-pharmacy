<?php

namespace App\Tenancy;

use Illuminate\Validation\DatabasePresenceVerifier;

class BusinessPresenceVerifier extends DatabasePresenceVerifier
{
    protected function table($table)
    {
        $query = parent::table($table);
        if (in_array($table, BusinessSchema::TABLES, true)) {
            $id = app(BusinessContext::class)->id();
            $id ? $query->where($table.'.business_id', $id) : $query->whereRaw('1 = 0');
        }
        return $query;
    }
}
