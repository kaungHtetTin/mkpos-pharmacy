<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
class SecurityHeaders
{
    public function handle(Request $r, Closure $next)
    {
        $response = $next($r);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        if ($r->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($r->user() || $r->is('login', 'platform/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        return $response;
    }
}
