<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use InvalidArgumentException;
use stdClass;

/**
 * Laravel's own long-running services, offered as a list instead of as commands to remember.
 *
 * A preset is only a way of filling in a background process (and, for one, a variable): what it
 * makes is an ordinary entry in the site's processes, which can be edited or removed there.
 * Reverb is not here: it has its own switch above the list.
 */
final class LaravelServices
{
    /** Where the Nightwatch agent listens unless told otherwise; the next free port up is used when something has it. */
    private const NIGHTWATCH_PORT = 2407;

    public function __construct(private readonly Sites $sites, private readonly WorkerScaling $scaling) {}

    /**
     * What the console shows: for each preset, its fields and the command it would run.
     *
     * @return list<array{key: string, label: string, help: string, command: string, fields: list<array<string, mixed>>}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'key' => 'queue',
                'label' => 'Queue worker for named queues',
                'help' => 'A second set of workers for particular queues, such as emails, with their own count. Moves onto each new release as the built-in workers do, letting a running job finish.',
                'command' => 'php artisan queue:work --queue={queues} --sleep=3 --tries={tries} --max-time=3600',
                'fields' => [
                    ['name' => 'queues', 'label' => 'Queues, in order', 'type' => 'text', 'default' => 'default', 'placeholder' => 'emails,default'],
                    ['name' => 'processes', 'label' => 'Processes', 'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 20],
                    ['name' => 'tries', 'label' => 'Tries', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10],
                ],
            ],
            [
                'key' => 'horizon',
                'label' => 'Horizon',
                'help' => 'Needs laravel/horizon in the application and Redis as its queue connection. Told to terminate on each deploy so it starts again on the new release after its jobs finish.',
                'command' => 'php artisan horizon',
                'fields' => [
                    ['name' => 'stopBuiltIn', 'label' => 'Stop the built-in queue workers (Horizon runs the queues)', 'type' => 'bool', 'default' => true],
                ],
            ],
            [
                'key' => 'pulse',
                'label' => 'Pulse',
                'help' => 'Needs laravel/pulse in the application. Records server and application metrics.',
                'command' => 'php artisan pulse:check',
                'fields' => [],
            ],
            [
                'key' => 'nightwatch',
                'placeable' => false,
                'label' => 'Nightwatch agent',
                'help' => 'Needs laravel/nightwatch in the application. The agent sends what the application reports to Nightwatch, so it runs on the same server. Your token is kept in the environment, never shown again.',
                'command' => 'php artisan nightwatch:agent',
                'fields' => [
                    ['name' => 'token', 'label' => 'Nightwatch token', 'type' => 'secret'],
                ],
            ],
        ];
    }

    /**
     * Adds (or replaces) a preset on the site.
     *
     * @param  array<string, mixed>  $input
     */
    public function add(stdClass $site, string $key, array $input): stdClass
    {
        // A request can send a field as a list or a map; none of these takes one.
        foreach ($input as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Each field takes a single value.');
            }
        }

        return match ($key) {
            'queue' => $this->queue($site, $input),
            'horizon' => $this->horizon($site, $input),
            'pulse' => $this->sites->setDaemon($site, 'pulse', 'php artisan pulse:check', extra: ['preset' => 'pulse'] + self::placement($input)),
            'nightwatch' => $this->nightwatch($site, $input),
            default => throw new InvalidArgumentException('Choose one of the services in the list.'),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function queue(stdClass $site, array $input): stdClass
    {
        $queues = strtolower(trim((string) ($input['queues'] ?? 'default')));
        if (! preg_match('/^[a-z0-9_.-]+(,[a-z0-9_.-]+)*$/', $queues) || strlen($queues) > 100) {
            throw new InvalidArgumentException('Queues are names separated by commas, such as emails,default.');
        }
        $processes = $this->integer($input, 'processes', 1, 1, 20);
        $tries = $this->integer($input, 'tries', 3, 1, 10);
        $name = 'queue-'.trim(substr((string) preg_replace('/[^a-z0-9]+/', '-', explode(',', $queues)[0]), 0, 22), '-');

        return $this->sites->setDaemon(
            $site, $name,
            "php artisan queue:work --queue={$queues} --sleep=3 --tries={$tries} --max-time=3600",
            $processes,
            extra: ['stopwait' => 3600, 'reload' => 'queue:restart', 'preset' => 'queue'] + self::placement($input),
        );
    }

    /** @param  array<string, mixed>  $input */
    private function horizon(stdClass $site, array $input): stdClass
    {
        $site = $this->sites->setDaemon($site, 'horizon', 'php artisan horizon', extra: ['stopwait' => 3600, 'reload' => 'horizon:terminate', 'preset' => 'horizon'] + self::placement($input));
        // Two sets of workers on one queue is almost never meant.
        if (filter_var($input['stopBuiltIn'] ?? true, FILTER_VALIDATE_BOOLEAN) && (int) ($site->queue_workers ?? 0) > 0) {
            if ($site->queue_workers_max !== null) {
                $this->scaling->disable($site, 0);
                $site = $this->sites->find($site->node_id, $site->name);
            } else {
                $site = $this->sites->setWorkers($site, 0);
            }
        }

        return $site;
    }

    /** @param  array<string, mixed>  $input */
    private function nightwatch(stdClass $site, array $input): stdClass
    {
        $token = trim((string) ($input['token'] ?? ''));
        if (strlen($token) < 8 || strlen($token) > 300 || preg_match('/[\s\x00-\x1f"\'\\\\$#]/', $token)) {
            throw new InvalidArgumentException('Paste the token from your Nightwatch environment settings.');
        }
        // The application sends to a loopback port; another application's agent on the server may hold the usual one.
        $existing = collect(Sites::daemons($site))->firstWhere('name', 'nightwatch');
        $port = (int) ($existing['port'] ?? 0) ?: $this->sites->freePort($site, 'nightwatch', self::NIGHTWATCH_PORT);

        $site = $this->sites->setDaemon($site, 'nightwatch', 'php artisan nightwatch:agent', port: $port, extra: ['preset' => 'nightwatch']);
        $this->sites->setEnvValues($site, ['NIGHTWATCH_TOKEN' => $token, 'NIGHTWATCH_INGEST_URI' => "127.0.0.1:{$port}"]);

        return $site;
    }

    /**
     * Where it runs, when it is not everywhere. Not for Nightwatch: its agent has to be wherever the application is.
     *
     * @param  array<string, mixed>  $input
     * @return array{runs_on?: string}
     */
    private static function placement(array $input): array
    {
        $on = (string) ($input['runs_on'] ?? 'all');

        return $on === 'all' ? [] : ['runs_on' => $on];
    }

    /** @param  array<string, mixed>  $input */
    private function integer(array $input, string $field, int $default, int $min, int $max): int
    {
        $value = filter_var($input[$field] ?? $default, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException(ucfirst($field)." must be a whole number from {$min} to {$max}.");
        }

        return $value;
    }
}
