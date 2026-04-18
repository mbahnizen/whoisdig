<?php
namespace WhoisDig\Utils;

/**
 * TldResolver — Accurate TLD detection using Mozilla's Public Suffix List (PSL).
 * 
 * Resolves effective TLDs like .my.id, .co.uk, .com.br correctly,
 * instead of naively using the last dot-segment.
 */
class TldResolver
{
    private static $suffixes = null;
    private static $pslPath;

    /**
     * Get the effective TLD for a domain.
     * Example: nizen.my.id → my.id, google.co.uk → co.uk, google.com → com
     */
    public static function resolve(string $domain): string
    {
        self::loadPsl();

        $domain = strtolower(trim($domain));
        $parts = explode('.', $domain);
        $numParts = count($parts);

        if ($numParts < 2) {
            return $domain;
        }

        // Walk from longest possible suffix to shortest.
        // e.g. for "nizen.my.id" check: "nizen.my.id", "my.id", "id"
        for ($i = 0; $i < $numParts; $i++) {
            $candidate = implode('.', array_slice($parts, $i));

            // Check exact match
            if (isset(self::$suffixes[$candidate])) {
                return $candidate;
            }

            // Check wildcard rule: *.my.id matches any prefix
            $wildcard = '*.' . implode('.', array_slice($parts, $i + 1));
            if (isset(self::$suffixes[$wildcard])) {
                // Check for exception rule (e.g., !www.ck)
                $exception = '!' . $candidate;
                if (isset(self::$suffixes[$exception])) {
                    continue;
                }
                return $candidate;
            }
        }

        // Fallback: return last segment
        return end($parts);
    }

    /**
     * Get the IANA-queryable TLD (always the rightmost label).
     * IANA WHOIS only knows about root TLDs like "id", "uk", "com".
     */
    public static function rootTld(string $domain): string
    {
        $parts = explode('.', strtolower(trim($domain)));
        return end($parts);
    }

    /**
     * Load and parse the Public Suffix List.
     * Downloads from Mozilla if not cached locally, then caches for 7 days.
     */
    private static function loadPsl(): void
    {
        if (self::$suffixes !== null) {
            return;
        }

        self::$pslPath = __DIR__ . '/../../storage/cache/public_suffix_list.dat';
        $maxAge = 7 * 86400; // 7 days

        // Download if missing or stale
        if (!file_exists(self::$pslPath) || (time() - filemtime(self::$pslPath)) > $maxAge) {
            self::downloadPsl();
        }

        self::$suffixes = [];

        if (!file_exists(self::$pslPath)) {
            // If download failed, use a hardcoded minimal list of common multi-level TLDs
            self::loadFallbackList();
            return;
        }

        $lines = file(self::$pslPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments and empty lines
            if ($line === '' || strpos($line, '//') === 0) {
                continue;
            }
            // Convert IDN to ASCII if needed
            if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $line)) {
                $ascii = idn_to_ascii($line, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if ($ascii !== false) {
                    $line = $ascii;
                }
            }
            self::$suffixes[strtolower($line)] = true;
        }
    }

    private static function downloadPsl(): void
    {
        $url = 'https://publicsuffix.org/list/public_suffix_list.dat';
        $dir = dirname(self::$pslPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WHOISDIG-PSL-Updater/1.0');
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 200 && strlen($data) > 1000) {
            file_put_contents(self::$pslPath, $data);
        }
    }

    /**
     * Fallback list of well-known multi-level TLDs in case PSL download fails.
     */
    private static function loadFallbackList(): void
    {
        $common = [
            // Indonesia
            'co.id', 'or.id', 'ac.id', 'web.id', 'my.id', 'biz.id', 'sch.id', 'go.id', 'mil.id', 'net.id',
            // UK
            'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'net.uk', 'me.uk',
            // Australia
            'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
            // Brazil
            'com.br', 'net.br', 'org.br', 'gov.br',
            // Japan
            'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp',
            // India
            'co.in', 'net.in', 'org.in', 'gov.in', 'ac.in',
            // New Zealand
            'co.nz', 'net.nz', 'org.nz', 'govt.nz', 'ac.nz',
            // South Africa
            'co.za', 'net.za', 'org.za', 'gov.za', 'ac.za',
            // Others
            'com.sg', 'com.my', 'com.ph', 'com.tw', 'com.hk', 'com.cn',
            'co.kr', 'co.th', 'co.ke', 'co.tz',
            'com.ar', 'com.mx', 'com.co', 'com.pe', 'com.ve',
            'com.tr', 'com.sa', 'com.eg', 'com.ng',
            'com.ua', 'com.ru', 'org.ru',
        ];

        foreach ($common as $suffix) {
            self::$suffixes[$suffix] = true;
        }
    }
}
