<?php

declare(strict_types=1);

namespace App\Lib\Providers\Incus;

use App\Lib\Providers\Rejected;
use App\Lib\Providers\Uncertain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/**
 * An Incus host's REST API over mutual TLS: we present our client certificate, and accept the host only if its public
 * key is the one we pinned when connecting (its certificate is self-signed, so there is no CA to check against). Every
 * call is scoped to our Incus project.
 *
 * A 4xx is Rejected (nothing happened), no answer or a 5xx is Uncertain (it may have), and a 404 is returned as null so
 * callers can treat "gone" as a fact. Long work (create, stop, delete) is an Incus operation, waited on with wait().
 *
 * Guzzle reads the certificate and key from files, so they live in two 0600 temporary files for this object's life.
 */
final class IncusClient
{
    private readonly string $certFile;

    private readonly string $keyFile;

    private readonly string $pin;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $endpoint,
        private readonly string $project,
        #[SensitiveParameter] string $clientCertificate,
        #[SensitiveParameter] string $clientKey,
        string $serverCertificate,
    ) {
        $this->certFile = self::tempFile($clientCertificate);
        $this->keyFile = self::tempFile($clientKey);
        $this->pin = self::pin($serverCertificate);
    }

    public function __destruct()
    {
        @unlink($this->certFile);
        @unlink($this->keyFile);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null the response's metadata, null for a 404
     */
    public function get(string $path, array $query = []): ?array
    {
        $body = $this->send('GET', $path, $query, null);

        return $body === null ? null : (array) ($body['metadata'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the whole response, so an async one's "operation" can be waited on
     */
    public function post(string $path, array $payload): array
    {
        return $this->send('POST', $path, [], $payload) ?? throw new Rejected("Incus answered 404 to {$path}");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function put(string $path, array $payload): ?array
    {
        return $this->send('PUT', $path, [], $payload);
    }

    /** @return array<string, mixed>|null */
    public function delete(string $path): ?array
    {
        return $this->send('DELETE', $path, [], null);
    }

    /**
     * Wait for an operation an async call started.
     *
     * @throws Rejected when it failed
     * @throws Uncertain when it is still running after the timeout
     */
    public function wait(string $operation, int $seconds): void
    {
        $op = $this->get($operation.'/wait', ['timeout' => $seconds]);
        if ($op === null) {
            return;
        }
        match ((string) ($op['status'] ?? '')) {
            'Success' => null,
            'Failure', 'Cancelled' => throw new Rejected('Incus: '.((string) ($op['err'] ?? '') ?: 'the operation failed')),
            default => throw new Uncertain("Incus is still working on it after {$seconds} seconds."),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $path, array $query, ?array $payload): ?array
    {
        $query += ['project' => $this->project];
        try {
            $request = $this->http->withOptions([
                'cert' => $this->certFile,
                'ssl_key' => $this->keyFile,
                'verify' => false,
                'curl' => [CURLOPT_PINNEDPUBLICKEY => $this->pin],
            ])->acceptJson()->timeout(30);
            $url = $this->endpoint.$path.'?'.http_build_query($query);
            $response = match ($method) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $payload ?? []),
                'PUT' => $request->put($url, $payload ?? []),
                'DELETE' => $request->delete($url),
            };
        } catch (ConnectionException $e) {
            if (str_contains($e->getMessage(), 'cURL error 90')) {
                throw new Rejected("The Incus host's certificate is not the one Unharbored trusted when it connected. If the host was reinstalled, run the setup script again and replace the connection.", 0, $e);
            }
            throw new Uncertain('Could not reach the Incus host: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 404) {
            return null;
        }
        if ($response->successful()) {
            return $response->json() ?? [];
        }
        $message = self::message($response);

        throw $response->status() >= 500 && ! str_contains($message, 'permissions') ? new Uncertain("Incus: {$message}") : new Rejected($message);
    }

    private static function message(Response $response): string
    {
        return (string) ($response->json('error') ?: 'HTTP '.$response->status());
    }

    /** curl's public-key pin for a certificate: "sha256//" and the base64 SHA-256 of its public key (SPKI). */
    public static function pin(string $certificatePem): string
    {
        $key = openssl_pkey_get_public($certificatePem) ?: throw new \InvalidArgumentException('The host certificate could not be read.');
        $pem = (string) (openssl_pkey_get_details($key)['key'] ?? '');
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $pem), true);

        return 'sha256//'.base64_encode(hash('sha256', (string) $der, true));
    }

    private static function tempFile(#[SensitiveParameter] string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'incus') ?: throw new \RuntimeException('Could not make a temporary file.');
        chmod($path, 0600);
        file_put_contents($path, $contents);

        return $path;
    }
}
