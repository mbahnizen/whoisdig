<?php
namespace WhoisDig\Parsers;

class RdapParser
{
    public static function parse($data)
    {
        // Guard: RDAP error responses (e.g. 404 Not Found) should not be parsed
        if (!$data || isset($data['errorCode'])) {
            return null;
        }

        $events = [];
        if (isset($data['events'])) {
            foreach ($data['events'] as $event) {
                $events[$event['eventAction']] = $event['eventDate'];
            }
        }

        $created = $events['registration'] ?? $events['last changed'] ?? 'N/A';
        $updated = $events['last changed'] ?? 'N/A';
        $expires = $events['expiration'] ?? 'N/A';

        $fmtDate = function ($d) {
            if ($d === 'N/A') return 'N/A';
            return date('Y-m-d\TH:i:s\Z', strtotime($d));
        };

        $nameservers = [];
        if (isset($data['nameservers'])) {
            foreach ($data['nameservers'] as $ns) {
                if (isset($ns['ldhName'])) {
                    $nameservers[] = $ns['ldhName'];
                }
            }
        }

        $registrar = 'N/A';
        if (isset($data['entities'])) {
            // First pass: look for registrar
            foreach ($data['entities'] as $entity) {
                if (isset($entity['roles']) && in_array('registrar', $entity['roles'])) {
                    if (isset($entity['vcardArray'][1])) {
                        foreach ($entity['vcardArray'][1] as $vcardItem) {
                            if ($vcardItem[0] === 'fn') {
                                $registrar = $vcardItem[3];
                            }
                        }
                    }
                }
            }
            
            // Second pass: if no registrar, look for registrant (common for IPs)
            if ($registrar === 'N/A') {
                foreach ($data['entities'] as $entity) {
                    if (isset($entity['roles']) && in_array('registrant', $entity['roles'])) {
                        if (isset($entity['vcardArray'][1])) {
                            foreach ($entity['vcardArray'][1] as $vcardItem) {
                                if ($vcardItem[0] === 'fn') {
                                    $registrar = $vcardItem[3];
                                }
                            }
                        }
                    }
                }
            }
        }

        return [
            'registrar' => $registrar,
            'status' => $data['status'] ?? [],
            'created_date' => $fmtDate($created),
            'updated_date' => $fmtDate($updated),
            'expiry_date' => $fmtDate($expires),
            'nameservers' => $nameservers,
        ];
    }

    /**
     * Parse RDAP response for an IP network.
     * IP RDAP has a completely different structure from domain RDAP.
     */
    public static function parseIp($data)
    {
        if (!$data || isset($data['errorCode'])) {
            return null;
        }

        $fmtDate = function ($d) {
            if (!$d || $d === 'N/A') return 'N/A';
            return date('Y-m-d\TH:i:s\Z', strtotime($d));
        };

        // Network info
        $name = $data['name'] ?? 'N/A';
        $handle = $data['handle'] ?? 'N/A';
        $startAddress = $data['startAddress'] ?? 'N/A';
        $endAddress = $data['endAddress'] ?? 'N/A';
        $ipVersion = $data['ipVersion'] ?? 'N/A';
        $type = $data['type'] ?? 'N/A';
        $country = $data['country'] ?? 'N/A';
        $port43 = $data['port43'] ?? 'N/A';
        $parentHandle = $data['parentHandle'] ?? 'N/A';
        $status = $data['status'] ?? [];

        // CIDR notation
        $cidr = 'N/A';
        if (isset($data['cidr0_cidrs']) && is_array($data['cidr0_cidrs'])) {
            $cidrs = [];
            foreach ($data['cidr0_cidrs'] as $c) {
                $prefix = $c['v4prefix'] ?? $c['v6prefix'] ?? null;
                $length = $c['length'] ?? null;
                if ($prefix && $length !== null) {
                    $cidrs[] = $prefix . '/' . $length;
                }
            }
            if (!empty($cidrs)) {
                $cidr = implode(', ', $cidrs);
            }
        }

        // Events (registration, last changed)
        $events = [];
        if (isset($data['events'])) {
            foreach ($data['events'] as $event) {
                if (isset($event['eventAction'], $event['eventDate'])) {
                    $events[$event['eventAction']] = $event['eventDate'];
                }
            }
        }
        $registration = $events['registration'] ?? 'N/A';
        $lastChanged = $events['last changed'] ?? 'N/A';

        // Extract entities using PRIORITY SYSTEM
        // Priority: registrant → administrative → technical → abuse → first available
        $organization = 'N/A';
        $orgAddress = 'N/A';
        $abuseContact = null;

        if (isset($data['entities']) && is_array($data['entities'])) {
            $rolePriority = ['registrant', 'administrative', 'technical', 'abuse'];
            $entityByRole = [];
            $firstEntity = null;

            foreach ($data['entities'] as $entity) {
                $roles = $entity['roles'] ?? [];
                if (!$firstEntity && isset($entity['vcardArray'][1])) {
                    $firstEntity = $entity;
                }
                foreach ($roles as $role) {
                    $entityByRole[$role] = $entity;
                }

                // Look for abuse contact at top-level entities
                if (in_array('abuse', $roles) && isset($entity['vcardArray'][1])) {
                    $abuse = ['handle' => $entity['handle'] ?? 'N/A'];
                    foreach ($entity['vcardArray'][1] as $vcardItem) {
                        if ($vcardItem[0] === 'fn') $abuse['name'] = $vcardItem[3];
                        if ($vcardItem[0] === 'email') $abuse['email'] = $vcardItem[3];
                        if ($vcardItem[0] === 'tel') $abuse['phone'] = $vcardItem[3];
                    }
                    $abuseContact = $abuse;
                }

                // Also look for abuse in nested entities
                if (isset($entity['entities']) && is_array($entity['entities'])) {
                    foreach ($entity['entities'] as $subEntity) {
                        $subRoles = $subEntity['roles'] ?? [];
                        if (in_array('abuse', $subRoles) && isset($subEntity['vcardArray'][1])) {
                            $abuse = ['handle' => $subEntity['handle'] ?? 'N/A'];
                            foreach ($subEntity['vcardArray'][1] as $vcardItem) {
                                if ($vcardItem[0] === 'fn') $abuse['name'] = $vcardItem[3];
                                if ($vcardItem[0] === 'email') $abuse['email'] = $vcardItem[3];
                                if ($vcardItem[0] === 'tel') $abuse['phone'] = $vcardItem[3];
                            }
                            if (!$abuseContact) $abuseContact = $abuse;
                        }
                    }
                }
            }

            // Find best entity for organization info by priority
            $bestEntity = null;
            foreach ($rolePriority as $targetRole) {
                if (isset($entityByRole[$targetRole])) {
                    $bestEntity = $entityByRole[$targetRole];
                    break;
                }
            }
            if (!$bestEntity) $bestEntity = $firstEntity;

            // Extract organization name and address from best entity
            if ($bestEntity && isset($bestEntity['vcardArray'][1])) {
                foreach ($bestEntity['vcardArray'][1] as $vcardItem) {
                    if ($vcardItem[0] === 'fn' && $organization === 'N/A') {
                        $organization = $vcardItem[3];
                    }
                    if ($vcardItem[0] === 'org') {
                        // org field is more authoritative than fn
                        $organization = $vcardItem[3];
                    }
                    if ($vcardItem[0] === 'adr' && isset($vcardItem[1]['label'])) {
                        $orgAddress = $vcardItem[1]['label'];
                    }
                }
            }
        }

        return [
            'network_name' => $name,
            'handle' => $handle,
            'start_address' => $startAddress,
            'end_address' => $endAddress,
            'cidr' => $cidr,
            'ip_version' => $ipVersion,
            'type' => $type,
            'country' => $country,
            'port43' => $port43,
            'parent_handle' => $parentHandle,
            'status' => $status,
            'registration' => $fmtDate($registration),
            'last_changed' => $fmtDate($lastChanged),
            'organization' => $organization,
            'org_address' => $orgAddress,
            'abuse_contact' => $abuseContact,
        ];
    }
}
