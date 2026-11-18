<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class AuthenticateWebSession
{
    public function handle(Request $r, Closure $next)
    {
        $u = $r->user();
        if (!$u) {
            return $next($r);
        }
        $epoch = User::withoutGlobalScopes()->whereKey($u->id)->value('auth_epoch');
        if ($epoch === null || $r->session()->get('auth_user_id') !== $u->id || $r->session()->get('auth_epoch') !== (int) $epoch) {
            Auth::guard('web')->logoutCurrentDevice();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            return redirect()->route('login')->with('status', 'Your session ended. Please sign in again.');
        }
        return $next($r);
    }
}
