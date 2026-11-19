<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
use App\Tenancy\BelongsToBusiness;
use LogicException;

class StockMovement extends Model
{
    use HasUuid, BelongsToBusiness;

    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $guarded = ['business_id'];
    protected $casts = [
        'before_quantity' => 'integer', 'quantity' => 'integer',
        'after_quantity' => 'integer', 'requested_quantity' => 'integer',
        'occurred_at' => 'datetime', 'document_date' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movement history cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Stock movement history cannot be deleted.'));
    }
}
