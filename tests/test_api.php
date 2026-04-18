<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET['action'] = 'whois-single';
$_GET['domain'] = 'domain.co';
require_once __DIR__ . '/../public/api.php';
