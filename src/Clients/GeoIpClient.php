<?php
namespace WhoisDig\Clients;

use WhoisDig\Utils\Cache;
use WhoisDig\Utils\Metrics;

/**
 * GeoIP Client — Enriches IP data with geolocation, ISP, and ASN info.
 * 
 * Primary:  ip-api.com (free, 45 req/min, no key)
 * Fallback: ipwho.is  (free, no key)
 * 
 * Results are cached for 24 hours (geo data is slow-changing).
 */
class GeoIpClient
{
    private $cache;
    private const CACHE_TTL = 86400; // 24 hours
    private const TIMEOUT = 3; // seconds per provider

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @return array|null Normalized geo data or null on total failure
     */
    public function lookup($ip)
    {
        $cacheKey = 'geoip_' . $ip;

        // Check cache first
        try {
            $cached = $this->cache->get($cacheKey);
            if ($cached) return $cached;
        } catch (\Exception $e) {
            // Negative cache hit — skip
            return null;
        }

        // Try primary provider
        $data = $this->queryIpApi($ip);

        // Fallback
        if (!$data) {
            Metrics::record('geoip_primary_failed', $ip);
            $data = $this->queryIpWhoIs($ip);
        }

        if ($data) {
            $this->cache->set($cacheKey, $data, self::CACHE_TTL);
        } else {
            // Cache failure for 5 minutes to avoid hammering
            $this->cache->setNegative($cacheKey, 300);
        }

        return $data;
    }

    /**
     * Primary: ip-api.com
     */
    private function queryIpApi($ip)
    {
        $fields = 'status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,asname,mobile,proxy,hosting,query';
        $url = "http://ip-api.com/json/{$ip}?fields={$fields}";

        $response = $this->httpGet($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        if (!$data || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        return $this->normalize($data, 'ip-api');
    }

    /**
     * Fallback: ipwho.is
     */
    private function queryIpWhoIs($ip)
    {
        $url = "https://ipwho.is/{$ip}";

        $response = $this->httpGet($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        if (!$data || !($data['success'] ?? false)) {
            return null;
        }

        return $this->normalizeIpWhoIs($data);
    }

    /**
     * Normalize ip-api.com response to standard format
     */
    private function normalize($data, $source)
    {
        // Parse ASN number from "AS58397 PT Infinys System Indonesia"
        $asRaw = $data['as'] ?? '';
        $asnNumber = '';
        $asnOrg = '';
        if (preg_match('/^(AS\d+)\s*(.*)$/', $asRaw, $m)) {
            $asnNumber = $m[1];
            $asnOrg = $m[2];
        }

        return [
            '_source' => $source,
            'country' => $data['country'] ?? null,
            'country_code' => $data['countryCode'] ?? null,
            'region' => $data['regionName'] ?? null,
            'region_code' => $data['region'] ?? null,
            'city' => $data['city'] ?? null,
            'postal' => $data['zip'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lon' => $data['lon'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'isp' => $data['isp'] ?? null,
            'org' => $data['org'] ?? null,
            'asn' => $asnNumber,
            'asn_org' => $asnOrg ?: ($data['asname'] ?? null),
            'asn_name' => $data['asname'] ?? null,
            'is_mobile' => $data['mobile'] ?? false,
            'is_proxy' => $data['proxy'] ?? false,
            'is_hosting' => $data['hosting'] ?? false,
        ];
    }

    /**
     * Normalize ipwho.is response to standard format
     */
    private function normalizeIpWhoIs($data)
    {
        $asn = $data['connection']['asn'] ?? null;

        return [
            '_source' => 'ipwho.is',
            'country' => $data['country'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'region' => $data['region'] ?? null,
            'region_code' => $data['region_code'] ?? null,
            'city' => $data['city'] ?? null,
            'postal' => $data['postal'] ?? null,
            'lat' => $data['latitude'] ?? null,
            'lon' => $data['longitude'] ?? null,
            'timezone' => $data['timezone']['id'] ?? null,
            'isp' => $data['connection']['isp'] ?? null,
            'org' => $data['connection']['org'] ?? null,
            'asn' => $asn ? 'AS' . $asn : null,
            'asn_org' => $data['connection']['org'] ?? null,
            'asn_name' => $data['connection']['isp'] ?? null,
            'is_mobile' => false,
            'is_proxy' => $data['security']['proxy'] ?? false,
            'is_hosting' => false,
        ];
    }

    private function httpGet($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            return $response;
        }

        return null;
    }
}
