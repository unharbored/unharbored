<?php

declare(strict_types=1);

namespace App\Lib\Pki;

use App\Lib\Pki\Exceptions\InvalidCsr;
use App\Lib\Pki\Exceptions\PkiException;
use DateTimeImmutable;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\File\X509;
use SensitiveParameter;
use Throwable;

/**
 * Issues node certificates for one organisation.
 *
 * This is the PHP counterpart of internal/pki in the Go agent, and the two
 * must agree exactly. The security property that matters is stated once in
 * both places: the common name on an issued certificate comes from the
 * identity the control plane assigned, never from the certificate signing
 * request. A node asking to be called something else does not get it.
 *
 * Implemented with phpseclib rather than PHP's native OpenSSL binding.
 * openssl_csr_sign() signs a request with the subject the request carries and
 * offers no way to replace it, which would make the property above
 * unenforceable: the only alternative would be to reject any CSR whose name
 * disagreed, and the agent does not know its assigned name when it builds
 * one. See docs/adr/0004-ecdsa-p256.md.
 */
final class CertificateAuthority
{
    /**
     * Thirty days. Short on purpose: a leaked node key stops being useful
     * quickly, and constant renewal means the renewal path is exercised
     * rather than discovered broken during an incident.
     */
    public const NODE_VALIDITY_DAYS = 30;

    public const CA_VALIDITY_DAYS = 3650;

    /**
     * Backdate NotBefore so a node whose clock runs fast does not reject a
     * certificate issued moments ago. Freshly booted droplets commonly have
     * a wrong clock until NTP settles.
     */
    private const CLOCK_SKEW_MINUTES = 5;

    private function __construct(
        private readonly X509 $caCert,
        private readonly PrivateKey $caKey,
        private readonly string $caCertPem,
    ) {}

    /**
     * Create a new organisation CA.
     */
    public static function create(string $commonName): self
    {
        $key = EC::createKey('secp256r1');

        $subject = new X509();
        $subject->setPublicKey($key->getPublicKey());
        $subject->setDN(['CN' => $commonName]);

        $issuer = new X509();
        $issuer->setPrivateKey($key);
        $issuer->setDN(['CN' => $commonName]);

        $x509 = new X509();
        $x509->makeCA();
        $x509->setStartDate(self::startDate());
        $x509->setEndDate('+'.self::CA_VALIDITY_DAYS.' days');
        $x509->setSerialNumber(self::serialNumber(), 10);

        $signed = $x509->sign($issuer, $subject);

        if ($signed === false) {
            throw new PkiException('Could not self-sign the certificate authority.');
        }

        $pem = $x509->saveX509($signed);

        return new self(self::loadCertificate($pem), $key, $pem);
    }

    /**
     * Reconstruct a CA from stored material.
     */
    public static function load(string $certPem, #[SensitiveParameter] string $keyPem): self
    {
        try {
            $key = EC::loadPrivateKey($keyPem);
        } catch (Throwable $e) {
            throw new PkiException('Could not load the CA private key.', previous: $e);
        }

        if (! $key instanceof PrivateKey) {
            throw new PkiException('The CA key is not an EC private key.');
        }

        return new self(self::loadCertificate($certPem), $key, $certPem);
    }

    public function certificatePem(): string
    {
        return $this->caCertPem;
    }

    /**
     * Export the CA private key so it can be placed in the vault.
     *
     * The only way out of this object. Callers must hand the result straight
     * to the vault and must never log, return, or persist it elsewhere: this
     * key mints identities for every node in an organisation.
     */
    public function exportPrivateKey(): string
    {
        return $this->caKey->toString('PKCS8');
    }

    /**
     * Sign a node certificate.
     *
     * Only the public key and the proof of possession are taken from the
     * request. $nodeId is what ends up in the certificate.
     */
    public function issueNodeCertificate(string $csrPem, string $nodeId): string
    {
        if (trim($nodeId) === '') {
            throw new PkiException('A node id is required; a certificate with no identity authenticates nothing.');
        }

        $publicKey = $this->publicKeyFromCsr($csrPem);

        $subject = new X509();
        $subject->setPublicKey($publicKey);
        // The assigned identity, not the requested one.
        $subject->setDN(['CN' => $nodeId]);

        $issuer = new X509();
        $issuer->setPrivateKey($this->caKey);
        $issuer->setDN($this->caCert->getDN());

        $x509 = new X509();
        $x509->setStartDate(self::startDate());
        $x509->setEndDate('+'.self::NODE_VALIDITY_DAYS.' days');
        $x509->setSerialNumber(self::serialNumber(), 10);

        // Two passes, which is not an accident. phpseclib's sign() rebuilds
        // the certificate body from the subject it is given and discards any
        // extensions set beforehand: setExtension returns true, the
        // certificate comes out without them, and nothing complains. The only
        // order that works is sign, reload, set extensions, then re-sign with
        // the loaded certificate as its own subject.
        //
        // Getting this wrong is silent, and what it silently produces is a
        // node certificate with no extKeyUsage restriction, which is exactly
        // the restriction that stops a compromised node impersonating the
        // control plane to its peers.
        $first = $x509->sign($issuer, $subject);

        if ($first === false) {
            throw new PkiException('Could not sign the node certificate.');
        }

        $x509->loadX509($x509->saveX509($first));

        // Client authentication only.
        $x509->setExtension('id-ce-basicConstraints', ['cA' => false], true);
        $x509->setExtension('id-ce-keyUsage', ['digitalSignature', 'keyEncipherment'], true);
        $x509->setExtension('id-ce-extKeyUsage', ['id-kp-clientAuth']);

        $signed = $x509->sign($issuer, $x509);

        if ($signed === false) {
            throw new PkiException('Could not sign the node certificate.');
        }

        $pem = $x509->saveX509($signed);

        // Assert rather than assume. If a phpseclib upgrade changes this
        // behaviour again, fail at issuance rather than shipping an
        // unrestricted certificate to a node.
        $verify = new X509();
        $verify->loadX509($pem);

        if ($verify->getExtension('id-ce-extKeyUsage') !== ['id-kp-clientAuth']) {
            throw new PkiException(
                'The issued certificate lost its client-auth restriction. Refusing to issue it.'
            );
        }

        return $pem;
    }

    /**
     * Issue a certificate for whatever terminates TLS in front of the control
     * plane.
     *
     * Agents pin this CA and verify the server name, which is what stops a
     * coerced public CA impersonating us to a node. That only works if the
     * terminator presents a certificate from the same CA, so the CA has to be
     * able to mint one.
     *
     * Server auth only, mirroring the client-auth-only restriction on node
     * certificates. Neither should ever be usable in the other direction.
     *
     * @param  array<int, string>  $dnsNames
     * @return array{0: string, 1: string} certificate PEM, private key PEM
     */
    public function issueServerCertificate(string $commonName, array $dnsNames = []): array
    {
        $key = EC::createKey('secp256r1');

        $subject = new X509();
        $subject->setPublicKey($key->getPublicKey());
        $subject->setDN(['CN' => $commonName]);

        $issuer = new X509();
        $issuer->setPrivateKey($this->caKey);
        $issuer->setDN($this->caCert->getDN());

        $x509 = new X509();
        $x509->setStartDate(self::startDate());
        $x509->setEndDate('+'.self::NODE_VALIDITY_DAYS.' days');
        $x509->setSerialNumber(self::serialNumber(), 10);

        // Two passes for the same reason as node certificates: phpseclib's
        // sign() discards extensions set beforehand. See the comment in
        // issueNodeCertificate.
        $first = $x509->sign($issuer, $subject);

        if ($first === false) {
            throw new PkiException('Could not sign the server certificate.');
        }

        $x509->loadX509($x509->saveX509($first));

        $x509->setExtension('id-ce-basicConstraints', ['cA' => false], true);
        $x509->setExtension('id-ce-keyUsage', ['digitalSignature', 'keyEncipherment'], true);
        $x509->setExtension('id-ce-extKeyUsage', ['id-kp-serverAuth']);

        // Modern TLS clients, including Go's, ignore the common name and
        // require a subject alternative name. Without this the agent refuses
        // the connection no matter what the CN says.
        $names = $dnsNames === [] ? [$commonName] : $dnsNames;
        $x509->setDomain(...$names);

        $signed = $x509->sign($issuer, $x509);

        if ($signed === false) {
            throw new PkiException('Could not sign the server certificate.');
        }

        $pem = $x509->saveX509($signed);

        $verify = new X509();
        $verify->loadX509($pem);

        if ($verify->getExtension('id-ce-extKeyUsage') !== ['id-kp-serverAuth']) {
            throw new PkiException('The issued server certificate lost its server-auth restriction.');
        }

        return [$pem, $key->toString('PKCS8')];
    }

    /**
     * Extract and verify the public key from a signing request.
     *
     * The signature check is proof of possession. Without it anyone could
     * request a certificate over somebody else's public key.
     */
    private function publicKeyFromCsr(string $csrPem): EC
    {
        $csr = new X509();

        // phpseclib does not reliably return false for garbage: a request
        // that is not valid base64 surfaces as a SodiumException from deep
        // inside its string helpers. Catch everything and give the caller the
        // typed failure it expects, or an unauthenticated enrollment attempt
        // can throw an exception nobody upstream is handling.
        try {
            $loaded = $csr->loadCSR($csrPem);
        } catch (Throwable $e) {
            throw new InvalidCsr('The certificate signing request could not be parsed.', previous: $e);
        }

        if ($loaded === false) {
            throw new InvalidCsr('The certificate signing request could not be parsed.');
        }

        try {
            $valid = $csr->validateSignature();
        } catch (Throwable $e) {
            throw new InvalidCsr('The certificate signing request signature could not be checked.', previous: $e);
        }

        if ($valid !== true) {
            throw new InvalidCsr('The certificate signing request signature is not valid.');
        }

        $publicKey = $csr->getPublicKey();

        if (! $publicKey instanceof EC) {
            throw new InvalidCsr('The certificate signing request does not carry an EC public key.');
        }

        return $publicKey;
    }

    /**
     * Read the identity a certificate asserts.
     */
    public static function commonName(string $certPem): ?string
    {
        $x509 = self::loadCertificate($certPem);
        $cn = $x509->getDNProp('CN');

        return is_array($cn) && $cn !== [] ? (string) $cn[0] : null;
    }

    /**
     * When a certificate expires.
     */
    public static function notAfter(string $certPem): DateTimeImmutable
    {
        $x509 = self::loadCertificate($certPem);
        $cert = $x509->getCurrentCert();

        $value = $cert['tbsCertificate']['validity']['notAfter']['utcTime']
            ?? $cert['tbsCertificate']['validity']['notAfter']['generalTime']
            ?? null;

        if ($value === null) {
            throw new PkiException('The certificate carries no expiry.');
        }

        return new DateTimeImmutable((string) $value);
    }

    private static function loadCertificate(string $pem): X509
    {
        $x509 = new X509();

        if ($x509->loadX509($pem) === false) {
            throw new PkiException('Could not parse the certificate.');
        }

        return $x509;
    }

    private static function startDate(): string
    {
        return (new DateTimeImmutable())
            ->modify('-'.self::CLOCK_SKEW_MINUTES.' minutes')
            ->format('D, d M Y H:i:s O');
    }

    /**
     * A random 128-bit serial, as a decimal string.
     *
     * Random rather than sequential: a predictable serial leaks how many
     * certificates an organisation has issued, and sequential serials across
     * tenants would leak it across organisations.
     */
    private static function serialNumber(): string
    {
        $bytes = random_bytes(16);
        $hex = bin2hex($bytes);

        // Convert without bcmath or gmp, neither of which is guaranteed.
        $decimal = '0';
        foreach (str_split($hex) as $digit) {
            $decimal = self::addDecimal(self::multiplyDecimal($decimal, 16), (string) hexdec($digit));
        }

        return $decimal;
    }

    private static function multiplyDecimal(string $value, int $multiplier): string
    {
        $result = '';
        $carry = 0;

        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $product = ((int) $value[$i] * $multiplier) + $carry;
            $result = (string) ($product % 10).$result;
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = (string) ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function addDecimal(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;

        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry
                + ($i >= 0 ? (int) $a[$i] : 0)
                + ($j >= 0 ? (int) $b[$j] : 0);
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }
}
