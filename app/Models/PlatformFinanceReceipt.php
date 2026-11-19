<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
class PlatformFinanceReceipt extends Model
{
    use HasUuid;
    protected $table = 'platform_finance_receipts';
    protected $guarded = ['id'];
    protected $casts = ['amount_minor' => 'integer', 'received_at' => 'datetime', 'assigned_at' => 'datetime'];
}
