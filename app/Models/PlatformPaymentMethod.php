<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
class PlatformPaymentMethod extends Model
{
    use HasUuid;
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
