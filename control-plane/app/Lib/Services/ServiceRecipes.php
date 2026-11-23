<?php

declare(strict_types=1);

namespace App\Lib\Services;

use App\Lib\Servers\ServerStack;
use App\Lib\Templates\Definition;

/**
 * Recipes for a native service, built from the agent's existing step types
 * (file, dir, command, package, service, absent). Pure: no database, no
 * gateway, so a test can read exactly what would run on the server.
 *
 * Layout on the server, all derived from the service name:
 *   /srv/cloud-manager/services/{name}/releases/{version}   the unpacked download (root's; the service may only read it)
 *   /srv/cloud-manager/data/{name}                          its data (the service user's)
 *   /etc/cloud-manager/services/{name}.env                  root-owned 0600, read by systemd as EnvironmentFile
 *   /etc/systemd/system/cm-svc-{name}.service               the unit
 */
final class ServiceRecipes
{
    public const ROOT = '/srv/cloud-manager/services';

    public const DATA_ROOT = '/srv/cloud-manager/data';

    public const ENV_DIR = '/etc/cloud-manager/services';

    /**
     * Everything a recipe needs, from a definition and one install's filled-in values.
     *
     * @param  array<string, mixed>  $inputs  every input, secrets included, as supplied
     * @param  array<string, string>  $secrets  the generated secrets
     * @return array<string, mixed>
     */
    public static function context(Definition $d, string $name, array $inputs, array $secrets, array $hostPorts = []): array
    {
        if ($d->runtime === 'container') {
            return ContainerRecipes::context($d, $name, $inputs, $secrets, $hostPorts);
        }
        $release = self::release($name, $d->version);
        $runs = $d->render($inputs + ['name' => $name], $secrets, $release.'/'.$d->runs['binary']);
        $secretInputs = array_column(array_filter($d->inputs, fn ($i) => $i['type'] === 'secret'), 'key');
        // Defence in depth: these are written into a systemd unit, where a newline or % starts a new directive or a specifier.
        foreach (['command', 'user', 'data'] as $field) {
            if (preg_match('/[\x00-\x1F\x7F%]/', (string) $runs[$field])) {
                throw new \InvalidArgumentException("The {$field} of this template contains a control character or %, which a service unit cannot carry.");
            }
        }
        if (preg_match('/[\x00-\x1F\x7F%]/', $release.'/'.$d->runs['binary'])) {
            throw new \InvalidArgumentException('The binary of this template contains a control character or %.');
        }

        return [
            'name' => $name,
            'version' => $d->version,
            'user' => $runs['user'],
            'release' => $release,
            'binary' => $release.'/'.$d->runs['binary'],
            'binary_rel' => $d->runs['binary'],
            'data' => $runs['data'],
            'env_file' => self::ENV_DIR."/{$name}.env",
            'unit' => "cm-svc-{$name}",
            'command' => $runs['command'],
            'env' => $runs['env'],
            'ports' => $runs['ports'],
            'limits' => $runs['limits'],
            'health' => $runs['health'],
            'proxy' => $d->proxy,
            'setup' => $runs['setup'] ?? null,
            'unpack' => $runs['unpack'],
            'download' => $runs['download'],
            'sha256' => $runs['sha256'],
            // Plain inputs only, for a setup action's arguments; a secret input never goes here.
            'inputs' => array_diff_key(array_map('strval', array_filter($inputs, 'is_scalar')), array_flip($secretInputs)),
        ];
    }

    public static function release(string $name, int $version): string
    {
        return self::ROOT."/{$name}/releases/{$version}";
    }

    public static function unit(string $name): string
    {
        return "cm-svc-{$name}";
    }

    /** What the server must already have: systemd, and for a service with a domain, nginx and certbot (the server stack). @return array{name: string, steps: list<array<string, mixed>>} */
    public static function check(array $c): array
    {
        $need = $c['ports'] === [] ? ['systemctl'] : ['systemctl', 'nginx', 'certbot'];
        $script = 'for c in '.implode(' ', $need).'; do command -v "$c" >/dev/null 2>&1 || { echo "$c is not installed on this server; install the server stack first"; exit 1; }; done';

        return ['name' => "service-check-{$c['name']}", 'steps' => [
            ['id' => 'tools', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'the server has '.implode(', ', $need)],
        ]];
    }

    /**
     * Download to a temporary file, check its sha256, only then unpack, into a folder that is moved into place whole.
     * Any failure removes the temporary file and leaves no release folder, so a retry starts clean.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function fetch(array $c): array
    {
        $home = self::ROOT."/{$c['name']}";
        $part = $c['release'].'.partial';
        $bin = $c['binary'];
        $e = 'escapeshellarg';
        $unpack = match ($c['unpack']) {
            'zip' => 'unzip -q "$tmp" -d '.$e($part),
            'tar.gz' => 'tar -xzf "$tmp" --no-same-owner -C '.$e($part),
            default => 'mkdir -p '.$e(dirname($part.'/'.$c['binary_rel'])).' && install -m 0755 "$tmp" '.$e($part.'/'.$c['binary_rel']),
        };
        $script = implode("\n", [
            'set -e',
            'tmp=$(mktemp -p '.$e($home).')',
            'trap \'rm -f "$tmp"\' EXIT',
            'curl -fsSL --proto =https --max-time 900 -o "$tmp" '.$e($c['download']),
            'echo '.$e($c['sha256'].'  ').'"$tmp" | sha256sum -c - >/dev/null || { echo "the download does not match the sha256 in the template; nothing was unpacked"; exit 1; }',
            'rm -rf '.$e($part),
            'mkdir -p '.$e($part),
            $unpack,
            'chmod -R go-w,a+rX '.$e($part),
            'chmod 0755 '.$e($part.'/'.$c['binary_rel']),
            'mv '.$e($part).' '.$e($c['release']),
        ]);

        return ['name' => "service-fetch-{$c['name']}", 'steps' => [
            ['id' => 'curl', 'type' => 'package', 'name' => 'curl'],
            ...($c['unpack'] === 'zip' ? [['id' => 'unzip', 'type' => 'package', 'name' => 'unzip']] : []),
            ['id' => 'dir-services', 'type' => 'dir', 'path' => self::ROOT, 'mode' => '0755'],
            ['id' => 'dir-service', 'type' => 'dir', 'path' => $home, 'mode' => '0755'],
            ['id' => 'dir-releases', 'type' => 'dir', 'path' => "{$home}/releases", 'mode' => '0755'],
            ['id' => 'download', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script],
                'unless' => ['test', '-e', $bin],
                'description' => 'download, check the sha256, then unpack '.basename($c['download'])],
        ]];
    }

    /** The user, folders, environment file and unit. Nothing is started. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function prepare(array $c): array
    {
        $owned = ['owner' => $c['user'], 'base' => self::DATA_ROOT];
        $steps = [
            ['id' => 'user', 'type' => 'command', 'name' => 'useradd',
                'args' => ['--system', '--no-create-home', '--home-dir', '/nonexistent', '--shell', '/usr/sbin/nologin', $c['user']],
                'unless' => ['id', '-u', $c['user']], 'description' => "system user {$c['user']}, no login, no shell"],
            ['id' => 'dir-data-root', 'type' => 'dir', 'path' => self::DATA_ROOT, 'mode' => '0755'],
            ['id' => 'dir-data', 'type' => 'dir', 'path' => $c['data'], 'mode' => '0750'] + $owned,
        ];
        if (SetupActions::writesRelease((string) $c['setup'])) {
            $steps[] = ['id' => 'release-owner', 'type' => 'command', 'name' => 'chown', 'args' => ['-R', $c['user'].':', $c['release']], 'description' => 'the release folder belongs to the service user (it keeps its registration there)'];
        }
        $steps[] = ['id' => 'dir-env', 'type' => 'dir', 'path' => self::ENV_DIR, 'mode' => '0700'];
        $steps[] = ['id' => 'env', 'type' => 'file', 'path' => $c['env_file'], 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode(self::envFile($c['env']))];
        $steps[] = ['id' => 'unit', 'type' => 'file', 'path' => "/etc/systemd/system/{$c['unit']}.service", 'mode' => '0644', 'content_b64' => base64_encode(self::unitFile($c))];
        $steps[] = ['id' => 'daemon-reload', 'type' => 'command', 'name' => 'systemctl', 'args' => ['daemon-reload'], 'description' => 'systemd reads the new unit'];

        return ['name' => "service-prepare-{$c['name']}", 'steps' => $steps];
    }

    /** systemd's EnvironmentFile format: NAME="value", with \ and " escaped. */
    public static function envFile(array $env): string
    {
        $out = "# Managed by cloud-manager. Root-owned, read by systemd.\n";
        foreach ($env as $k => $v) {
            $out .= $k.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v)."\"\n";
        }

        return $out;
    }

    public static function unitFile(array $c): string
    {
        $writable = $c['data'].(SetupActions::writesRelease((string) $c['setup']) ? ' '.$c['release'] : '');
        $dir = SetupActions::writesRelease((string) $c['setup']) ? $c['release'] : $c['data'];
        $limits = '';
        if (isset($c['limits']['memory'])) {
            $limits .= 'MemoryMax='.Definition::parseSize($c['limits']['memory'])."\n";
        }
        if (isset($c['limits']['cpu'])) {
            $limits .= 'CPUQuota='.(int) round($c['limits']['cpu'] * 100)."%\n";
        }

        return <<<UNIT
# Managed by cloud-manager. Changes here are overwritten.
[Unit]
Description={$c['name']} (Unharbored service)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User={$c['user']}
WorkingDirectory={$dir}
EnvironmentFile={$c['env_file']}
Environment=HOME={$c['data']}
ExecStart={$c['command']}
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths={$writable}
{$limits}
[Install]
WantedBy=multi-user.target

UNIT;
    }

    /** The setup action, if the definition names one, then enable and start. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function run(array $c): array
    {
        $steps = $c['setup'] ? SetupActions::steps($c['setup'], $c) : [];
        $steps[] = ['id' => 'start', 'type' => 'service', 'name' => $c['unit'], 'state' => 'running'];

        return ['name' => "service-run-{$c['name']}", 'steps' => $steps];
    }

    /** Waits until the service answers, and fails at once if it has stopped. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function health(array $c): array
    {
        $port = $c['health']['port'] ?? array_key_first($c['ports']);
        $alive = 'systemctl is-active --quiet '.escapeshellarg($c['unit']).' || { echo "the service stopped; read its log with: journalctl -u '.$c['unit'].'"; exit 1; }';
        $probe = self::probe($c['health'], (int) $port);
        $script = 'for i in $(seq 1 30); do '.$alive.'; if '.$probe.'; then exit 0; fi; sleep 2; done; echo "no answer on 127.0.0.1:'.(int) $port.' in 60 seconds"; exit 1';

        return ['name' => "service-health-{$c['name']}", 'steps' => [
            ['id' => 'health', 'type' => 'command', 'name' => 'bash', 'args' => ['-c', $script], 'description' => 'answers on 127.0.0.1:'.(int) $port],
        ]];
    }

    /** The shell test for "answers on 127.0.0.1:port": an HTTP path that returns 2xx or 3xx, or a TCP connect. */
    public static function probe(array $health, int $port): string
    {
        return isset($health['path'])
            ? 'curl -s -o /dev/null --max-time 3 -w "%{http_code}" '.escapeshellarg('http://127.0.0.1:'.$port.$health['path']).' | grep -qE "^[23]"'
            : 'timeout 3 bash -c '.escapeshellarg('echo > /dev/tcp/127.0.0.1/'.$port).' 2>/dev/null';
    }

    /** Stop and disable the unit; used to stop a failed install and for the stop action. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function stop(string $name): array
    {
        return ['name' => "service-stop-{$name}", 'steps' => [['id' => 'stop', 'type' => 'service', 'name' => self::unit($name), 'state' => 'stopped']]];
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public static function action(string $name, string $action): array
    {
        $unit = self::unit($name);
        $steps = match ($action) {
            'start' => [['id' => 'start', 'type' => 'service', 'name' => $unit, 'state' => 'running']],
            'stop' => [['id' => 'stop', 'type' => 'service', 'name' => $unit, 'state' => 'stopped']],
            'restart' => [
                ['id' => 'enable', 'type' => 'command', 'name' => 'systemctl', 'args' => ['enable', $unit], 'description' => "{$unit} starts at boot"],
                ['id' => 'restart', 'type' => 'command', 'name' => 'systemctl', 'args' => ['restart', $unit], 'description' => "restart {$unit}"],
            ],
            default => throw new \InvalidArgumentException("Unknown action {$action}."),
        };

        return ['name' => "service-{$action}-{$name}", 'steps' => $steps];
    }

    // ---- update (docs/specs/templates.md section 5, step 5) ------------------------------------------------------

    /** Backups, and what an update keeps to put the old unit back, live here. 0700, root's. */
    public const BACKUP_DIR = '/var/backups/cloud-manager/services';

    public static function previousDir(string $name): string
    {
        return self::BACKUP_DIR."/{$name}/update-prev";
    }

    /** The folders under BACKUP_DIR down to $leaf, each 0700 (the dir step makes one level). @return list<array<string, mixed>> */
    public static function backupDirs(string $name, string $leaf = ''): array
    {
        $paths = ['/var/backups/cloud-manager', self::BACKUP_DIR, self::BACKUP_DIR."/{$name}"];
        if ($leaf !== '') {
            $paths[] = $leaf;
        }

        return array_map(fn ($p, $i) => ['id' => "dir-backups-{$i}", 'type' => 'dir', 'path' => $p, 'mode' => '0700'], $paths, array_keys($paths));
    }

    /** The release being left must still be on the server, or a failed update could not be put back. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function updateCheck(string $name, int $fromVersion): array
    {
        $release = self::release($name, $fromVersion);

        return ['name' => "service-update-check-{$name}", 'steps' => [
            ['id' => 'previous-release', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'test -d '.escapeshellarg($release).' || { echo "the previous release folder '.$release.' is gone"; exit 1; }'],
                'description' => "the current release ({$release}) is still on the server"],
        ]];
    }

    /**
     * Native switch: keep the unit (and the environment file, when it is going to change) where switchBack can find them,
     * stop, write the unit for the new release, start. The data folder is not touched.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function updateSwitch(array $c, bool $envChanged): array
    {
        $prev = self::previousDir($c['name']);
        $unitPath = "/etc/systemd/system/{$c['unit']}.service";
        $keep = 'rm -rf '.escapeshellarg($prev).' && mkdir -p -m 0700 '.escapeshellarg($prev).' && cp -p '.escapeshellarg($unitPath).' '.escapeshellarg("{$prev}/unit")
            .($envChanged ? ' && { [ ! -f '.escapeshellarg($c['env_file']).' ] || cp -p '.escapeshellarg($c['env_file']).' '.escapeshellarg("{$prev}/env").'; }' : '');
        $steps = [
            ...self::backupDirs($c['name']),
            ['id' => 'keep-previous', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $keep], 'description' => 'the current unit'.($envChanged ? ' and environment file' : '').' are kept to put back'],
            ['id' => 'stop', 'type' => 'service', 'name' => $c['unit'], 'state' => 'stopped'],
        ];
        if ($envChanged) {
            $steps[] = ['id' => 'env', 'type' => 'file', 'path' => $c['env_file'], 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode(self::envFile($c['env']))];
        }
        $steps[] = ['id' => 'unit', 'type' => 'file', 'path' => $unitPath, 'mode' => '0644', 'content_b64' => base64_encode(self::unitFile($c))];
        $steps[] = ['id' => 'daemon-reload', 'type' => 'command', 'name' => 'systemctl', 'args' => ['daemon-reload'], 'description' => 'systemd reads the unit for the new release'];
        $steps[] = ['id' => 'start', 'type' => 'service', 'name' => $c['unit'], 'state' => 'running'];

        return ['name' => "service-update-switch-{$c['name']}", 'steps' => $steps];
    }

    /** Puts the kept unit (and environment file) back and starts the previous release, then checks it stayed up. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function updateSwitchBack(array $c, bool $envChanged): array
    {
        $prev = self::previousDir($c['name']);
        $unitPath = "/etc/systemd/system/{$c['unit']}.service";
        $put = '[ -f '.escapeshellarg("{$prev}/unit").' ] && cp -p '.escapeshellarg("{$prev}/unit").' '.escapeshellarg($unitPath)
            .($envChanged ? '; [ -f '.escapeshellarg("{$prev}/env").' ] && cp -p '.escapeshellarg("{$prev}/env").' '.escapeshellarg($c['env_file']) : '').'; true';

        return ['name' => "service-update-back-{$c['name']}", 'steps' => [
            ['id' => 'stop', 'type' => 'service', 'name' => $c['unit'], 'state' => 'stopped'],
            ['id' => 'put-back', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $put], 'description' => 'the previous unit'.($envChanged ? ' and environment file' : '').' put back'],
            ['id' => 'daemon-reload', 'type' => 'command', 'name' => 'systemctl', 'args' => ['daemon-reload'], 'description' => 'systemd reads the previous unit'],
            ['id' => 'start', 'type' => 'service', 'name' => $c['unit'], 'state' => 'running'],
            ['id' => 'settle', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'sleep 3; systemctl is-active --quiet '.escapeshellarg($c['unit']).' || { echo "the previous release did not stay up"; exit 1; }'],
                'description' => 'the previous release is running'],
        ]];
    }

    /** After a good update: the kept unit goes, and release folders beyond the two previous ones (never the running one). @return array{name: string, steps: list<array<string, mixed>>} */
    public static function updateFinish(string $name, int $runningVersion): array
    {
        $releases = self::ROOT."/{$name}/releases";
        $script = 'rm -rf '.escapeshellarg(self::previousDir($name)).'; cd '.escapeshellarg($releases).' || exit 0; '
            .'ls -1 | grep -E "^[0-9]+$" | grep -vx '.escapeshellarg((string) $runningVersion).' | sort -rn | tail -n +'.(self::KEEP_PREVIOUS + 1).' | while read -r v; do rm -rf -- "$v"; done; true';

        return ['name' => "service-update-finish-{$name}", 'steps' => [
            ['id' => 'prune', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'release folders older than the two previous ones are removed'],
        ]];
    }

    /** How many previous releases or images an update keeps. */
    public const KEEP_PREVIOUS = 2;

    // ---- nginx -----------------------------------------------------------

    /**
     * The service's vhost for one domain. Before the certificate exists it answers HTTP only (and the ACME
     * challenge); with $tls it redirects port 80 and serves 443. Checked by nginx -t, the old file put back on failure.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function vhost(array $c, int $port, string $domain, bool $tls): array
    {
        $conf = self::vhostPath($c['name'], $port);
        $backup = "/var/backups/cloud-manager/nginx/cm-svc-{$c['name']}-{$port}.conf";

        return ['name' => "service-vhost-{$c['name']}-{$port}", 'steps' => [
            ['id' => 'nginx-names', 'type' => 'file', 'path' => '/etc/nginx/conf.d/00-server-names.conf', 'content_b64' => base64_encode(ServerStack::NGINX_NAMES), 'mode' => '0644'],
            ['id' => 'vhost-backup', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'install -d -m 0700 '.dirname($backup).' && if [ -f '.$conf.' ]; then cp -p '.$conf.' '.$backup.'; else rm -f '.$backup.'; fi'],
                'description' => 'previous web server configuration kept'],
            ['id' => 'vhost', 'type' => 'file', 'path' => $conf, 'content_b64' => base64_encode(self::vhostFile($c, $port, $domain, $tls)), 'mode' => '0644'],
            ['id' => 'nginx-test', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'nginx -t 2>&1 && exit 0; if [ -f '.$backup.' ]; then cp -p '.$backup.' '.$conf.'; else rm -f '.$conf.'; fi; exit 1'],
                'description' => 'nginx configuration is valid'],
            ['id' => 'reload-nginx', 'type' => 'service', 'name' => 'nginx', 'state' => 'reloaded'],
        ]];
    }

    public static function vhostPath(string $name, int $port): string
    {
        return "/etc/nginx/sites-enabled/cm-svc-{$name}-{$port}.conf";
    }

    public static function vhostFile(array $c, int $port, string $domain, bool $tls): string
    {
        $acme = ServerStack::ACME_WEBROOT;
        $challenge = "    location ^~ /.well-known/acme-challenge/ {\n        root {$acme};\n        default_type text/plain;\n    }";

        $limits = '';
        $max = $c['proxy']['client_max_body_size'] ?? null;
        if ($max !== null) {
            $limits .= '    client_max_body_size '.(is_int($max) ? $max : Definition::parseSize((string) $max)).";\n";
        }
        $buffering = $c['proxy']['request_buffering'] ?? null;
        if ($buffering === false || $buffering === 'off') {
            $limits .= "    proxy_request_buffering off;\n";
        }

        $proxy = <<<CONF
    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
CONF;

        $head = "# Managed by Unharbored (service {$c['name']}). Changes here are overwritten.\n";
        if (! $tls) {
            return $head."server {\n    listen 80;\n    listen [::]:80;\n    server_name {$domain};\n{$limits}\n{$challenge}\n\n{$proxy}\n}\n";
        }

        return $head."server {\n    listen 80;\n    listen [::]:80;\n    server_name {$domain};\n\n{$challenge}\n\n    location / {\n        return 301 https://\$host\$request_uri;\n    }\n}\n\n"
            ."server {\n    listen 443 ssl http2;\n    listen [::]:443 ssl http2;\n    server_name {$domain};\n"
            ."    ssl_certificate /etc/letsencrypt/live/{$domain}/fullchain.pem;\n    ssl_certificate_key /etc/letsencrypt/live/{$domain}/privkey.pem;\n"
            ."    ssl_protocols TLSv1.2 TLSv1.3;\n    ssl_session_cache shared:SSL:10m;\n    add_header Strict-Transport-Security \"max-age=31536000\" always;\n{$limits}\n{$proxy}\n}\n";
    }

    // ---- removal, in the three parts the operation's stages name ----------

    /** Stop the unit and take it and its environment file off the server. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function removeUnit(string $name): array
    {
        $unit = self::unit($name);

        return ['name' => "service-remove-unit-{$name}", 'steps' => [
            ['id' => 'stop', 'type' => 'service', 'name' => $unit, 'state' => 'stopped'],
            ['id' => 'unit', 'type' => 'absent', 'path' => "/etc/systemd/system/{$unit}.service"],
            ['id' => 'daemon-reload', 'type' => 'command', 'name' => 'systemctl', 'args' => ['daemon-reload'], 'description' => 'systemd forgets the unit'],
            ['id' => 'env', 'type' => 'absent', 'path' => self::ENV_DIR."/{$name}.env"],
        ]];
    }

    /**
     * The vhost and the certificate of each endpoint.
     *
     * @param  list<array{port: int, domain: string}>  $endpoints
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function removeEndpoints(string $name, array $endpoints): array
    {
        $steps = [];
        foreach ($endpoints as $e) {
            $steps[] = ['id' => "vhost-{$e['port']}", 'type' => 'absent', 'path' => self::vhostPath($name, $e['port'])];
        }
        $steps[] = ['id' => 'reload-nginx', 'type' => 'service', 'name' => 'nginx', 'state' => 'reloaded'];
        foreach ($endpoints as $e) {
            $cert = escapeshellarg("/etc/letsencrypt/live/{$e['domain']}");
            $steps[] = ['id' => "cert-{$e['port']}", 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "if [ -e {$cert} ]; then certbot delete --cert-name ".escapeshellarg($e['domain']).' --non-interactive; fi'],
                'description' => "certificate for {$e['domain']} removed"];
        }

        return ['name' => "service-remove-endpoints-{$name}", 'steps' => $steps];
    }

    /** The release folder always; the data folder only when it was asked for. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function removeFiles(string $name, string $user, string $dataPath, bool $deleteData): array
    {
        $home = self::ROOT."/{$name}";
        // A release folder may belong to the service user (the runner), so it goes through an owned step.
        $steps = [
            ['id' => 'releases', 'type' => 'absent', 'path' => "{$home}/releases", 'owner' => $user, 'base' => $home],
            ['id' => 'service-dir', 'type' => 'absent', 'path' => $home],
        ];
        if ($deleteData) {
            $steps[] = ['id' => 'data', 'type' => 'absent', 'path' => $dataPath, 'owner' => $user, 'base' => self::DATA_ROOT];
        }

        return ['name' => "service-remove-files-{$name}", 'steps' => $steps];
    }
}
