<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
class PlatformFinanceEntry extends Model
{
    use HasUuid;
    protected $table = 'platform_finance_entries';
    protected $guarded = ['id'];
    protected $casts = ['amount_minor' => 'integer', 'entry_date' => 'date', 'voided_at' => 'datetime'];
}
