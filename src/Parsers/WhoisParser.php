<?php
namespace WhoisDig\Parsers;

class WhoisParser
{
    public static function parse($raw)
    {
        return [
            'registrar' => self::extractRegistrar($raw),
            'status' => self::extractStatus($raw),
            'created_date' => self::extractDate($raw, 'created'),
            'updated_date' => self::extractDate($raw, 'updated'),
            'expiry_date' => self::extractDate($raw, 'expires'),
            'nameservers' => self::extractNameservers($raw),
        ];
    }

    private static function extractRegistrar($raw)
    {
        $patterns = [
            '/Registrar\s*:\s*(.+)/i',
            '/Sponsoring Registrar\s*:\s*(.+)/i',
            '/Registrar Name\s*:\s*(.+)/i',
            '/Registrar Organization\s*:\s*(.+)/i',
            '/registrar\s*:\s*(.+)/i',
            '/Registrar Handle\s*:\s*(.+)/i',
            '/\[Registrar\]\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $match)) {
                $val = trim($match[1]);
                if ($val && $val !== '' && strtolower($val) !== 'n/a') {
                    return $val;
                }
            }
        }
        return 'N/A';
    }

    private static function extractStatus($raw)
    {
        preg_match_all('/(?:Domain )?Status\s*:\s*([^\s]+)/i', $raw, $matches);
        if (!empty($matches[1])) {
            return array_values(array_unique(array_map('trim', $matches[1])));
        }
        return [];
    }

    private static function extractDate($raw, $type)
    {
        $patterns = [];

        if ($type === 'created') {
            $patterns = [
                '/Creation Date\s*:\s*(.+)/i',
                '/Created\s*:\s*(.+)/i',
                '/Created Date\s*:\s*(.+)/i',
                '/RegDate\s*:\s*(.+)/i',
                '/Registered on\s*:\s*(.+)/i',
                '/Registered\s*:\s*(.+)/i',
                '/Registration Date\s*:\s*(.+)/i',
                '/Registrar Registration Date\s*:\s*(.+)/i',
                '/Registration Time\s*:\s*(.+)/i',
                '/Domain Name Commencement Date\s*:\s*(.+)/i',
                '/created\s*:\s*(.+)/i',
                '/\[Created on\]\s*(.+)/i',
                '/Activation\s*:\s*(.+)/i',
                '/Domain registered\s*:\s*(.+)/i',
            ];
        } elseif ($type === 'updated') {
            $patterns = [
                '/Updated Date\s*:\s*(.+)/i',
                '/Updated\s*:\s*(.+)/i',
                '/Last Updated on\s*:\s*(.+)/i',
                '/Last Updated\s*:\s*(.+)/i',
                '/Last Modified\s*:\s*(.+)/i',
                '/Last modified\s*:\s*(.+)/i',
                '/Changed\s*:\s*(.+)/i',
                '/changed\s*:\s*(.+)/i',
                '/Modification Date\s*:\s*(.+)/i',
                '/Modified\s*:\s*(.+)/i',
                '/last-update\s*:\s*(.+)/i',
                '/\[Last Updated\]\s*(.+)/i',
            ];
        } elseif ($type === 'expires') {
            $patterns = [
                '/Expiry Date\s*:\s*(.+)/i',
                '/Expiration Date\s*:\s*(.+)/i',
                '/Registry Expiry Date\s*:\s*(.+)/i',
                '/Registrar Registration Expiration Date\s*:\s*(.+)/i',
                '/Expires on\s*:\s*(.+)/i',
                '/Expires\s*:\s*(.+)/i',
                '/Expiration Time\s*:\s*(.+)/i',
                '/Expire Date\s*:\s*(.+)/i',
                '/paid-till\s*:\s*(.+)/i',
                '/Renewal Date\s*:\s*(.+)/i',
                '/Domain Expiration Date\s*:\s*(.+)/i',
                '/\[Expires on\]\s*(.+)/i',
                '/free-date\s*:\s*(.+)/i',
            ];
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $match)) {
                $val = trim($match[1]);
                if ($val && $val !== '' && strtolower($val) !== 'n/a') {
                    return $val;
                }
            }
        }
        return 'N/A';
    }

    private static function extractNameservers($raw)
    {
        $nameservers = [];

        // Standard patterns
        $patterns = [
            '/Name Server\s*:\s*(.+)/i',
            '/Nameservers?\s*:\s*(.+)/i',
            '/nserver\s*:\s*(.+)/i',
            '/DNS\s*:\s*(.+)/i',
            '/Host Name\s*:\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $raw, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $ns) {
                    $ns = trim(strtolower(preg_replace('/\s+.*$/', '', $ns)));
                    if ($ns && filter_var($ns, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                        $nameservers[] = $ns;
                    }
                }
            }
        }

        return array_values(array_unique($nameservers));
    }
}
