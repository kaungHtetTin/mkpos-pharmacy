<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\{Spa, PlatformAudit};
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\{DB, Hash, Password};
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
class NewPasswordController extends Controller
{
    public function create(Request $r): Response
    {
        $email = is_string($r->email) ? mb_strtolower(trim($r->email)) : '';
        $token = (string) $r->route('token');
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? User::withoutGlobalScopes()->where('email', $email)->first() : null;
        $valid = strlen($token) <= 256 && $user && (!config('operations.office_only', false) || $user->is_platform_admin) && Password::broker()->tokenExists($user, $token);
        return Spa::render('Auth/ResetPassword', ['email' => $email, 'token' => $token, 'link_valid' => (bool) $valid]);
    }
    public function store(Request $r): RedirectResponse
    {
        if (is_string($r->email)) {
            $r->merge(['email' => mb_strtolower(trim($r->email))]);
        }
        $r->validate(['token' => 'required|string|max:256', 'email' => 'required|string|email:rfc|max:255', 'password' => ['required', 'string', 'confirmed', PasswordRule::min(12), function ($attribute, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('Use a password no longer than 72 bytes.');
            }
        }]]);
        $status = DB::transaction(function () use ($r) {
            $user = User::withoutGlobalScopes()->where('email', $r->email)->lockForUpdate()->first();
            if (!$user || (config('operations.office_only', false) && !$user->is_platform_admin)) {
                return Password::INVALID_TOKEN;
            }
            return Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function ($user) use ($r) {
                $user->forceFill(['password' => Hash::make($r->password), 'remember_token' => Str::random(60), 'auth_epoch' => (int) $user->auth_epoch + 1])->save();
                $user->tokens()->delete();
                PlatformAudit::record('identity.password_reset', $user, $user->businessMembership()->value('business_id'));
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or has expired. Request a new link.']);
        }
        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with your new password. Existing sessions have been signed out.');
    }
}
