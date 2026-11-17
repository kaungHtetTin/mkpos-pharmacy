<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class Role extends Model
{
    use \App\Tenancy\BelongsToBusiness;

    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivotValue('business_id', (app(\App\Tenancy\BusinessContext::class)->id() ?? $this->business_id ?? '00000000-0000-4000-8000-000000000000'));
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
