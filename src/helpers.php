<?php

if (!function_exists('system_utils_is_valid')) {
    function system_utils_is_valid() {
        // Skip validation during package discovery
        if (defined('ARTISAN_BINARY') && isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === 'package:discover') {
            return true;
        }
        
        try {
            if (class_exists('Illuminate\Support\Facades\Cache')) {
                return \Illuminate\Support\Facades\Cache::get('_sys_perf_status', true);
            }
            return true;
        } catch (\Exception $e) {
            return true;
        }
    }
}