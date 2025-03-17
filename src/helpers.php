<?php

if (!function_exists('system_utils_is_valid')) {
    function system_utils_is_valid() {
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