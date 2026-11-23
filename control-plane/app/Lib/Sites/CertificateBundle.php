<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Validates an uploaded certificate chain and private key for a site.
 * Pure: no database, no filesystem. Throws InvalidArgumentException with a
 * user-facing message; the key is never included in a message.
 */
final class CertificateBundle
{
    private const EXPIRY_WARNING_DAYS = 21;

    /**
     * @return array{
     *     expires_at: CarbonImmutable,
     *     issuer: string,
     *     names: list<string>,
     *     cert_count: int,
     *     fullchain: string,
     *     key: string,
     *     warnings: list<string>,
     * }
     */
    public static function check(string $chain, string $key, string $domain): array
    {
        $certs = self::splitCertificates($chain);
        $parsed = [];
        foreach ($certs as $pem) {
            $info = openssl_x509_parse($pem);
            if ($info === false) {
                throw new InvalidArgumentException('One of the certificates in the chain could not be read. Check that it is a valid PEM certificate.');
            }
            $parsed[] = $info;
        }

        $leaf = $parsed[0];
        $names = self::namesFor($leaf);

        if (! self::covers($names, $domain)) {
            $list = $names === [] ? 'no names' : implode(', ', $names);
            throw new InvalidArgumentException("This certificate does not cover {$domain}. It covers: {$list}.");
        }

        $keyPem = self::normaliseKey($key);
        $privateKey = openssl_pkey_get_private($keyPem);
        if ($privateKey === false) {
            throw new InvalidArgumentException('The private key could not be read. Check that it is a valid unencrypted PEM private key.');
        }
        if (! openssl_x509_check_private_key($certs[0], $privateKey)) {
            throw new InvalidArgumentException('The private key does not match the certificate.');
        }

        $expiresAt = CarbonImmutable::createFromTimestampUTC((int) $leaf['validTo_time_t']);
        $now = CarbonImmutable::now();
        if ($expiresAt->lessThanOrEqualTo($now)) {
            throw new InvalidArgumentException('This certificate expired on '.$expiresAt->toDateString().'.');
        }

        $warnings = [];
        if ($expiresAt->lessThan($now->addDays(self::EXPIRY_WARNING_DAYS))) {
            $warnings[] = 'This certificate expires on '.$expiresAt->toDateString().', less than '.self::EXPIRY_WARNING_DAYS.' days away.';
        }
        if (count($certs) === 1) {
            $warnings[] = 'The chain contains only the certificate itself, with no intermediates. Some clients may fail to trust it.';
        }
        for ($i = 0; $i < count($parsed) - 1; $i++) {
            if (self::dn($parsed[$i]['issuer'] ?? []) !== self::dn($parsed[$i + 1]['subject'] ?? [])) {
                $warnings[] = 'The certificates do not appear to be in chain order (leaf first, then each issuer).';
                break;
            }
        }

        $issuer = $leaf['issuer'] ?? [];

        return [
            'expires_at' => $expiresAt,
            'issuer' => (string) ($issuer['CN'] ?? $issuer['O'] ?? ''),
            'names' => $names,
            'cert_count' => count($certs),
            'fullchain' => implode("\n", $certs)."\n",
            'key' => $keyPem,
            'warnings' => $warnings,
        ];
    }

    /** @return list<string> normalised PEM blocks, each ending with a newline */
    private static function splitCertificates(string $chain): array
    {
        $chain = self::normalise($chain);
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $chain, $m);
        if ($m[0] === []) {
            throw new InvalidArgumentException('No certificate found. Paste the certificate in PEM format, starting with -----BEGIN CERTIFICATE-----.');
        }

        return array_map(fn (string $pem) => rtrim($pem)."\n", $m[0]);
    }

    private static function normaliseKey(string $key): string
    {
        $key = self::normalise($key);
        if (str_contains($key, 'ENCRYPTED PRIVATE KEY') || str_contains($key, 'Proc-Type: 4,ENCRYPTED')) {
            throw new InvalidArgumentException('This private key is protected by a passphrase. Upload a key without a passphrase.');
        }
        if (! preg_match('/-----BEGIN (?:RSA |EC |)PRIVATE KEY-----.+?-----END (?:RSA |EC |)PRIVATE KEY-----/s', $key, $m)) {
            throw new InvalidArgumentException('That is not a private key. Paste the private key in PEM format, starting with -----BEGIN PRIVATE KEY-----.');
        }

        return rtrim($m[0])."\n";
    }

    private static function normalise(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /** @return list<string> lower-case names the leaf is valid for */
    private static function namesFor(array $leaf): array
    {
        $names = [];
        foreach (explode(',', (string) ($leaf['extensions']['subjectAltName'] ?? '')) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $names[] = self::clean(substr($part, 4));
            }
        }
        if ($names === [] && isset($leaf['subject']['CN'])) {
            $cn = $leaf['subject']['CN'];
            $names[] = self::clean(is_array($cn) ? (string) $cn[0] : (string) $cn);
        }

        return array_values(array_unique(array_filter($names)));
    }

    private static function covers(array $names, string $domain): bool
    {
        $domain = self::clean($domain);
        foreach ($names as $name) {
            if ($name === $domain) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1); // ".example.com"
                $label = substr($domain, 0, -strlen($suffix));
                if (str_ends_with($domain, $suffix) && $label !== '' && ! str_contains($label, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function clean(string $name): string
    {
        return rtrim(strtolower(trim($name)), '.');
    }

    private static function dn(array $parts): string
    {
        ksort($parts);

        return json_encode($parts);
    }
}
