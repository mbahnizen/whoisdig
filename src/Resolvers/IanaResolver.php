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

        // Try to get from SWR Cache (TTL 7 days)
        return $this->cache->get($cacheKey, function() use ($tld, $cacheKey) {
            
            // 1. Try whois.iana.org
            $server = $this->queryIanaWhois($tld);
            if ($server) {
                Metrics::record('iana_discovery_whois', $tld);
                // Save it for 7 days
                $this->cache->set($cacheKey, $server, 86400 * 7);
                return $server;
            }

            // 2. Fallback to RDAP Bootstrap JSON
            $server = $this->queryIanaRdapBootstrap($tld);
            if ($server) {
                Metrics::record('iana_discovery_rdap', $tld);
                $this->cache->set($cacheKey, $server, 86400 * 7);
                return $server;
            }

            // Fallback failure negative cache
            $this->cache->setNegative($cacheKey, 600);
            return null;
        });
    }

    private function queryIanaWhois($tld)
    {
        $timeout = 5;
        $fp = @fsockopen('whois.iana.org', 43, $errno, $errstr, $timeout);
        if (!$fp) return null;

        fwrite($fp, $tld . "\r\n");
        $response = '';
        while (!feof($fp)) {
            $response .= fgets($fp, 128);
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
        
        $data = $this->cache->get($cacheKey, function() use ($bootstrapUrl, $cacheKey) {
            $ch = curl_init($bootstrapUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            
            if ($response) {
                $json = json_decode($response, true);
                if (isset($json['services'])) {
                    $this->cache->set($cacheKey, $json, 86400 * 30); // 30 days
                    return $json;
                }
            }
            return null;
        });

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
