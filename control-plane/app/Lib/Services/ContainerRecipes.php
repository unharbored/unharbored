<?php

declare(strict_types=1);

namespace App\Lib\Services;

use App\Lib\Templates\Definition;
use InvalidArgumentException;

/**
 * Recipes for a container service: the container branch of ServiceRecipes, with the same method names so
 * ServiceRunner drives both through one pipeline (check, fetch, prepare, run, proxy, health). Pure: no
 * database, no gateway. The nginx vhost and certificate steps are ServiceRecipes' and shared.
 *
 * On the server:
 *   container cm-svc-{name}                 --restart unless-stopped, every port bound to 127.0.0.1
 *   volume    cm-svc-{name}-{volume}        named volumes only; a failure or a removal that keeps the data leaves them
 *   /etc/cloud-manager/services/{name}.env  root-owned 0600, handed to `docker run --env-file`, never in an argument
 */
final class ContainerRecipes
{
    /** Host ports for containers: allocated from here, one per published port, unique on the node. */
    public const PORT_MIN = 23000;

    public const PORT_MAX = 23999;

    private const PIDS_LIMIT = '512';

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string>  $secrets
     * @param  array<int, int>  $hostPorts  container port => allocated host port (empty until allocated)
     * @return array<string, mixed>
     */
    public static function context(Definition $d, string $name, array $inputs, array $secrets, array $hostPorts = []): array
    {
        $runs = $d->render($inputs + ['name' => $name], $secrets);
        $secretInputs = array_column(array_filter($d->inputs, fn ($i) => $i['type'] === 'secret'), 'key');

        $volumes = [];
        foreach ($runs['volumes'] as $v) {
            [$vol, $path, $mode] = array_pad(explode(':', $v, 3), 3, 'rw');
            $full = "cm-svc-{$name}-{$vol}";
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $full)) {
                throw new InvalidArgumentException("The volume name {$full} is not one Docker accepts.");
            }
            $volumes[] = ['name' => $full, 'path' => $path, 'mode' => $mode];
        }
        foreach ($runs['env'] as $k => $v) {
            if (preg_match('/[\r\n\0]/', (string) $v)) {
                throw new InvalidArgumentException("The value of {$k} has a line break; an environment file holds one line per value.");
            }
        }

        $digest = substr((string) strstr($runs['image'], '@'), 1);
        $limits = $runs['limits'] + ['memory' => $d->memory];

        return [
            'runtime' => 'container',
            'name' => $name,
            'version' => $d->version,
            'user' => '',
            'run_as' => $runs['user'] ?? null,
            'container' => self::container($name),
            'unit' => self::container($name),
            'image' => $d->imageRepository().'@'.$digest,
            'repository' => $d->imageRepository(),
            'digest' => $digest,
            'volumes' => $volumes,
            'data' => $volumes === [] ? 'no volume' : 'docker volume '.implode(', ', array_column($volumes, 'name')),
            'read_only' => (bool) ($runs['read_only'] ?? false),
            'capabilities' => $runs['capabilities'] ?? [],
            'env_file' => ServiceRecipes::ENV_DIR."/{$name}.env",
            'env' => $runs['env'],
            'ports' => $runs['ports'],
            'host_ports' => $hostPorts,
            'limits' => $limits,
            'health' => $runs['health'],
            'proxy' => $d->proxy,
            'setup' => null,
            'inputs' => array_diff_key(array_map('strval', array_filter($inputs, 'is_scalar')), array_flip($secretInputs)),
        ];
    }

    public static function container(string $name): string
    {
        return "cm-svc-{$name}";
    }

    /** Docker is running, and nginx and certbot are there for a service with a domain. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function check(array $c): array
    {
        $need = $c['ports'] === [] ? ['docker'] : ['docker', 'nginx', 'certbot'];
        $script = 'for c in '.implode(' ', $need).'; do command -v "$c" >/dev/null 2>&1 || { echo "$c is not installed on this server; '
            .'prepare it for containers and install the server stack first"; exit 1; }; done; '
            .'systemctl is-active --quiet docker || { echo "Docker is not running on this server"; exit 1; }';

        return ['name' => "service-check-{$c['name']}", 'steps' => [
            ['id' => 'tools', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'Docker is running, and the server has '.implode(', ', $need)],
        ]];
    }

    /**
     * Pull by digest, then check that the image Docker now holds carries that digest. A mismatch removes nothing
     * but starts nothing: the run stage never happens.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function fetch(array $c): array
    {
        $e = 'escapeshellarg';
        $script = implode("\n", [
            'set -e',
            'docker pull --quiet '.$e($c['image']).' >/dev/null',
            'docker image inspect --format \'{{range .RepoDigests}}{{println .}}{{end}}\' '.$e($c['image']).' | grep -Fq '.$e('@'.$c['digest'])
                .' || { echo "the pulled image does not carry the digest in the template ('.substr($c['digest'], 0, 19).'...); nothing was started"; exit 1; }',
        ]);

        return ['name' => "service-fetch-{$c['name']}", 'steps' => [
            ['id' => 'pull', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'pull '.$c['repository'].' by digest and check it'],
        ]];
    }

    /** The environment file and the named volumes. Nothing is started. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function prepare(array $c): array
    {
        $steps = [
            ['id' => 'dir-env', 'type' => 'dir', 'path' => ServiceRecipes::ENV_DIR, 'mode' => '0700'],
            ['id' => 'env', 'type' => 'file', 'path' => $c['env_file'], 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode(self::envFile($c['env']))],
        ];
        foreach ($c['volumes'] as $i => $v) {
            $steps[] = ['id' => "volume-{$i}", 'type' => 'command', 'name' => 'docker',
                'args' => ['volume', 'create', '--label', 'cloud-manager.service='.$c['name'], $v['name']],
                'unless' => ['docker', 'volume', 'inspect', $v['name']], 'description' => "volume {$v['name']} exists"];
        }

        return ['name' => "service-prepare-{$c['name']}", 'steps' => $steps];
    }

    /** `docker run --env-file` reads NAME=value lines literally: no quoting and no escapes. */
    public static function envFile(array $env): string
    {
        $out = "# Managed by cloud-manager. Root-owned, read by docker run --env-file.\n";
        foreach ($env as $k => $v) {
            $out .= $k.'='.$v."\n";
        }

        return $out;
    }

    /**
     * The flags that confine a container, in one place: services (runArguments) and applications (AppRecipes) both start
     * their containers with exactly these. Never privileged, never the host network, no added capability unless the template names one from a short list (the confinement still drops ALL first), no host mount
     * (the callers add only named volumes), a memory and process limit, and a read-only root filesystem when asked.
     *
     * @param  string  $memoryBytes  the limit in bytes; swap is set to the same so the container cannot use more
     * @return list<string>
     */
    public static function confinement(string $memoryBytes, ?float $cpu, bool $readOnly, ?string $runAs, array $capabilities = []): array
    {
        $args = ['--memory', $memoryBytes, '--memory-swap', $memoryBytes,
            '--pids-limit', self::PIDS_LIMIT,
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges'];
        foreach ($capabilities as $cap) {
            array_push($args, '--cap-add', $cap);
        }
        if ($cpu !== null) {
            $args[] = '--cpus';
            $args[] = rtrim(rtrim(number_format($cpu, 2, '.', ''), '0'), '.');
        }
        if ($readOnly) {
            array_push($args, '--read-only', '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m');
        }
        if ($runAs !== null) {
            array_push($args, '--user', $runAs);
        }

        return $args;
    }

    /**
     * The container, started. An older container of the same name is stopped and removed first (its volumes stay), so a retry
     * converges. Every flag that confines it is here, in one list.
     *
     * @return list<string> the arguments after `docker`
     */
    public static function runArguments(array $c): array
    {
        $args = ['run', '-d', '--name', $c['container'], '--restart', 'unless-stopped',
            '--label', 'cloud-manager.service='.$c['name'],
            '--env-file', $c['env_file'],
            ...self::confinement((string) Definition::parseSize((string) $c['limits']['memory']), isset($c['limits']['cpu']) ? (float) $c['limits']['cpu'] : null, (bool) $c['read_only'], $c['run_as'], $c['capabilities'] ?? [])];
        foreach ($c['volumes'] as $v) {
            array_push($args, '-v', "{$v['name']}:{$v['path']}".($v['mode'] === 'ro' ? ':ro' : ''));
        }
        foreach ($c['host_ports'] as $container => $host) {
            array_push($args, '-p', "127.0.0.1:{$host}:{$container}");
        }
        $args[] = $c['image'];

        return $args;
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public static function run(array $c): array
    {
        $name = escapeshellarg($c['container']);

        return ['name' => "service-run-{$c['name']}", 'steps' => [
            ['id' => 'remove-old', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', "docker rm -f {$name} >/dev/null 2>&1 || true"],
                'description' => 'an older container of this name is stopped and removed (its volumes stay)'],
            ['id' => 'run', 'type' => 'command', 'name' => 'docker', 'args' => self::runArguments($c), 'description' => "start {$c['container']}, published on 127.0.0.1 only"],
        ]];
    }

    /** Waits until the container answers on its host port, and fails at once if it has stopped. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function health(array $c): array
    {
        $container = $c['container'];
        $port = (int) ($c['host_ports'][$c['health']['port'] ?? array_key_first($c['ports'])] ?? array_values($c['host_ports'])[0] ?? 0);
        $alive = '[ "$(docker inspect -f \'{{.State.Running}}\' '.escapeshellarg($container).' 2>/dev/null)" = true ] || { echo "the container stopped; read its log with: docker logs '.$container.'"; exit 1; }';
        // An answer is not enough: an image can listen and then die (Uptime Kuma did, with a capability missing), so after the first
        // answer the container must stay up for ten more seconds without having restarted.
        $settle = 'for j in 1 2 3 4 5; do sleep 2; '.$alive.'; [ "$(docker inspect -f \'{{.RestartCount}}\' '.escapeshellarg($container).')" = 0 ] || { echo "the container answered, then restarted; read its log with: docker logs '.$container.'"; exit 1; }; done; exit 0';
        $script = 'for i in $(seq 1 30); do '.$alive.'; if '.ServiceRecipes::probe($c['health'], $port).'; then '.$settle.'; fi; sleep 2; done; echo "no answer on 127.0.0.1:'.$port.' in 60 seconds"; exit 1';

        return ['name' => "service-health-{$c['name']}", 'steps' => [
            ['id' => 'health', 'type' => 'command', 'name' => 'bash', 'args' => ['-c', $script], 'description' => "{$container} is running and answers on 127.0.0.1:{$port}"],
        ]];
    }

    /** Remove the container (stopping it); its volumes are never touched. Used to clean up a failed install. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function stop(string $name): array
    {
        return ['name' => "service-stop-{$name}", 'steps' => [
            ['id' => 'stop', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'docker rm -f '.escapeshellarg(self::container($name)).' >/dev/null 2>&1 || true'],
                'description' => 'the container is removed; its volumes stay'],
        ]];
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public static function action(string $name, string $action): array
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new InvalidArgumentException("Unknown action {$action}.");
        }

        return ['name' => "service-{$action}-{$name}", 'steps' => [
            ['id' => $action, 'type' => 'command', 'name' => 'docker', 'args' => [$action, self::container($name)], 'description' => "docker {$action} ".self::container($name)],
        ]];
    }

    // ---- update ---------------------------------------------------------------------------------------------

    public static function previousContainer(string $name): string
    {
        return self::container($name).'-previous';
    }

    /** The image now running must still be on the server, or a failed update could not be put back. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function updateCheck(string $name, string $image): array
    {
        return ['name' => "service-update-check-{$name}", 'steps' => [
            ['id' => 'previous-image', 'type' => 'command', 'name' => 'docker', 'args' => ['image', 'inspect', '--format', '{{.Id}}', $image], 'description' => 'the current image is still on the server'],
        ]];
    }

    /**
     * Switch: keep the environment file when it will change, stop the running container and rename it (not remove it: it is what
     * switchBack starts again), then run the new image with the same volumes, ports and environment file.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function updateSwitch(array $c, bool $envChanged): array
    {
        $prev = ServiceRecipes::previousDir($c['name']);
        $old = escapeshellarg(self::container($c['name']));
        $kept = escapeshellarg(self::previousContainer($c['name']));
        $steps = [
            ...ServiceRecipes::backupDirs($c['name']),
            ['id' => 'keep-previous', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "docker rm -f {$kept} >/dev/null 2>&1; rm -rf ".escapeshellarg($prev).' && mkdir -p -m 0700 '.escapeshellarg($prev)
                    .($envChanged ? ' && { [ ! -f '.escapeshellarg($c['env_file']).' ] || cp -p '.escapeshellarg($c['env_file']).' '.escapeshellarg("{$prev}/env").'; }' : '')],
                'description' => 'an older kept container is removed'.($envChanged ? ' and the current environment file is kept' : '')],
            ['id' => 'stop-old', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', "docker stop {$old} >/dev/null && docker rename {$old} {$kept} && docker update --restart no {$kept} >/dev/null"],
                'description' => 'the running container is stopped and kept under another name'],
        ];
        if ($envChanged) {
            $steps[] = ['id' => 'env', 'type' => 'file', 'path' => $c['env_file'], 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode(self::envFile($c['env']))];
        }
        array_push($steps, ...self::run($c)['steps']);

        return ['name' => "service-update-switch-{$c['name']}", 'steps' => $steps];
    }

    /** Removes the new container, starts the kept one under its own name again, and checks it stayed up. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function updateSwitchBack(array $c, bool $envChanged): array
    {
        $prev = ServiceRecipes::previousDir($c['name']);
        $old = escapeshellarg(self::container($c['name']));
        $kept = escapeshellarg(self::previousContainer($c['name']));
        $script = "if docker inspect {$kept} >/dev/null 2>&1; then docker rm -f {$old} >/dev/null 2>&1; docker rename {$kept} {$old}; fi; "
            .($envChanged ? '[ ! -f '.escapeshellarg("{$prev}/env").' ] || cp -p '.escapeshellarg("{$prev}/env").' '.escapeshellarg($c['env_file']).'; ' : '')
            ."docker update --restart unless-stopped {$old} >/dev/null && docker start {$old} >/dev/null && sleep 3 && [ \"$(docker inspect -f '{{.State.Running}}' {$old})\" = true ]"
            .' || { echo "the previous container did not stay up"; exit 1; }';

        return ['name' => "service-update-back-{$c['name']}", 'steps' => [
            ['id' => 'put-back', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'the new container is removed and the previous one started again'],
        ]];
    }

    /**
     * After a good update: the kept container and environment file go, and images older than the two previous ones are removed
     * (best effort; an image something else uses stays). $drop is the list of image references to remove, never the running one.
     *
     * @param  list<string>  $drop
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function updateFinish(string $name, array $drop): array
    {
        $script = 'docker rm -f '.escapeshellarg(self::previousContainer($name)).' >/dev/null 2>&1; rm -rf '.escapeshellarg(ServiceRecipes::previousDir($name)).'; '
            .implode(' ', array_map(fn ($ref) => 'docker rmi '.escapeshellarg($ref).' >/dev/null 2>&1;', $drop)).' true';

        return ['name' => "service-update-finish-{$name}", 'steps' => [
            ['id' => 'prune', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'the kept container is removed, and images older than the two previous ones'],
        ]];
    }

    /** Stop and remove the container and take its environment file off the server. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function removeUnit(string $name): array
    {
        return ['name' => "service-remove-unit-{$name}", 'steps' => [
            ['id' => 'stop', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'docker rm -f '.escapeshellarg(self::container($name)).' >/dev/null 2>&1 || true'], 'description' => 'the container is stopped and removed'],
            ['id' => 'env', 'type' => 'absent', 'path' => ServiceRecipes::ENV_DIR."/{$name}.env"],
        ]];
    }

    /**
     * The service's volumes, found by the label they were created with, removed only when the person chose to delete the data.
     * Returns null when the data is kept: nothing to apply.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}|null
     */
    public static function removeVolumes(string $name, bool $deleteData): ?array
    {
        if (! $deleteData) {
            return null;
        }

        return ['name' => "service-remove-files-{$name}", 'steps' => [
            ['id' => 'volumes', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'docker volume ls -q --filter '.escapeshellarg('label=cloud-manager.service='.$name).' | xargs -r docker volume rm'],
                'description' => "the volumes of {$name} are deleted"],
        ]];
    }
}
