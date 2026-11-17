<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class PlatformAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_platform_admin && $request->user()->getRawOriginal('status')==='Active',403);
        return $next($request);
    }
}
