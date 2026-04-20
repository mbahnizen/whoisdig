<?php
namespace WhoisDig\Resolvers;

use WhoisDig\Utils\Cache;
use WhoisDig\Utils\Metrics;

class IanaResolver
{
    private $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function getWhoisServer($tld)
    {
        $tld = strtolower($tld);
        $cacheKey = 'iana_tld_' . $tld;

        // Cache-ignorant callback: just returns the server string.
        // Cache handles TTL (7 days) and negative cache (10 min).
        return $this->cache->get($cacheKey, function() use ($tld) {
            
            // 1. Try whois.iana.org
            $server = $this->queryIanaWhois($tld);
            if ($server) {
                Metrics::record('iana_discovery_whois', $tld);
                return $server;
            }

            // 2. Fallback to RDAP Bootstrap JSON
            $server = $this->queryIanaRdapBootstrap($tld);
            if ($server) {
                Metrics::record('iana_discovery_rdap', $tld);
                return $server;
            }

            // Both failed — return null (Cache will setNegative)
            return null;
        }, 86400 * 7, 600);
    }

    private function queryIanaWhois($tld)
    {
        $timeout = 5;
        $fp = @fsockopen('whois.iana.org', 43, $errno, $errstr, $timeout);
        if (!$fp) return null;

        // BUG-C2 FIX: Set stream read timeout to prevent infinite hang
        stream_set_timeout($fp, $timeout);

        fwrite($fp, $tld . "\r\n");
        $response = '';
        while (!feof($fp)) {
            $chunk = fgets($fp, 128);
            if ($chunk !== false) {
                $response .= $chunk;
            }
            // BUG-C2 FIX: Check for stream timeout inside loop
            $meta = stream_get_meta_data($fp);
            if ($meta['timed_out']) {
                fclose($fp);
                return null;
            }
        }
        fclose($fp);

        // Typical IANA refer:
        if (preg_match('/refer:\s*([^\s]+)/i', $response, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/whois:\s*([^\s]+)/i', $response, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function queryIanaRdapBootstrap($tld)
    {
        $bootstrapUrl = 'https://data.iana.org/rdap/dns.json';
        $cacheKey = 'iana_rdap_bootstrap';
        
        // Cache-ignorant callback for bootstrap data
        $data = $this->cache->get($cacheKey, function() use ($bootstrapUrl) {
            $ch = curl_init($bootstrapUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $json = json_decode($response, true);
                if (isset($json['services'])) {
                    return $json;
                }
            }
            return null;
        }, 86400 * 30, 600);

        if ($data && isset($data['services'])) {
            foreach ($data['services'] as $serviceBlock) {
                $tlds = $serviceBlock[0];
                $urls = $serviceBlock[1];
                if (in_array($tld, $tlds)) {
                    // Return the first RDAP endpoint
                    return isset($urls[0]) ? $urls[0] : null;
                }
            }
        }

        return null;
    }
}
