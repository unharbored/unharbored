<?php

declare(strict_types=1);

namespace App\Lib\Services;

/**
 * The closed registry of setup actions a native definition may name with
 * `runs.setup` (docs/specs/templates.md section 2). A definition has no step
 * list and no shell; a program that needs one thing done before its first
 * start names a reviewed action here instead. Each action is a fixed list of
 * recipe steps, parameterised only by values the validator and the install
 * have already checked. Adding an action is a code change with a unit test.
 *
 * Both actions run the program as the service user under systemd-run, with
 * the same environment file and the same sandbox as the service's own unit,
 * so a secret travels in the environment file and never in an argument.
 */
final class SetupActions
{
    public const NAMES = ['runner-register', 'environment-to-ini'];

    /**
     * What an action needs the definition to declare: input key => type.
     * Checked by DefinitionChecker so a definition cannot name an action it
     * cannot feed.
     *
     * @return array<string, string>
     */
    public static function inputs(string $name): array
    {
        return match ($name) {
            'runner-register' => ['url' => 'text', 'token' => 'secret'],
            default => [],
        };
    }

    /** Does the program write inside its own release folder (the runner keeps its registration there)? */
    public static function writesRelease(string $name): bool
    {
        return $name === 'runner-register';
    }

    /**
     * @param  array{name: string, user: string, release: string, data: string, binary: string, env_file: string, inputs: array<string, string>}  $c
     * @return list<array<string, mixed>>
     */
    public static function steps(string $name, array $c): array
    {
        return match ($name) {
            'runner-register' => self::runnerRegister($c),
            'environment-to-ini' => self::environmentToIni($c),
            default => throw new \InvalidArgumentException("Unknown setup action {$name}."),
        };
    }

    /**
     * Registers the runner once with GitHub: `config.sh --unattended --ephemeral --url U --name N`
     * with the registration token read from ACTIONS_RUNNER_INPUT_TOKEN in the environment file
     * (never an argument). Skipped when the runner is already registered (.runner exists).
     *
     * @param  array<string, mixed>  $c
     * @return list<array<string, mixed>>
     */
    private static function runnerRegister(array $c): array
    {
        $url = $c['inputs']['url'] ?? '';
        if (! preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?/[A-Za-z0-9._~/-]*$#', $url)) {
            throw new \InvalidArgumentException('The runner URL must be an https:// address such as https://github.com/your-org/your-repo.');
        }

        return [[
            // The runner needs .NET's libraries (libicu and friends); its own script installs them, as root, once.
            'id' => 'setup-runner-dependencies', 'type' => 'command', 'name' => $c['release'].'/bin/installdependencies.sh', 'args' => [],
            'unless' => ['sh', '-c', 'ldconfig -p | grep -q libicu'],
            'description' => 'the runner\'s system libraries (its own installdependencies.sh)',
        ], [
            'id' => 'setup-runner-register', 'type' => 'command', 'name' => 'systemd-run',
            'args' => [...self::sandbox($c, writeRelease: true), $c['release'].'/config.sh', '--unattended', '--ephemeral', '--replace',
                '--name', $c['name'], '--url', $url, '--work', '_work'],
            'unless' => ['test', '-f', $c['release'].'/.runner'],
            'description' => 'register the runner with GitHub (the token is read from the environment file)',
        ]];
    }

    /**
     * Turns the GITEA__section__KEY variables of the environment file into Gitea's app.ini, once,
     * before the first start. The binary the first template pins (28.4.0) no longer has an
     * `environment-to-ini` command; its replacement, found by reading that binary's own help text,
     * is `gitea config edit-ini --config P --apply-env --out P`. The action keeps the name that says
     * what it does. An empty app.ini is made first (owned by the service user) so the edit has a file to read.
     *
     * @param  array<string, mixed>  $c
     * @return list<array<string, mixed>>
     */
    private static function environmentToIni(array $c): array
    {
        $owned = ['owner' => $c['user'], 'base' => '/srv/cloud-manager/data'];
        $ini = $c['data'].'/custom/conf/app.ini';

        return [
            ['id' => 'setup-ini-dir-custom', 'type' => 'dir', 'path' => $c['data'].'/custom', 'mode' => '0750'] + $owned,
            ['id' => 'setup-ini-dir-conf', 'type' => 'dir', 'path' => $c['data'].'/custom/conf', 'mode' => '0750'] + $owned,
            ['id' => 'setup-ini-file', 'type' => 'command', 'name' => 'install',
                'args' => ['-o', $c['user'], '-m', '0640', '/dev/null', $ini], 'unless' => ['test', '-e', $ini], 'description' => 'an empty app.ini for Gitea to fill'],
            ['id' => 'setup-environment-to-ini', 'type' => 'command', 'name' => 'systemd-run',
                'args' => [...self::sandbox($c), $c['binary'], '--work-path', $c['data'], 'config', 'edit-ini', '--config', $ini, '--apply-env', '--out', $ini],
                'unless' => ['test', '-s', $ini],
                'description' => 'write app.ini from the environment file'],
        ];
    }

    /**
     * systemd-run as the service user, in the service's sandbox and environment, waiting for it and passing its exit status and output back.
     *
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private static function sandbox(array $c, bool $writeRelease = false): array
    {
        $writable = $c['data'].($writeRelease ? ' '.$c['release'] : '');

        return ['--quiet', '--wait', '--pipe', '--collect',
            '-p', 'User='.$c['user'], '-p', 'EnvironmentFile='.$c['env_file'], '-p', 'Environment=HOME='.$c['data'],
            // The runner's scripts look for ./bin, so it works from its release folder; everything else from its data.
            '-p', 'WorkingDirectory='.($writeRelease ? $c['release'] : $c['data']), '-p', 'NoNewPrivileges=yes', '-p', 'ProtectSystem=strict',
            '-p', 'PrivateTmp=yes', '-p', 'ReadWritePaths='.$writable, '--'];
    }
}
