<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->scoped(\App\Tenancy\BusinessContext::class);
        $this->app->scoped(\App\Billing\SubscriptionAccess::class);

    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->afterResolving('validator', fn ($factory, $app) => $factory->setPresenceVerifier(new \App\Tenancy\BusinessPresenceVerifier($app['db'])));
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\BusinessToken::class);
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->addToMiddlewarePriorityAfter(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, \App\Http\Middleware\ResolveBusiness::class);
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->addToMiddlewarePriorityBefore(\App\Http\Middleware\ResolveBusiness::class, \App\Http\Middleware\AuthenticateWebSession::class);
    }
}
