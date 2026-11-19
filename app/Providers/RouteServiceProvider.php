<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('password-link',fn(Request $r)=>[Limit::perMinute(6)->by($r->ip()),Limit::perHour(20)->by($r->ip()),Limit::perHour(6)->by(hash('sha256',is_string($r->email)?mb_strtolower(trim($r->email)):'invalid'))]);
        RateLimiter::for('password-reset-submit',fn(Request $r)=>Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('office-finance-write',fn(Request $r)=>Limit::perMinute(30)->by($r->user()?->id??$r->ip()));
        RateLimiter::for('office-finance-export',fn(Request $r)=>Limit::perMinute(5)->by($r->user()?->id??$r->ip()));
        RateLimiter::for('office-mail-test',fn(Request $r)=>Limit::perHour(5)->by($r->user()?->id??$r->ip()));
        RateLimiter::for('business-signup', fn(Request $r)=>[Limit::perMinute(5)->by($r->ip()),Limit::perDay(20)->by($r->ip())]);
        RateLimiter::for('office-mfa',fn(Request $r)=>Limit::perMinute(5)->by($r->user()?->id??$r->ip()));
        RateLimiter::for('support-actions',fn(Request $r)=>Limit::perMinute(20)->by($r->user()?->id??$r->ip()));
        RateLimiter::for('support-create',fn(Request $r)=>Limit::perMinute(5)->by($r->user()?->id??$r->ip()));
        RateLimiter::for('business-exports',fn(Request $r)=>Limit::perHour(2)->by($r->user()?->id??$r->ip()));
        RateLimiter::for('credential-login', fn(Request $r)=>[Limit::perMinute(5)->by(mb_strtolower((string)$r->input('email')).'|'.$r->ip()),Limit::perMinute(60)->by($r->ip())]);
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
