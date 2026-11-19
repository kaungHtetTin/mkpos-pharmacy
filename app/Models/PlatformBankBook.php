<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
class PlatformBankBook extends Model
{
    use HasUuid;
    protected $table = 'platform_bank_books';
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];
}
