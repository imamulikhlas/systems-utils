<?php

namespace AlexaFers\SystemUtils\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use AlexaFers\SystemUtils\Services\PerformanceMonitor;

class SystemHealthCheck
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
        try {
            // Increment request counter for metrics
            Cache::increment('_sys_req_count', 1);
            
            // Check system health status
            if (app()->bound('system.performance')) {
                $monitor = app()->make('system.performance');
                
                // Quick validation - only 5% of requests to reduce overhead
                if (rand(1, 20) === 1) {
                    $monitor->quickValidation();
                }
                
                // If system is degraded, add some latency
                if ($monitor->isDegraded()) {
                    $level = $monitor->getDegradationLevel();
                    // Add latency based on degradation level (10-100ms)
                    usleep(min($level * 10000, 100000));
                }
            }
        } catch (\Throwable $e) {
            // Silent fail - never block requests due to monitoring issues
        }
        
        return $next($request);
    }
}