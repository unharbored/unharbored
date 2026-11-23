<?php

declare(strict_types=1);

namespace App\Lib\Templates;

use App\Lib\Dns\Lookup;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one guarded HTTP fetch for every remote template request (a URL someone
 * gave us, docs/specs/templates.md section 3). HTTPS only, no redirects, the
 * name is resolved here and every address must be public, the request is
 * pinned to the address we checked (so DNS cannot change between the check and
 * the connection), a size limit and a short timeout.
 */
final class SafeFetcher
{
    public const MAX_INDEX = 1048576;   // 1 MB

    public const MAX_FILE = 262144;     // 256 KB

    public const TIMEOUT = 10;

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param Closure(string): list<string>|null $resolver host to IP addresses; a seam for tests */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? Lookup::addresses(...);
    }

    /** @throws FetchFailed */
    public function get(string $url, int $maxBytes): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchFailed('only plain https:// URLs are fetched');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? 443;

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new FetchFailed("$host did not resolve");
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new FetchFailed("$host resolves to $ip, which is not a public address");
            }
        }
        $pin = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        $tooBig = fn () => new FetchFailed("the response is larger than the limit of $maxBytes bytes");
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
                'curl' => [CURLOPT_RESOLVE => ["$host:$port:$pin"], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS],
                'on_headers' => function ($r) use ($maxBytes, $tooBig) {
                    if ((int) $r->getHeaderLine('Content-Length') > $maxBytes) {
                        throw $tooBig();
                    }
                },
                'progress' => function ($total, $now) use ($maxBytes, $tooBig) {
                    if ($now > $maxBytes) {
                        throw $tooBig();
                    }
                },
            ])->withHeaders($this->requestHeadersFor($url))->get($url);
        } catch (FetchFailed $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw new FetchFailed("$host could not be reached");
        } catch (Throwable $e) {
            if (($p = $e->getPrevious()) instanceof FetchFailed) {
                throw $p;
            }
            throw new FetchFailed("$host could not be fetched: ".$e->getMessage());
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            throw new FetchFailed("$host answered with a redirect, which is not followed");
        }
        if (! $response->successful()) {
            throw new FetchFailed("$host answered with HTTP ".$response->status());
        }
        $body = $response->body();
        if (strlen($body) > $maxBytes) {
            throw $tooBig();
        }

        return $body;
    }

    /**
     * SEAM: credentials for a private source (an S3 key or a token held in the
     * vault) are added here later. Public sources send nothing extra.
     *
     * @return array<string, string>
     */
    protected function requestHeadersFor(string $url): array
    {
        return [];
    }

    /** Loopback, private, link-local, unique-local, shared (CGNAT) and other reserved addresses are not public. */
    public static function isPublic(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            $bin = inet_pton($ip);

            return ! ((ord($bin[0]) & 0xFE) === 0xFC || (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80)); // fc00::/7, fe80::/10
        }
        $long = ip2long($ip);

        return ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
    }
}
