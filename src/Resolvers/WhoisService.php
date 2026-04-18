<?php
namespace WhoisDig\Resolvers;

use WhoisDig\Utils\Cache;
use WhoisDig\Utils\Metrics;
use WhoisDig\Utils\TldResolver;
use WhoisDig\Clients\WhoisClient;
use WhoisDig\Clients\RdapClient;
use WhoisDig\Clients\GeoIpClient;
use WhoisDig\Parsers\WhoisParser;
use WhoisDig\Parsers\RdapParser;
use WhoisDig\Parsers\ReferralParser;

class WhoisService
{
    private $cache;
    private $iana;
    private $whoisClient;
    private $rdapClient;
    private $geoIpClient;

    public function __construct(Cache $cache, IanaResolver $iana, WhoisClient $whoisClient, RdapClient $rdapClient)
    {
        $this->cache = $cache;
        $this->iana = $iana;
        $this->whoisClient = $whoisClient;
        $this->rdapClient = $rdapClient;
        $this->geoIpClient = new GeoIpClient($cache);
    }

    public function lookup($domain, $skipCache = false)
    {
        $originalDomain = trim(strtolower($domain));
        $isIP = filter_var($originalDomain, FILTER_VALIDATE_IP);

        if ($isIP) {
            $punycodeDomain = $originalDomain;
            $effectiveTld = null;
            $tld = null;
            $cacheKey = 'ip_' . md5($originalDomain);
        } else {
            // 1. IDN -> Punycode Handling
            $punycodeDomain = $originalDomain;
            if (mb_detect_encoding($originalDomain, 'ASCII', true) === false) {
                if (function_exists('idn_to_ascii')) {
                    // Ensure proper UTS46 compatibility if available
                    $punycodeDomain = defined('INTL_IDNA_VARIANT_UTS46') 
                        ? idn_to_ascii($originalDomain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                        : idn_to_ascii($originalDomain);
                    if ($punycodeDomain === false) {
                        throw new \Exception("Invalid IDN format.");
                    }
                } else {
                    throw new \Exception("PHP 'intl' extension is required for IDN (Internationalized Domain Names).");
                }
            }

            // Accurate TLD via Public Suffix List (e.g. my.id, co.uk)
            $effectiveTld = TldResolver::resolve($punycodeDomain);
            $tld = TldResolver::rootTld($punycodeDomain); // Root TLD for IANA queries
            $cacheKey = 'domain_' . md5($punycodeDomain);
        }

        if ($skipCache) {
            return $isIP ? $this->executeIpLookupFlow($originalDomain) : $this->executeLookupFlow($punycodeDomain, $tld, $originalDomain, $effectiveTld);
        }

        return $this->cache->get($cacheKey, function() use ($punycodeDomain, $tld, $effectiveTld, $originalDomain, $cacheKey, $isIP) {
            $result = $isIP ? $this->executeIpLookupFlow($originalDomain) : $this->executeLookupFlow($punycodeDomain, $tld, $originalDomain, $effectiveTld);
            if ($result['success']) {
                $this->cache->set($cacheKey, $result, 3600); // Base 1 hour TTL
            } else {
                $this->cache->setNegative($cacheKey, 300); // 5 min retry block
            }
            return $result;
        });
    }

    private function executeIpLookupFlow($ip)
    {
        // === IP VALIDATION ===
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'is_ip' => true, 'error' => 'Invalid IP address.', 'domain' => $ip];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['success' => false, 'is_ip' => true, 'error' => 'Private or reserved IP addresses cannot be looked up.', 'domain' => $ip];
        }

        // === RDAP LOOKUP (primary) ===
        $ipData = null;
        $rawText = '';
        try {
            $rdapResult = $this->rdapClient->query($ip);
            $ipData = RdapParser::parseIp($rdapResult);
            $rawText = json_encode($rdapResult, JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            Metrics::record('rdap_ip_failed', $ip);
            // RDAP failed but we can still try GeoIP
        }

        // === GEOIP ENRICHMENT ===
        $geoData = null;
        try {
            $geoData = $this->geoIpClient->lookup($ip);
        } catch (\Exception $e) {
            Metrics::record('geoip_failed', $ip);
        }

        // === REVERSE DNS (PTR) ===
        $hostname = null;
        $ptr = @gethostbyaddr($ip);
        if ($ptr && $ptr !== $ip) {
            $hostname = $ptr;
        }

        // If both RDAP and GeoIP failed completely
        if (!$ipData && !$geoData) {
            return [
                'success' => false,
                'is_ip' => true,
                'error' => 'No data found for this IP address.',
                'domain' => $ip
            ];
        }

        // === MERGE STRATEGY: RDAP priority, GeoIP enriches ===
        $org = ($ipData['organization'] ?? 'N/A');
        if ($org === 'N/A' && $geoData) {
            // Fallback to GeoIP org/isp
            $org = $geoData['org'] ?: ($geoData['isp'] ?? 'N/A');
        }

        $country = ($ipData['country'] ?? 'N/A');
        if ($country === 'N/A' && $geoData) {
            $country = $geoData['country_code'] ?? 'N/A';
        }

        // ASN: prefer GeoIP (has structured ASN), cross-check with RDAP if available
        $asn = $geoData['asn'] ?? null;
        $asnName = $geoData['asn_name'] ?? null;
        $asnOrg = $geoData['asn_org'] ?? null;

        // Technical details — BUG-4 FIX: safe for 32-bit PHP and IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            // Use sprintf('%u') for unsigned conversion on 32-bit systems
            $ipDecimal = $ipLong !== false ? sprintf('%u', $ipLong) : 'N/A';
            $ipHex = $ipLong !== false ? strtoupper(implode('.', str_split(str_pad(dechex(sprintf('%u', $ipLong)), 8, '0', STR_PAD_LEFT), 2))) : 'N/A';
        } else {
            // IPv6 — decimal/hex representation is not applicable
            $ipDecimal = 'N/A';
            $ipHex = 'N/A';
        }

        return [
            'success' => true,
            'is_ip' => true,
            'domain' => $ip,

            // RDAP data
            'network_name' => $ipData['network_name'] ?? 'N/A',
            'handle' => $ipData['handle'] ?? 'N/A',
            'start_address' => $ipData['start_address'] ?? 'N/A',
            'end_address' => $ipData['end_address'] ?? 'N/A',
            'cidr' => $ipData['cidr'] ?? 'N/A',
            'ip_version' => $ipData['ip_version'] ?? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'v6' : 'v4'),
            'type' => $ipData['type'] ?? 'N/A',
            'port43' => $ipData['port43'] ?? 'N/A',
            'parent_handle' => $ipData['parent_handle'] ?? 'N/A',
            'status' => $ipData['status'] ?? [],
            'registration' => $ipData['registration'] ?? 'N/A',
            'last_changed' => $ipData['last_changed'] ?? 'N/A',
            'org_address' => $ipData['org_address'] ?? 'N/A',
            'abuse_contact' => $ipData['abuse_contact'] ?? null,
            'raw' => $rawText ? base64_encode($rawText) : null,

            // Merged fields (RDAP priority, GeoIP fallback)
            'organization' => $org,
            'country' => $country,

            // GeoIP enrichment
            'geo' => $geoData ? [
                'country_name' => $geoData['country'],
                'country_code' => $geoData['country_code'],
                'region' => $geoData['region'],
                'city' => $geoData['city'],
                'postal' => $geoData['postal'],
                'lat' => $geoData['lat'],
                'lon' => $geoData['lon'],
                'timezone' => $geoData['timezone'],
                'isp' => $geoData['isp'],
                'is_proxy' => $geoData['is_proxy'],
                'is_hosting' => $geoData['is_hosting'],
                'is_mobile' => $geoData['is_mobile'],
            ] : null,

            // ASN
            'asn' => $asn,
            'asn_name' => $asnName,
            'asn_org' => $asnOrg,

            // Reverse DNS
            'hostname' => $hostname,

            // Technical
            'ip_decimal' => $ipDecimal,
            'ip_hex' => $ipHex,
        ];
    }

    private function executeLookupFlow($domain, $tld, $originalDomain, $effectiveTld = null)
    {
        // Modern TLDs prioritize RDAP
        $rdapFirstTlds = ['app', 'dev', 'page', 'google', 'cloud'];
        $preferRdap = in_array($tld, $rdapFirstTlds);

        $rdapData = null;
        $whoisData = null;
        $rawText = '';
        $usedServer = '';

        if ($preferRdap) {
            try {
                $rdapResult = $this->rdapClient->query($domain);
                $rdapData = RdapParser::parse($rdapResult);
                $rawText = json_encode($rdapResult);
                $usedServer = 'rdap.org';
            } catch (\Exception $e) {
                // Fallback will trigger below
                Metrics::record('rdap_priority_failed_fallback_whois', $domain);
            }
        }

        if (!$rdapData) {
            try {
                // Determine Root WHOIS Server
                $server = $this->iana->getWhoisServer($tld);
                if (!$server) {
                    // Fallbacks for common missing ones or complete failure
                    $server = 'whois.iana.org';
                }

                // Referential Chaining (Max depth: 2)
                $depth = 0;
                $maxDepth = 2;
                
                while ($depth <= $maxDepth) {
                    if (strpos($server, 'http://') === 0 || strpos($server, 'https://') === 0) {
                        try {
                            $rdapResult = $this->rdapClient->query($domain, $server);
                            $rdapData = RdapParser::parse($rdapResult);
                            $rawText = json_encode($rdapResult);
                            $usedServer = $server;
                            break; // Successfully got it via RDAP fallback instead of Port 43
                        } catch (\Exception $e) {
                            break;
                        }
                    }

                    $rawText = $this->whoisClient->query($domain, $server);
                    $usedServer = $server;
                    
                    // Specific logic for registry returning "No match" inside a large referral setup string
                    if (stripos($rawText, 'No match for') !== false && $depth > 0) {
                        break; 
                    }

                    $referral = ReferralParser::extractServer($rawText);
                    if ($referral && $referral !== $server) {
                        $server = $referral;
                        $depth++;
                    } else {
                        break;
                    }
                }

                $whoisData = WhoisParser::parse($rawText);
                
                // If the WHOIS parser found nothing valid, maybe the domain isn't registered,
                // or we need RDAP fallback.
            } catch (\Exception $e) {
                if (!$preferRdap) {
                    Metrics::record('whois_failed_fallback_rdap', $domain);
                    try {
                        $rdapResult = $this->rdapClient->query($domain);
                        $rdapData = RdapParser::parse($rdapResult);
                        $rawText = json_encode($rdapResult);
                        $usedServer = 'rdap.org';
                    } catch (\Exception $e2) {
                        return [
                            'success' => false,
                            'error' => "All resolution methods failed (WHOIS + RDAP).",
                            'domain' => $originalDomain
                        ];
                    }
                } else {
                    return [
                        'success' => false,
                        'error' => "All resolution methods failed.",
                        'domain' => $originalDomain
                    ];
                }
            }
        }

        $finalData = $rdapData ? $rdapData : ($whoisData ? $whoisData : []);

        $expiresStr = $finalData['expiry_date'] ?? 'N/A';
        $lifecycle = null;

        if ($expiresStr !== 'N/A') {
            $expTs = strtotime($expiresStr);
            if ($expTs) {
                $diff = $expTs - time();
                $lifecycle = [
                    'is_expired' => $diff <= 0,
                    'days_until_expiry' => (int) ceil($diff / 86400)
                ];
            }
        }

        // Bridge Legacy Requirements 
        return [
            'success' => true,
            'domain' => $originalDomain,
            'punycode' => $domain,
            'whois_server' => $usedServer,
            'registrar' => $finalData['registrar'] ?? 'N/A',
            'created_date' => $finalData['created_date'] ?? 'N/A',
            'expiry_date' => $finalData['expiry_date'] ?? 'N/A',
            'updated_date' => $finalData['updated_date'] ?? 'N/A',
            'status' => $finalData['status'] ?? [],
            'nameservers' => $finalData['nameservers'] ?? [],
            'raw' => base64_encode($rawText), // safe transmission

            // Legacy Frontend Hooks
            'created' => $finalData['created_date'] ?? 'N/A',
            'expires' => $finalData['expiry_date'] ?? 'N/A',
            'updated' => $finalData['updated_date'] ?? 'N/A',
            'tld' => $effectiveTld ?: $tld,
            'lifecycle' => $lifecycle
        ];
    }
}
