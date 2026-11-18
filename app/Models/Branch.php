<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\HasUuid;

class Branch extends Model
{
    use \App\Tenancy\BelongsToBusiness;
    use \App\Billing\EnforcesPlanLimit;

    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'email',
        'status',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'business_memberships', 'branch_id', 'user_id')->withPivotValue('business_id', (app(\App\Tenancy\BusinessContext::class)->id() ?? $this->business_id ?? '00000000-0000-4000-8000-000000000000'));
    }

    public function usersWithAccess()
    {
        return $this->belongsToMany(User::class)->withPivotValue('business_id', (app(\App\Tenancy\BusinessContext::class)->id() ?? '00000000-0000-4000-8000-000000000000'))->withTimestamps();
    }

    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
