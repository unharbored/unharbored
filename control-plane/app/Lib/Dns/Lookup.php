<?php

declare(strict_types=1);

namespace App\Lib\Dns;

/** What a name resolves to in public DNS: its A and AAAA records, so a server reached only over IPv6 counts too. */
final class Lookup
{
    /** @return list<string> */
    public static function addresses(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ipv6'];
        }

        return $ips;
    }
}
