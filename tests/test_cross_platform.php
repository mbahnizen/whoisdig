<?php

use WhoisDig\Utils\SystemHelper;

test("CrossPlatform - Path Normalization", function() {
    // Windows Style Paths
    $winPath1 = 'C:\\laragon\\www\\my-projects\\whoisdig\\storage';
    $normalized1 = SystemHelper::normalizePath($winPath1);
    assertEquals('C:/laragon/www/my-projects/whoisdig/storage', $normalized1, "Windows backslashes should become forward slashes");

    $winPath2 = 'C:\\laragon\\\\www//my-projects\\\\whoisdig\\';
    $normalized2 = SystemHelper::normalizePath($winPath2);
    assertEquals('C:/laragon/www/my-projects/whoisdig/', $normalized2, "Multiple mixed slashes should collapse into single forward slashes");

    // Linux Style Paths
    $linuxPath1 = '/var/www/html/whoisdig/storage/';
    $normalized3 = SystemHelper::normalizePath($linuxPath1);
    assertEquals('/var/www/html/whoisdig/storage/', $normalized3, "Linux paths should remain valid");

    $linuxPath2 = '/var//www///html////whoisdig';
    $normalized4 = SystemHelper::normalizePath($linuxPath2);
    assertEquals('/var/www/html/whoisdig', $normalized4, "Multiple forward slashes should collapse");

    // Protocols
    $protoPath = 'http://api.ipify.org/';
    $normalized5 = SystemHelper::normalizePath($protoPath);
    assertEquals('http://api.ipify.org/', $normalized5, "Protocols should not have their double slashes collapsed");
});

test("CrossPlatform - Safe File Locking (Acquisition & Timeout)", function() {
    $lockFile = TEST_STORAGE_DIR . 'test_lock.txt';
    @unlink($lockFile);

    // 1. First process acquires lock
    $fp1 = SystemHelper::acquireLock($lockFile, 'c+', 1000);
    assertTrue($fp1 !== false, "First process should immediately acquire the lock");

    // 2. Second process tries to acquire same lock (should fail/timeout)
    $start = microtime(true);
    $fp2 = SystemHelper::acquireLock($lockFile, 'c+', 500); // 500ms timeout
    $duration = (microtime(true) - $start) * 1000;

    assertFalse($fp2, "Second process MUST fail to acquire the lock");
    assertTrue($duration >= 450, "Second process MUST wait until timeout (approx 500ms)");

    // 3. First process releases lock
    @flock($fp1, LOCK_UN);
    @fclose($fp1);

    // 4. Second process tries again (should succeed immediately)
    $fp3 = SystemHelper::acquireLock($lockFile, 'c+', 1000);
    assertTrue($fp3 !== false, "Third attempt should succeed after lock is released");

    @flock($fp3, LOCK_UN);
    @fclose($fp3);
    @unlink($lockFile);
});
