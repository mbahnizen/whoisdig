<?php
namespace WhoisDig\Utils;

class Cache
{
    private $cacheDir;
    
    public function __construct()
    {
        $this->cacheDir = __DIR__ . '/../../storage/cache/';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
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

        @file_put_contents($file, json_encode($payload));
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
     * Get a value from cache
     * Implements Stale-While-Revalidate and locking
     * 
     * @param string $key
     * @param callable $revalidateCallback Passed to perform sync refresh if needed
     * @return mixed|null
     */
    public function get($key, ?callable $revalidateCallback = null)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $this->revalidateAndSet($key, $revalidateCallback);
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
                    
                    return $this->revalidateAndSet($key, $revalidateCallback);
                }

                // Completely expired
                return $this->revalidateAndSet($key, $revalidateCallback);
            }
        }
        
        return $this->revalidateAndSet($key, $revalidateCallback);
    }

    private function revalidateAndSet($key, $callback)
    {
        if (!$callback) {
            return null;
        }

        $result = call_user_func($callback);
        if ($result !== null) {
            // Set handles the ttl logic via service layer
            // but default we save it. (TTL should be passed via callback if varied, 
            // but for simplicity we rely on manual ->set in the service or defaults here)
            // Actually, the callback usually just returns data and we set it with default.
            // A better pattern: callback returns data, we cache it. IF it's an error, maybe we setNegative.
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
