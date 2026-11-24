<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\RequestFailed;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use App\Lib\Sites\Sites;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * A DigitalOcean Load Balancer in front of one site's servers.
 *
 * HTTPS ends at the balancer, with the site's own Let's Encrypt certificate
 * uploaded to it: DigitalOcean can only issue certificates itself for
 * domains whose DNS it hosts. The primary server still obtains and renews
 * the certificate (the other servers pass the challenge to it), and
 * syncCertificate() uploads it again whenever it has changed.
 *
 * The balancer talks plain HTTP to every server on port 80 and checks /up
 * on the site's health port (Sites::healthPort).
 */
final class LoadBalancers implements \App\Lib\Scaling\EdgeDriver
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ResourceManager $resources,
        private readonly AuditLog $audit,
        private readonly GatewayClient $gateway,
    ) {}

    /** Asks DigitalOcean for a balancer over the site's servers. Safe to repeat. */
    public function create(stdClass $site): ResourceRecord
    {
        \App\Lib\Providers\ProviderScope::assertDigitalOcean($site, 'Load balancers');
        $region = $this->region($site);
        $resource = $this->resources->declare($site->organization_id, 'load_balancer', $site->name, ['region' => $region, 'site_id' => $site->id]);
        if ($resource->externalId !== null) {
            return $resource;
        }

        $actor = Actor::system('control-plane');
        $client = $this->client($site, "Creating a load balancer for {$site->name}");
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: "Creating a load balancer for {$site->name}", actor: $actor);
        try {
            $lb = $client->post('load_balancers', [
                'name' => "cm-{$site->name}",
                'region' => $region,
                'size_unit' => 1,
                ...$this->spec($site, null),
                'tags' => ['cloud-manager', "org:{$site->organization_id}", "resource:{$site->name}"],
            ], $resource->idempotencyKey)['load_balancer'] ?? [];
        } catch (RequestFailed $e) {
            $uncertain = $e->status === null || $e->status >= 500;
            $this->resources->transition($resource, $uncertain ? ResourceState::Orphaned : ResourceState::Failed,
                reason: $uncertain ? 'Create did not return a usable answer; a balancer may exist' : 'DigitalOcean refused the create',
                error: $e->getMessage(), actor: $actor);

            throw new RuntimeException('Load balancer creation failed: '.$e->getMessage(), previous: $e);
        }

        $resource = $this->resources->recordExternalId($resource, (string) ($lb['id'] ?? throw new RuntimeException('DigitalOcean returned no balancer id.')));
        DB::table('sites')->where('id', $site->id)->update(['load_balancer_id' => $resource->id, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, $actor, 'site.load_balancer_created', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['load_balancer' => $resource->externalId]);

        return $resource;
    }

    /** Active once DigitalOcean says so and it has an address. */
    public function refresh(stdClass $site, ResourceRecord $resource): ResourceRecord
    {
        if ($resource->state === ResourceState::Active) {
            return $resource;
        }
        $lb = $this->client($site, "Checking the load balancer for {$site->name}")->get("load_balancers/{$resource->externalId}")['load_balancer'] ?? [];
        if (($lb['status'] ?? null) !== 'active' || ($lb['ip'] ?? '') === '') {
            return $resource;
        }
        $resource = $this->resources->mergeAttributes($resource, ['ip' => $lb['ip']]);

        return $this->resources->transition($resource, ResourceState::Active, reason: 'Load balancer is active', actor: Actor::system('control-plane'));
    }

    /**
     * Rewrites the balancer from the site as it is now: its servers, and
     * HTTPS if a certificate has been uploaded. DigitalOcean replaces the
     * whole configuration on update, so it is always sent whole.
     */
    public function apply(stdClass $site): string
    {
        $resource = $this->of($site);
        $this->client($site, "Updating the load balancer for {$site->name}")->put("load_balancers/{$resource->externalId}", [
            'name' => "cm-{$site->name}",
            'region' => $resource->attributes['region'],
            ...$this->spec($site, $resource->attributes['certificate_id'] ?? null),
        ]);

        return 'balancer sends traffic to '.implode(', ', array_map(fn ($n) => strstr($n, '.', true) ?: $n, Sites::webNodes($site)));
    }

    /**
     * Uploads the primary's current certificate if it is not the one the
     * balancer already has, then retires the old one. Returns what it did.
     */
    public function syncCertificate(stdClass $site): string
    {
        $resource = $this->of($site);
        [$chainPath, $keyPath] = Sites::certificateFiles($site);
        $fullchain = $this->read($site, $chainPath);
        $sha = hash('sha256', $fullchain);
        if (($resource->attributes['certificate_sha'] ?? null) === $sha) {
            return 'balancer already has the current certificate';
        }

        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $fullchain, $m);
        $certs = $m[0];
        if ($certs === []) {
            throw new RuntimeException("No certificate found at {$chainPath} on the primary server.");
        }
        $client = $this->client($site, "Uploading the certificate for {$site->domain}");
        $uploaded = $client->post('certificates', [
            'name' => 'cm-'.$site->name.'-'.substr($sha, 0, 12),
            'type' => 'custom',
            'private_key' => $this->read($site, $keyPath),
            'leaf_certificate' => $certs[0],
            'certificate_chain' => implode("\n", array_slice($certs, 1)),
        ])['certificate']['id'] ?? throw new RuntimeException('DigitalOcean stored the certificate but returned no id.');

        $old = $resource->attributes['certificate_id'] ?? null;
        $this->resources->mergeAttributes($resource, ['certificate_id' => $uploaded, 'certificate_sha' => $sha]);
        $this->apply($site);
        if ($old !== null) {
            try {
                $client->delete("certificates/{$old}");
            } catch (RequestFailed) {
                // Left behind costs nothing; the next sync does not retry it.
            }
        }
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.load_balancer_certificate', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['certificate' => $uploaded, 'domain' => $site->domain]);

        return "balancer serves https://{$site->domain}/ with the certificate from ".(strstr($site->node_id, '.', true) ?: $site->node_id);
    }

    public function of(stdClass $site): ResourceRecord
    {
        return $this->resources->findById((int) ($site->load_balancer_id ?? 0))
            ?? throw new InvalidArgumentException("{$site->name} has no load balancer.");
    }

    /**
     * Deletes the site's balancer and the certificate uploaded for it, then
     * clears the site's reference. The certificate matters: it is billed and
     * useless once its balancer is gone.
     */
    public function destroy(stdClass $site): void
    {
        $resource = $this->of($site);
        $actor = Actor::system('control-plane');
        $client = $this->client($site, "Removing the load balancer for {$site->name}");

        if ($resource->externalId !== null) {
            try {
                $client->delete("load_balancers/{$resource->externalId}");
            } catch (RequestFailed $e) {
                if ($e->status !== 404) {
                    throw new RuntimeException('Could not delete the load balancer: '.$e->getMessage(), previous: $e);
                }
            }
        }

        $certificate = $resource->attributes['certificate_id'] ?? null;
        if ($certificate !== null) {
            try {
                $client->delete("certificates/{$certificate}");
            } catch (RequestFailed) {
                // Already gone, or refused: a leftover certificate costs nothing.
            }
        }

        if ($resource->state === ResourceState::Active || $resource->state === ResourceState::Updating) {
            $resource = $this->resources->transition($resource, ResourceState::Deleting, reason: 'Application deleted', actor: $actor);
            $this->resources->transition($resource, ResourceState::Deleted, reason: 'Load balancer deleted', actor: $actor);
        }
        DB::table('sites')->where('id', $site->id)->update(['load_balancer_id' => null, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, $actor, 'site.load_balancer_deleted', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['load_balancer' => $resource->externalId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(stdClass $site, ?string $certificateId): array
    {
        $rules = [['entry_protocol' => 'http', 'entry_port' => 80, 'target_protocol' => 'http', 'target_port' => 80]];
        if ($certificateId !== null) {
            $rules[] = ['entry_protocol' => 'https', 'entry_port' => 443, 'target_protocol' => 'http', 'target_port' => 80, 'certificate_id' => $certificateId];
        }

        return [
            'forwarding_rules' => $rules,
            'redirect_http_to_https' => $certificateId !== null,
            'health_check' => ['protocol' => 'http', 'port' => Sites::healthPort($site), 'path' => '/up', 'check_interval_seconds' => 10,
                'response_timeout_seconds' => 5, 'healthy_threshold' => 3, 'unhealthy_threshold' => 3],
            'droplet_ids' => $this->dropletIds($site),
        ];
    }

    /** @return list<int> */
    private function dropletIds(stdClass $site): array
    {
        return array_map(function (string $node) use ($site) {
            $id = DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')
                ->where('slug', strstr($node, '.', true) ?: $node)->value('external_id');

            return (int) ($id ?? throw new InvalidArgumentException("{$node} is not a Droplet the platform created, so a load balancer cannot name it."));
        }, Sites::webNodes($site));
    }

    private function region(stdClass $site): string
    {
        $attributes = DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')
            ->where('slug', strstr($site->node_id, '.', true) ?: $site->node_id)->value('attributes');

        return (string) (json_decode((string) $attributes, true)['region'] ?? throw new InvalidArgumentException("{$site->node_id} is not a Droplet the platform created."));
    }

    private function read(stdClass $site, string $path): string
    {
        $result = $this->gateway->run($site->organization_id, $site->node_id, 'exec', ['name' => 'cat', 'args' => [$path]],
            reason: "Reading {$path} for the load balancer");
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException("Could not read {$path}: ".($result['error'] ?? 'no detail'));
        }

        return (string) ($result['detail']['output'] ?? '');
    }

    private function client(stdClass $site, string $reason): Client
    {
        return $this->credentials->clientFor($site->organization_id, $reason, Actor::system('control-plane'));
    }
}
