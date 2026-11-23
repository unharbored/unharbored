<?php

declare(strict_types=1);

namespace App\Lib\Gateway;

use Illuminate\Support\Facades\DB;

/**
 * What the gateway is told about organisations: each CA it should accept
 * nodes from, and the node certificates it must refuse. The gateway keeps
 * this in memory only, so it is sent whole, every time.
 */
final class OrganisationSync
{
    public function __construct(private readonly GatewayClient $gateway) {}

    /** @return list<array{id: string, ca_certificate_pem: string, revoked_serials: list<string>}> */
    public function payload(): array
    {
        $revoked = DB::table('revoked_certificates')->get(['organization_id', 'serial'])->groupBy('organization_id');

        return DB::table('organization_certificate_authorities')->orderBy('organization_id')->get()
            ->map(fn ($row) => [
                'id' => $row->organization_id,
                'ca_certificate_pem' => $row->certificate_pem,
                'revoked_serials' => $revoked->get($row->organization_id, collect())->pluck('serial')->values()->all(),
            ])->values()->all();
    }

    /** @return int Organisations the gateway accepted. */
    public function push(): int
    {
        return $this->gateway->syncOrganisations($this->payload());
    }

    /** Lower-case hex without leading zeros, as the gateway compares them. */
    public static function serialOf(string $certificatePem): ?string
    {
        $parsed = openssl_x509_parse($certificatePem);
        if ($parsed === false || ! isset($parsed['serialNumberHex'])) {
            return null;
        }
        $serial = ltrim(strtolower((string) $parsed['serialNumberHex']), '0');

        return $serial === '' ? '0' : $serial;
    }
}
