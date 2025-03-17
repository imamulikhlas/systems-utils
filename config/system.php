<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application ID
    |--------------------------------------------------------------------------
    |
    | Nama aplikasi ini (terenkripsi)
    |
    */
    'app_id' => env('SYSTEM_APP_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Metrics Endpoint
    |--------------------------------------------------------------------------
    |
    | Endpoint untuk pengumpulan metrik sistem (terenkripsi)
    |
    */
    'metrics_endpoint' => env('SYSTEM_METRICS_ENDPOINT', ''),

    /*
    |--------------------------------------------------------------------------
    | Performance Thresholds
    |--------------------------------------------------------------------------
    |
    | Pengaturan threshold untuk performance monitoring
    |
    */
    'thresholds' => [
        'memory' => 128 * 1024 * 1024, // 128MB
        'response_time' => 500, // 500ms
        'cpu' => 80, // 80%
    ],
];