<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
class SupportMessage extends Model
{
    use HasUuid;
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime'];
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
