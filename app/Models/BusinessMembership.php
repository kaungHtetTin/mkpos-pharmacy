<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class BusinessMembership extends Model
{
    use HasUuid;
    use \App\Billing\EnforcesPlanLimit;

    protected $fillable = ['business_id', 'user_id', 'status', 'branch_id', 'active_branch_id'];

    protected static function booted(): void
    {
        static::saving(function (BusinessMembership $membership) {
            $user = User::withoutGlobalScopes()->findOrFail($membership->user_id);
            if ($user->is_platform_admin) {
                throw ValidationException::withMessages(['account' => 'Platform office accounts must remain separate from business accounts.']);
            }
            if ($membership->exists && ($membership->isDirty('business_id') || $membership->isDirty('user_id'))) {
                throw ValidationException::withMessages(['account' => 'An account cannot be moved to another business.']);
            }
            if (!$membership->exists && static::where('user_id', $membership->user_id)->exists()) {
                throw ValidationException::withMessages(['account' => 'This account already belongs to a business. Create a separate account.']);
            }
        });
    }

    public function business() { return $this->belongsTo(Business::class); }
    public function user() { return $this->belongsTo(User::class); }
}
