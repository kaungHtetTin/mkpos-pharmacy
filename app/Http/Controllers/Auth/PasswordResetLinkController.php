<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\RecoveryMail;
use App\Support\Spa;
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\{DB, Password};
use Symfony\Component\HttpFoundation\Response;
class PasswordResetLinkController extends Controller
{
    public const MESSAGE = 'If an account uses this email, you will receive a password reset link shortly. Check your inbox and spam folder.';
    public function create(RecoveryMail $mail): Response
    {
        return Spa::render('Auth/ForgotPassword', ['status' => session('status'), 'mail_available' => $mail->available()]);
    }
    public function store(Request $r, RecoveryMail $mail): RedirectResponse
    {
        if (is_string($r->email)) {
            $r->merge(['email' => mb_strtolower(trim($r->email))]);
        }
        $r->validate(['email' => 'required|string|email:rfc|max:255']);
        if (!$mail->available()) {
            return back()->withErrors(['email' => 'Password reset email is not configured yet. Contact the platform office for help.']);
        }
        try {
            DB::transaction(function () use ($r, $mail) {
                User::withoutGlobalScopes()->where('email', $r->email)->lockForUpdate()->first();
                $credentials = $r->only('email');
                if (config('operations.office_only', false)) {
                    $credentials['is_platform_admin'] = true;
                }
                $status = Password::sendResetLink($credentials);
                if ($status === Password::RESET_LINK_SENT) {
                    $mail->sent();
                }
            });
        } catch (\Throwable $error) {
            $mail->failed($error);
        }
        return back()->with('status', self::MESSAGE);
    }
}
