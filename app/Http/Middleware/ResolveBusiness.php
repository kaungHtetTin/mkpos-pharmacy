<?php

namespace App\Http\Middleware;

use App\Models\BusinessToken;
use App\Tenancy\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;

class ResolveBusiness
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(BusinessContext::class);
        $context->set(null);
        app(\App\Billing\SubscriptionAccess::class)->forget();

        try {
            $user = $request->user();
            if (!$user || $request->is('login', 'api/login', 'cashier', 'cashier/*', 'api/health', 'api/v1/health', 'business-media/*', 'platform/*', 'logout', 'api/logout')) {
                return $next($request);
            }

            // The account determines the business. Sessions and client IDs never select it.
            $business = $user->assignedBusiness();
            $token = $user->currentAccessToken();
            if ($request->is('api/*') && !($token instanceof TransientToken)) {
                if (!$token instanceof BusinessToken || $token->business_id !== $business?->id) {
                    return response()->json(['message' => 'This token has no access to the assigned business.'], 403);
                }
            }

            if (!$business) {
                if ($user->getRawOriginal('status') === 'Active' && $request->is('profile', 'password', 'confirm-password', 'verify-email', 'verify-email/*', 'email/verification-notification', 'api/account/password')) {
                    return $next($request);
                }
                if ($user->is_platform_admin && !$request->is('api/*') && !$request->expectsJson()) {
                    return redirect()->route('platform.businesses.index');
                }
                abort(403, 'Your account has no active business access. Contact your administrator.');
            }

            if (!$request->isMethodSafe() && ($expected = $request->header('X-Business-ID')) && $expected !== $business->id) {
                return response()->json(['message' => 'This request does not match your assigned business. Reload before continuing.'], 409);
            }

            $context->set($business);
            $identityOrBilling = $request->is('billing','billing/*','profile','password','confirm-password','verify-email','verify-email/*','email/verification-notification','api/account/profile','api/account/password','api/account/subscription','api/billing','api/billing/*');
            if (!$identityOrBilling) {
                $feature = $request->is('pos','pos/*','api/cashier/*') ? 'cashier' : ($request->is('api/sync/*','api/v1/sync/*') ? 'offline_sync' : ($request->routeIs('reports.*') ? 'reports' : null));
                app(\App\Billing\SubscriptionAccess::class)->assertOperational($business, $feature);
            }
            $user->unsetRelation('roles')->unsetRelation('branch')->unsetRelation('branches')->unsetRelation('activeBranch');
            return $next($request);
        } finally {
            $context->set(null);
        }
    }
}
