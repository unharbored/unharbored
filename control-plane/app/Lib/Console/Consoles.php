<?php

declare(strict_types=1);

namespace App\Lib\Console;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\Exceptions\GatewayUnavailable;
use App\Lib\Gateway\Exceptions\NodeNotConnected;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Sites\Sites;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A shell in the browser, on one of an application's servers.
 *
 * It goes through the agent, as everything does: the page sends what is typed
 * here, the gateway passes it to the agent, and the agent's output comes back
 * as events and is broadcast to the page that opened it, on a private channel
 * only that person can join. Nothing typed or shown is stored. What is kept is
 * who opened a console, where, as whom, and when it ended.
 */
final class Consoles
{
    /** The account a console runs as unless root is asked for. */
    public const DEFAULT_USER = 'deploy';

    public function __construct(private readonly GatewayClient $gateway, private readonly AuditLog $audit) {}

    public static function enabled(string $org): bool
    {
        return (bool) DB::table('organizations')->where('id', $org)->value('console_enabled');
    }

    /**
     * Servers of an application a console could open on, with whether each is connected.
     *
     * @return list<array{id: string, name: string, connected: bool, agent: ?string, stale: bool}>
     */
    public function servers(object $site): array
    {
        $up = $this->gateway->connectedNodes();
        $serving = (string) config('control_plane.agent_version');

        return array_map(function (string $n) use ($up, $serving) {
            $agent = DB::table('nodes')->where('node_id', $n)->value('agent_version');

            return [
                'id' => $n,
                'name' => strstr($n, '.', true) ?: $n,
                'connected' => in_array($n, array_map(fn ($c) => $c['node_id'] ?? $c, $up), true),
                'agent' => $agent,
                // Consoles need the agent from the build that added them; an older one ignores the request.
                'stale' => $agent !== null && $agent !== $serving,
            ];
        }, Sites::nodes($site));
    }

    /**
     * Records a console and gives the page its id to listen on. The shell is not started until start(),
     * so the page can be listening first and not miss the prompt. An application is optional: a server
     * with none can still have a console, and then the server alone is what is recorded.
     *
     * @return string The session id.
     *
     * @throws RuntimeException Said plainly to the person.
     */
    public function open(string $org, ?object $site, string $node, string $asUser, Actor $actor, string $name, ?string $ip = null): string
    {
        if (! self::enabled($org)) {
            throw new RuntimeException('Consoles are off for this team. Turn them on under Account.');
        }
        if ($site !== null && ! in_array($node, Sites::nodes($site), true)) {
            throw new RuntimeException('That server does not run this application.');
        }
        if ($site === null && ! DB::table('nodes')->where('organization_id', $org)->where('node_id', $node)->exists()) {
            throw new RuntimeException('That server is not part of this team.');
        }
        if (! in_array($asUser, [self::DEFAULT_USER, 'root'], true)) {
            throw new RuntimeException('A console runs as deploy or as root.');
        }

        $id = (string) Str::uuid();

        // Recorded before it is opened: a console that opened and was never recorded is the bad case.
        DB::table('console_sessions')->insert([
            'id' => $id, 'organization_id' => $org, 'site_id' => $site?->id, 'node_id' => $node, 'as_user' => $asUser,
            'opened_by_id' => $actor->id, 'opened_by_name' => $name, 'opened_at' => now(),
        ]);
        $this->audit->record($org, $actor, 'console.opened', resourceType: 'node', resourceId: $node, metadata: ['session' => $id, 'as' => $asUser, 'site' => $site?->name], ipAddress: $ip);

        return $id;
    }

    /** @throws RuntimeException */
    public function start(string $session, int $cols, int $rows): void
    {
        $row = DB::table('console_sessions')->where('id', $session)->whereNull('closed_at')->first();
        if ($row === null) {
            throw new RuntimeException('This console has ended.');
        }

        try {
            $this->gateway->terminal($row->node_id, ['session' => $session, 'op' => 'open', 'user' => $row->as_user, 'cols' => $cols, 'rows' => $rows]);
        } catch (NodeNotConnected|GatewayUnavailable) {
            $this->end($session, 'could not reach the server');
            throw new RuntimeException('That server is not connected, so a console cannot be opened on it.');
        }
    }

    /** @param  array<string, mixed>  $payload  Sent through to the agent: op input, resize or close */
    public function send(string $session, array $payload): void
    {
        $row = DB::table('console_sessions')->where('id', $session)->whereNull('closed_at')->first();
        if ($row === null) {
            throw new RuntimeException('This console has ended.');
        }

        try {
            $this->gateway->terminal($row->node_id, ['session' => $session] + $payload);
        } catch (NodeNotConnected|GatewayUnavailable) {
            $this->end($session, 'lost the server');
            throw new RuntimeException('The server is no longer connected.');
        }
    }

    public function close(string $session, string $org, Actor $actor): void
    {
        $row = DB::table('console_sessions')->where('id', $session)->where('organization_id', $org)->first();
        if ($row === null || $row->closed_at !== null) {
            return;
        }
        try {
            $this->gateway->terminal($row->node_id, ['session' => $session, 'op' => 'close']);
        } catch (NodeNotConnected|GatewayUnavailable) {
        }
        $this->end($session, 'closed');
        $this->audit->record($org, $actor, 'console.closed', resourceType: 'node', resourceId: $row->node_id, metadata: ['session' => $session]);
    }

    /** Whether this person opened it, and it is theirs to type in or watch. */
    public static function belongsTo(string $session, string $userId): bool
    {
        return DB::table('console_sessions')->where('id', $session)->where('opened_by_id', $userId)->exists();
    }

    /** The gateway's report of what a shell wrote: tell the page, and note when it ends. */
    public function output(array $body): void
    {
        $session = (string) ($body['session'] ?? '');
        if ($session === '' || ! DB::table('console_sessions')->where('id', $session)->exists()) {
            return;
        }

        $payload = array_filter([
            'seq' => (int) ($body['seq'] ?? 0),
            'data' => $body['data'] ?? null,
            'exit' => $body['exit'] ?? null,
            'error' => $body['error'] ?? null,
        ], static fn ($v) => $v !== null);

        if (isset($payload['exit']) || isset($payload['error'])) {
            $this->end($session, isset($payload['error']) ? 'could not start' : 'exited', $payload['exit'] ?? null);
        }

        try {
            ConsoleOutput::dispatch($session, $payload);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function end(string $session, string $because, ?int $exit = null): void
    {
        DB::table('console_sessions')->where('id', $session)->whereNull('closed_at')
            ->update(['closed_at' => now(), 'ended_because' => $because, 'exit_code' => $exit]);
    }

    /** Where the browser reaches Reverb: the configured address, or the local one. */
    public static function socketUrl(): string
    {
        $configured = config('console.socket_url');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        $c = config('broadcasting.connections.reverb');
        $o = $c['options'] ?? [];

        return sprintf('%s://%s:%s/app/%s?protocol=7&client=console&version=0.1', ($o['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws', $o['host'] ?? '127.0.0.1', $o['port'] ?? 8080, $c['key'] ?? '');
    }
}
