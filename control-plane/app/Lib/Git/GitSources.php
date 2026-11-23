<?php

declare(strict_types=1);

namespace App\Lib\Git;

use App\Lib\GitHub\GitHubApp;
use InvalidArgumentException;
use Throwable;

/**
 * How an application reaches its code, for a repository the user typed or
 * picked, on any host:
 *
 *   github_app   our GitHub App can read it (a short-lived token per deploy)
 *   https        open to anyone: cloned with no credential
 *   deploy_key   private, anywhere: cloned over SSH with a read-only key made
 *                for this application, which the user adds on the host
 *
 * A repository the GitHub App can read always uses it. Otherwise public wins,
 * since it needs nothing from the user, and a key is the fallback that works on
 * every host.
 */
final class GitSources
{
    public function __construct(private readonly GitHubApp $github, private readonly GitRemote $remote) {}

    /**
     * @return array{url: string, source: string, host: string, display: string, default: ?string, branches: list<string>,
     *               fingerprint: ?string, ssh_port: int, note: string}
     *
     * @throws InvalidArgumentException with a message fit to show
     */
    public function inspect(string $organizationId, string $input): array
    {
        $repo = GitRepo::parse($input);

        if ($repo->isGitHub() && $this->github->find($organizationId) !== null && $this->github->canRead($organizationId, $repo->path)) {
            return $this->result($repo, 'github_app', null, null, 'Read through your GitHub App.');
        }

        try {
            $refs = $this->remote->publicRefs($repo);
        } catch (Throwable $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
        if ($refs !== null) {
            return $this->result($repo, 'https', $refs, null, 'Public: cloned without a login, so there is nothing to set up. A repository you do not own sends no webhooks, so new commits are found by checking every five minutes.');
        }

        try {
            $key = $this->remote->hostKey($repo);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("That repository is not public, and {$repo->host} could not be reached over SSH to set up a deploy key: {$e->getMessage()}");
        }

        return $this->result($repo, 'deploy_key', null, $key['fingerprint'], "Private: cloned over SSH with a key made for this application. After you create it you add the key to {$repo->host} as a read-only deploy key; the host key is {$key['fingerprint']}, which you can compare with the host's own page.");
    }

    /**
     * The branches an existing application's repository has, read the way the application clones it. Null when
     * they cannot be read (the host is down, or a deploy key is not accepted yet): callers then do not block on it.
     *
     * @return list<string>|null
     */
    public function siteBranches(\stdClass $site, ?string $deployKey): ?array
    {
        $repo = GitRepo::parse((string) $site->repository);
        try {
            return match ($site->git_source) {
                'github_app' => $this->github->branches($site->organization_id, $repo->path),
                'https' => $this->remote->publicRefs($repo)['branches'] ?? null,
                default => $deployKey === null || $deployKey === '' ? null : ($this->remote->refsWithKey($repo, $deployKey)['branches'] ?? null),
            };
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    public function branches(string $organizationId, string $url, string $source): array
    {
        $repo = GitRepo::parse($url);
        try {
            return match ($source) {
                'github_app' => $this->github->branches($organizationId, $repo->path),
                'https' => $this->remote->publicRefs($repo)['branches'] ?? [],
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{framework: ?string, php: ?string, frontend: ?string, env: array<string, string>} */
    public function detect(string $organizationId, string $url, string $source, string $branch, string $dir = ''): array
    {
        $repo = GitRepo::parse($url);

        return match ($source) {
            'github_app' => $this->github->detect($organizationId, $repo->path, $branch, $dir),
            'https' => GitHubApp::summarise(fn (string $file) => $this->remote->rawFile($repo, $branch, $dir === '' ? $file : "{$dir}/{$file}")),
            default => ['framework' => null, 'php' => null, 'frontend' => null, 'env' => []],
        };
    }

    /**
     * The applications in a repository, and whether the whole repository could be searched. Where only
     * the top can be read (a public repository on a host with no file list, or a deploy-key one, which
     * is not cloned until the first deploy) the top is looked at alone and `listed` is false: a folder
     * can still be named and is looked at on its own.
     *
     * @return array{apps: list<array<string, mixed>>, listed: bool}
     */
    public function scan(string $organizationId, string $url, string $source, string $branch): array
    {
        $repo = GitRepo::parse($url);
        $paths = null;
        $read = null;
        try {
            if ($source === 'github_app') {
                $paths = $this->github->paths($organizationId, $repo->path, $branch);
                $read = $this->github->reader($organizationId, $repo->path, $branch);
            } elseif ($source === 'https') {
                $paths = $repo->isGitHub() ? $this->github->publicPaths($repo->path, $branch) : null;
                $read = fn (string $file): ?string => $this->remote->rawFile($repo, $branch, $file);
            }
        } catch (Throwable) {
        }
        if ($read === null) {
            return ['apps' => [], 'listed' => false];
        }
        if ($paths === null) {
            $top = AppDetector::inspect($read);

            return ['apps' => $top === null ? [] : [['dir' => ''] + $top], 'listed' => false];
        }

        return ['apps' => AppDetector::scan($paths, $read), 'listed' => true];
    }

    /**
     * The Dockerfile at a path of the repository (and any Compose file beside it, noticed and never run), or null when there is none or
     * the repository cannot be read yet (a deploy-key repository is not cloned until its first deploy).
     *
     * @return array{file: string, port: ?int, ports: list<int>, compose: ?string}|null
     */
    public function dockerfile(string $organizationId, string $url, string $source, string $branch, string $file = 'Dockerfile'): ?array
    {
        $repo = GitRepo::parse($url);
        try {
            $read = match ($source) {
                'github_app' => $this->github->reader($organizationId, $repo->path, $branch),
                'https' => fn (string $f): ?string => $this->remote->rawFile($repo, $branch, $f),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
        if ($read === null) {
            return null;
        }
        $dir = dirname($file);
        $name = basename($file);

        return AppDetector::dockerfile($dir === '.' ? $read : fn (string $f): ?string => $read("{$dir}/{$f}"), $name);
    }

    /** What one folder of the repository holds, for a folder the person named. Null when it holds nothing we recognise or cannot be read. */
    public function inspectDir(string $organizationId, string $url, string $source, string $branch, string $dir): ?array
    {
        $repo = GitRepo::parse($url);
        try {
            $read = match ($source) {
                'github_app' => $this->github->reader($organizationId, $repo->path, $branch),
                'https' => fn (string $file): ?string => $this->remote->rawFile($repo, $branch, $file),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
        if ($read === null) {
            return null;
        }
        $app = AppDetector::inspect($dir === '' ? $read : fn (string $file): ?string => $read("{$dir}/{$file}"));

        return $app === null ? null : ['dir' => $dir] + $app;
    }

    /**
     * @param  array{default: ?string, branches: list<string>}|null  $refs
     * @return array<string, mixed>
     */
    private function result(GitRepo $repo, string $source, ?array $refs, ?string $fingerprint, string $note): array
    {
        return ['url' => $source === 'https' ? $repo->httpsUrl() : $repo->sshUrl(), 'source' => $source, 'host' => $repo->host, 'display' => $repo->display(), 'default' => $refs['default'] ?? null,
            'branches' => $refs['branches'] ?? [], 'fingerprint' => $fingerprint, 'ssh_port' => $repo->sshPort, 'note' => $note];
    }
}
