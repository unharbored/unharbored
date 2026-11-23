<?php

declare(strict_types=1);

namespace App\Lib\Containers;

use App\Lib\Services\ContainerRecipes;
use App\Lib\Services\ServiceRecipes;

/**
 * Recipes for an application from a Dockerfile or a Docker image (docs/specs/templates.md, step 6). Pure: no database, no
 * gateway. They are built from the step types the agent already has (`command`, `file`, `dir`, `absent`); nothing here needs
 * a new one.
 *
 * Confinement and the environment file are ContainerRecipes' (one list of flags for services and applications). What is
 * different: containers are named by their host port so a new one can run beside the old, images are built or pulled by
 * digest, and a private registry's token reaches Docker only through a file read on standard input.
 *
 * On the server:
 *   container cm-app-{name}-{hostport}      the live one is the one the site's state names; the other exists only mid-deploy
 *   image     cm-app-{name}:{commit12}      built from the release; or {repo}@sha256:... for an image application
 *   /etc/cloud-manager/apps/{name}.env      root-owned 0600, handed to `docker run --env-file`, never in an argument
 *   /etc/cloud-manager/docker-auth/{name}/  a per-application DOCKER_CONFIG, 0700, there only while a pull runs
 */
final class AppRecipes
{
    public const ENV_DIR = '/etc/cloud-manager/apps';

    public const AUTH_DIR = '/etc/cloud-manager/docker-auth';

    public static function container(string $name, int $hostPort): string
    {
        return "cm-app-{$name}-{$hostPort}";
    }

    public static function envPath(string $name): string
    {
        return self::ENV_DIR."/{$name}.env";
    }

    public static function tag(string $name, string $commit): string
    {
        return "cm-app-{$name}:".substr($commit, 0, 12);
    }

    /** Docker is running and nginx is there. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function check(string $name): array
    {
        $script = 'for c in docker nginx certbot; do command -v "$c" >/dev/null 2>&1 || { echo "$c is not installed on this server; prepare it for containers and install the server stack first"; exit 1; }; done; '
            .'systemctl is-active --quiet docker || { echo "Docker is not running on this server"; exit 1; }';

        return ['name' => "app-check-{$name}", 'steps' => [
            ['id' => 'tools', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'Docker is running, and the server has nginx and certbot'],
        ]];
    }

    /** The Dockerfile and its build context are in the checkout. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function verifySource(string $name, string $releasePath, string $dockerfile, string $context): array
    {
        return ['name' => "app-source-{$name}", 'steps' => [
            ['id' => 'dockerfile', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', self::INSIDE.' && test -f "$r"', 'sh', "{$releasePath}/{$dockerfile}", $releasePath], 'description' => "{$dockerfile} is a file inside the repository on this branch"],
            ['id' => 'context', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', self::INSIDE.' && test -d "$r"', 'sh', self::contextPath($releasePath, $context), $releasePath], 'description' => "the build context {$context} is a folder inside the repository"],
        ]];
    }

    /** Resolves symlinks and refuses a path that leaves the release folder: docker build runs as root and follows them. */
    private const INSIDE = 'r=$(realpath -e -- "$1") && case "$r" in "$2"|"$2"/*) ;; *) echo "$1 resolves outside the repository ($r)"; exit 1;; esac';

    public static function contextPath(string $releasePath, string $context): string
    {
        return $context === '.' ? $releasePath : "{$releasePath}/{$context}";
    }

    /**
     * `docker build` on the server with BuildKit (its layer cache stays on the server between deploys; Containers' cleanup
     * decides when it goes). No build arguments and no secrets: what the build needs is in the repository.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function build(string $name, string $releasePath, string $dockerfile, string $context, string $commit): array
    {
        // The paths are resolved and checked again here, right before the build, and the resolved paths are what docker gets: a
        // symlink in the repository must not point the Dockerfile or the build context outside it (docker build runs as root).
        $script = implode("\n", [
            'set -e',
            'rel=$(realpath -e -- "$1"); df=$(realpath -e -- "$2"); cx=$(realpath -e -- "$3"); shift 3',
            'for p in "$df" "$cx"; do case "$p" in "$rel"|"$rel"/*) ;; *) echo "$p is outside the repository"; exit 1;; esac; done',
            'DOCKER_BUILDKIT=1 exec docker build --file "$df" "$@" "$cx"',
        ]);

        return ['name' => "app-build-{$name}", 'steps' => [
            ['id' => 'build', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script, 'sh', $releasePath, "{$releasePath}/{$dockerfile}", self::contextPath($releasePath, $context),
                '--tag', self::tag($name, $commit), '--label', "cloud-manager.app={$name}", '--label', 'cloud-manager.commit='.substr($commit, 0, 12)],
                'description' => 'docker build '.self::tag($name, $commit)],
        ]];
    }

    /** `docker image inspect` arguments that print the image's repo digests, one per line. @return list<string> */
    public static function digestArguments(string $ref): array
    {
        return ['image', 'inspect', '--format', '{{range .RepoDigests}}{{println .}}{{end}}', $ref];
    }

    /** The image's content id (what `docker rmi` takes), and whether it is on the server at all. @return list<string> */
    public static function idArguments(string $ref): array
    {
        return ['image', 'inspect', '--format', '{{.Id}}', $ref];
    }

    /**
     * The digest for $ref from `docker image inspect`'s lines: the one for the same repository, else the only one there is.
     * Null when the image has none (a locally built one) or several that cannot be told apart.
     */
    public static function parseDigest(string $output, string $ref): ?string
    {
        $repo = self::repository($ref);
        $found = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/^(.+)@(sha256:[0-9a-f]{64})$/', trim($line), $m)) {
                $found[$m[1]] = $m[2];
            }
        }
        foreach ([$repo, 'docker.io/'.$repo, 'docker.io/library/'.$repo, 'library/'.$repo] as $candidate) {
            if (isset($found[$candidate])) {
                return $found[$candidate];
            }
        }

        return count($found) === 1 ? array_values($found)[0] : null;
    }

    /** The reference without its tag or digest: ghcr.io/acme/app:1.2 gives ghcr.io/acme/app (a registry port is kept). */
    public static function repository(string $ref): string
    {
        $ref = (string) preg_replace('/@sha256:[0-9a-f]{64}$/', '', $ref);
        $slash = (int) strrpos($ref, '/');

        return ($colon = strrpos($ref, ':')) !== false && $colon > $slash ? substr($ref, 0, $colon) : $ref;
    }

    /** The registry host a reference names; Docker Hub for a plain one. */
    public static function registry(string $ref): string
    {
        $first = explode('/', $ref, 2)[0];

        return str_contains($ref, '/') && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost') ? $first : 'docker.io';
    }

    /**
     * Log in to a private registry for one pull. The token is in a 0600 root file and is read on standard input
     * (`--password-stdin`), so it is in no argument, no environment and no step description. The credentials live in a
     * DOCKER_CONFIG folder of this application's own (0700, root's), never in /root/.docker, so no other application's pull
     * can use them; forget() removes both afterwards. $token is the only secret and goes only into the `file` step.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function login(string $name, string $server, string $user, #[\SensitiveParameter] string $token): array
    {
        return ['name' => "app-login-{$name}", 'steps' => [
            ['id' => 'dir-base', 'type' => 'dir', 'path' => self::AUTH_DIR, 'mode' => '0700'],
            ['id' => 'dir-config', 'type' => 'dir', 'path' => self::configDir($name), 'mode' => '0700'],
            ['id' => 'token', 'type' => 'file', 'path' => self::tokenFile($name), 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode($token)],
            ['id' => 'login', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'DOCKER_CONFIG="$1" exec docker login --username "$2" --password-stdin "$3" < "$4"', 'sh', self::configDir($name), $user, $server, self::tokenFile($name)],
                'description' => "log in to {$server} as {$user} (the token is read from a file, not passed as an argument)"],
        ]];
    }

    /** Removes the token file and the per-application config folder; run after every pull, whether or not it worked. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function forget(string $name): array
    {
        return ['name' => "app-logout-{$name}", 'steps' => [
            ['id' => 'forget-token', 'type' => 'absent', 'path' => self::tokenFile($name)],
            ['id' => 'forget-config', 'type' => 'absent', 'path' => self::configDir($name)],
        ]];
    }

    public static function configDir(string $name): string
    {
        return self::AUTH_DIR."/{$name}";
    }

    public static function tokenFile(string $name): string
    {
        return self::AUTH_DIR."/{$name}.token";
    }

    /**
     * Pull a tag or a digest. With $withLogin the pull uses the per-application DOCKER_CONFIG the login created.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function pull(string $name, string $ref, bool $withLogin): array
    {
        $script = ($withLogin ? 'DOCKER_CONFIG="$1" ' : '').'exec docker pull --quiet "$2"';

        return ['name' => "app-pull-{$name}", 'steps' => [
            ['id' => 'pull', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script, 'sh', $withLogin ? self::configDir($name) : '', $ref], 'description' => "pull {$ref}"],
        ]];
    }

    /**
     * The environment file and the named volumes. Nothing is started.
     *
     * @param  array{name: string, env_file: string, env: array<string, string>, volumes: list<array{name: string, path: string}>}  $c
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function prepare(array $c): array
    {
        $steps = [
            ['id' => 'dir-env', 'type' => 'dir', 'path' => self::ENV_DIR, 'mode' => '0700'],
            ['id' => 'env', 'type' => 'file', 'path' => $c['env_file'], 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode(ContainerRecipes::envFile($c['env']))],
        ];
        foreach ($c['volumes'] as $i => $v) {
            $steps[] = ['id' => "volume-{$i}", 'type' => 'command', 'name' => 'docker',
                'args' => ['volume', 'create', '--label', 'cloud-manager.app='.$c['name'], $v['name']],
                'unless' => ['docker', 'volume', 'inspect', $v['name']], 'description' => "volume {$v['name']} exists"];
        }

        return ['name' => "app-prepare-{$c['name']}", 'steps' => $steps];
    }

    /**
     * `docker run` for a new container beside the live one, published on 127.0.0.1 only, with the flags every container of ours
     * gets (ContainerRecipes::confinement).
     *
     * @param  array{name: string, container: string, image: string, env_file: string, memory: string, read_only: bool, volumes: list<array{name: string, path: string}>, host_port: int, container_port: int}  $c
     * @return list<string> the arguments after `docker`
     */
    public static function runArguments(array $c): array
    {
        $args = ['run', '-d', '--name', $c['container'], '--restart', 'unless-stopped',
            '--label', 'cloud-manager.app='.$c['name'],
            '--env-file', $c['env_file'],
            ...ContainerRecipes::confinement($c['memory'], null, $c['read_only'], null)];
        foreach ($c['volumes'] as $v) {
            array_push($args, '-v', "{$v['name']}:{$v['path']}");
        }
        array_push($args, '-p', "127.0.0.1:{$c['host_port']}:{$c['container_port']}");
        $args[] = $c['image'];

        return $args;
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public static function run(array $c): array
    {
        return ['name' => "app-run-{$c['name']}", 'steps' => [
            ['id' => 'remove-old', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'docker rm -f "$1" >/dev/null 2>&1 || true', 'sh', $c['container']],
                'description' => 'a leftover container of this name is removed'],
            ['id' => 'run', 'type' => 'command', 'name' => 'docker', 'args' => self::runArguments($c), 'description' => "start {$c['container']} beside the live one, published on 127.0.0.1 only"],
        ]];
    }

    /**
     * Wait until the new container answers on its host port; fail at once if it has stopped. The old container is still serving.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function health(array $c, array $health): array
    {
        $alive = '[ "$(docker inspect -f \'{{.State.Running}}\' "$1" 2>/dev/null)" = true ] || { echo "the container stopped; read its log with: docker logs $1"; exit 1; }';
        $probe = ServiceRecipes::probe($health, (int) $c['host_port']);
        $script = 'for i in $(seq 1 30); do '.$alive.'; if '.$probe.'; then exit 0; fi; sleep 2; done; echo "no answer on 127.0.0.1:'.(int) $c['host_port'].' in 60 seconds"; exit 1';

        return ['name' => "app-health-{$c['name']}", 'steps' => [
            ['id' => 'health', 'type' => 'command', 'name' => 'bash', 'args' => ['-c', $script, 'bash', $c['container']],
                'description' => "{$c['container']} is running and answers on 127.0.0.1:{$c['host_port']}"],
        ]];
    }

    /** Remove a container (stopping it): the new one after a failed health check or switch, the old one after a good switch. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function discard(string $name, string $container): array
    {
        return ['name' => "app-discard-{$name}", 'steps' => [
            ['id' => 'remove', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'docker rm -f "$1" >/dev/null 2>&1 || true', 'sh', $container], 'description' => "{$container} is stopped and removed"],
        ]];
    }

    /**
     * Images older than the ones kept, removed by id (best effort: Docker refuses one a container still uses, and the running
     * image is never in the list anyway).
     *
     * @param  list<string>  $ids
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function prune(string $name, array $ids): array
    {
        return ['name' => "app-prune-{$name}", 'steps' => [
            ['id' => 'prune', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'for i in "$@"; do docker rmi "$i" >/dev/null 2>&1; done; true', 'sh', ...$ids],
                'description' => 'images older than the previous two are removed'],
        ]];
    }

    /**
     * What removing an application takes off the server: its containers (found by label), its environment file, and, unless the
     * data is kept, its images and volumes.
     *
     * @param  list<string>  $imageIds  the images an image application pulled (they carry no label of ours)
     * @return list<array<string, mixed>>
     */
    public static function removal(string $name, bool $keepData, array $imageIds = []): array
    {
        $label = escapeshellarg("label=cloud-manager.app={$name}");
        $steps = [
            ['id' => 'containers', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', "docker ps -aq --filter {$label} | xargs -r docker rm -f"], 'description' => "the containers of {$name} are stopped and removed"],
            ['id' => 'app-env', 'type' => 'absent', 'path' => self::envPath($name)],
            ['id' => 'app-auth', 'type' => 'absent', 'path' => self::tokenFile($name)],
            ['id' => 'app-auth-dir', 'type' => 'absent', 'path' => self::configDir($name)],
        ];
        if (! $keepData) {
            $steps[] = ['id' => 'images', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', "docker images -q --filter {$label} | sort -u | xargs -r docker rmi >/dev/null 2>&1; true"], 'description' => 'its built images are removed'];
            if ($imageIds !== []) {
                $steps[] = ['id' => 'pulled-images', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'for i in "$@"; do docker rmi "$i" >/dev/null 2>&1; done; true', 'sh', ...$imageIds], 'description' => 'the images it pulled are removed'];
            }
            $steps[] = ['id' => 'volumes', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', "docker volume ls -q --filter {$label} | xargs -r docker volume rm"], 'description' => 'its volumes are deleted'];
        }

        return $steps;
    }
}
