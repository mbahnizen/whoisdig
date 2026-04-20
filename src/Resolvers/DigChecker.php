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

    private function parseDigOutput($output)
    {
        // This method is no longer needed but kept for interface compatibility if called elsewhere, 
        // passing through the array if it's already an array.
        if (is_array($output))
            return $output;
        return [];
    }
}
