<?php
namespace WhoisDig\Clients;

use WhoisDig\Utils\CircuitBreaker;
use WhoisDig\Utils\Metrics;

class RdapClient
{
    private $circuitBreaker;

    public function __construct(CircuitBreaker $cb)
    {
        $this->circuitBreaker = $cb;
    }

    public function query($domain, $rdapServer = 'https://rdap.org/domain/')
    {
        // If it's the raw rdap.org, we trust it, else check circuit breaker
        $host = parse_url($rdapServer, PHP_URL_HOST);
        if ($host && !$this->circuitBreaker->isAvailable($host)) {
            throw new \Exception("RDAP server $host is temporarily blacklisted.");
        }

        $base = rtrim($rdapServer, '/');
        
        $isIP = filter_var($domain, FILTER_VALIDATE_IP);
        $entityType = $isIP ? 'ip' : 'domain';
        
        // BUG-5 FIX: Use regex to strip any existing entity type segment, then append the correct one.
        // This prevents double /domain or /ip when base URL has an unexpected structure.
        $base = preg_replace('#/(domain|ip)$#', '', $base);
        $url = $base . '/' . $entityType . '/' . $domain;
        
        $start = microtime(true);
        $ch = curl_init($url);
        
        // Headers needed by some RDAP servers
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/rdap+json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ]);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $duration = round((microtime(true) - $start) * 1000);

        if ($httpCode >= 200 && $httpCode < 300) {
            if ($host) $this->circuitBreaker->recordSuccess($host);
            Metrics::record('rdap_success', $url, $duration);
            return json_decode($response, true);
        }

        if ($httpCode === 404) {
            if ($host) $this->circuitBreaker->recordSuccess($host); // Server is fine, just unregistered domain
            Metrics::record('rdap_not_found', $url, $duration);
            return ['errorCode' => 404, 'title' => 'Not Found', 'events' => []];
        }

        if ($host) $this->circuitBreaker->recordFailure($host);
        Metrics::record('rdap_failure', "URL: $url | Code: $httpCode | Err: $error", $duration);
        throw new \Exception("RDAP Error: HTTP $httpCode");
    }
}
