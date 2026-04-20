<?php

// Define test isolation constants BEFORE loading app config
define('TEST_ENV', true);
define('TEST_STORAGE_DIR', __DIR__ . '/tmp/');

// Ensure test storage directories exist and are clean
foreach (['cache', 'logs', 'uploads'] as $dir) {
    $path = TEST_STORAGE_DIR . $dir . '/';
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
}

// Load application
require_once __DIR__ . '/../config/app.php';

// ---------------------------------------------------------
// Assertion Framework
// ---------------------------------------------------------
$testCount = 0;
$passCount = 0;
$failCount = 0;
$currentTestName = '';

function test($name, $callback) {
    global $testCount, $passCount, $failCount, $currentTestName;
    $testCount++;
    $currentTestName = $name;
    
    // Clear test cache before each test
    $files = glob(TEST_STORAGE_DIR . 'cache/*');
    foreach ($files as $f) { if (is_file($f)) @unlink($f); }
    
    // Clear test logs before each test
    $files = glob(TEST_STORAGE_DIR . 'logs/*');
    foreach ($files as $f) { if (is_file($f)) @unlink($f); }

    try {
        $callback();
        $passCount++;
        echo "✅ PASS: $name\n";
    } catch (\Exception $e) {
        $failCount++;
        echo "❌ FAIL: $name\n";
        echo "   -> " . $e->getMessage() . "\n";
    }
}

function assertEquals($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        $msg = $message ?: "Expected: " . print_r($expected, true) . ", Actual: " . print_r($actual, true);
        throw new \Exception("Assertion Failed: $msg");
    }
}

function assertTrue($condition, $message = "Expected true") {
    if ($condition !== true) throw new \Exception("Assertion Failed: $message");
}

function assertFalse($condition, $message = "Expected false") {
    if ($condition !== false) throw new \Exception("Assertion Failed: $message");
}

function assertContains($needle, $haystack, $message = '') {
    if (strpos($haystack, $needle) === false) {
        $msg = $message ?: "String '$needle' not found in haystack.";
        throw new \Exception("Assertion Failed: $msg");
    }
}

// ---------------------------------------------------------
// Mock Network Clients (For Deterministic Testing)
// ---------------------------------------------------------

class MockWhoisClient extends \WhoisDig\Clients\WhoisClient {
    public $callCount = 0;
    public $mockResponse = '';
    public $mockException = null;

    public function __construct() {}

    public function query($domain, $server = 'whois.verisign-grs.com', $fallbackServer = null) {
        $this->callCount++;
        if ($this->mockException) throw $this->mockException;
        return $this->mockResponse;
    }
}

class MockRdapClient extends \WhoisDig\Clients\RdapClient {
    public $callCount = 0;
    public $mockResponse = [];
    public $mockException = null;

    public function __construct() {}

    public function query($domain, $server = null) {
        $this->callCount++;
        if ($this->mockException) throw $this->mockException;
        return $this->mockResponse;
    }
}

// To allow injecting mocks into WhoisService easily
function createTestWhoisService($mockWhois = null, $mockRdap = null) {
    $cache = new \WhoisDig\Utils\Cache();
    $iana = new \WhoisDig\Resolvers\IanaResolver($cache);
    
    // We can't easily mock IanaResolver internal stream without complex stream wrappers,
    // but we can mock the clients injected into WhoisService.
    $whoisClient = $mockWhois ?: new MockWhoisClient();
    $rdapClient = $mockRdap ?: new MockRdapClient();
    
    return new \WhoisDig\Resolvers\WhoisService($cache, $iana, $whoisClient, $rdapClient);
}
