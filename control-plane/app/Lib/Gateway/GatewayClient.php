<?php

declare(strict_types=1);

namespace App\Lib\Gateway;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\Gateway\Exceptions\CommandOutcomeUnknown;
use App\Lib\Gateway\Exceptions\GatewayUnavailable;
use App\Lib\Gateway\Exceptions\NodeNotConnected;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Talks to the session gateway.
 *
 * The gateway holds every agent connection, which PHP-FPM cannot. This is how
 * the control plane reaches a node.
 *
 * The distinction this class exists to preserve is between a command that
 * failed and a command whose outcome is unknown. They look similar and are
 * not: a failure can be retried, and an unknown outcome means the work may
 * already have happened. Retrying a release activation or a migration that
 * actually ran is how a deploy corrupts data while appearing to recover.
 *
 * So anything uncertain raises CommandOutcomeUnknown rather than a generic
 * failure, and the caller has to decide deliberately.
 */
final class GatewayClient
{
    /**
     * How long to wait for a command result before giving up.
     *
     * Giving up does not mean the command stopped. It means we stopped
     * watching, which is why the timeout path is an unknown outcome rather
     * than a failure.
     */
    public const DEFAULT_WAIT_SECONDS = 120;

    /** @var non-empty-string */
    private readonly string $token;

    public function __construct(
        private readonly string $baseUrl,
        #[SensitiveParameter] string $token,
        private readonly HttpFactory $http,
        private readonly AuditLog $audit,
    ) {
        if ($token === '') {
            throw new GatewayUnavailable(
                'No gateway token is configured. The control plane link must be authenticated.'
            );
        }

        $this->token = $token;
    }

    /**
     * Run a command on a node and wait for the result.
     *
     * $idempotencyKey must be set for anything unsafe to run twice. The
     * gateway and the agent both honour it, which is what makes retrying
     * after an unknown outcome safe.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed> The command result.
     *
     * @throws NodeNotConnected       The node is not currently reachable.
     * @throws CommandOutcomeUnknown  It may or may not have run.
     * @throws GatewayUnavailable     The gateway itself could not be reached.
     */
    public function run(
        string $organizationId,
        string $nodeId,
        string $kind,
        array $args = [],
        ?string $idempotencyKey = null,
        ?int $timeoutSeconds = null,
        ?int $waitSeconds = null,
        ?Actor $actor = null,
        ?string $reason = null,
    ): array {
        // A command given a long timeout is waited for that long (plus a margin), not for the default: otherwise a backup the
        // server is allowed half an hour for would be reported as lost after two minutes.
        $wait = $waitSeconds ?? max(self::DEFAULT_WAIT_SECONDS, ($timeoutSeconds ?? 0) + 30);
        $actor ??= Actor::system('control-plane');

        $this->audit->record(
            organizationId: $organizationId,
            actor: $actor,
            action: 'node.command_sent',
            resourceType: 'node',
            resourceId: $nodeId,
            reason: $reason,
            metadata: ['kind' => $kind, 'idempotency_key' => $idempotencyKey],
        );

        try {
            $response = $this->http
                ->withHeaders($this->headers($organizationId))
                ->acceptJson()
                // Outlast the gateway's own wait, so a timeout here means the
                // gateway is unreachable rather than merely patient.
                ->timeout($wait + 15)
                ->post($this->url("/v1/nodes/{$nodeId}/commands"), array_filter([
                    'kind' => $kind,
                    'args' => $args === [] ? null : $args,
                    'timeout_seconds' => $timeoutSeconds,
                    'idempotency_key' => $idempotencyKey,
                    'wait_seconds' => $wait,
                ], static fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            // The gateway may have received the command and dispatched it.
            $this->auditOutcome($organizationId, $nodeId, $actor, 'unknown', $e->getMessage());

            throw new CommandOutcomeUnknown(
                'Could not reach the gateway; the command may or may not have run.',
                previous: $e,
            );
        } catch (Throwable $e) {
            $this->auditOutcome($organizationId, $nodeId, $actor, 'failed', $e->getMessage());

            throw new GatewayUnavailable('Gateway request failed: '.$e->getMessage(), previous: $e);
        }

        $body = $response->json() ?? [];
        $outcomeKnown = (bool) ($body['outcome_known'] ?? false);

        if ($response->status() === 409) {
            $this->auditOutcome($organizationId, $nodeId, $actor, 'not_connected', $body['error'] ?? null);

            throw new NodeNotConnected(sprintf('Node %s is not connected to the gateway.', $nodeId));
        }

        if ($response->status() === 504 || ! $outcomeKnown) {
            $this->auditOutcome($organizationId, $nodeId, $actor, 'unknown', $body['error'] ?? null);

            throw new CommandOutcomeUnknown(sprintf(
                'The outcome of %s on node %s is unknown: %s',
                $kind,
                $nodeId,
                $body['error'] ?? 'the gateway stopped waiting'
            ));
        }

        if ($response->failed()) {
            $this->auditOutcome($organizationId, $nodeId, $actor, 'failed', $body['error'] ?? null);

            throw new GatewayUnavailable(sprintf(
                'The gateway refused the command with status %d: %s',
                $response->status(),
                $body['error'] ?? 'no detail'
            ));
        }

        $this->auditOutcome(
            $organizationId,
            $nodeId,
            $actor,
            (string) ($body['status'] ?? 'unknown'),
            $body['error'] ?? null,
        );

        return $body;
    }

    /**
     * Which nodes the gateway currently holds.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Pass one instruction to a console on a node: open, input, resize or close.
     * Not audited each time: what is typed would be a keystroke log. The
     * console's opening and closing are.
     *
     * @param  array<string, mixed>  $terminal
     *
     * @throws NodeNotConnected
     * @throws GatewayUnavailable
     */
    public function terminal(string $nodeId, array $terminal): void
    {
        $organizationId = $this->organizationOf($nodeId);
        if ($organizationId === null) {
            throw new NodeNotConnected(sprintf('Node %s is not known.', $nodeId));
        }

        try {
            $response = $this->http
                ->withHeaders($this->headers($organizationId))
                ->acceptJson()
                ->timeout(10)
                ->post($this->url("/v1/nodes/{$nodeId}/terminal"), $terminal);
        } catch (Throwable $e) {
            throw new GatewayUnavailable('Gateway request failed: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 409) {
            throw new NodeNotConnected(sprintf('Node %s is not connected to the gateway.', $nodeId));
        }
        if ($response->failed()) {
            throw new GatewayUnavailable('The gateway refused the console message.');
        }
    }

    public function connectedNodes(?string $organizationId = null): array
    {
        $response = $this->http
            ->withHeaders([self::AUTH_HEADER => $this->token])
            ->acceptJson()
            ->timeout(10)
            ->get($this->url('/v1/nodes'), array_filter(['organization_id' => $organizationId]));

        if ($response->failed()) {
            throw new GatewayUnavailable('Could not list nodes: status '.$response->status());
        }

        return $response->json('nodes') ?? [];
    }

    public function isConnected(string $nodeId): bool
    {
        $organizationId = $this->organizationOf($nodeId);
        if ($organizationId === null) {
            return false;
        }

        $response = $this->http
            ->withHeaders($this->headers($organizationId))
            ->acceptJson()
            ->timeout(10)
            ->get($this->url("/v1/nodes/{$nodeId}"));

        if ($response->failed()) {
            throw new GatewayUnavailable('Could not read node status: status '.$response->status());
        }

        return (bool) $response->json('connected');
    }

    /**
     * Tell the gateway which organisations exist and which CA identifies each.
     *
     * Only the certificate is sent. The CA private key stays in the vault; the
     * gateway only ever verifies, and giving it signing power would widen the
     * blast radius of a gateway compromise from "can command nodes" to "can
     * mint node identities".
     *
     * @param  array<int, array{id: string, ca_certificate_pem: string}>  $organisations
     */
    public function syncOrganisations(array $organisations): int
    {
        $response = $this->http
            ->withHeaders([self::AUTH_HEADER => $this->token])
            ->acceptJson()
            ->timeout(30)
            ->put($this->url('/v1/organisations'), ['organisations' => $organisations]);

        if ($response->failed()) {
            throw new GatewayUnavailable(sprintf(
                'The gateway refused the organisation list with status %d: %s',
                $response->status(),
                $response->json('error') ?? 'no detail'
            ));
        }

        return (int) ($response->json('organisations') ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $response = $this->http->acceptJson()->timeout(10)->get($this->url('/health'));

        return [
            'reachable' => ! $response->serverError() || $response->status() === 503,
            'status' => $response->status(),
            'body' => $response->json() ?? [],
        ];
    }

    private function auditOutcome(
        string $organizationId,
        string $nodeId,
        Actor $actor,
        string $status,
        ?string $error,
    ): void {
        $this->audit->record(
            organizationId: $organizationId,
            actor: $actor,
            action: 'node.command_result',
            outcome: match ($status) {
                'succeeded' => Outcome::Succeeded,
                // Unknown is recorded as a failure deliberately. It is not a
                // success, and treating it as neutral would hide the cases
                // most worth reviewing after an incident.
                default => Outcome::Failed,
            },
            resourceType: 'node',
            resourceId: $nodeId,
            reason: $error,
            metadata: ['status' => $status],
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * The gateway keys sessions by organisation and node, so every node call
     * names the organisation it is for.
     *
     * @return array<string, string>
     */
    private function headers(string $organizationId): array
    {
        return [self::AUTH_HEADER => $this->token, 'X-Organization-ID' => $organizationId];
    }

    /**
     * The organisation a node belongs to, from the control plane's own record.
     * Node ids are unique here, so this is authoritative; an unknown node has
     * no organisation and is never sent to the gateway.
     */
    private function organizationOf(string $nodeId): ?string
    {
        $id = DB::table('nodes')->where('node_id', $nodeId)->value('organization_id');

        return $id === null ? null : (string) $id;
    }

    /** Matches gateway.AuthHeader. */
    public const AUTH_HEADER = 'X-Gateway-Token';
}
