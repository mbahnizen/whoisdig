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

    /**
     * Protocol detection helpers.
     * Used by WhoisService to decide which client to route to.
     */
    public static function isRdapUrl($value)
    {
        return is_string($value) && preg_match('#^https?://#i', $value);
    }

    public static function isWhoisHost($value)
    {
        if (!is_string($value) || $value === '') return false;
        // Must look like a hostname (letters, digits, dots, hyphens), NOT a URL
        return !self::isRdapUrl($value) && preg_match('/^[a-z0-9.-]+$/i', $value);
    }

    /**
     * Get the WHOIS (Port 43) server for a TLD.
     * Returns ONLY valid hostnames, never URLs.
     */
    public function getWhoisServer($tld)
    {
        $tld = strtolower($tld);
        $cacheKey = 'iana_whois_' . $tld;

        return $this->cache->get($cacheKey, function() use ($tld) {
            $server = $this->queryIanaWhois($tld);
            if ($server && self::isWhoisHost($server)) {
                Metrics::record('iana_discovery_whois', $tld);
                return $server;
            }
            // No valid WHOIS server found for this TLD
            return null;
        }, 86400 * 7, 600);
    }

    /**
     * Get the RDAP (HTTPS) base URL for a TLD.
     * Uses the official IANA RDAP Bootstrap registry.
     * Returns ONLY valid HTTPS URLs, never hostnames.
     */
    public function getRdapServer($tld)
    {
        $tld = strtolower($tld);
        $cacheKey = 'rdap_server_' . $tld;

        return $this->cache->get($cacheKey, function() use ($tld) {
            $url = $this->queryIanaRdapBootstrap($tld);
            if ($url && self::isRdapUrl($url)) {
                Metrics::record('iana_discovery_rdap', $tld);
                return $url;
            }
            return null;
        }, 86400, 600); // 24h cache, 10min negative
    }

    /**
     * Query whois.iana.org via TCP Port 43 for the TLD's WHOIS server.
     * STRICT regex: only matches non-empty hostname values.
     */
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

        // STRICT regex: only match 'refer:' or 'whois:' followed by a valid hostname
        // ON THE SAME LINE. Using [ \t]+ (horizontal whitespace) instead of \s+ to prevent
        // matching across newlines when whois: field is empty.
        if (preg_match('/^refer:[ \t]+([a-z0-9][a-z0-9.-]+[a-z0-9])/mi', $response, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/^whois:[ \t]+([a-z0-9][a-z0-9.-]+[a-z0-9])/mi', $response, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Query the IANA RDAP Bootstrap JSON for the TLD's RDAP base URL.
     * Source: https://data.iana.org/rdap/dns.json (official registry)
     */
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
