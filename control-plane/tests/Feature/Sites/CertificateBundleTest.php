<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Sites\CertificateBundle;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Tests\TestCase;

class CertificateBundleTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_valid_chain_returns_facts(): void
    {
        $ca = $this->issue('Test Root', [], null, 365, true);
        $int = $this->issue('Test Intermediate', [], $ca, 365, true);
        $leaf = $this->issue('app.example.com', ['app.example.com', 'www.example.com'], $int);

        $chain = $leaf['cert']."\r\n\r\n".$int['cert'];
        $r = CertificateBundle::check("  {$chain}  \r\n", $leaf['key'], 'APP.example.com.');

        $this->assertSame(2, $r['cert_count']);
        $this->assertSame('Test Intermediate', $r['issuer']);
        $this->assertSame(['app.example.com', 'www.example.com'], $r['names']);
        $this->assertSame([], $r['warnings']);
        $this->assertTrue($r['expires_at']->isFuture());
        $this->assertSame($leaf['cert']."\n".$int['cert']."\n", $r['fullchain']);
        $this->assertStringEndsWith("\n", $r['key']);
        $this->assertStringNotContainsString("\r", $r['fullchain']);
    }

    public function test_rejects_text_without_certificate(): void
    {
        $leaf = $this->issue('a.example.com', ['a.example.com']);
        $this->assertRejected('No certificate found', 'hello', $leaf['key'], 'a.example.com');
    }

    public function test_rejects_unparseable_certificate(): void
    {
        $leaf = $this->issue('a.example.com', ['a.example.com']);
        $bad = "-----BEGIN CERTIFICATE-----\nbm90IGEgY2VydA==\n-----END CERTIFICATE-----";
        $this->assertRejected('could not be read', $bad, $leaf['key'], 'a.example.com');
    }

    public function test_rejects_encrypted_key(): void
    {
        $leaf = $this->issue('a.example.com', ['a.example.com']);
        $pkey = openssl_pkey_get_private($leaf['key']);
        openssl_pkey_export($pkey, $enc, 'secret');
        $this->assertStringContainsString('ENCRYPTED PRIVATE KEY', $enc);
        $this->assertRejected('without a passphrase', $leaf['cert'], $enc, 'a.example.com');

        $legacy = "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,00\n\nAAAA\n-----END RSA PRIVATE KEY-----";
        $this->assertRejected('without a passphrase', $leaf['cert'], $legacy, 'a.example.com');
    }

    public function test_rejects_non_private_key_input(): void
    {
        $leaf = $this->issue('a.example.com', ['a.example.com']);
        $this->assertRejected('not a private key', $leaf['cert'], $leaf['cert'], 'a.example.com');

        $public = openssl_pkey_get_details(openssl_pkey_get_private($leaf['key']))['key'];
        $this->assertRejected('not a private key', $leaf['cert'], $public, 'a.example.com');
    }

    public function test_exact_and_san_matching(): void
    {
        $leaf = $this->issue('ignored-cn.example.com', ['a.example.com']);
        $r = CertificateBundle::check($leaf['cert'], $leaf['key'], 'a.example.com');
        $this->assertSame(['a.example.com'], $r['names']);

        // SAN present: the CN is not consulted.
        $this->assertRejected('does not cover', $leaf['cert'], $leaf['key'], 'ignored-cn.example.com');
        $this->assertRejected('does not cover', $leaf['cert'], $leaf['key'], 'other.example.com');
    }

    public function test_falls_back_to_cn_without_san(): void
    {
        $leaf = $this->issue('cn-only.example.com', []);
        $r = CertificateBundle::check($leaf['cert'], $leaf['key'], 'CN-Only.example.com');
        $this->assertSame(['cn-only.example.com'], $r['names']);
    }

    public function test_wildcard_matches_one_label_only(): void
    {
        $leaf = $this->issue('*.example.com', ['*.example.com']);
        CertificateBundle::check($leaf['cert'], $leaf['key'], 'a.example.com');

        $this->assertRejected('does not cover', $leaf['cert'], $leaf['key'], 'a.b.example.com');
        $this->assertRejected('does not cover', $leaf['cert'], $leaf['key'], 'example.com');
    }

    public function test_supports_ec_keys(): void
    {
        $leaf = $this->issue('ec.example.com', ['ec.example.com'], null, 90, false, 'ec');
        $r = CertificateBundle::check($leaf['cert'], $leaf['key'], 'ec.example.com');
        $this->assertSame(1, $r['cert_count']);
    }

    public function test_rejects_mismatched_key(): void
    {
        $a = $this->issue('a.example.com', ['a.example.com']);
        $b = $this->issue('a.example.com', ['a.example.com']);
        $this->assertRejected('does not match', $a['cert'], $b['key'], 'a.example.com');
    }

    public function test_rejects_expired_certificate(): void
    {
        // days = 0 gives a certificate whose notAfter is now, so it is already expired.
        $leaf = $this->issue('a.example.com', ['a.example.com'], null, 0);
        $this->assertRejected('expired', $leaf['cert'], $leaf['key'], 'a.example.com');
    }

    public function test_warns_when_expiring_soon(): void
    {
        $leaf = $this->issue('a.example.com', ['a.example.com'], null, 10);
        $r = CertificateBundle::check($leaf['cert'], $leaf['key'], 'a.example.com');
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'expires on')));

        $far = $this->issue('a.example.com', ['a.example.com'], null, 60);
        $r = CertificateBundle::check($far['cert'], $far['key'], 'a.example.com');
        $this->assertFalse(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'expires on')));
    }

    public function test_warns_when_only_leaf_is_present(): void
    {
        $leaf = $this->issue('a.example.com', ['a.example.com']);
        $r = CertificateBundle::check($leaf['cert'], $leaf['key'], 'a.example.com');
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'no intermediates')));
    }

    public function test_warns_when_chain_order_is_wrong_but_accepts(): void
    {
        $ca = $this->issue('Test Root', [], null, 365, true);
        $int = $this->issue('Test Intermediate', [], $ca, 365, true);
        $leaf = $this->issue('a.example.com', ['a.example.com'], $int);
        $unrelated = $this->issue('Unrelated', [], null, 365, true);

        $r = CertificateBundle::check($leaf['cert']."\n".$unrelated['cert'], $leaf['key'], 'a.example.com');
        $this->assertSame(2, $r['cert_count']);
        $this->assertTrue(collect($r['warnings'])->contains(fn ($w) => str_contains($w, 'chain order')));
    }

    public function test_error_messages_never_contain_the_key(): void
    {
        $a = $this->issue('a.example.com', ['a.example.com']);
        $b = $this->issue('a.example.com', ['a.example.com']);
        try {
            CertificateBundle::check($a['cert'], $b['key'], 'a.example.com');
            $this->fail('Expected rejection');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    private function assertRejected(string $fragment, string $chain, string $key, string $domain): void
    {
        try {
            CertificateBundle::check($chain, $key, $domain);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());

            return;
        }
        $this->fail("Expected rejection containing '{$fragment}'");
    }

    /**
     * Build a certificate signed by $issuer (or self-signed).
     *
     * @param  list<string>  $sans
     * @param  array{cert: string, key: string, x509: OpenSSLCertificate, pkey: OpenSSLAsymmetricKey}|null  $issuer
     * @return array{cert: string, key: string, x509: OpenSSLCertificate, pkey: OpenSSLAsymmetricKey}
     */
    private function issue(string $cn, array $sans, ?array $issuer = null, int $days = 365, bool $ca = false, string $type = 'rsa'): array
    {
        $ext = 'basicConstraints = CA:'.($ca ? 'TRUE' : 'FALSE')."\n";
        if ($sans !== []) {
            $ext .= 'subjectAltName = '.implode(',', array_map(fn ($s) => "DNS:{$s}", $sans))."\n";
        }
        $conf = tempnam(sys_get_temp_dir(), 'cbt');
        $this->tmp[] = $conf;
        file_put_contents($conf, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n[ext]\n{$ext}");

        $opts = $type === 'ec'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $pkey = openssl_pkey_new($opts + ['config' => $conf]);
        $csr = openssl_csr_new(['commonName' => $cn], $pkey, ['config' => $conf, 'digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign(
            $csr,
            $issuer['x509'] ?? null,
            $issuer['pkey'] ?? $pkey,
            $days,
            ['config' => $conf, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'],
            random_int(1, PHP_INT_MAX),
        );
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($pkey, $keyPem, null, ['config' => $conf]);

        return ['cert' => $certPem, 'key' => $keyPem, 'x509' => $x509, 'pkey' => $pkey];
    }
}
