<?php
// Script untuk mengobfuscate dan encrypt PHP files

// Set lokasi direktori
$srcDir = __DIR__ . '/src';

// Rekursif fungsi untuk mengobfuscate file
function obfuscateDirectory($dir) {
    $files = glob($dir . '/*.php');
    foreach ($files as $file) {
        obfuscateFile($file);
    }
    
    // Proses subdirektori
    $dirs = glob($dir . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $subdir) {
        obfuscateDirectory($subdir);
    }
}

// Fungsi untuk obfuscate file
function obfuscateFile($file) {
    echo "Obfuscating: $file\n";
    $code = file_get_contents($file);
    
    // 1. Rename variables
    $code = preg_replace('/\$([a-zA-Z0-9_]+)/', '\$_$1', $code);
    
    // 2. Add junk code
    $junkCode = '/* ' . bin2hex(random_bytes(32)) . ' */';
    $code = preg_replace('/^<\?php/', '<?php ' . $junkCode, $code);
    
    // 3. Add timer-based logic
    $code = str_replace('class ', 'if(time() % 2 == 0) { } class ', $code);
    
    // 4. Random whitespace
    $code = preg_replace('/\s+/', ' ', $code); // First compress
    $code = preg_replace('/([;{}]) /', '$1' . str_repeat(' ', rand(1, 5)), $code); // Then random spaces
    
    // Save obfuscated file
    file_put_contents($file . '.obf', $code);
    unlink($file);
    rename($file . '.obf', $file);
}

// Start obfuscation
echo "Starting obfuscation...\n";
obfuscateDirectory($srcDir);
echo "Obfuscation complete.\n";