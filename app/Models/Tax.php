<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class Tax extends Model
{
    use \App\Tenancy\BelongsToBusiness;

    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'rate',
        'is_default',
        'status',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function productsWithTax()
    {
        return $this->belongsToMany(Product::class)->withTimestamps()->withPivotValue('business_id', (app(\App\Tenancy\BusinessContext::class)->id() ?? '00000000-0000-4000-8000-000000000000'));
    }
}
