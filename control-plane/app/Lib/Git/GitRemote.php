<?php

declare(strict_types=1);

namespace App\Lib\Git;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * What the control plane can learn about a repository on any host without a
 * credential: whether it is public, its branches and default, the SSH host
 * key, and whether a deploy key has been accepted. It talks to hosts a user
 * typed in, so every address is resolved first and refused if it is private,
 * loopback or link-local: the control plane must not be a way to probe the
 * network it sits in.
 *
 * Public means git's own smart-HTTP answer: /info/refs for upload-pack over
 * HTTPS. Every host serves that, so no host-specific API is needed.
 */
final class GitRemote
{
    private const MAX_REDIRECTS = 3;

    /**
     * The three closures are how tests stand in for the network: name lookup,
     * the SSH host key a server offers, and an SSH login.
     *
     * @param  (Closure(string): list<string>)|null  $resolver  host => its IP addresses
     * @param  (Closure(string, int): (string|false))|null  $keyReader  host, port => "ssh-ed25519 AAAA…"
     * @param  (Closure(string, int, string, string): bool)|null  $authenticator  host, port, user, private key => accepted
     * @param  (Closure(GitRepo, string): ?string)|null  $keyedRefs  repository, private key => the reference advertisement
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?Closure $resolver = null,
        private readonly ?Closure $keyReader = null,
        private readonly ?Closure $authenticator = null,
        private readonly ?Closure $keyedRefs = null,
    ) {}

    /**
     * The branches of a repository open to anyone, its default first, or null
     * when it is not reachable without a login.
     *
     * @return array{default: ?string, branches: list<string>, shas: array<string, string>}|null
     */
    public function publicRefs(GitRepo $repo): ?array
    {
        $url = $repo->httpsUrl().'/info/refs?service=git-upload-pack';
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $this->assertPublicHost($host);
            try {
                $response = $this->http->withoutRedirecting()->timeout(15)->withHeaders(['User-Agent' => 'Unharbored', 'Accept' => '*/*'])->get($url);
            } catch (Throwable) {
                return null;
            }
            if (in_array($response->status(), [301, 302, 307, 308], true) && $response->header('Location') !== '') {
                $url = $this->absolute($url, $response->header('Location'));
                if (! str_starts_with($url, 'https://')) {
                    return null;
                }

                continue;
            }
            if (! $response->successful() || ! str_contains(strtolower($response->header('Content-Type')), 'x-git-upload-pack-advertisement')) {
                return null;
            }

            return self::parseRefs($response->body());
        }

        return null;
    }

    /**
     * Git's reference advertisement: pkt-lines of "<sha> <ref>", the first
     * carrying capabilities, among them symref=HEAD:refs/heads/<default>.
     *
     * @return array{default: ?string, branches: list<string>, shas: array<string, string>}
     */
    public static function parseRefs(string $body): array
    {
        $branches = [];
        $shas = [];
        $default = null;
        for ($pos = 0; $pos + 4 <= strlen($body);) {
            $length = hexdec(substr($body, $pos, 4));
            if ($length === 0 || $length < 4) {
                $pos += 4;

                continue;
            }
            $line = substr($body, $pos + 4, $length - 4);
            $pos += $length;
            [$ref, $caps] = array_pad(explode("\0", rtrim($line, "\n"), 2), 2, '');
            if (preg_match('/^([0-9a-f]{40,64}) refs\/heads\/(.+)$/', $ref, $m) && ! str_contains($m[2], '..')) {
                $branches[] = $m[2];
                $shas[$m[2]] = $m[1];
            }
            if (preg_match('/symref=HEAD:refs\/heads\/(\S+)/', $caps, $m)) {
                $default = $m[1];
            }
        }
        if ($default !== null && in_array($default, $branches, true)) {
            $branches = [$default, ...array_values(array_diff($branches, [$default]))];
        }

        return ['default' => $default ?? ($branches[0] ?? null), 'branches' => array_values(array_unique($branches)), 'shas' => $shas];
    }

    /**
     * The host's SSH key as a known_hosts line, and its fingerprint as ssh
     * shows it. Trusted on first use: the caller shows the fingerprint.
     *
     * @return array{line: string, fingerprint: string}
     */
    public function hostKey(GitRepo $repo): array
    {
        $this->assertPublicHost($repo->host);
        try {
            $key = $this->keyReader !== null ? ($this->keyReader)($repo->host, $repo->sshPort) : (new SSH2($repo->host, $repo->sshPort, 10))->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new RuntimeException("Could not reach {$repo->host} over SSH on port {$repo->sshPort}.", 0, $e);
        }
        if (! is_string($key) || substr_count($key, ' ') < 1) {
            throw new RuntimeException("{$repo->host} did not offer an SSH host key on port {$repo->sshPort}.");
        }
        [$type, $blob] = explode(' ', $key);

        return ['line' => "{$repo->knownHostsName()} {$type} {$blob}", 'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($blob, true), true)), '=')];
    }

    /** Whether the host accepts this key for the repository's SSH user. The key is only used here, in memory. */
    public function accepts(GitRepo $repo, #[SensitiveParameter] string $privateKey): bool
    {
        $this->assertPublicHost($repo->host);
        try {
            if ($this->authenticator !== null) {
                return ($this->authenticator)($repo->host, $repo->sshPort, $repo->sshUser, $privateKey);
            }

            return (new SSH2($repo->host, $repo->sshPort, 10))->login($repo->sshUser, PublicKeyLoader::load($privateKey));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The branches of a repository read with this key, over SSH as git itself does (the host runs
     * git-upload-pack and starts with the reference advertisement). Null when it could not be read.
     *
     * @return array{default: ?string, branches: list<string>, shas: array<string, string>}|null
     */
    public function refsWithKey(GitRepo $repo, #[SensitiveParameter] string $privateKey): ?array
    {
        $this->assertPublicHost($repo->host);
        try {
            if ($this->keyedRefs !== null) {
                $body = ($this->keyedRefs)($repo, $privateKey);
            } else {
                $ssh = new SSH2($repo->host, $repo->sshPort, 10);
                if (! $ssh->login($repo->sshUser, PublicKeyLoader::load($privateKey))) {
                    return null;
                }
                $body = '';
                // The advertisement ends with a flush packet; the host then waits for us, so stop reading there.
                $ssh->exec('git-upload-pack '.escapeshellarg($repo->path.'.git'), function (string $chunk) use (&$body): bool {
                    $body .= $chunk;

                    return str_ends_with($body, '0000');
                });
            }
        } catch (Throwable) {
            return null;
        }

        return is_string($body) && $body !== '' ? self::parseRefs($body) : null;
    }

    /** A file from a public repository's branch, for the hosts that serve raw files at a known address. Null when it cannot be had. */
    public function rawFile(GitRepo $repo, string $branch, string $file): ?string
    {
        $url = match (true) {
            $repo->host === 'github.com' => "https://raw.githubusercontent.com/{$repo->path}/{$branch}/{$file}",
            $repo->host === 'gitlab.com' => "https://gitlab.com/{$repo->path}/-/raw/{$branch}/{$file}",
            $repo->host === 'bitbucket.org' => "https://bitbucket.org/{$repo->path}/raw/{$branch}/{$file}",
            $repo->host === 'codeberg.org' => "https://codeberg.org/{$repo->path}/raw/branch/{$branch}/{$file}",
            default => null,
        };
        if ($url === null || ! preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
            return null;
        }
        try {
            $response = $this->http->withoutRedirecting()->timeout(15)->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && strlen($response->body()) < 1_000_000 ? $response->body() : null;
    }

    /** @throws RuntimeException when the host is, or resolves to, an address the control plane must not reach */
    public function assertPublicHost(string $host): void
    {
        // An address typed in as a number needs no lookup, and must not be trusted to get one.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host]
            : ($this->resolver !== null ? ($this->resolver)($host) : (gethostbynamel($host) ?: []));
        if ($addresses === []) {
            throw new RuntimeException("Could not find {$host}.");
        }
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("{$host} is not a public address, so Unharbored will not connect to it.");
            }
        }
    }

    private function absolute(string $from, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $base = parse_url($from);

        return ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '').(str_starts_with($location, '/') ? '' : '/').$location;
    }
}
