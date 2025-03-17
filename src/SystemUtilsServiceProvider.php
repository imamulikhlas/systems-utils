<?php

namespace AlexaFers\SystemUtils;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // Skip ALL operations during package discovery
        if ($this->isPackageDiscovery()) {
            return;
        }
        
        // Publikasikan konfigurasi
        $this->publishes([
            __DIR__ . '/../config/system.php' => config_path('system.php'),
        ], 'system-config');

        // Daftarkan konfigurasi
        $this->mergeConfigFrom(
            __DIR__ . '/../config/system.php', 'system'
        );

        // Register performance monitor sebagai singleton
        $this->app->singleton('system.performance', function ($app) {
            return new PerformanceMonitor();
        });

        // Tambahkan route middleware
        if (method_exists($this->app, 'router')) {
            $this->app->router->aliasMiddleware('system_health', SystemHealthCheck::class);
        }

        // Load bootstrap functionality safely
        $this->loadBootstrapFunctionality();
    }
    
    /**
     * Check if we're running in package discovery
     */
    protected function isPackageDiscovery()
    {
        return defined('ARTISAN_BINARY') && 
               isset($_SERVER['argv'][1]) && 
               $_SERVER['argv'][1] === 'package:discover';
    }
    
    /**
     * Load bootstrap functionality safely
     */
    protected function loadBootstrapFunctionality()
    {
        try {
            // Only load if not in package discovery
            if (!$this->isPackageDiscovery() && file_exists(__DIR__ . '/bootstrap.php')) {
                require_once __DIR__ . '/bootstrap.php';
            }
        } catch (\Throwable $e) {
            // Silent fail
        }
    }
    
    // Rest of your service provider code...
}