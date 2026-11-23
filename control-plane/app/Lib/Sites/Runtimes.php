<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Servers\ServerStack;
use InvalidArgumentException;
use stdClass;

/**
 * What an application is, other than Laravel: a static site, Node, or Go.
 *
 * A runtime is a preset of five things: the toolchain it needs, the commands
 * that build a release, the command that starts it, where it listens, and how
 * to tell it is up. Everything else, the releases, domains, variables, logs,
 * previews, the console, works the same for every runtime. A new runtime is a
 * new entry in PRESETS, and whatever its toolchain needs.
 *
 * Toolchains are installed with mise, for the deploy user, from the version
 * the application chose (or the preset's). Nothing is installed system-wide.
 *
 * Builds run on the server, in the release's directory, as the deploy user.
 */
final class Runtimes
{
    public const PHP = 'php';

    /** The two container runtimes (docs/specs/templates.md, step 6): not presets, since nothing is built with mise; see ContainerApps. */
    public const DOCKERFILE = 'dockerfile';

    public const IMAGE = 'image';

    /**
     * tool: mise tool; version: its default; install, build, release: commands, run in the release
     * directory (release once, on the primary, while the old release still serves);
     * start: the command a supervisor runs, told its port in PORT; output: for a static site, the
     * directory nginx serves; health: a path that answers 2xx or 3xx once it is up.
     */
    public const PRESETS = [
        'static' => ['label' => 'Static site', 'tool' => null, 'version' => null, 'install' => '', 'build' => '', 'release' => '', 'start' => null, 'output' => '.', 'spa' => false, 'health' => null],
        'node' => ['label' => 'Node', 'tool' => 'node', 'version' => '22',
            'install' => 'if [ -f package-lock.json ]; then npm ci; else npm install; fi', 'build' => 'npm run build --if-present',
            'release' => '', 'start' => 'npm start', 'output' => null, 'spa' => false, 'health' => '/'],
        'go' => ['label' => 'Go', 'tool' => 'go', 'version' => 'latest',
            'install' => '', 'build' => 'go build -o bin/app .', 'release' => '', 'start' => './bin/app', 'output' => null, 'spa' => false, 'health' => '/'],
    ];

    /** The fields a person may change, and what each must look like. */
    private const FIELDS = ['version', 'install', 'build', 'release', 'start', 'output', 'spa', 'health', 'port'];

    private const MISE = '/home/'.ServerStack::DEPLOY_USER.'/.local/bin/mise';

    public static function runtime(stdClass $site): string
    {
        return (string) ($site->runtime ?? self::PHP);
    }

    /** An application from a Dockerfile or a Docker image, run by the container runner. */
    public static function isContainer(stdClass $site): bool
    {
        return in_array(self::runtime($site), [self::DOCKERFILE, self::IMAGE], true);
    }

    /**
     * What each kind of application has, by name. This table is the one place that says what Laravel is: the rest of
     * the code asks for a capability, never "is this PHP?". A kind not listed has none of them; a preset's own process,
     * port and build commands are its data in PRESETS. A new framework is a row here and, if it is built with mise, a preset.
     *
     *   composer           built with Composer, which takes the application's credentials
     *   fpm                served by PHP-FPM, from a public/ folder, rather than by a process nginx proxies to
     *   workers            queue workers and Reverb, run by supervisor
     *   scheduler          a cron entry running the framework's scheduler
     *   php-version        the PHP branch is the person's choice
     *   env-file           variables live in a .env file the running application can be given again without a deploy
     *   framework-defaults a new application gets the framework's own settings injected (an app key, SQLite)
     */
    private const PROFILES = [
        self::PHP => ['composer', 'fpm', 'workers', 'scheduler', 'php-version', 'env-file', 'framework-defaults'],
    ];

    public static function has(stdClass $site, string $capability): bool
    {
        return in_array($capability, self::PROFILES[self::runtime($site)] ?? [], true);
    }

    /** @return list<string> the capabilities, for the pages that show or hide a control by them */
    public static function capabilities(stdClass $site): array
    {
        return self::PROFILES[self::runtime($site)] ?? [];
    }

    /** @return array<string, string> key => label, for a choice */
    public static function choices(): array
    {
        // Container applications are made by their own pages and cannot be switched to or from.
        return [self::PHP => 'Laravel (PHP)'] + array_map(fn ($p) => $p['label'], self::PRESETS);
    }

    /** Does it run a process of its own that nginx proxies to? */
    public static function serves(stdClass $site): bool
    {
        return (self::PRESETS[self::runtime($site)]['start'] ?? null) !== null;
    }

    /**
     * The runtime's preset with the site's changes on top, and the port.
     *
     * @return array<string, mixed>
     */
    public static function config(stdClass $site): array
    {
        if (self::isContainer($site)) {
            return ['port' => \App\Lib\Containers\ContainerApps::state($site)['host_port'] ?? null, 'health' => \App\Lib\Containers\ContainerApps::state($site)['health'] ?? ''];
        }
        $preset = self::PRESETS[self::runtime($site)] ?? throw new InvalidArgumentException('This application is not one of the runtimes with a preset.');
        $own = json_decode((string) ($site->runtime_config ?? 'null'), true) ?: [];
        $config = array_replace($preset, array_intersect_key($own, array_flip(self::FIELDS)));
        // A static site that builds, with npm and the like, gets Node unless told otherwise.
        if ($config['tool'] === null && self::runtime($site) === 'static' && ($config['install'] !== '' || $config['build'] !== '')) {
            $config['tool'] = 'node';
            $config['version'] ??= '22';
        }
        $config['port'] = $config['port'] ?? ($preset['start'] !== null ? self::defaultPort($site) : null);

        return $config;
    }

    /**
     * The band of ports Unharbored keeps for itself on every server: 20000 to 22999 for applications' own
     * internal use, 23000 to 23999 for the host ports of container services (ContainerRecipes::PORT_MIN/MAX).
     * Every check of a port an application, a background process or a native service may take uses this one.
     */
    public const RESERVED_FROM = 20000;

    public const RESERVED_TO = 23999;

    public static function reservedPort(int $port): bool
    {
        return $port >= self::RESERVED_FROM && $port <= self::RESERVED_TO;
    }

    /** One a site gets unless it picks: from 4000, away from the 20000s the platform keeps and the usual services. */
    public static function defaultPort(stdClass $site): int
    {
        return 4000 + ((int) $site->id % 10000);
    }

    /**
     * Checks a set of changes and returns them as they are stored. Anything not
     * in the set is left as it was.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function validate(string $runtime, array $in): array
    {
        if (! isset(self::PRESETS[$runtime])) {
            throw new InvalidArgumentException('Pick Laravel, Node, Go or a static site.');
        }
        $out = [];
        foreach (array_intersect_key($in, array_flip(self::FIELDS)) as $key => $value) {
            if ($key === 'spa') {
                $out[$key] = (bool) $value;

                continue;
            }
            if ($key === 'port') {
                if ($value === null || $value === '') {
                    continue;
                }
                $port = (int) $value;
                if ($port < 1024 || $port > 65535 || self::reservedPort($port)) {
                    throw new InvalidArgumentException('Pick a port from 1024 to 65535, not 20000 to 23999, which Unharbored keeps for itself.');
                }
                $out[$key] = $port;

                continue;
            }
            $value = trim((string) $value);
            $out[$key] = match ($key) {
                'version' => preg_match('/^(latest|\d+(\.\d+){0,2})$/', $value) === 1 ? $value : throw new InvalidArgumentException('A version is a number such as 22 or 1.24, or latest.'),
                'output' => preg_match('#^(\.|[A-Za-z0-9_][A-Za-z0-9._/-]{0,99})$#', $value) === 1 && ! str_contains($value, '..') ? $value : throw new InvalidArgumentException('The output folder is a path inside the repository, such as dist or public.'),
                'health' => $value === '' || preg_match('#^/[A-Za-z0-9._~/%?=&-]{0,200}$#', $value) === 1 ? $value : throw new InvalidArgumentException('The health check is a path that starts with /, such as /health.'),
                default => mb_strlen($value) <= ($key === 'build' ? 5000 : 1000) && ! str_contains($value, "\0") ? $value : throw new InvalidArgumentException("The {$key} command is too long."),
            };
        }
        if (isset($out['start']) && $out['start'] === '' && self::PRESETS[$runtime]['start'] !== null) {
            throw new InvalidArgumentException('A start command is needed: it is what runs the application.');
        }
        return $out;
    }

    /** The mise tool and version, as mise names them: node@22. Null for a static site with nothing to build. */
    private static function tool(array $c): ?string
    {
        return $c['tool'] === null ? null : "{$c['tool']}@{$c['version']}";
    }

    /** A command run in the release directory with the toolchain on its path and the application's variables set. */
    private static function inRelease(stdClass $site, array $c, string $dir, string $command, bool $withEnv): string
    {
        $env = $withEnv ? 'set -a; . '.escapeshellarg(Sites::root($site).'/shared/.env').'; set +a; ' : '';
        $wrapped = self::tool($c) === null ? $command : escapeshellarg(self::MISE).' exec '.escapeshellarg((string) self::tool($c)).' -- sh -c '.escapeshellarg($command);

        return 'cd '.escapeshellarg($dir).' && '.$env.$wrapped;
    }

    /**
     * mise, and the toolchain it installs, for the deploy user.
     *
     * @return list<array<string, mixed>>
     */
    private static function toolchainSteps(string $tool): array
    {
        $user = ServerStack::DEPLOY_USER;
        $as = static fn (string $script): array => ['-u', $user, '--', 'env', "HOME=/home/{$user}", 'sh', '-c', $script];

        return [
            ['id' => 'mise', 'type' => 'command', 'name' => 'runuser', 'args' => $as('curl -fsSL https://mise.run | sh'),
                'unless' => ['test', '-x', self::MISE], 'description' => 'mise, which installs language toolchains, is on the server'],
            ['id' => 'toolchain', 'type' => 'command', 'name' => 'runuser', 'args' => $as(escapeshellarg(self::MISE).' install --yes '.escapeshellarg($tool)),
                'description' => "{$tool} is installed"],
        ];
    }

    /** Whether a Node version is one mise understands here: 22, 22.11, 22.11.0, or lts. */
    public static function validNodeVersion(string $version): bool
    {
        return preg_match('/^(\d{1,3}(\.\d{1,3}){0,2}|lts)$/', $version) === 1;
    }

    /**
     * A PHP application's build commands, run in the release by sh, one after another and stopping at the first that
     * fails. They replace the fixed Composer step, so the default script starts with that install; they run before
     * migrations and the caches, so what they make, such as the Vite manifest, is there.
     *
     * $PHP is the application's PHP, $COMPOSER runs Composer with it, and Composer's credentials (when saved) are in
     * place. Node is installed with mise when a version is given or the commands use npm, yarn, pnpm or node. The
     * release's .env is linked in and tools such as Vite read their own variables from it; it is not sourced, because
     * a Laravel .env is not valid shell.
     *
     * @return list<array<string, mixed>>
     */
    public static function buildScriptSteps(stdClass $site, string $path, string $script, string $nodeVersion, string $phpBinary, ?string $composerHome): array
    {
        $user = ServerStack::DEPLOY_USER;
        $usesNode = $nodeVersion !== '' || preg_match('/\b(npm|npx|node|yarn|pnpm|corepack)\b/', $script) === 1;
        $config = ['tool' => $usesNode ? 'node' : null, 'version' => $nodeVersion !== '' ? $nodeVersion : '22'];
        $exports = 'export PHP='.escapeshellarg($phpBinary).' COMPOSER='.escapeshellarg($phpBinary.' '.ServerStack::COMPOSER).' COMPOSER_NO_INTERACTION=1'
            .($composerHome === null ? '' : ' COMPOSER_HOME='.escapeshellarg($composerHome));
        $first = trim((string) strtok(trim($script), "\n"));

        return [
            ...($usesNode ? self::toolchainSteps(self::tool($config)) : []),
            ['id' => 'build-script', 'type' => 'command', 'name' => 'runuser',
                'args' => ['-u', $user, '--', 'env', "HOME=/home/{$user}", 'sh', '-c', self::inRelease($site, $config, $path, $exports."\nset -e\n".$script, false)],
                'description' => 'build commands: '.self::short($first).(substr_count(trim($script), "\n") > 0 ? ' …' : '')],
        ];
    }

    /**
     * Installs the toolchain and builds a release.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function buildRecipe(stdClass $site, string $path, bool $primary = true): array
    {
        $c = self::config($site);
        $user = ServerStack::DEPLOY_USER;
        $as = static fn (string $script): array => ['-u', $user, '--', 'env', "HOME=/home/{$user}", 'sh', '-c', $script];
        $steps = [];

        if (($tool = self::tool($c)) !== null) {
            array_push($steps, ...self::toolchainSteps($tool));
        }

        foreach (['install' => 'install dependencies', 'build' => 'build'] as $key => $label) {
            if (($c[$key] ?? '') !== '') {
                $steps[] = ['id' => $key, 'type' => 'command', 'name' => 'runuser',
                    'args' => $as(self::inRelease($site, $c, $path, self::stopOnFailure((string) $c[$key]), true)), 'description' => $label.': '.self::short((string) $c[$key])];
            }
        }
        if ($primary && ($c['release'] ?? '') !== '') {
            $steps[] = ['id' => 'release', 'type' => 'command', 'name' => 'runuser',
                'args' => $as(self::inRelease($site, $c, $path, self::stopOnFailure((string) $c['release']), true)), 'description' => 'before the switch, once: '.self::short((string) $c['release'])];
        }
        // A recipe with nothing in it is refused, and a static site with no build has nothing to do but be there.
        $steps[] = ['id' => 'release-present', 'type' => 'command', 'name' => 'test', 'args' => ['-d', $path], 'description' => 'the release is on the server'];

        return ['name' => 'build', 'steps' => $steps];
    }

    /**
     * The supervisor program that runs the application, for runtimes that run one.
     *
     * @return list<array{name: string, command: string, processes: int, stopwait: int, restart: bool, port: int}>
     */
    public static function programs(stdClass $site): array
    {
        if (! self::serves($site)) {
            return [];
        }
        $c = self::config($site);
        $start = 'set -a; . '.escapeshellarg(Sites::root($site).'/shared/.env').'; set +a; exec '
            .(self::tool($c) === null ? '' : escapeshellarg(self::MISE).' exec '.escapeshellarg((string) self::tool($c)).' -- ').$c['start'];

        return [['name' => "cm-{$site->name}-app", 'command' => 'sh -c '.escapeshellarg($start), 'processes' => 1, 'stopwait' => 30, 'restart' => true, 'port' => (int) $c['port']]];
    }

    /**
     * Waits for the application to answer on its port after a restart.
     *
     * @return list<array<string, mixed>>
     */
    public static function healthSteps(stdClass $site): array
    {
        $c = self::config($site);
        if (! self::serves($site) || ($c['health'] ?? '') === '') {
            return [];
        }
        $url = 'http://127.0.0.1:'.(int) $c['port'].$c['health'];

        return [['id' => 'health', 'type' => 'command', 'name' => 'sh',
            'args' => ['-c', 'for i in $(seq 1 30); do curl -s -o /dev/null --max-time 3 -w "%{http_code}" '.escapeshellarg($url).' | grep -qE "^[23]" && exit 0; sleep 2; done; echo "no answer from '.$url.' in 60 seconds"; exit 1'],
            'description' => "answers on port {$c['port']}"]];
    }

    /**
     * The nginx server block, in place of the PHP one. $listen and $extra are what the PHP block
     * takes: the listen and certificate lines, and the balancer or challenge handling.
     */
    public static function server(stdClass $site, string $listen, string $extra, string $snippet, string $limits, string $robots): string
    {
        if (self::isContainer($site)) {
            return self::containerServer($site, $listen, $extra, $snippet, $limits, $robots);
        }
        $c = self::config($site);
        $root = Sites::current($site);

        if (! self::serves($site)) {
            $dir = $c['output'] === '.' ? $root : $root.'/'.$c['output'];
            $fallback = $c['spa'] ? '/index.html' : '=404';

            return <<<CONF
server {
{$listen}
    server_name {$site->domain};
    root {$dir};
    index index.html;
    charset utf-8;{$robots}{$limits}{$snippet}

{$extra}

    location / {
        try_files \$uri \$uri/ {$fallback};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

CONF;
        }

        $port = (int) $c['port'];

        return <<<CONF
server {
{$listen}
    server_name {$site->domain};
    charset utf-8;{$robots}{$limits}{$snippet}

{$extra}

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
    }
}

CONF;
    }

    /**
     * A container application: nginx proxies to the container's host port on 127.0.0.1, the one the last good deploy (or rollback)
     * switched to, with WebSocket headers. Before the first good deploy there is no port and the answer is 503.
     */
    private static function containerServer(stdClass $site, string $listen, string $extra, string $snippet, string $limits, string $robots): string
    {
        $port = \App\Lib\Containers\ContainerApps::state($site)['host_port'] ?? null;
        $location = $port === null
            ? "        return 503;"
            : <<<LOC
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 300s;
LOC;

        return <<<CONF
server {
{$listen}
    server_name {$site->domain};
    charset utf-8;{$robots}{$limits}{$snippet}

{$extra}

    location / {
{$location}
    }
}

CONF;
    }

    /** Several lines run one after another and stop at the first that fails, as the Laravel build commands do. */
    private static function stopOnFailure(string $command): string
    {
        return str_contains(trim($command), "\n") ? "set -e\n".trim($command) : $command;
    }

    private static function short(string $command): string
    {
        $command = preg_replace('/\s+/', ' ', $command) ?? $command;

        return mb_strlen($command) > 70 ? mb_substr($command, 0, 67).'…' : $command;
    }
}
