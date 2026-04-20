<?php
require_once __DIR__ . '/bootstrap.php';

$isLive = in_array('--live', $argv);

echo "========================================\n";
echo "🚀 WhoisDig Test Suite Runner\n";
echo "========================================\n";
echo "Mode: " . ($isLive ? "LIVE (Network requests enabled)" : "DETERMINISTIC (Mocked responses)") . "\n";
echo "Storage: " . TEST_STORAGE_DIR . "\n";
echo "----------------------------------------\n\n";

$start = microtime(true);

// Discover test files
$testFiles = glob(__DIR__ . '/test_*.php');
foreach ($testFiles as $file) {
    echo "\n📂 Running suite: " . basename($file) . "\n";
    echo str_repeat('-', 40) . "\n";
    require_once $file;
}

$duration = microtime(true) - $start;

echo "\n========================================\n";
echo "🏁 Test Run Complete\n";
echo "========================================\n";
echo "Total Tests: $testCount\n";
echo "Passed:      $passCount\n";
echo "Failed:      $failCount\n";
echo "Time:        " . number_format($duration, 3) . "s\n";
echo "========================================\n";

// Cleanup tmp dir
function deleteDir($dirPath) {
    if (!is_dir($dirPath)) return;
    $files = scandir($dirPath);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $path = $dirPath . '/' . $file;
            is_dir($path) ? deleteDir($path) : @unlink($path);
        }
    }
    @rmdir($dirPath);
}
deleteDir(TEST_STORAGE_DIR);

exit($failCount > 0 ? 1 : 0);
