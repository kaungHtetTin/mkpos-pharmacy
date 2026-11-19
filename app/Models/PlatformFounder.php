<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
class PlatformFounder extends Model
{
    use HasUuid;
    protected $table = 'platform_founders';
    protected $guarded = ['id'];
    protected $casts = ['share_bps' => 'integer', 'is_active' => 'boolean'];
}
