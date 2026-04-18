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
    }
    
    public static function getStats()
    {
        // Future endpoint parsing logic would go here
    }
}
