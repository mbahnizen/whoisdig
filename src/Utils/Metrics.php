<?php
namespace WhoisDig\Utils;

class Metrics
{
    private static $logDir = __DIR__ . '/../../storage/logs/';

    public static function record($event, $context = '', $durationMs = 0)
    {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }

        $file = self::$logDir . 'metrics_' . date('Y-m-d') . '.jsonl';
        
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
            $files = glob(self::$logDir . 'metrics_*.jsonl');
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
