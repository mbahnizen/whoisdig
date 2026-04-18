<?php
// api.php - API Router

require_once __DIR__ . '/../config/app.php';

use WhoisDig\Resolvers\WHOISChecker;
use WhoisDig\Resolvers\DigChecker;

$action = $_GET['action'] ?? $_POST['action'] ?? null;

header('Content-Type: application/json; charset=utf-8');

// Handle Preflight CORS - Handled in config.php

$rateCheck = checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
if ($rateCheck['limited']) {
    http_response_code(429);
    $retryMin = ceil($rateCheck['retry_after'] / 60);
    $domain = $_GET['domain'] ?? $_POST['domain'] ?? 'Unknown';
    die(json_encode([
        'success' => false,
        'domain' => $domain,
        'error' => "Rate limit tercapai. Coba lagi dalam {$retryMin} menit.",
        'retry_after' => $rateCheck['retry_after']
    ]));
}

try {
    switch ($action) {
        case 'whois-single':
            handleWhoisSingle();
            break;

        case 'whois-bulk':
            handleWhoisBulk();
            break;

        case 'dig':
            handleDig();
            break;

        case 'dig-bulk':
            handleDigBulk();
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Action tidak dikenali',
                'available_actions' => ['whois-single', 'whois-bulk', 'dig', 'dig-bulk']
            ]);
            break;
    }
} catch (Exception $e) {
    // S-3: Log full error internally, return generic message to client
    http_response_code(500);
    logActivity($e->getMessage() . "\n" . $e->getTraceAsString(), 'ERROR');
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error. Please try again later.'
    ]);
}

function handleWhoisSingle()
{
    $domain = $_GET['domain'] ?? $_POST['domain'] ?? null;
    $refresh = filter_var($_GET['refresh'] ?? $_POST['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$domain) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Domain parameter diperlukan']);
        return;
    }

    // BUG-2 FIX: No more SimpleCache here. All caching is handled by WhoisService.
    // The refresh flag is passed through to skip the internal cache.
    $whois = new WHOISChecker();
    $result = $whois->lookup($domain, $refresh);

    logActivity("WHOIS lookup: $domain - " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
    echo json_encode($result);
    exit;
}

function handleWhoisBulk()
{
    set_time_limit(300); // 5 minute ceiling for bulk
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method hanya POST']);
        return;
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!isset($data['domains']) || !is_array($data['domains'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Format data salah']);
        return;
    }

    $domains = array_slice($data['domains'], 0, MAX_DOMAINS_BULK);
    $refresh = filter_var($data['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $whois = new WHOISChecker();
    $results = [];

    // BUG-2 FIX: No more SimpleCache here. Caching handled by WhoisService.
    foreach ($domains as $domain) {
        $results[] = $whois->lookup($domain, $refresh);
        usleep(100000); // 100ms delay
    }

    logActivity("WHOIS bulk: " . count($domains) . " domains checked");
    echo json_encode([
        'success' => true,
        'total' => count($domains),
        'results' => $results
    ]);
    exit;
}

function handleDig()
{
    $domain = $_GET['domain'] ?? $_POST['domain'] ?? null;
    $type = $_GET['type'] ?? $_POST['type'] ?? 'A';

    if (!$domain) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Domain parameter diperlukan']);
        return;
    }

    $dig = new DigChecker();
    $result = $dig->lookup($domain, $type);

    logActivity("Dig lookup: $domain ($type) - " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
    echo json_encode($result);
}

function handleDigBulk()
{
    set_time_limit(300); // 5 minute ceiling for bulk
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method hanya POST']);
        return;
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!isset($data['domains']) || !is_array($data['domains'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Format data salah']);
        return;
    }

    $domains = array_slice($data['domains'], 0, MAX_DOMAINS_BULK);
    $type = $data['type'] ?? 'A';
    $dig = new DigChecker();
    $results = [];

    foreach ($domains as $domain) {
        $results[] = $dig->lookup($domain, $type);
        usleep(100000); // 100ms delay
    }

    logActivity("Dig bulk: " . count($domains) . " domains checked ($type)");
    echo json_encode([
        'success' => true,
        'total' => count($domains),
        'record_type' => $type,
        'results' => $results
    ]);
    exit;
}
