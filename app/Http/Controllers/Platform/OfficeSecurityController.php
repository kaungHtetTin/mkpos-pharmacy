<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Operations\Totp;
use App\Support\{Spa, PlatformAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class OfficeSecurityController extends Controller
{
    public function index(Request $r)
    {
        return Spa::render('Platform/Security', ['enabled' => (bool) $r->user()->office_mfa_secret, 'verified' => (bool) $r->user()->office_mfa_secret && $r->session()->get('office_mfa_at', 0) >= now()->subHours(8)->timestamp && hash_equals(hash('sha256', $r->user()->office_mfa_secret), $r->session()->get('office_mfa_key', '')), 'setup_secret' => $r->session()->get('office_pending_secret'), 'recovery_codes' => $r->session()->pull('office_new_recovery', [])]);
    }
    public function setup(Request $r)
    {
        $r->validate(['password' => 'required|string']);
        if (!Hash::check($r->password, $r->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
        abort_if($r->user()->office_mfa_secret, 409);
        $r->session()->put('office_pending_secret', Totp::secret());
        return back();
    }
    private function verified(Request $r, User $u): void
    {
        $r->session()->regenerate();
        $r->session()->put(['office_mfa_at' => now()->timestamp, 'office_mfa_key' => hash('sha256', $u->office_mfa_secret)]);
    }
    public function enable(Request $r)
    {
        $r->validate(['code' => 'required|string']);
        $secret = $r->session()->get('office_pending_secret');
        abort_unless($secret, 409);
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::lower(Str::random(12));
        }
        $u = DB::transaction(function () use ($r, $secret, $codes) {
            $u = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($r->user()->id);
            abort_if($u->office_mfa_secret, 409);
            $counter = Totp::counter($secret, $r->code);
            if ($counter === null) {
                throw ValidationException::withMessages(['code' => 'Enter a current authenticator code.']);
            }
            $u->forceFill(['office_mfa_secret' => $secret, 'office_recovery_codes' => array_map(fn($v) => hash('sha256', $v), $codes), 'office_mfa_counter' => $counter])->save();
            PlatformAudit::record('office.mfa_enabled', $u);
            return $u;
        });
        $r->session()->forget('office_pending_secret');
        $r->session()->put('office_new_recovery', $codes);
        $this->verified($r, $u);
        return redirect()->route('platform.security.index');
    }
    public function challenge(Request $r)
    {
        $r->validate(['code' => 'required|string|max:32']);
        $u = DB::transaction(function () use ($r) {
            $u = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($r->user()->id);
            abort_unless($u->office_mfa_secret, 409);
            $counter = Totp::counter($u->office_mfa_secret, $r->code, $u->office_mfa_counter);
            $codes = $u->office_recovery_codes ?? [];
            $index = array_search(hash('sha256', strtolower(trim($r->code))), $codes, true);
            if ($counter === null && $index === false) {
                throw ValidationException::withMessages(['code' => 'Invalid or already used verification code.']);
            }
            if ($counter !== null) {
                $u->office_mfa_counter = $counter;
            } else {
                unset($codes[$index]);
                $u->office_recovery_codes = array_values($codes);
            }
            $u->save();
            PlatformAudit::record('office.mfa_verified', $u);
            return $u;
        });
        $this->verified($r, $u);
        return redirect()->route('platform.dashboard');
    }
}
