<?php
// whois.php - Legacy Adapter for WHOISDIG Refactored Engine

namespace WhoisDig\Resolvers;

use WhoisDig\Utils\Cache;
use WhoisDig\Utils\CircuitBreaker;
use WhoisDig\Clients\RdapClient;
use WhoisDig\Clients\WhoisClient;

class WHOISChecker
{
    private $service;

    public function __construct()
    {
        $cache = new Cache();
        $cb = new CircuitBreaker();
        $iana = new IanaResolver($cache);
        $whoisClient = new WhoisClient($cb);
        $rdapClient = new RdapClient($cb);
        $this->service = new WhoisService($cache, $iana, $whoisClient, $rdapClient);
    }

    public function lookup($target, $refresh = false)
    {
        $target = sanitizeInput($target);

        try {
            return $this->service->lookup($target, $refresh);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Service error: ' . $e->getMessage(),
                'domain' => $target
            ];
        }
    }
}
