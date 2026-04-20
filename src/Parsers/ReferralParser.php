<?php
namespace WhoisDig\Parsers;

class ReferralParser
{
    /**
     * Extracts a referral WHOIS server from a raw text payload.
     * Supports both traditional whois:// and modern HTTPS RDAP URLs.
     * 
     * @param string $raw
     * @return string|null Server hostname or full RDAP URL
     */
    public static function extractServer($raw)
    {
        // Traditional WHOIS referral patterns (return hostname only)
        $whoisPatterns = [
            '/Whois Server:\s*(?:whois:\/\/)?([a-z0-9\-\.]+)/i',
            '/ReferralServer:\s*(?:whois:\/\/)?([a-z0-9\-\.:]+)/i',
            '/Registrar WHOIS Server:\s*(?:whois:\/\/)?([a-z0-9\-\.]+)/i',
            '/refer:\s*(?:whois:\/\/)?([a-z0-9\-\.]+)/i'
        ];

        foreach ($whoisPatterns as $pattern) {
            if (preg_match($pattern, $raw, $matches)) {
                $server = trim($matches[1]);
                if (!empty($server)) {
                    // If it looks like an HTTP URL was captured, extract and return full URL
                    if (str_starts_with($server, 'http')) {
                        return $server;
                    }
                    return $server;
                }
            }
        }

        // RDAP URL referral patterns (return full URL for RDAP processing)
        $rdapPatterns = [
            '/Whois Server:\s*(https?:\/\/[^\s]+)/i',
            '/ReferralServer:\s*(https?:\/\/[^\s]+)/i',
            '/Registrar WHOIS Server:\s*(https?:\/\/[^\s]+)/i',
        ];

        foreach ($rdapPatterns as $pattern) {
            if (preg_match($pattern, $raw, $matches)) {
                $url = trim($matches[1]);
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    return $url;
                }
            }
        }

        return null;
    }
}
