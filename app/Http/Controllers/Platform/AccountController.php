<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};
class AccountController extends Controller
{
    private function filters(Request $r): array
    {
        return $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:Active,Inactive', 'business_id' => 'nullable|uuid|exists:businesses,id', 'role' => 'nullable|in:owner,admin,billing,support']);
    }
    public function users(Request $r)
    {
        $f = $this->filters($r);
        $q = DB::table('users as u')->join('business_memberships as m', 'm.user_id', '=', 'u.id')->join('businesses as b', 'b.id', '=', 'm.business_id')->where('u.is_platform_admin', false)->when($f['q'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('u.name', 'like', '%' . $v . '%')->orWhere('u.email', 'like', '%' . $v . '%')->orWhere('b.name', 'like', '%' . $v . '%')))->when($f['status'] ?? null, fn($q, $v) => $q->where('u.status', $v))->when($f['business_id'] ?? null, fn($q, $v) => $q->where('b.id', $v));
        return Spa::render('Platform/Accounts', ['mode' => 'users', 'filters' => $f, 'accounts' => $q->orderBy('u.name')->paginate(25, ['u.id', 'u.name', 'u.email', 'u.status', 'u.created_at', 'b.id as business_id', 'b.name as business_name', 'm.status as membership_status'])->withQueryString()]);
    }
    public function admins(Request $r)
    {
        $f = $this->filters($r);
        return Spa::render('Platform/Accounts', ['mode' => 'admins', 'filters' => $f, 'accounts' => User::withoutGlobalScopes()->where('is_platform_admin', true)->when($f['q'] ?? null, fn($q, $v) => $q->where(fn($q) => $q->where('name', 'like', '%' . $v . '%')->orWhere('email', 'like', '%' . $v . '%')))->when($f['status'] ?? null, fn($q, $v) => $q->where('status', $v))->when($f['role'] ?? null, fn($q, $v) => $q->where('platform_role', $v))->orderBy('name')->paginate(25, ['id', 'name', 'email', 'status', 'platform_role', 'created_at'])->withQueryString()]);
    }
    private function fields(Request $r, ?User $u, bool $office): array
    {
        $fields = ['name' => 'required|string|max:255', 'email' => ['required', 'email:rfc', 'lowercase', 'max:255', Rule::unique('users', 'email')->ignore($u?->id)], 'status' => 'required|in:Active,Inactive'];
        if ($office) {
            $fields['platform_role'] = 'required|in:owner,admin,billing,support';
        }
        return $r->validate($fields);
    }
    public function createAdmin(Request $r)
    {
        $d = $this->fields($r, null, true);
        $r->validate(['password' => ['required', 'string', 'min:12', fn($a, $v, $fail) => strlen($v) > 72 ? $fail('Use at most 72 bytes.') : null]]);
        DB::transaction(function () use ($r, $d) {
            $u = User::forceCreate($d + ['password' => Hash::make($r->password), 'is_platform_admin' => true]);
            PlatformAudit::record('office.account_created', $r->user(), null, ['account_id' => $u->id, 'role' => $u->platform_role]);
        });
        return back()->with('success', 'Office administrator created. Share credentials privately.');
    }
    public function updateUser(Request $r, string $account)
    {
        return $this->update($r, $account, false);
    }
    public function updateAdmin(Request $r, string $account)
    {
        return $this->update($r, $account, true);
    }
    private function update(Request $r, string $id, bool $office)
    {
        $u = User::withoutGlobalScopes()->where('is_platform_admin', $office)->findOrFail($id);
        $d = $this->fields($r, $u, $office);
        DB::transaction(function () use ($r, $u, $d, $office) {
            // All office role changes lock the active owner set before the target.
            if ($office) {
                User::withoutGlobalScopes()->where('is_platform_admin', true)->where('platform_role', 'owner')->orderBy('id')->lockForUpdate()->get();
            }
            $u = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($u->id);
            if ($office && $u->id === $r->user()->id && ($d['platform_role'] !== 'owner' || $d['status'] !== 'Active')) {
                throw ValidationException::withMessages(['status' => 'You cannot disable or demote your own office owner account.']);
            }
            if ($office && $u->platform_role === 'owner' && ($d['platform_role'] !== 'owner' || $d['status'] !== 'Active') && !User::withoutGlobalScopes()->where('is_platform_admin', true)->where('status', 'Active')->where('platform_role', 'owner')->whereKeyNot($u->id)->exists()) {
                throw ValidationException::withMessages(['platform_role' => 'At least one active office owner is required.']);
            }
            $changed = $u->email !== $d['email'] || $u->status !== $d['status'] || $office && $u->platform_role !== $d['platform_role'];
            if ($u->email !== $d['email']) {
                $d['email_verified_at'] = null;
                DB::table('password_resets')->whereIn('email', [$u->email, $d['email']])->delete();
            }
            if ($changed) {
                $d['auth_epoch'] = (int) $u->auth_epoch + 1;
                $d['remember_token'] = Str::random(60);
                $u->tokens()->delete();
            }
            $u->forceFill($d)->save();
            $business = $office ? null : DB::table('business_memberships')->where('user_id', $u->id)->value('business_id');
            PlatformAudit::record($office ? 'office.account_updated' : 'business.account_updated', $r->user(), $business, ['account_id' => $u->id, 'status' => $u->status, 'role' => $office ? $u->platform_role : null, 'sessions_revoked' => $changed]);
        });
        return back()->with('success', 'Account updated. Identity or access changes sign out existing sessions.');
    }
    public function passwordUser(Request $r, string $account)
    {
        return $this->password($r, $account, false);
    }
    public function passwordAdmin(Request $r, string $account)
    {
        return $this->password($r, $account, true);
    }
    private function password(Request $r, string $id, bool $office)
    {
        $r->validate(['password' => ['required', 'string', 'confirmed', 'min:12', fn($a, $v, $fail) => strlen($v) > 72 ? $fail('Use at most 72 bytes.') : null], 'note' => 'required|string|max:500']);
        DB::transaction(function () use ($r, $id, $office) {
            $u = User::withoutGlobalScopes()->where('is_platform_admin', $office)->lockForUpdate()->findOrFail($id);
            $u->forceFill(['password' => Hash::make($r->password), 'auth_epoch' => (int) $u->auth_epoch + 1, 'remember_token' => Str::random(60)])->save();
            $u->tokens()->delete();
            DB::table('password_resets')->where('email', $u->email)->delete();
            PlatformAudit::record('identity.office_assisted_password_reset', $r->user(), $office ? null : DB::table('business_memberships')->where('user_id', $id)->value('business_id'), ['account_id' => $id, 'note' => $r->note]);
        });
        return back()->with('success', 'Password replaced and existing sessions revoked.');
    }
}
