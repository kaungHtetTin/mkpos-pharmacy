<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use App\Traits\HasUuid;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuid;

    protected $attributes = ['status'=>'Active', 'is_platform_admin'=>false];
    protected array $pendingMembership = [];
    protected $appends = ['branch_id', 'active_branch_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('business_member', function ($query) {
            if ($id = app(\App\Tenancy\BusinessContext::class)->id()) {
                $query->whereHas('memberships', fn ($memberships) => $memberships->where('business_id', $id));
            }
        });
        static::saving(function (User $user) {
            if ($user->is_platform_admin && $user->exists && $user->memberships()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['account' => 'Business accounts cannot become platform office accounts. Use a separate account.']);
            }
        });
        static::saved(function (User $user) {
            $context = app(\App\Tenancy\BusinessContext::class);
            if (!$context->id()) return;
            $values = $user->pendingMembership;
            foreach (['branch_id','active_branch_id'] as $column) {
                if (!empty($values[$column]) && !Branch::whereKey($values[$column])->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([$column=>'The branch is not in this business.']);
                }
            }
            $user->memberships()->updateOrCreate(['business_id'=>$context->id()], $values);
            $user->pendingMembership = [];
            $user->unsetRelation('roles')->unsetRelation('branches')->unsetRelation('branch')->unsetRelation('activeBranch');
        });
        static::deleting(function (User $user) {
            if (\Illuminate\Support\Facades\DB::table('role_user')->join('roles','roles.id','=','role_user.role_id')->where('user_id',$user->id)->whereIn('roles.name',['Owner','Root'])->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['account'=>'Transfer business ownership before deleting this account.']);
            }
        });
    }

    public function memberships() { return $this->hasMany(BusinessMembership::class); }
    public function businessMembership() { return $this->hasOne(BusinessMembership::class); }

    public function assignedBusiness(): ?Business
    {
        if ($this->is_platform_admin || $this->getRawOriginal('status') !== 'Active') return null;
        $membership = $this->businessMembership()->where('status', 'Active')->with('business')->first();
        return $membership?->business?->status === 'Active' ? $membership->business : null;
    }
    public function currentMembership(): ?BusinessMembership
    {
        $id = app(\App\Tenancy\BusinessContext::class)->id();
        return $id ? $this->memberships()->where('business_id',$id)->first() : null;
    }
    public function getBranchIdAttribute($value) { return array_key_exists('branch_id',$this->pendingMembership) ? $this->pendingMembership['branch_id'] : $this->currentMembership()?->branch_id; }
    public function getActiveBranchIdAttribute($value) { return array_key_exists('active_branch_id',$this->pendingMembership) ? $this->pendingMembership['active_branch_id'] : $this->currentMembership()?->active_branch_id; }
    public function setBranchIdAttribute($value): void { $this->pendingMembership['branch_id'] = $value; }
    public function setActiveBranchIdAttribute($value): void { $this->pendingMembership['active_branch_id'] = $value; }
    public function getStatusAttribute($value) { return $this->pendingMembership['status'] ?? $this->currentMembership()?->status ?? $value; }
    public function setStatusAttribute($value): void
    {
        if (app(\App\Tenancy\BusinessContext::class)->id()) $this->pendingMembership['status']=$value;
        else $this->attributes['status']=$value;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'image_path',
        'branch_id',
        'active_branch_id',
        'phone',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_platform_admin' => 'boolean',
    ];

    /**
     * The roles that belong to the user.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class)->withPivotValue('business_id', (app(\App\Tenancy\BusinessContext::class)->id() ?? '00000000-0000-4000-8000-000000000000'));
    }

    /**
     * Get the branch that the user belongs to.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class)->withPivotValue('business_id', (app(\App\Tenancy\BusinessContext::class)->id() ?? '00000000-0000-4000-8000-000000000000'))->withTimestamps();
    }

    public function activeBranch()
    {
        return $this->belongsTo(Branch::class, 'active_branch_id');
    }

    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function saleStaffSales()
    {
        return $this->hasMany(Sale::class, 'sale_staff_id');
    }

    public function cashSessions()
    {
        return $this->hasMany(CashSession::class);
    }

    public function currentBranchId()
    {
        return $this->active_branch_id ?: $this->branch_id;
    }

    public function canAccessBranch(string $branchId): bool
    {
        if ($this->branch_id && $this->branch_id === $branchId) {
            return true;
        }

        return $this->branches()->where('branches.id', $branchId)->exists();
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole($roleName)
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission($permissionSlug)
    {
        if ($this->hasRole('Owner') || $this->hasRole('Root')) {
            return true;
        }

        return $this->roles()->whereHas('permissions', function ($query) use ($permissionSlug) {
            $query->where('slug', $permissionSlug);
        })->exists();
    }
}
