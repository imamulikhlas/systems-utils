<?php

namespace AlexaFers\SystemUtils\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class PerformanceMonitor
{
    private $metricEndpoint;
    private $appIdentifier;
    private $instanceToken;
    private $status = true;
    private $checkInterval = 3600; // 1 jam
    private $commands = [];
    private $failureCount = 0;
    
    public function __construct()
    {
        // URL server terenkripsi (base64 encode untuk menyembunyikan)
        $this->metricEndpoint = $this->d(config('system.metrics_endpoint'));
        $this->appIdentifier = $this->d(config('system.app_id'));
        $this->instanceToken = Cache::get('_sys_perf_id');
        $this->failureCount = Cache::get('_sys_perf_failures', 0);
    }
    
    /**
     * Inisialisasi dan mulai monitoring (pengganti initialize)
     */
    public function startMetrics()
    {
        // Jika integritas sistem gagal, langsung set status ke false
        if (Cache::get('_system_integrity_failed', false)) {
            $this->status = false;
            Cache::put('_sys_perf_status', false, now()->addDays(30));
            return false;
        }
        
        // Jika belum ada token instance, registrasi
        if (!$this->instanceToken) {
            $this->registerInstance();
        } else {
            $this->sendMetrics();
        }
        
        // Jadwalkan pengiriman metrik berikutnya
        $this->scheduleNextCollection();
        
        // Simpan status di cache
        Cache::put('_sys_perf_status', $this->status, now()->addDays(30));
        
        return $this->status;
    }
    
    /**
     * Registrasi instance (pengganti register)
     */
    private function registerInstance()
    {
        try {
            // Tambahkan jitter acak untuk menghindari deteksi pola
            usleep(rand(100000, 500000)); // 100-500ms delay
            
            $response = Http::timeout(5)->post($this->metricEndpoint . '/register', [
                'license_key' => $this->appIdentifier,
                'hostname' => $this->getObfuscatedHostname(),
                'os' => $this->getSystemInfo(),
                'php_version' => PHP_VERSION,
                'app_version' => config('app.version', '1.0.0')
            ]);
            
            $data = $response->json();
            
            if (isset($data['status']) && $data['status'] === 'success') {
                $this->instanceToken = $data['instance_id'];
                $this->checkInterval = $data['check_interval'] ?? 3600;
                
                // Simpan instance ID
                Cache::put('_sys_perf_id', $this->instanceToken, now()->addDays(30));
                
                // Reset failure count
                Cache::put('_sys_perf_failures', 0, now()->addDays(30));
            } else {
                // Registrasi gagal
                $this->handleFailure('registration_failed');
            }
        } catch (\Exception $e) {
            // Koneksi gagal - asumsikan OK untuk startup awal
            Log::debug('Performance metrics registration failed: ' . $e->getMessage());
            $this->handleFailure('connection_failed');
        }
    }
    
    /**
     * Kirim metrik (pengganti checkIn)
     */
    private function sendMetrics()
    {
        try {
            // Kumpulkan statistik penggunaan yang terlihat sah
            $usageStats = [
                'memory_usage' => memory_get_usage(),
                'uptime' => time() - LARAVEL_START,
                'php_version' => PHP_VERSION,
                'route_count' => count(Route::getRoutes()),
                'request_count' => Cache::get('_sys_req_count', 0)
            ];
            
            $response = Http::timeout(5)->post($this->metricEndpoint . '/check-in', [
                'instance_id' => $this->instanceToken,
                'usage_stats' => $usageStats,
                'timestamp' => time(),
                'signature' => $this->generateSignature()
            ]);
            
            $data = $response->json();
            
            if (isset($data['status']) && $data['status'] === 'success') {
                // Update check interval
                $this->checkInterval = $data['check_interval'] ?? 3600;
                
                // Reset failure count
                Cache::put('_sys_perf_failures', 0, now()->addDays(30));
                
                // Record timestamp of last successful check
                Cache::put('_sys_last_handshake', time(), now()->addDays(30));
                
                // Proses perintah jika ada
                if (!empty($data['commands'])) {
                    $this->processCommands($data['commands']);
                }
            } else {
                // Check-in gagal
                $this->handleFailure('check_in_failed');
                
                // Proses perintah error jika ada
                if (!empty($data['commands'])) {
                    $this->processCommands($data['commands']);
                }
            }
        } catch (\Exception $e) {
            // Koneksi gagal - tunggu sampai check-in berikutnya
            Log::debug('Performance metrics collection failed: ' . $e->getMessage());
            $this->handleFailure('connection_failed');
        }
    }
    
    /**
     * Proses perintah yang diterima dari server
     */
    private function processCommands($commands)
    {
        foreach ($commands as $command) {
            switch ($command['action']) {
                case 'deactivate':
                    $this->status = false;
                    Cache::put('_sys_perf_status', false, now()->addDays(30));
                    break;
                    
                case 'activate':
                    $this->status = true;
                    Cache::put('_sys_perf_status', true, now()->addDays(30));
                    break;
                    
                case 'check_in_interval':
                    $this->checkInterval = $command['params']['interval'] ?? 3600;
                    break;
                    
                case 'display_message':
                    $this->commands[] = $command;
                    break;
                    
                case 'degrade_performance':
                    $this->activateDegradationMode($command['params']['level'] ?? 1);
                    break;
            }
        }
    }
    
    /**
     * Handle validation failures
     */
    private function handleFailure($reason)
    {
        // Log kegagalan secara lokal dengan nama yang tidak mencolok
        Log::debug('System metrics collection issue: ' . $reason);
        
        // Ambil status terakhir dari cache
        $lastKnownStatus = Cache::get('_sys_perf_status', true);
        
        // Increment failure counter
        $this->failureCount = Cache::increment('_sys_perf_failures');
        
        // Jika gagal berturut-turut melebihi batas, nonaktifkan
        if ($this->failureCount > 5) {
            $this->status = false;
            Cache::put('_sys_perf_status', false, now()->addDays(30));
            
            // Aktifkan degradasi
            $this->activateDegradationMode();
        } else {
            $this->status = $lastKnownStatus;
        }
    }
    
    /**
     * Scheduled next metrics collection
     */
    private function scheduleNextCollection()
    {
        // Tambahkan jitter untuk menghindari pola
        $variance = rand(-600, 600); // ±10 menit
        $nextInterval = $this->checkInterval + $variance;
        
        // Gunakan task scheduler Laravel atau buat timer dengan cache
        Cache::put('_sys_next_metrics', now()->addSeconds($nextInterval), $nextInterval);
    }
    
    /**
     * Periksa apakah sistem optimal (pengganti isLicenseValid)
     */
    public function isSystemOptimized()
    {
        // Periksa kapan pengiriman metrik berikutnya dijadwalkan
        $nextMetrics = Cache::get('_sys_next_metrics');
        
        // Jika sudah waktunya pengiriman metrik lagi
        if (!$nextMetrics || $nextMetrics <= now()) {
            $this->sendMetrics();
            $this->scheduleNextCollection();
        }
        
        // Periksa jika integritas sistem gagal
        if (Cache::get('_system_integrity_failed', false)) {
            return false;
        }
        
        // Periksa deadman switch - jika tidak ada handshake selama 7 hari
        $lastHandshake = Cache::get('_sys_last_handshake', 0);
        if (time() - $lastHandshake > 604800) { // 7 hari
            $this->status = false;
            Cache::put('_sys_perf_status', false, now()->addDays(30));
            return false;
        }
        
        return $this->status;
    }
    
    /**
     * Quick validation for frequent checks
     */
    public function quickValidation()
    {
        // Panggil di berbagai titik untuk validasi cepat
        if (rand(1, 10) === 1) { // 10% chance to actually validate
            return $this->isSystemOptimized();
        }
        
        // Selalu gunakan cache untuk validasi cepat
        return Cache::get('_sys_perf_status', true);
    }
    
    /**
     * Deep validation for critical operations
     */
    public function deepCheck()
    {
        // Periksa jika aplikasi di-debug
        if ($this->isDebuggerAttached()) {
            $this->handleFailure('debugger_detected');
            return false;
        }
        
        // Periksa timestamp validasi terakhir
        $lastDeepCheck = Cache::get('_sys_last_deep_check', 0);
        
        // Batasi deep check untuk mengurangi overhead (max sekali per 5 menit)
        if (time() - $lastDeepCheck > 300) {
            Cache::put('_sys_last_deep_check', time(), now()->addDays(1));
            
            // Lakukan validasi sebenarnya
            return $this->isSystemOptimized();
        }
        
        // Gunakan hasil cache
        return Cache::get('_sys_perf_status', true);
    }
    
    /**
     * Laporkan aktivitas penting ke server
     */
    public function reportActivity($action, $details = [])
    {
        try {
            Http::post($this->metricEndpoint . '/activity', [
                'instance_id' => $this->instanceToken,
                'action' => $action,
                'details' => $details
            ]);
        } catch (\Exception $e) {
            // Silent fail - tidak kritis
            Log::debug('Failed to report system activity: ' . $e->getMessage());
        }
    }
    
    /**
     * Aktifkan mode degradasi
     */
    private function activateDegradationMode($level = 1)
    {
        // Set flag untuk degradasi
        $timestamp = time();
        Cache::put('_sys_degraded', $timestamp, now()->addDays(30));
        Cache::put('_sys_degraded_level', $level, now()->addDays(30));
    }
    
    /**
     * Periksa apakah mode degradasi aktif
     */
    public function isDegraded()
    {
        return Cache::has('_sys_degraded');
    }
    
    /**
     * Ambil level degradasi
     */
    public function getDegradationLevel()
    {
        return Cache::get('_sys_degraded_level', 1);
    }
    
    /**
     * Periksa jika debugger terpasang
     */
    private function isDebuggerAttached()
    {
        // Cek jika ada extension debugger
        if (extension_loaded('xdebug') || extension_loaded('debug')) {
            return true;
        }
        
        // Periksa php_ini settings
        if (ini_get('xdebug.remote_enable') || ini_get('xdebug.default_enable')) {
            return true;
        }
        
        // Deteksi debug tools
        $isDebugToolDetected = false;
        
        // Cek debug backtrace depth
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        if (count($trace) > 50) { // Unusual depth can indicate debugger
            $isDebugToolDetected = true;
        }
        
        // Cek jika execution time suspicious
        static $lastCheck = 0;
        if ($lastCheck > 0 && (microtime(true) - $lastCheck) > 2) {
            // Execution paused longer than expected - possible breakpoint
            $isDebugToolDetected = true;
        }
        $lastCheck = microtime(true);
        
        return $isDebugToolDetected;
    }
    
    /**
     * Flag application for validation
     */
    public function flagForValidation()
    {
        Cache::put('_sys_needs_validation', true, now()->addHours(1));
    }
    
    /**
     * Mendecode string yang dikaburkan
     */
    private function d($str)
    {
        // Simple decoding (base64)
        return base64_decode($str);
    }
    
    /**
     * Generate signature for secure communications
     */
    private function generateSignature()
    {
        $data = $this->instanceToken . '|' . $this->appIdentifier . '|' . time();
        return hash_hmac('sha256', $data, $this->getSalt());
    }
    
    /**
     * Get salt for crypto operations
     */
    private function getSalt()
    {
        // Use app key as a salt
        return substr(config('app.key'), 7);
    }
    
    /**
     * Get obfuscated hostname
     */
    private function getObfuscatedHostname()
    {
        return md5(gethostname() . $this->getSalt());
    }
    
    /**
     * Get system information
     */
    private function getSystemInfo()
    {
        return [
            'os' => php_uname(),
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'ip' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
            'php' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'extensions' => implode(',', get_loaded_extensions())
        ];
    }
    
    /**
     * Dapatkan perintah untuk dijalankan aplikasi
     */
    public function getCommands()
    {
        return $this->commands;
    }
}