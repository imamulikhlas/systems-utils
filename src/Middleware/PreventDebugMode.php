<?php

namespace AlexaFers\SystemUtils\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class PreventDebugMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if system is valid and in production
        if (!system_utils_is_valid() && !App::hasDebugModeEnabled()) {
            // Prevent debug mode from being enabled
            config(['app.debug' => false]);
        }
        
        return $next($request);
    }
}