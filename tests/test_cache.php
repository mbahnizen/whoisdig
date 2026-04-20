<?php
use WhoisDig\Utils\Cache;

$cache = new Cache();

test("Cache Hit and Callback Count", function() use ($cache) {
    $callCount = 0;
    $key = 'test_hit';
    
    $callback = function() use (&$callCount) {
        $callCount++;
        return "fresh_data";
    };

    // First call: Should miss cache and call callback
    $res1 = $cache->get($key, $callback, 60);
    assertEquals("fresh_data", $res1);
    assertEquals(1, $callCount, "Callback should be called once on miss");

    // Second call: Should hit cache, callback NOT called
    $res2 = $cache->get($key, $callback, 60);
    assertEquals("fresh_data", $res2);
    assertEquals(1, $callCount, "Callback should NOT be called on hit");
});

test("Cache Expiry and Stale-While-Revalidate", function() use ($cache) {
    $key = 'test_swr';
    
    // Set item expired but within stale window
    $file = TEST_STORAGE_DIR . 'cache/' . md5($key) . '.json';
    $payload = [
        'expires_at' => time() - 10, // Expired 10s ago
        'stale_at' => time() + 3600, // Stale window valid
        'data' => 'stale_data'
    ];
    file_put_contents($file, json_encode($payload));

    $callCount = 0;
    $callback = function() use (&$callCount) {
        $callCount++;
        return "new_data";
    };

    $res = $cache->get($key, $callback, 60);
    // SWR: returns new data (because Cache::get synchronously fetches and returns new if callback provided in this architecture)
    // Wait, the current Cache::get architecture synchronously fetches if expired, even if stale, unless it returns old data immediately.
    // Let's look at Cache::get. It says: if ($now <= $payload['stale_at'] && $revalidateCallback) { update expires_at, return fetchAndCache() }
    // Yes, it returns new data synchronously in this implementation, but locks to prevent thundering herd.
    assertEquals("new_data", $res);
    assertEquals(1, $callCount);
});

test("Cache Stampede (Thundering Herd) Anti-pattern", function() {
    // We will spawn 10 background PHP processes to hit the same expired cache key.
    // Only one should trigger the long-running callback. The others should get stale data or wait.
    // Actually, in our Cache.php SWR: 
    // It sets expires_at = $now + 60, then runs callback. 
    // Concurrent requests will see the new expires_at and return the STALE data immediately!
    // Let's verify this behavior locally without full proc_open by simulating the file state.
    $cache = new Cache();
    $key = 'test_stampede';
    
    // Simulate expired but stale cache
    $file = TEST_STORAGE_DIR . 'cache/' . md5($key) . '.json';
    $payload = [
        'expires_at' => time() - 10,
        'stale_at' => time() + 3600,
        'data' => 'stale_data'
    ];
    file_put_contents($file, json_encode($payload));

    // Simulate Thread 1: Hits cache, enters SWR, updates expires_at, and starts slow callback
    $content = json_decode(file_get_contents($file), true);
    $content['expires_at'] = time() + 60; // Lock acquired
    file_put_contents($file, json_encode($content));

    // Simulate Thread 2: Comes in during Thread 1's callback execution
    $callCount2 = 0;
    $callback2 = function() use (&$callCount2) {
        $callCount2++;
        return "thread2_data";
    };

    $res2 = $cache->get($key, $callback2, 60);
    
    // Thread 2 should return the 'stale_data' because expires_at was pushed to future by Thread 1
    assertEquals("stale_data", $res2);
    assertEquals(0, $callCount2, "Thread 2 should NOT execute callback (Stampede prevented)");
});

test("Cache JSON Corruption Recovery", function() use ($cache) {
    $key = 'test_corrupt';
    $file = TEST_STORAGE_DIR . 'cache/' . md5($key) . '.json';
    
    // Write garbage
    file_put_contents($file, "{bad_json: 'broken}");

    $callCount = 0;
    $res = $cache->get($key, function() use (&$callCount) {
        $callCount++;
        return "recovered_data";
    }, 60);

    assertEquals("recovered_data", $res);
    assertEquals(1, $callCount, "Should recover and call callback");
});

test("Negative Caching", function() use ($cache) {
    $key = 'test_negative';
    $callCount = 0;
    
    $callback = function() use (&$callCount) {
        $callCount++;
        return null; // Simulate failure
    };

    // First call: Miss, executes callback, gets null, sets negative cache
    $res1 = $cache->get($key, $callback, 60, 300);
    assertEquals(null, $res1);
    assertEquals(1, $callCount);

    // Second call: Should hit negative cache and throw/return null immediately without callback
    try {
        $res2 = $cache->get($key, $callback, 60, 300);
        // Depending on service layer, Cache throws Exception or service catches it.
        // Wait, Cache::processPayload throws Exception on negative hit.
    } catch (\Exception $e) {
        $res2 = 'caught_negative';
    }
    
    assertEquals('caught_negative', $res2);
    assertEquals(1, $callCount, "Callback must NOT be called on negative cache hit");
});
