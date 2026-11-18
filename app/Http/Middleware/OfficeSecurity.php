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
        if ($r->is('platform/security', 'platform/security/*')) {
            return $next($r);
        }
        if (config('operations.mfa_required') && (!$u->office_mfa_secret || $r->session()->get('office_mfa_at', 0) < now()->subHours(8)->timestamp || !hash_equals(hash('sha256', $u->office_mfa_secret), $r->session()->get('office_mfa_key', '')))) {
            return redirect()->route('platform.security.index');
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
