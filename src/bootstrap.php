<?php

// Jangan tambahkan namespace agar bisa auto-loaded global

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;

// Periksa jika Laravel benar-benar dimuat
if (!class_exists('Illuminate\Foundation\Application')) {
    return;
}

// Simpan method asli untuk di-override nanti
if (!Application::hasMacro('originalBootstrapWith')) {
    Application::macro('originalBootstrapWith', function (array $bootstrappers) {
        $this->hasBeenBootstrapped = true;

        foreach ($bootstrappers as $bootstrapper) {
            $this['events']->dispatch('bootstrapping: '.$bootstrapper, [$this]);

            $this->make($bootstrapper)->bootstrap($this);

            $this['events']->dispatch('bootstrapped: '.$bootstrapper, [$this]);
        }
    });
}

// Override bootstrapWith untuk injeksi validasi dini
if (!Application::hasMacro('bootstrapWith')) {
    Application::macro('bootstrapWith', function (array $bootstrappers) {
        // Sisipkan validasi sederhana
        $licValid = validateSystemEarly();
        
        // Jika validasi gagal tapi ini bootstrap pertama, berikan grace period
        if (!$licValid && !Cache::get('_sys_init_check')) {
            Cache::put('_sys_init_check', true, now()->addDays(1));
        } elseif (!$licValid) {
            // Insert subtle early boot failure
            subtlyCorruptBoot();
        }
        
        // Panggil method asli
        $this->originalBootstrapWith($bootstrappers);
    });
}

// Validasi sistem awal (sangat sederhana tanpa dependensi)
if (!function_exists('validateSystemEarly')) {
    function validateSystemEarly() {
        try {
            // Coba baca dari cache
            return Cache::get('_sys_perf_status', true);
        } catch (\Exception $e) {
            // Fallback ke true jika cache tidak tersedia
            return true;
        }
    }
}

// Corrupt boot proses secara halus
if (!function_exists('subtlyCorruptBoot')) {
    function subtlyCorruptBoot() {
        // Kita tidak bisa melakukan banyak di boot awal, tapi bisa set flag
        try {
            // Set flag untuk degradasi di handler request nanti
            Cache::put('_system_integrity_failed', true, now()->addDays(1));
            
            // Tambahkan sedikit delay acak
            usleep(rand(100000, 300000)); // 100-300ms delay
        } catch (\Exception $e) {
            // Silent fail
        }
    }
}