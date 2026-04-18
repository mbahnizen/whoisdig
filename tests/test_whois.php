<?php
require_once __DIR__ . '/../config/app.php';

use WhoisDig\Resolvers\WHOISChecker;

$domain = isset($argv[1]) ? $argv[1] : 'google.com';
$checker = new WHOISChecker();
$result = $checker->lookup($domain);

echo json_encode($result, JSON_PRETTY_PRINT);
