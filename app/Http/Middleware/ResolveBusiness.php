<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Tenancy\BusinessContext;
use Closure;
use Illuminate\Http\Request;

class ResolveBusiness
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(BusinessContext::class);
        $context->set(null);
        try {
            $user = $request->user();
            if ($request->is('login', 'api/login', 'cashier', 'cashier/*', 'api/health', 'api/v1/health', 'business-media/*') || !$user) return $next($request);
            // These endpoints operate on global identity or authorized memberships, not tenant data.
            if ($request->is('businesses', 'businesses/*', 'platform/*', 'logout', 'api/logout', 'api/account/businesses', 'api/account/businesses/*')) return $next($request);
            $token = $user->currentAccessToken();
            $stateful = $token instanceof \Laravel\Sanctum\TransientToken;
            $id = $request->is('api/*') ? ($token instanceof \App\Models\BusinessToken ? $token->business_id : ($stateful ? ($request->hasSession() ? $request->session()->get('business_id') : $request->header('X-Business-ID')) : null)) : $request->session()->get('business_id');
            if (!$id && (!$request->is('api/*') || $stateful)) {
                $available = $user->businesses()->wherePivot('status','Active')->where('businesses.status','Active')->get();
                if ($available->count() === 1) { $id=$available->first()->id; if ($request->hasSession()) $request->session()->put('business_id',$id); }
            }
            $business = $id ? Business::whereKey($id)->where('status','Active')->whereHas('memberships', fn ($q) => $q->where('user_id',$user->id)->where('status','Active'))->first() : null;
            if (!$business && $user->getRawOriginal('status') === 'Active' && $request->is('profile','password','confirm-password','verify-email','verify-email/*','email/verification-notification','api/account/password')) return $next($request);
            if (!$business || $user->getRawOriginal('status') !== 'Active') {
                if ($request->is('api/*') || $request->expectsJson()) return response()->json(['message'=>'Select an active business before continuing.', 'business_selection_required'=>true],403);
                return redirect()->route($user->is_platform_admin ? 'platform.businesses.index' : 'businesses.index');
            }
            if (!$request->isMethodSafe() && ($expected=$request->header('X-Business-ID'))) {
                if ($expected!==$business->id) return response()->json(['message'=>'The active business changed. Reload this page before continuing.'],409);
            }
            $context->set($business);
            $user->unsetRelation('roles')->unsetRelation('branch')->unsetRelation('branches')->unsetRelation('activeBranch');
            return $next($request);
        } finally { $context->set(null); }
    }
}
