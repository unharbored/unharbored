<?php

declare(strict_types=1);

namespace App\Lib\Providers\Incus;

use App\Lib\Operations\OperationRunner;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The IPv4 ingress on an Incus host: one more server, "incus-ingress", that owns the host's IPv4 ports 80 and 443
 * (Incus proxy devices in NAT mode, so visitors' addresses survive) and passes each connection to the right server's
 * IPv6 address by name. HTTPS is routed on the name in the TLS handshake (SNI) and never decrypted; certificates stay
 * on the servers. HTTP is routed on the Host header, which carries Let's Encrypt's checks.
 *
 * Servers trust a PROXY header (on 10443) and X-Forwarded-For (on 80) only from the ingress's own IPv6 address, which
 * is worked out from its name like every server's. The routes are rebuilt whole from the database after anything that
 * can change them, so they never drift.
 */
final class Ingress
{
    public const SLUG = 'incus-ingress';

    /** The port a server answers HTTPS from the ingress on, with a PROXY header first. */
    public const PROXY_PORT = 10443;

    /** Operation kinds after which a domain, a site or a server on the host may have changed. */
    public const AFFECTS = ['app_create', 'app_delete', 'domain', 'preview_create', 'preview_destroy', 'server_destroy', 'provision', 'adopt'];

    public function __construct(private readonly OperationRunner $runner) {}

    /**
     * Create the ingress for a team that has connected Incus, unless it exists or is on its way. A destroyed ingress's
     * record is kept for its history under a new name, since the ingress always takes the same one.
     */
    public function ensure(string $organizationId, string $actor): ?int
    {
        if (self::resource($organizationId) !== null || DB::table('operations')->where('organization_id', $organizationId)
            ->where('node_id', self::nodeId($organizationId))->whereIn('status', ['queued', 'running', 'waiting'])->exists()) {
            return null;
        }
        foreach (DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')->where('slug', self::SLUG)->where('state', 'deleted')->pluck('id') as $id) {
            DB::table('resources')->where('id', $id)->update(['slug' => self::SLUG."-deleted-{$id}", 'updated_at' => now()]);
        }

        return $this->runner->queueIngressCreate($organizationId, self::SLUG, $actor);
    }

    /** Rebuild the routes on the ingress, once it is up; a sync already waiting covers this change too. */
    public function queueSync(string $organizationId, string $trigger, string $actor): ?int
    {
        if (! self::active($organizationId) || DB::table('operations')->where('node_id', self::nodeId($organizationId))
            ->where('kind', 'ingress_sync')->where('status', 'queued')->exists()) {
            return null;
        }

        return $this->runner->queueIngressSync(self::nodeId($organizationId), $trigger, $actor);
    }

    public static function nodeId(string $organizationId): string
    {
        return self::SLUG.'.'.$organizationId;
    }

    /** The ingress's server record, whatever its state, or null. */
    public static function resource(string $organizationId): ?stdClass
    {
        return DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')->where('slug', self::SLUG)
            ->where('state', '!=', 'deleted')->first();
    }

    /** Whether the ingress is enrolled and set up, so routes can be pushed and names can point at the host's IPv4. */
    public static function active(string $organizationId): bool
    {
        return DB::table('nodes')->where('node_id', self::nodeId($organizationId))->where('role', 'ingress')->exists();
    }

    /** The host's public IPv4, where every server on it is reached by visitors without IPv6, once the ingress is up. */
    public static function ipv4(string $organizationId): ?string
    {
        return self::active($organizationId) ? (self::attributes($organizationId)['ipv4'] ?? null) : null;
    }

    /**
     * Where visitors without IPv6 reach a server: the host's IPv4, when the server is on the Incus host (and is not the
     * ingress) and the ingress is up. Null otherwise, and the server's own address applies.
     */
    public static function ipv4ForNode(string $organizationId, string $nodeId): ?string
    {
        return self::behind($organizationId, $nodeId) ? self::ipv4($organizationId) : null;
    }

    /** The ingress's IPv6, the only address a server behind it trusts to tell it who the visitor is. */
    public static function source(string $organizationId): ?string
    {
        return self::attributes($organizationId)['ipv6'] ?? null;
    }

    /**
     * The address a site's server trusts for forwarded visitors' addresses: the ingress's, when the site's server is
     * on the same Incus host and is not the ingress itself. Null for everything else.
     */
    public static function sourceFor(stdClass $site): ?string
    {
        return self::sourceForNode((string) $site->organization_id, (string) $site->node_id);
    }

    /** The same, for a server: what its firewall lets in on 80 and 10443 whatever else it closes. */
    public static function sourceForNode(string $organizationId, string $nodeId): ?string
    {
        return self::behind($organizationId, $nodeId) ? self::source($organizationId) : null;
    }

    /** Whether a node is a server on the team's Incus host, other than the ingress. */
    private static function behind(string $organizationId, string $nodeId): bool
    {
        $slug = strstr($nodeId, '.', true) ?: $nodeId;

        return $slug !== self::SLUG && DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')
            ->where('slug', $slug)->value('provider') === IncusProvider::PROVIDER;
    }

    /**
     * Every domain served by a server on the host, and that server's IPv6 address.
     *
     * @return array<string, string>
     */
    public static function routes(string $organizationId): array
    {
        $addresses = [];
        foreach (DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')->where('provider', IncusProvider::PROVIDER)
            ->where('state', '!=', 'deleted')->where('slug', '!=', self::SLUG)->get(['slug', 'attributes']) as $server) {
            $ipv6 = json_decode((string) $server->attributes, true)['ipv6'] ?? null;
            if ($ipv6 !== null) {
                $addresses["{$server->slug}.{$organizationId}"] = $ipv6;
            }
        }
        $routes = [];
        foreach (DB::table('sites')->where('organization_id', $organizationId)->whereIn('node_id', array_keys($addresses))->orderBy('domain')->get(['domain', 'node_id']) as $site) {
            if (preg_match('/^[a-z0-9.-]+$/', (string) $site->domain)) {
                $routes[(string) $site->domain] = $addresses[$site->node_id];
            }
        }

        return $routes;
    }

    /**
     * What the ingress runs: nginx with its stream module, and the two route maps. The packages come first, because
     * installing the module runs nginx's own config check, which a stream block without its module would fail.
     *
     * @param  array<string, string>  $routes  domain => IPv6
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function recipe(array $routes): array
    {
        $https = $http = '';
        foreach ($routes as $domain => $ipv6) {
            $https .= "        {$domain} [{$ipv6}]:".self::PROXY_PORT.";\n";
            $http .= "    {$domain} \"[{$ipv6}]\";\n";
        }
        $stream = <<<CONF
# Managed by Unharbored: the IPv4 ingress. HTTPS by name, passed through untouched.
stream {
    map \$ssl_preread_server_name \$ingress_backend {
{$https}        default "";
    }
    server {
        listen 443;
        ssl_preread on;
        proxy_pass \$ingress_backend;
        proxy_protocol on;
        proxy_connect_timeout 5s;
    }
}

CONF;
        $plain = <<<CONF
# Managed by Unharbored: the IPv4 ingress. HTTP by Host header, for Let's Encrypt and the redirect to HTTPS.
map \$host \$ingress_http_backend {
{$http}    default "";
}
server {
    listen 80 default_server;
    if (\$ingress_http_backend = "") { return 444; }
    location / {
        proxy_pass http://\$ingress_http_backend;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto http;
    }
}

CONF;

        return ['name' => 'ingress', 'steps' => [
            ['id' => 'ingress-nginx', 'type' => 'package', 'name' => 'nginx'],
            ['id' => 'ingress-stream-module', 'type' => 'package', 'name' => 'libnginx-mod-stream'],
            ['id' => 'ingress-no-default', 'type' => 'absent', 'path' => '/etc/nginx/sites-enabled/default'],
            ['id' => 'ingress-stream', 'type' => 'file', 'path' => '/etc/nginx/modules-enabled/90-ingress-stream.conf', 'content_b64' => base64_encode($stream), 'mode' => '0644'],
            ['id' => 'ingress-http', 'type' => 'file', 'path' => '/etc/nginx/conf.d/90-ingress-http.conf', 'content_b64' => base64_encode($plain), 'mode' => '0644'],
            ['id' => 'ingress-check', 'type' => 'command', 'name' => 'nginx', 'args' => ['-t'], 'description' => 'nginx accepts the routes'],
            ['id' => 'ingress-running', 'type' => 'service', 'name' => 'nginx', 'state' => 'running'],
            ['id' => 'ingress-reload', 'type' => 'service', 'name' => 'nginx', 'state' => 'reloaded'],
        ]];
    }

    /** @return array<string, mixed> */
    private static function attributes(string $organizationId): array
    {
        return json_decode((string) (self::resource($organizationId)->attributes ?? '{}'), true) ?: [];
    }
}
