<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    use \App\Tenancy\BelongsToBusiness;

    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['name', 'is_active', 'sort_order', 'pricing_mode', 'markup_percent', 'rounding', 'minimum_profit', 'version'];
    protected $casts = ['is_active'=>'boolean', 'sort_order'=>'integer', 'markup_percent' => 'decimal:4', 'minimum_profit' => 'decimal:2', 'rounding' => 'integer', 'version' => 'integer'];
}
