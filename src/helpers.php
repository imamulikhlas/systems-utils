<?php

if (!function_exists('system_utils_is_valid')) {
    function system_utils_is_valid() {
        // Skip validation during Artisan commands that run during Composer operations
        if (defined('ARTISAN_BINARY') && isset($_SERVER['argv'][1]) && (
            $_SERVER['argv'][1] === 'package:discover' || 
            $_SERVER['argv'][1] === 'vendor:publish' ||
            strpos($_SERVER['argv'][1], 'cache:') === 0 ||
            strpos($_SERVER['argv'][1], 'config:') === 0
        )) {
            return true;
        }
        
        try {
            // Only access Cache if it's fully initialized
            if (class_exists('Illuminate\Support\Facades\Cache') && 
                app()->bound('cache') && 
                app()->make('cache')->has('_sys_perf_status')) {
                return app()->make('cache')->get('_sys_perf_status', true);
            }
            return true;
        } catch (\Throwable $e) {
            // Catch any errors and return true as a fallback
            return true;
        }
    }
}