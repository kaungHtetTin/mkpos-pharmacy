<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class BusinessMembership extends Model
{
    use HasUuid;
    protected $fillable = ['business_id', 'user_id', 'status', 'branch_id', 'active_branch_id'];
    public function business() { return $this->belongsTo(Business::class); }
    public function user() { return $this->belongsTo(User::class); }
}
