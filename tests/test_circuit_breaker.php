<?php
use WhoisDig\Utils\CircuitBreaker;

test("Circuit Breaker - Partial Failure (No Trip)", function() {
    $cb = new CircuitBreaker(3, 60, 300);
    $server = 'test.server.com';
    
    assertTrue($cb->isAvailable($server));
    
    $cb->recordFailure($server);
    $cb->recordFailure($server);
    
    assertTrue($cb->isAvailable($server), "Breaker should still be closed after 2 failures (threshold is 3)");
});

test("Circuit Breaker - Tripping and Cooldown Reset", function() {
    $cb = new CircuitBreaker(2, 60, 300);
    $server = 'trip.server.com';
    
    $cb->recordFailure($server);
    $cb->recordFailure($server);
    
    assertFalse($cb->isAvailable($server), "Breaker should trip after 2 failures");

    // Simulate cooldown expiration
    $file = TEST_STORAGE_DIR . 'logs/cb_' . md5($server) . '.json';
    $data = json_decode(file_get_contents($file), true);
    $data['banned_until'] = time() - 10; // Expired 10s ago
    file_put_contents($file, json_encode($data));

    assertTrue($cb->isAvailable($server), "Breaker should reset after cooldown");
    
    // Check if deterministic reset worked
    $newData = json_decode(file_get_contents($file), true);
    assertEquals(0, count($newData['failures']), "Failures should be reset to empty array");
    assertEquals(0, $newData['banned_until'], "Banned until should be reset to 0");
});

test("Circuit Breaker - Flapping Test (Bug H1 Verification)", function() {
    $cb = new CircuitBreaker(2, 60, 300);
    $server = 'flap.server.com';

    // 1. Fail -> Fail -> Trip
    $cb->recordFailure($server);
    $cb->recordFailure($server);
    assertFalse($cb->isAvailable($server));

    // 2. Cooldown
    $file = TEST_STORAGE_DIR . 'logs/cb_' . md5($server) . '.json';
    $data = json_decode(file_get_contents($file), true);
    $data['banned_until'] = time() - 10;
    file_put_contents($file, json_encode($data));

    // Must call isAvailable to trigger the deterministic reset
    assertTrue($cb->isAvailable($server));

    // 3. Success
    $cb->recordSuccess($server);

    // 4. Fail again (just 1 time)
    $cb->recordFailure($server);

    // 5. Assert: Should NOT trip again (threshold is 2)
    // If it trips, it means old failures weren't cleared
    assertTrue($cb->isAvailable($server), "Breaker should NOT flap (trip immediately) after recovery and single failure");
});
