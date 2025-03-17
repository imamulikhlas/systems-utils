<?php

namespace AlexaFers\SystemUtils;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Foundation\Application;
use AlexaFers\SystemUtils\Services\PerformanceMonitor;
use AlexaFers\SystemUtils\Middleware\SystemHealthCheck;

class SystemUtilsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Skip ALL operations during Artisan commands
        if ($this->isArtisanCommand()) {
            return;
        }
        
        // Daftarkan konfigurasi - keep this simple
        $this->mergeConfigFrom(
            __DIR__ . '/../config/system.php', 'system'
        );

        // Register performance monitor as singleton - but don't initialize it yet
        $this->app->singleton('system.performance', function ($app) {
            return new PerformanceMonitor();
        });

        // Tambahkan route middleware - simple registration only
        if (class_exists(SystemHealthCheck::class)) {
            $this->app->router->aliasMiddleware('system_health', SystemHealthCheck::class);
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Skip ALL operations during Artisan commands
        if ($this->isArtisanCommand()) {
            // Only register config publishing during artisan
            $this->publishes([
                __DIR__ . '/../config/system.php' => config_path('system.php'),
            ], 'system-config');
            
            return;
        }
        
        // Register helper functions - keep it simple
        if (!function_exists('system_utils_is_valid')) {
            function system_utils_is_valid() {
                try {
                    return true; // Always return true during initialization
                } catch (\Throwable $e) {
                    return true;
                }
            }
        }

        // Defer all complex operations to after application is booted
        $this->app->booted(function () {
            $this->setupAfterBoot();
        });
    }
    
    /**
     * Setup after application is fully booted
     */
    protected function setupAfterBoot()
    {
        try {
            // Now it's safe to initialize the performance monitor
            $monitor = $this->app->make('system.performance');
            
            // Add middleware - but only if we're not in an Artisan command and the class exists
            if (!$this->isArtisanCommand() && class_exists(SystemHealthCheck::class)) {
                try {
                    $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
                        ->pushMiddleware(SystemHealthCheck::class);
                } catch (\Throwable $e) {
                    // Silently fail if middleware registration fails
                }
            }
            
            // Register routes - simple version
            $this->registerSimpleRoutes();
            
            // Initialize metrics in a safe way
            dispatch(function () use ($monitor) {
                try {
                    $monitor->startMetrics();
                } catch (\Throwable $e) {
                    // Silently fail
                }
            })->afterResponse();
        } catch (\Throwable $e) {
            // Silently fail if anything goes wrong
        }
    }
    
    /**
     * Register simple validation routes
     */
    protected function registerSimpleRoutes()
    {
        Route::group(['prefix' => 'api', 'middleware' => ['api']], function () {
            Route::post('system-check', function () {
                return response()->json([
                    'status' => 'valid',
                    'ts' => time(),
                    'ref' => md5(uniqid())
                ]);
            });
        });
    }
    
    /**
     * Check if we're running in an Artisan command
     */
    protected function isArtisanCommand()
    {
        return defined('ARTISAN_BINARY') || php_sapi_name() === 'cli';
    }
}