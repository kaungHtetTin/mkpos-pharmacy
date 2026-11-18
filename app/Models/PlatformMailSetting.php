<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class PlatformMailSetting extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['password'];
    protected $casts = ['enabled' => 'boolean', 'password' => 'encrypted', 'port' => 'integer', 'last_tested_at' => 'datetime', 'last_failure_at' => 'datetime', 'last_sent_at' => 'datetime'];
}
