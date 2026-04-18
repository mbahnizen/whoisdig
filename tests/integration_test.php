<?php
// tests/integration_test.php - Comprehensive Integration Testing Suite

require_once __DIR__ . '/../config/app.php';

use WhoisDig\Resolvers\WHOISChecker;
use WhoisDig\Resolvers\DigChecker;

// CLI Colors
const COLOR_GREEN = "\033[32m";
const COLOR_RED = "\033[31m";
const COLOR_YELLOW = "\033[33m";
const COLOR_RESET = "\033[0m";

function printHeader($text) {
    echo "\n" . COLOR_YELLOW . str_repeat('=', 50) . COLOR_RESET . "\n";
    echo COLOR_YELLOW . " $text " . COLOR_RESET . "\n";
    echo COLOR_YELLOW . str_repeat('=', 50) . COLOR_RESET . "\n";
}

function printResult($name, $success, $details = '') {
    $status = $success ? COLOR_GREEN . "[PASSED]" . COLOR_RESET : COLOR_RED . "[FAILED]" . COLOR_RESET;
    echo str_pad($status, 20) . " $name " . ($details ? "\n   -> $details" : "") . "\n";
}

// 1. CLEAR CACHE
printHeader("1. SYSTEM PREPARATION");
$cachePath = __DIR__ . '/../storage/cache/';
$files = glob($cachePath . '*');
$deleted = 0;
foreach ($files as $file) {
    if (is_file($file)) {
        unlink($file);
        $deleted++;
    }
}
echo COLOR_GREEN . "Cleared $deleted cached file(s) from storage." . COLOR_RESET . "\n";

// 2. WHOIS TESTS
printHeader("2. WHOIS RESOLUTION (Hybrid Engine)");
$whois = new WHOISChecker();

$whoisTests = [
    ['domain' => 'google.com', 'desc' => 'Standard .com TLD (Port 43)'],
    ['domain' => 'domain.co', 'desc' => 'Specific .co TLD (Referral & Regex)'],
    ['domain' => 'flutter.dev', 'desc' => 'Modern .dev TLD (RDAP Priority)'],
    ['domain' => 'invalid-domain-that-surely-does-not-exist.com', 'desc' => 'Unregistered Domain'],
    ['domain' => '8.8.8.8', 'desc' => 'IP Address Lookup (RDAP + GeoIP)']
];

foreach ($whoisTests as $test) {
    $start = microtime(true);
    $res = $whois->lookup($test['domain']);
    $time = round((microtime(true) - $start) * 1000);

    if ($test['domain'] === '8.8.8.8') {
        // IP lookups are now supported — expect success with is_ip flag
        $success = $res['success'] && isset($res['is_ip']) && $res['is_ip'] === true;
        $details = $success 
            ? "IP lookup OK. Org: " . ($res['organization'] ?? 'N/A') . " | Country: " . ($res['country'] ?? 'N/A') . " ($time ms)" 
            : "IP lookup failed: " . ($res['error'] ?? 'Unknown');
        printResult($test['desc'], $success, $details);
    } elseif (strpos($test['domain'], 'invalid') !== false) {
        $success = !$res['success'] || $res['registrar'] === 'N/A';
        printResult($test['desc'], $success, "Unregistered detected. ($time ms)");
    } else {
        $success = $res['success'] && $res['registrar'] !== 'N/A';
        $details = $success 
            ? "Server: {$res['whois_server']} | Expires: {$res['expires']} ($time ms)" 
            : "Error/Missing Registrar";
        printResult($test['desc'], $success, $details);
    }
}

// 3. DIG TESTS
printHeader("3. DIG / DNS RECORD RESOLUTION");
$dig = new DigChecker();

$digTests = [
    ['domain' => 'google.com', 'type' => 'A', 'desc' => 'A Record (IPv4) Lookup'],
    ['domain' => 'yahoo.com', 'type' => 'MX', 'desc' => 'MX Record (Mail) Lookup'],
    ['domain' => '8.8.8.8', 'type' => 'PTR', 'desc' => 'PTR Record (Reverse IP) Lookup'],
    ['domain' => 'google.com', 'type' => 'INVALID', 'desc' => 'Invalid Record Error Handling']
];

foreach ($digTests as $test) {
    $start = microtime(true);
    $res = $dig->lookup($test['domain'], $test['type']);
    $time = round((microtime(true) - $start) * 1000);

    if ($test['type'] === 'INVALID') {
        $success = !$res['success'] && isset($res['valid_types']);
        printResult($test['desc'], $success, "Invalid type caught correctly. ($time ms)");
    } else {
        $success = $res['success'] && !empty($res['results']);
        $sample = $success ? implode(', ', array_slice($res['results'], 0, 2)) : 'Failed/Empty';
        printResult($test['desc'], $success, "Found: $sample ($time ms)");
    }
}

printHeader("TEST SUITE COMPLETED");
echo "\n";
