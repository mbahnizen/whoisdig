<?php
namespace WhoisDig\Utils;

class CircuitBreaker
{
    private $logDir;
    private $threshold;
    private $timeWindow;
    private $cooldown;

    public function __construct($threshold = 5, $timeWindow = 60, $cooldown = 300)
    {
        $baseDir = defined('TEST_STORAGE_DIR') ? TEST_STORAGE_DIR : __DIR__ . '/../../storage/';
        $baseDir = SystemHelper::normalizePath($baseDir);
        $this->logDir = $baseDir . 'logs/';
        $this->threshold = $threshold; // Max failures
        $this->timeWindow = $timeWindow; // Window to count failures (seconds)
        $this->cooldown = $cooldown; // How long to ban (seconds)
        
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    private function getFile($server)
    {
        return $this->logDir . 'cb_' . md5($server) . '.json';
    }

    public function recordFailure($server)
    {
        $file = $this->getFile($server);
        $now = time();
        
        $fp = SystemHelper::acquireLock($file, 'c+', 5000);
        if ($fp) {
            try {
                $content = stream_get_contents($fp);
                $data = $content ? json_decode($content, true) : ['failures' => [], 'banned_until' => 0];
                
                // Cleanup old failures
                $data['failures'] = array_filter($data['failures'] ?? [], function($t) use ($now) {
                    return $t > ($now - $this->timeWindow);
                });
                
                $data['failures'][] = $now;
                
                if (count($data['failures']) >= $this->threshold) {
                    $data['banned_until'] = $now + $this->cooldown;
                    Metrics::record('circuit_broken', $server);
                }
                
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data));
                fflush($fp);
            } finally {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }
    
    public function isAvailable($server)
    {
        $file = $this->getFile($server);
        if (!file_exists($file)) return true;
        
        $fp = SystemHelper::acquireLock($file, 'c+', 5000);
        if ($fp) {
            try {
                $content = stream_get_contents($fp);
                if ($content) {
                    $data = json_decode($content, true);
                    if (isset($data['banned_until']) && $data['banned_until'] > 0) {
                        if (time() < $data['banned_until']) {
                            return false;
                        }
                        // Cooldown expired — reset state deterministically (not unlink)
                        $data['failures'] = [];
                        $data['banned_until'] = 0;
                        ftruncate($fp, 0);
                        rewind($fp);
                        fwrite($fp, json_encode($data));
                        fflush($fp);
                    }
                }
            } finally {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
        
        return true;
    }
    
    public function recordSuccess($server)
    {
        $file = $this->getFile($server);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
