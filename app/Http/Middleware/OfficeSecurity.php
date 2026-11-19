<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Operations\OfficeAccess;
class OfficeSecurity
{
    public function handle(Request $r, Closure $next)
    {
        $u = $r->user();
        if (config('operations.office_only', false) && $u && !$u->is_platform_admin) {
            abort(403);
        }
        if (!$u?->is_platform_admin || $r->is('login', 'logout')) {
            return $next($r);
        }
        if ($r->is('platform/security', 'platform/security/*')) {
            return $next($r);
        }
        if (config('operations.mfa_required') && (!$u->office_mfa_secret || $r->session()->get('office_mfa_at', 0) < now()->subHours(8)->timestamp || !hash_equals(hash('sha256', $u->office_mfa_secret), $r->session()->get('office_mfa_key', '')))) {
            return redirect()->route('platform.security.index');
        }
        if (!$r->is('platform/*')) {
            return $next($r);
        }
        $area = $r->segment(2);
        $area = match ($area) {
            'payments' => 'billing',
            default => $area,
        };
        abort_unless(OfficeAccess::allows($u, $area), 403);
        return $next($r);
    }
}
