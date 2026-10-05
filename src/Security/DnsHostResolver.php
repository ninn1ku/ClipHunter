<?php

declare(strict_types=1);

namespace ClipHunter\Security;

/**
 * Resolves A and AAAA records with the system resolver.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            // Some environments lack AAAA support in dns_get_record(); fall back to IPv4 lookup.
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }
}
