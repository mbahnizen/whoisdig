<?php
// config.php - Konfigurasi Global

spl_autoload_register(function ($class) {
    if (strpos($class, 'WhoisDig\\') === 0) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

define('MAX_DOMAINS_BULK', 500);
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
$baseStorageDir = defined('TEST_STORAGE_DIR') ? TEST_STORAGE_DIR : __DIR__ . '/../storage/';
define('UPLOAD_DIR', $baseStorageDir . 'uploads/');
define('LOG_DIR', $baseStorageDir . 'logs/');
define('CACHE_DIR', $baseStorageDir . 'cache/');

// Ensure storage directories exist (crucial for Wasmer Edge persistent volumes)
foreach ([UPLOAD_DIR, LOG_DIR, CACHE_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// CORS Headers — restrict origin for production, use '*' for public API
define('CORS_ORIGIN', getenv('WHOISDIG_CORS_ORIGIN') ?: '*');
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Fungsi logging
function logActivity($message, $type = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    $logFile = LOG_DIR . 'activity.log';

    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }

    $logMessage = "[$timestamp] [$type] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Fungsi sanitasi input — clean only (trim + lowercase)
// HTML escaping belongs at the output/display layer, not the input layer.
function sanitizeInput($input)
{
    return trim(strtolower($input));
}

// Validasi domain
function isValidDomain($domain)
{
    $domain = sanitizeInput($domain);
    return (preg_match("/^([a-z0-9_]([a-z0-9\-_]*\.)+[a-z0-9-]*|localhost)$/i", $domain) === 1);
}

// Rate Limiting - Simpan di session atau file
function checkRateLimit($identifier, $maxRequests = 120, $timeWindow = 3600)
{
    $file = LOG_DIR . 'ratelimit_' . md5($identifier) . '.txt';
    $now = time();

    // Garbage Collection (1% chance)
    if (rand(1, 100) === 1) {
        $files = glob(LOG_DIR . 'ratelimit_*.txt');
        foreach ($files as $f) {
            if (filemtime($f) < ($now - $timeWindow - 3600)) {
                @unlink($f);
            }
        }
    }

    // Atomic read-modify-write with exclusive lock
    $fp = false;
    for ($i = 0; $i < 100; $i++) {
        $fp = @fopen($file, 'c+');
        if ($fp) break;
        usleep(50000); // 50ms backoff
    }

    if (!$fp) {
        // Can't acquire file handle after retries — allow request to proceed (fail-open)
        return ['limited' => false, 'remaining' => $maxRequests];
    }

    flock($fp, LOCK_EX);

    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode($raw, true) : [];

    // Guard against corrupt JSON
    if (!is_array($data) || !isset($data['requests'])) {
        $data = ['requests' => []];
    }

    // Clean old requests
    $data['requests'] = array_values(array_filter($data['requests'], function ($time) use ($now, $timeWindow) {
        return $time > ($now - $timeWindow);
    }));

    if (count($data['requests']) >= $maxRequests) {
        $oldestInWindow = min($data['requests']);
        $retryAfter = ($oldestInWindow + $timeWindow) - $now;

        flock($fp, LOCK_UN);
        fclose($fp);

        return ['limited' => true, 'retry_after' => max(1, $retryAfter), 'remaining' => 0];
    }

    $data['requests'][] = $now;

    // Truncate + rewind before write to prevent leftover data
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    $remaining = $maxRequests - count($data['requests']);
    return ['limited' => false, 'remaining' => $remaining];
}

