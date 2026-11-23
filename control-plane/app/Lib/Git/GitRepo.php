<?php

declare(strict_types=1);

namespace App\Lib\Git;

use InvalidArgumentException;

/**
 * A repository on any Git host, however it was written down: owner/repo
 * (GitHub, as the wizard's list gives it), an https:// URL, git@host:path.git,
 * or ssh://git@host:port/path.git. Every part ends up in a git argv or a file
 * on a root agent, so the formats are strict.
 */
final readonly class GitRepo
{
    public function __construct(
        public string $host,
        public int $sshPort,
        public string $path,
        public string $sshUser,
        public bool $viaSsh,
        public bool $absolute = false,
    ) {}

    /** @throws InvalidArgumentException */
    public static function parse(string $input): self
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 300 || preg_match('/[\s\x00-\x1f\\\\\'"`$;&|<>(){}*?!#]/', $input)) {
            throw new InvalidArgumentException('That is not a repository address.');
        }

        $host = $user = $path = null;
        $port = 22;
        $ssh = false;
        $absolute = false;
        if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(\.git)?$#', $input, $m)) {
            [$host, $path] = ['github.com', "{$m[1]}/{$m[2]}"];
        } elseif (preg_match('#^https://([A-Za-z0-9.-]+)(?::(\d{1,5}))?/([A-Za-z0-9_./-]+?)(\.git)?/?$#', $input, $m)) {
            [$host, $path] = [strtolower($m[1]), $m[3]];
            if ($m[2] !== '') {
                throw new InvalidArgumentException('A port in an https address is not supported; use the ssh form.');
            }
        } elseif (preg_match('#^([a-z_][a-z0-9_-]*)@([A-Za-z0-9.-]+):([A-Za-z0-9_./-]+?)(\.git)?$#', $input, $m)) {
            [$user, $host, $path, $ssh] = [$m[1], strtolower($m[2]), $m[3], true];
            // git@host:/srv/git/app.git is from the root; git@host:app.git is from the login's home.
            $absolute = str_starts_with($m[3], '/');
        } elseif (preg_match('#^ssh://([a-z_][a-z0-9_-]*)@([A-Za-z0-9.-]+)(?::(\d{1,5}))?/([A-Za-z0-9_./-]+?)(\.git)?$#', $input, $m)) {
            [$user, $host, $path, $ssh] = [$m[1], strtolower($m[2]), $m[4], true];
            $port = $m[3] !== '' ? (int) $m[3] : 22;
            // In an ssh:// address the path is from the root, always.
            $absolute = true;
        } else {
            throw new InvalidArgumentException('Use owner/repo, an https:// address, or an ssh address like git@host:owner/repo.git.');
        }

        $path = trim((string) $path, '/');
        // A name with a top-level domain, or an IPv4 address: a server with no name is as good as any.
        $named = (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', (string) $host);
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $segments = $path === '' ? [] : explode('/', $path);
        if ((! $named && ! $address) || $port < 1 || $port > 65535
            || ! preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$#', $path) || str_contains($path, '..') || str_starts_with($path, '-')
            || array_filter($segments, fn ($p) => $p === '' || $p === '.' || str_starts_with($p, '-'))) {
            throw new InvalidArgumentException('That is not a repository address.');
        }
        // GitHub is always owner/repo, and its paths never need the leading slash.
        if ($host === 'github.com') {
            if (count($segments) !== 2) {
                throw new InvalidArgumentException('A GitHub repository is owner/repo.');
            }
            $absolute = false;
        }

        return new self((string) $host, $port, $path, $user ?? 'git', $ssh, $absolute);
    }

    /** The address clones use over SSH, in the form git takes. */
    public function sshUrl(): string
    {
        return $this->sshPort === 22
            ? "{$this->sshUser}@{$this->host}:".($this->absolute ? '/' : '')."{$this->path}.git"
            : "ssh://{$this->sshUser}@{$this->host}:{$this->sshPort}/{$this->path}.git";
    }

    public function httpsUrl(): string
    {
        return "https://{$this->host}/{$this->path}.git";
    }

    /** What a site stores: the SSH form if it was written that way, else HTTPS. */
    public function url(): string
    {
        return $this->viaSsh ? $this->sshUrl() : $this->httpsUrl();
    }

    public function isGitHub(): bool
    {
        return $this->host === 'github.com';
    }

    /** The known_hosts name of the SSH endpoint: the host, or [host]:port. */
    public function knownHostsName(): string
    {
        return $this->sshPort === 22 ? $this->host : "[{$this->host}]:{$this->sshPort}";
    }

    public function display(): string
    {
        return $this->isGitHub() ? $this->path : "{$this->host}/{$this->path}";
    }
}
