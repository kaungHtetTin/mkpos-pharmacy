<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Limits the dedicated office entry to central administration and identity. */
class OfficePortal
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('operations.office_only', false)) {
            return $next($request);
        }

        if ($request->is('/')) {
            return redirect()->route('platform.dashboard');
        }

        abort_unless($request->is(
            'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/*',
            'platform/*', 'profile', 'password', 'confirm-password', 'language',
            'business-media/*', 'storage/businesses/*'
        ), 404);

        return $next($request);
    }
}
