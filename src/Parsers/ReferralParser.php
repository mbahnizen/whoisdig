<?php
namespace WhoisDig\Parsers;

class ReferralParser
{
    /**
     * Extracts a referral WHOIS server from a raw text payload.
     * @param string $raw
     * @return string|null
     */
    public static function extractServer($raw)
    {
        $patterns = [
            '/Whois Server:\s*(?:whois:\/\/)?([a-z0-9\-\.]+)/i',
            '/ReferralServer:\s*(?:whois:\/\/)?([a-z0-9\-\.]+)/i',
            '/Registrar WHOIS Server:\s*(?:whois:\/\/)?([a-z0-9\-\.]+)/i',
            '/refer:\s*(?:whois:\/\/)?([a-z0-9\-\.]+)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $matches)) {
                $server = trim($matches[1]);
                if (!empty($server)) {
                    return $server;
                }
            }
        }

        return null;
    }
}
