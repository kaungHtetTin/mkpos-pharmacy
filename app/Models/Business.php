<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use HasUuid;
    protected $fillable = ['name', 'slug', 'status'];
    public function memberships() { return $this->hasMany(BusinessMembership::class); }
    public function users() { return $this->belongsToMany(User::class, 'business_memberships')->withPivot(['status','branch_id','active_branch_id'])->withTimestamps(); }
}
