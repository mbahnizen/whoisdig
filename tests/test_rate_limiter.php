<?php

// Normal Rate Limit
test("Rate Limiter - Normal Enforcement", function() {
    $ip = '192.168.1.1';
    
    // Clear state
    $file = TEST_STORAGE_DIR . 'logs/ratelimit_' . md5($ip) . '.txt';
    @unlink($file);

    $res1 = checkRateLimit($ip, 2, 60);
    assertFalse($res1['limited']);
    assertEquals(1, $res1['remaining']);

    $res2 = checkRateLimit($ip, 2, 60);
    assertFalse($res2['limited']);
    assertEquals(0, $res2['remaining']);

    $res3 = checkRateLimit($ip, 2, 60);
    assertTrue($res3['limited']);
    assertTrue($res3['retry_after'] > 0);
});

// Burst + Cooldown + Burst
test("Rate Limiter - Burst + Cooldown + Burst", function() {
    $ip = '10.0.0.1';
    $file = TEST_STORAGE_DIR . 'logs/ratelimit_' . md5($ip) . '.txt';
    @unlink($file);

    // Burst 1
    checkRateLimit($ip, 2, 2);
    checkRateLimit($ip, 2, 2);
    $resBlocked = checkRateLimit($ip, 2, 2);
    assertTrue($resBlocked['limited']);

    // Simulate cooldown by manually editing the timestamps in the file
    // Move all requests back by 3 seconds
    $data = json_decode(file_get_contents($file), true);
    $data['requests'] = array_map(function($t) { return $t - 3; }, $data['requests']);
    file_put_contents($file, json_encode($data));

    // Burst 2
    $resAllowed = checkRateLimit($ip, 2, 2);
    assertFalse($resAllowed['limited']);
    assertEquals(1, $resAllowed['remaining']);
});

// JSON Corruption Recovery
test("Rate Limiter - JSON Corruption Recovery", function() {
    $ip = '127.0.0.1';
    $file = TEST_STORAGE_DIR . 'logs/ratelimit_' . md5($ip) . '.txt';
    
    file_put_contents($file, "{garbage-data");

    $res = checkRateLimit($ip, 10, 60);
    assertFalse($res['limited'], "Should recover and allow request");
    assertEquals(9, $res['remaining']);
});

// Concurrency Test
test("Rate Limiter - High Concurrency (20 Workers)", function() {
    $ip = '172.16.0.1';
    $file = TEST_STORAGE_DIR . 'logs/ratelimit_' . md5($ip) . '.txt';
    @unlink($file);

    // Create a temporary worker script
    // CRITICAL: Escape backslashes for Windows paths embedded in double-quoted PHP strings
    $escapedStorageDir = str_replace('\\', '\\\\', TEST_STORAGE_DIR);
    $escapedConfigPath = str_replace('\\', '\\\\', realpath(__DIR__ . '/../config/app.php'));
    $workerCode = '<?php
define("TEST_STORAGE_DIR", "' . $escapedStorageDir . '");
require_once "' . $escapedConfigPath . '";
usleep(rand(1000, 50000));
$res = checkRateLimit("' . $ip . '", 10, 60);
echo json_encode($res);';

    $workerFile = TEST_STORAGE_DIR . 'worker.php';
    file_put_contents($workerFile, $workerCode);

    $processes = [];
    $pipes = [];
    $numWorkers = 20;

    for ($i = 0; $i < $numWorkers; $i++) {
        $processes[$i] = proc_open('php ' . escapeshellarg($workerFile), [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $pipes[$i]);
    }

    $allowedCount = 0;
    $limitedCount = 0;

    for ($i = 0; $i < $numWorkers; $i++) {
        $out = stream_get_contents($pipes[$i][1]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        proc_close($processes[$i]);

        $res = json_decode($out, true);
        assertTrue(is_array($res), "Worker output must be valid JSON array. Got: $out");

        if ($res['limited']) {
            $limitedCount++;
        } else {
            $allowedCount++;
        }
    }

    @unlink($workerFile);

    // Limit was 10. We sent 20 requests. Exactly 10 should be allowed.
    assertEquals(10, $allowedCount, "Exactly 10 requests should be allowed under concurrency. Actual allowed: $allowedCount, Actual limited: $limitedCount");
    assertEquals(10, $limitedCount, "Exactly 10 requests should be blocked under concurrency");
});
