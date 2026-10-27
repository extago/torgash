<?php

namespace App\Core\Http;

/**
 * Every address a host name resolves to, IPv4 and IPv6. A class of its own so
 * a test can answer for a made-up host instead of asking the real DNS.
 */
class HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
