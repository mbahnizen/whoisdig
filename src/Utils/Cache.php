<?php
namespace WhoisDig\Utils;

class Cache
{
    private $cacheDir;
    
    public function __construct()
    {
        $baseDir = defined('TEST_STORAGE_DIR') ? TEST_STORAGE_DIR : __DIR__ . '/../../storage/';
        $baseDir = SystemHelper::normalizePath($baseDir);
        $this->cacheDir = $baseDir . 'cache/';
        
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    private function getFilePath($key)
    {
        return $this->cacheDir . md5($key) . '.json';
    }

    /**
     * Set a value in the cache with exclusive locking.
     * 
     * @param string $key 
     * @param mixed $data 
     * @param int $ttl Seconds until expired
     */
    public function set($key, $data, $ttl = 7200)
    {
        $file = $this->getFilePath($key);
        $payload = [
            'expires_at' => time() + $ttl,
            'stale_at' => time() + $ttl + 86400, // 24 hours stale window
            'data' => $data
        ];

        $fp = SystemHelper::acquireLock($file, 'c+', 5000);
        if ($fp) {
            try {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($payload));
                fflush($fp);
            } finally {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        } else {
            // Fail open and write without lock if lock fails
            @file_put_contents($file, json_encode($payload));
        }
    }

    /**
     * Negative caching mechanism (for failures)
     */
    public function setNegative($key, $ttl = 300)
    {
        // 5 minute default negative cache
        $this->set($key, ['_negative' => true], $ttl);
    }

    /**
     * Get a value from cache with proper Stale-While-Revalidate.
     * 
     * The callback is CACHE-IGNORANT — it only returns fresh data.
     * All caching logic (set, TTL, negative cache) is handled here.
     * 
     * @param string $key
     * @param callable|null $revalidateCallback Returns fresh data (no cache awareness needed)
     * @param int $ttl TTL in seconds for fresh data (default: 3600)
     * @param int $negativeTtl TTL in seconds for negative cache on failure (default: 300)
     * @return mixed|null
     */
    public function get($key, ?callable $revalidateCallback = null, $ttl = 3600, $negativeTtl = 300)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $this->fetchAndCache($key, $revalidateCallback, $ttl, $negativeTtl);
        }

        $content = @file_get_contents($file);

        if ($content) {
            $payload = json_decode($content, true);
            if (is_array($payload) && isset($payload['expires_at'])) {
                $now = time();

                // VALID CACHE
                if ($now <= $payload['expires_at']) {
                    return $this->processPayload($payload['data']);
                }
                
                // EXPIRED but STALE-WHILE-REVALIDATE window
                if ($now <= $payload['stale_at'] && $revalidateCallback) {
                    // Update expires_at immediately to prevent thundering herd (simulating lock)
                    $tempPayload = $payload;
                    $tempPayload['expires_at'] = $now + 60; // 60s lock
                    @file_put_contents($file, json_encode($tempPayload));
                    
                    return $this->fetchAndCache($key, $revalidateCallback, $ttl, $negativeTtl);
                }

                // Completely expired
                return $this->fetchAndCache($key, $revalidateCallback, $ttl, $negativeTtl);
            }
        }
        
        return $this->fetchAndCache($key, $revalidateCallback, $ttl, $negativeTtl);
    }

    /**
     * Execute callback and cache the result.
     * Callback is cache-ignorant — it just returns data.
     * Cache handles all storage logic.
     */
    private function fetchAndCache($key, $callback, $ttl, $negativeTtl)
    {
        if (!$callback) {
            return null;
        }

        $result = call_user_func($callback);

        if ($result !== null) {
            $this->set($key, $result, $ttl);
        } else {
            $this->setNegative($key, $negativeTtl);
        }

        return $result;
    }

    private function processPayload($data) {
        if (isset($data['_negative']) && $data['_negative'] === true) {
            throw new \Exception("Negative cache hit."); // Handled by service
        }
        return $data;
    }
    
    public function delete($key) {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
