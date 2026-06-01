<?php
// dig.php - Dig Lookup Class

namespace WhoisDig\Resolvers;

use Exception;

class DigChecker
{
    public function lookup($target, $recordType = 'A')
    {
        $target = sanitizeInput($target);

        // Check if input is IP or Domain
        $isIP = filter_var($target, FILTER_VALIDATE_IP);
        $isValidDomain = isValidDomain($target);

        if (!$isIP && !$isValidDomain) {
            return [
                'success' => false,
                'error' => 'Input tidak valid (Bukan Domain atau IP)',
                'domain' => $target
            ];
        }


        // Special Case: PTR lookup on IP address
        if ($isIP && $recordType === 'PTR') {
            // Phase 1: IPv6 PTR not yet supported
            if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return [
                    'success' => false,
                    'error' => 'IPv6 PTR lookup belum didukung saat ini.',
                    'domain' => $target
                ];
            }
            // IPv4: Convert to in-addr.arpa
            $octets = explode('.', $target);
            $target = implode('.', array_reverse($octets)) . '.in-addr.arpa';
        }

        $recordType = strtoupper($recordType);
        $validRecords = ['A', 'AAAA', 'MX', 'NS', 'CNAME', 'TXT', 'SOA', 'SRV', 'PTR', 'ANY'];

        if (!in_array($recordType, $validRecords)) {
            return [
                'success' => false,
                'error' => 'Tipe record tidak valid',
                'valid_types' => $validRecords
            ];
        }

        try {
            $result = $this->executeDig($target, $recordType);
            return [
                'success' => true,
                'domain' => $target,
                'record_type' => $recordType,
                'results' => $this->parseDigOutput($result),
                'raw_output' => $result
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'domain' => $target
            ];
        }
    }

    private function executeDig($domain, $recordType)
    {
        try {
            return $this->executeDoh($domain, $recordType);
        } catch (Exception $dohError) {
            if (!function_exists('dns_get_record')) {
                throw $dohError;
            }
        }

        return $this->executeNativeDns($domain, $recordType);
    }

    private function executeDoh($domain, $recordType)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('DNS-over-HTTPS requires the PHP cURL extension.');
        }

        $providers = [
            'https://cloudflare-dns.com/dns-query?name=' . rawurlencode($domain) . '&type=' . rawurlencode($recordType),
            'https://dns.google/resolve?name=' . rawurlencode($domain) . '&type=' . rawurlencode($recordType),
        ];

        $lastError = null;
        foreach ($providers as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/dns-json',
                'User-Agent: WHOISDIG/0.2 DNS-over-HTTPS'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode < 200 || $httpCode >= 300) {
                $lastError = $curlError ?: "DoH HTTP {$httpCode}";
                continue;
            }

            $data = json_decode($response, true);
            if (!is_array($data)) {
                $lastError = 'Invalid DoH JSON response';
                continue;
            }

            return $this->parseDohResponse($data, $recordType);
        }

        throw new Exception('Gagal mengambil DNS record via DNS-over-HTTPS' . ($lastError ? ": {$lastError}" : ''));
    }

    private function executeNativeDns($domain, $recordType)
    {
        // BUG-3 FIX: Validate constant exists before using it
        $constName = 'DNS_' . $recordType;
        if (!defined($constName)) {
            throw new Exception("DNS record type '{$recordType}' is not supported on this platform.");
        }
        $typeConst = constant($constName);
        $records = @dns_get_record($domain, $typeConst);

        if ($records === false) {
            throw new Exception("Gagal mengambil DNS record");
        }

        $results = [];
        foreach ($records as $r) {
            switch ($r['type']) {
                case 'A':
                    $results[] = $r['ip'];
                    break;
                case 'AAAA':
                    $results[] = $r['ipv6'];
                    break;
                case 'MX':
                    $results[] = $r['pri'] . ' ' . $r['target'];
                    break;
                case 'NS':
                case 'CNAME':
                case 'PTR':
                    $results[] = $r['target'];
                    break;
                case 'TXT':
                    $results[] = '"' . $r['txt'] . '"';
                    break;
                case 'SOA':
                    $results[] = $r['mname'] . ' ' . $r['rname'] . ' ' . $r['serial'];
                    break;
                case 'SRV':
                    $results[] = $r['pri'] . ' ' . $r['weight'] . ' ' . $r['port'] . ' ' . $r['target'];
                    break;
                default:
                    // Try to find any useful field
                    $results[] = json_encode($r);
            }
        }

        return empty($results) ? [] : $results;
    }

    private function parseDohResponse($data, $recordType)
    {
        $answers = $data['Answer'] ?? [];
        if (empty($answers) && isset($data['Authority'])) {
            $answers = $data['Authority'];
        }

        if (!is_array($answers)) {
            return [];
        }

        $expectedType = $this->recordTypeNumber($recordType);
        $results = [];

        foreach ($answers as $answer) {
            if (!isset($answer['type'], $answer['data'])) {
                continue;
            }

            $type = (int) $answer['type'];
            if ($recordType !== 'ANY' && $expectedType !== null && $type !== $expectedType && $type !== 5) {
                continue;
            }

            $value = trim($answer['data']);
            if ($type === 16 && $value !== '' && $value[0] !== '"') {
                $value = '"' . $value . '"';
            }

            $results[] = $value;
        }

        return array_values(array_unique($results));
    }

    private function recordTypeNumber($recordType)
    {
        $types = [
            'A' => 1,
            'NS' => 2,
            'CNAME' => 5,
            'SOA' => 6,
            'PTR' => 12,
            'MX' => 15,
            'TXT' => 16,
            'AAAA' => 28,
            'SRV' => 33,
            'ANY' => 255,
        ];

        return $types[$recordType] ?? null;
    }

    private function parseDigOutput($output)
    {
        // This method is no longer needed but kept for interface compatibility if called elsewhere, 
        // passing through the array if it's already an array.
        if (is_array($output))
            return $output;
        return [];
    }
}
