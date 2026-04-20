<?php
namespace WhoisDig\Utils;

class Metrics
{
    private static $logDir = null;

    private static function getLogDir() {
        if (self::$logDir === null) {
            $baseDir = defined('TEST_STORAGE_DIR') ? TEST_STORAGE_DIR : __DIR__ . '/../../storage/';
            $baseDir = SystemHelper::normalizePath($baseDir);
            self::$logDir = $baseDir . 'logs/';
        }
        return self::$logDir;
    }

    public static function record($event, $context = '', $durationMs = 0)
    {
        $dir = self::getLogDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . 'metrics_' . date('Y-m-d') . '.jsonl';
        
        $payload = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'context' => $context,
            'duration_ms' => $durationMs
        ];
        
        // Append to JSON Lines file
        @file_put_contents($file, json_encode($payload) . "\n", FILE_APPEND);

        // GC: 1% chance, delete metrics files older than 30 days
        if (rand(1, 100) === 1) {
            $files = glob($dir . 'metrics_*.jsonl');
            if ($files) {
                $maxAge = time() - (30 * 86400);
                $scanned = 0;
                foreach ($files as $f) {
                    if (++$scanned > 100) break; // Limit scan
                    if (@filemtime($f) < $maxAge) {
                        @unlink($f);
                    }
                }
            }
        }
    }
    
    public static function getStats()
    {
        // Future endpoint parsing logic would go here
    }
}
