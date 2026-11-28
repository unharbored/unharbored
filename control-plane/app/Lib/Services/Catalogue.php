<?php

declare(strict_types=1);

namespace App\Lib\Services;

use App\Lib\Providers\Incus\Ingress;
use App\Lib\Providers\ProviderServer;
use App\Lib\Templates\Definition;
use App\Lib\Templates\Sources;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * What the console shows about templates: the catalogue cards, the generated
 * form, the server picker and the review screen. Installing is Services'.
 *
 * Which templates are offered is a list of ids in config('templates.offered')
 * (CM_TEMPLATES_OFFERED, comma separated), not a flag in the definition: the
 * definitions are reviewed and, for remote ones, signed, and "this one is not
 * proven on a real server yet" is the operator's call. A template is offered
 * only if it is on the list; a container template can be installed only onto a server prepared for containers (the form's
 * server picker says which, and Services refuses the rest); the console enforces it on
 * every call, and the command line (Services) does not, so unverified
 * definitions can still be tried there.
 */
final class Catalogue
{
    public function __construct(private readonly Sources $sources, private readonly Services $services) {}

    public static function offered(string $id): bool
    {
        return in_array($id, (array) config('templates.offered', []), true);
    }

    /** @return list<array<string, mixed>> */
    public function cards(?string $organizationId = null): array
    {
        $cards = [];
        $anyPrepared = $organizationId !== null && DB::table('nodes')->where('organization_id', $organizationId)->whereNotNull('containers_ready_at')->exists();
        foreach ($this->sources->entries() as $e) {
            $def = $e->definition;
            if ($def === null && self::offered($e->id)) {
                try {
                    $def = $this->sources->definition($e);
                } catch (Throwable) {
                    $def = null;
                }
            }
            $runtime = $def?->runtime;
            $state = match (true) {
                self::offered($e->id) && $runtime === 'native' => 'ready',
                self::offered($e->id) && $runtime === 'container' => $anyPrepared ? 'ready' : 'needs-containers',
                $runtime === 'container' => 'container',
                default => 'unavailable',
            };
            $cards[] = [
                'id' => $e->id, 'name' => $e->name, 'category' => $e->category, 'version' => $e->version,
                'description' => $def?->description ?? '', 'memory' => $def?->memory, 'runtime' => $runtime,
                'tier' => $e->tier, 'label' => $e->label, 'publisher' => $e->publisher, 'state' => $state,
            ];
        }
        usort($cards, fn ($a, $b) => [$a['state'] !== 'ready', $a['name']] <=> [$b['state'] !== 'ready', $b['name']]);

        return $cards;
    }

    /** The definition of an offered template; anything else is refused the same way an unknown one is. */
    public function offeredDefinition(string $id): Definition
    {
        $def = $this->services->definition($id);
        if (! self::offered($id)) {
            throw new InvalidArgumentException("{$def->name} is not available to install yet.");
        }

        return $def;
    }

    /**
     * What the form sent, checked field by field before Services sees it: the template is offered, every input is valid,
     * a server is chosen, the acknowledgement and the extra ports are approved. Errors are keyed by the form's own paths
     * (values.key, secrets.key, node, acknowledged, portsApproved, form).
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $secrets
     * @return array<string, mixed> the inputs Services takes, secrets included
     *
     * @throws ValidationException
     */
    public function prepare(string $template, array $values, array $secrets, string $node, bool $acknowledged, bool $portsApproved): array
    {
        try {
            $def = $this->offeredDefinition($template);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
        $secretKeys = array_column(array_filter($def->inputs, fn ($i) => $i['type'] === 'secret'), 'key');
        $inputs = array_filter(array_diff_key($values, array_flip($secretKeys)) + array_intersect_key($secrets, array_flip($secretKeys)), fn ($v) => is_scalar($v));
        $errors = [];
        foreach ($def->checkInputs($inputs) as $key => $message) {
            $errors[(in_array($key, $secretKeys, true) ? 'secrets.' : 'values.').$key] = $message;
        }
        if ($node === '') {
            $errors['node'] = 'Choose the server it goes on.';
        }
        if ($def->acknowledge !== null && ! $acknowledged) {
            $errors['acknowledged'] = 'Tick the box to confirm you have read this.';
        }
        if ($def->extraPorts !== [] && ! $portsApproved) {
            $errors['portsApproved'] = 'Approve opening these ports, or do not install.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $inputs;
    }

    /** One entry for the form: its inputs, with everything the browser needs and nothing it should not. @return list<array<string, mixed>> */
    public function fields(Definition $def): array
    {
        return array_map(fn ($i) => [
            'key' => $i['key'], 'type' => $i['type'], 'label' => $i['label'], 'help' => $i['help'] ?? null,
            'default' => $i['default'] ?? null, 'options' => $i['options'] ?? [], 'required' => ! array_key_exists('default', $i) && ! isset($i['generate']),
            'generated' => isset($i['generate']),
        ], $def->inputs);
    }

    /**
     * The team's servers and whether the template fits each, with the reason when it does not.
     *
     * A container template also needs the server prepared for containers: an unprepared one is listed with `prepare` true,
     * and the form offers a Manager the way to prepare it.
     *
     * @return list<array{id: string, name: string, fits: bool, reason: ?string, memory: ?string, disk: ?string, prepare: bool}>
     */
    public function servers(string $organizationId, Definition $def, string $storage): array
    {
        $nodes = DB::table('nodes')->where('organization_id', $organizationId)->whereNull('archived_at')
            ->whereNotIn('node_id', DB::table('site_nodes')->whereNotNull('autoscale_pool_id')->select('node_id'))
            ->orderBy('node_id')->get();
        $out = [];
        foreach ($nodes as $n) {
            $reason = null;
            $needsPrepare = $def->runtime === 'container' && $n->containers_ready_at === null;
            try {
                $this->services->checkFit($n, $def, ['storage' => $storage]);
            } catch (InvalidArgumentException $e) {
                $reason = $e->getMessage();
            }
            try {
                $this->services->checkPorts($n->node_id, $def, null);
            } catch (InvalidArgumentException $e) {
                $reason = ($reason !== null ? $reason.' ' : '').$e->getMessage();
            }
            if ($needsPrepare) {
                $reason = 'Not prepared for containers.'.($reason !== null ? ' '.$reason : '');
            }
            $m = DB::table('node_metrics')->where('node_id', $n->node_id)->orderByDesc('collected_at')->first();
            $out[] = [
                'id' => $n->node_id, 'name' => strstr($n->node_id, '.', true) ?: $n->node_id, 'fits' => $reason === null, 'reason' => $reason, 'prepare' => $needsPrepare,
                'memory' => $m ? round($m->mem_total / 1024 ** 3, 1).' GB memory' : null,
                'disk' => $m ? round($m->disk_free / 1024 ** 3).' GB free' : null,
            ];
        }

        return $out;
    }

    /**
     * The DNS records each endpoint needs: an A or AAAA record for the server's address, and with an ingress in front
     * (an Incus host), an A record for the host's IPv4 beside the server's own AAAA.
     *
     * @param  list<array{port: int, domain: string}>  $endpoints
     * @return list<array{domain: string, type: string, value: ?string}>
     */
    private static function records(array $endpoints, ?string $ip, ?string $ingress): array
    {
        $out = [];
        foreach ($endpoints as $e) {
            if (str_ends_with($e['domain'], '.sslip.io')) {
                continue;
            }
            if ($ingress !== null) {
                $out[] = ['domain' => $e['domain'], 'type' => 'A', 'value' => $ingress];
            }
            $out[] = ['domain' => $e['domain'], 'type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A', 'value' => $ip];
        }

        return $out;
    }

    /** The server's public address, if the provider told us. */
    public static function serverIp(string $organizationId, string $nodeId): ?string
    {
        $attributes = DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')
            ->where('slug', strstr($nodeId, '.', true) ?: $nodeId)->value('attributes');

        return $attributes === null ? null : ProviderServer::publicAddress(json_decode((string) $attributes, true) ?: []);
    }

    /**
     * The review screen, from what Services::check returned: only what will happen, never a secret.
     *
     * @param  array{def: Definition, node: object, name: string, effective: array<string, string>, context: array<string, mixed>, project: int}  $a
     * @return array<string, mixed>
     */
    public function review(string $organizationId, array $a, string $role): array
    {
        $def = $a['def'];
        $entry = $this->sources->find($def->id);
        $ip = self::serverIp($organizationId, $a['node']->node_id);
        $endpoints = [];
        foreach ($a['context']['ports'] as $port => $domain) {
            $endpoints[] = ['port' => (int) $port, 'domain' => $domain];
        }
        $label = $entry?->label;
        $warning = match ($entry?->tier) {
            'community' => 'Community template from '.$entry->publisher.': the publisher vouches for it, Unharbored has not reviewed it.',
            'custom' => 'Custom, not reviewed: this template comes from '.$entry->source.'. It will download and run a program from '.($def->downloadHost() ?? 'the host it names').' on your server.',
            default => null,
        };

        return [
            'service' => $a['name'], 'template' => $def->name, 'version' => $def->version, 'label' => $label, 'warning' => $warning,
            'server' => $a['node']->node_id, 'ip' => $ip,
            'project' => (string) DB::table('projects')->where('id', $a['project'])->value('name'),
            'data' => $a['context']['data'], 'storage' => $a['effective']['storage'] ?? null,
            'endpoints' => $endpoints,
            'dns' => self::records($endpoints, $ip, Ingress::ipv4ForNode($organizationId, $a['node']->node_id)),
            'container' => $def->runtime !== 'container' ? null : [
                'image' => $a['context']['repository'], 'digest' => $a['context']['digest'],
                'memory' => $a['context']['limits']['memory'], 'cpu' => $a['context']['limits']['cpu'] ?? null,
                'volumes' => array_column($a['context']['volumes'], 'name'),
                'readOnly' => $a['context']['read_only'],
                'ports' => array_map(fn ($p) => (int) $p, array_keys($a['context']['ports'])),
            ],
            'download' => ['host' => $def->downloadHost(), 'url' => $def->runs['download'] ?? null, 'sha256' => $def->runs['sha256'] ?? null],
            'extraPorts' => $def->extraPorts,
            'acknowledge' => $def->acknowledge,
            'secrets' => array_column($def->secrets, 'key'),
            'backup' => isset($a['effective']['backup']) ? $a['effective']['backup'] : null,
            'role' => 'Allowed: you are '.(in_array($role, ['owner'], true) ? 'an ' : 'a ').ucfirst($role),
        ];
    }
}
