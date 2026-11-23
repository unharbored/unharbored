<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Providers\ProviderServer;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use App\Lib\Servers\Firewall;
use App\Lib\Servers\LocalServices;
use App\Lib\Servers\ServerStack;
use App\Lib\Sites\Sites;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * A database cluster that Unharbored runs on a server of its own, on any provider that gives
 * us servers: the self-run way to supply a database, labelled "managed by Unharbored".
 * DigitalOcean's managed clusters are DatabaseClusters; this is what a provider with no
 * managed database offers, and it sits behind the same cluster records, the same attach
 * and the same Databases page.
 *
 * The cluster is a `database_cluster` resource (mode self_run) and a `server` resource
 * the provisioner made for it. The engine listens on the network, TLS only, and the
 * server's firewall admits the servers of attached sites and nothing else. Each site
 * gets a database and a user of its own, never the administrator.
 *
 * What this does not give you, and a managed cluster does: failover, point-in-time
 * restore and provider-side backups. One server is one machine; the site backups
 * (nightly dumps from each application's own variables) still apply.
 */
final class DatabaseServers
{
    /** What it can run so far. Keys are the engine names the clusters page already uses. */
    public const ENGINES = ['pg' => 'PostgreSQL', 'mysql' => 'MySQL'];

    private const SERVICE = ['pg' => 'pgsql', 'mysql' => 'mysql'];

    private const PORT = ['pg' => 5432, 'mysql' => 3306];

    private const KEY_FILE = '/root/.cm-db-tls.key';

    private const CERT_FILE = '/root/.cm-db-tls.crt';

    public function __construct(
        private readonly ResourceManager $resources,
        private readonly ServerProvisioner $provisioner,
        private readonly GatewayClient $gateway,
        private readonly LocalServices $local,
        private readonly Sites $sites,
        private readonly AuditLog $audit,
    ) {}

    public static function isSelfRun(ResourceRecord $cluster): bool
    {
        return ($cluster->attributes['mode'] ?? null) === 'self_run';
    }

    public static function serverSlug(string $clusterSlug): string
    {
        return "db-{$clusterSlug}";
    }

    public function nodeId(string $organizationId, string $clusterSlug): string
    {
        return $this->provisioner->nodeId($organizationId, self::serverSlug($clusterSlug));
    }

    /**
     * Declares the cluster and has the provider create its server. The server then enrolls, and
     * prepare() sets the engine up: the operation waits between the two.
     */
    public function create(string $organizationId, string $slug, string $engine, string $provider, string $region, string $size): ResourceRecord
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $slug)) {
            throw new InvalidArgumentException('Name a cluster with lowercase letters, digits and dashes.');
        }
        if (! isset(self::ENGINES[$engine])) {
            throw new InvalidArgumentException('A server can run '.implode(' or ', self::ENGINES).' so far; pick one of those.');
        }

        $actor = Actor::system('control-plane');
        $cluster = $this->resources->declare($organizationId, 'database_cluster', $slug, [
            'engine' => $engine, 'region' => $region, 'size' => $size, 'mode' => 'self_run', 'server' => self::serverSlug($slug), 'admitted' => [],
        ], provider: $provider);
        if ($cluster->state === ResourceState::Deleted) {
            throw new InvalidArgumentException("{$slug} was the name of a destroyed cluster. Choose another name.");
        }
        if ($cluster->state !== ResourceState::Pending && $cluster->state !== ResourceState::Failed) {
            throw new InvalidArgumentException("There is already a cluster called {$slug}.");
        }
        $cluster = $this->resources->transition($cluster, ResourceState::Provisioning, reason: "Creating database server for {$slug}", actor: $actor);

        try {
            $server = $this->provisioner->provision($organizationId, self::serverSlug($slug), [
                'provider' => $provider, 'region' => $region, 'size' => $size, 'attributes' => ['database_cluster' => $slug],
            ], $actor);
        } catch (Throwable $e) {
            $this->resources->transition($cluster, ResourceState::Failed, reason: 'The database server could not be created', error: $e->getMessage(), actor: $actor);

            throw $e;
        }

        return $this->resources->recordExternalId($cluster, (string) ($server->externalId ?? self::serverSlug($slug)));
    }

    /**
     * Installs the engine on the connected server, turns TLS on with a certificate made for its address,
     * lets the network reach it, and brings the firewall up closed. Safe to run again: the certificate is
     * made afresh each run and only kept once everything worked, so it always matches the key on the server.
     *
     * @param  Closure(string, string): void  $log
     */
    public function prepare(ResourceRecord $cluster, Closure $log): ResourceRecord
    {
        if ($cluster->state === ResourceState::Active) {
            return $cluster;
        }
        $org = $cluster->organizationId;
        $engine = (string) $cluster->attributes['engine'];
        $service = self::SERVICE[$engine];
        $node = $this->nodeId($org, $cluster->slug);
        $server = $this->resources->find($org, 'server', self::serverSlug($cluster->slug)) ?? throw new RuntimeException('The database server record is gone.');
        $address = ProviderServer::publicAddress($server->attributes) ?? throw new RuntimeException('The database server has no public address yet.');

        [$certificate, $key] = self::certificateFor($address);
        $steps = [
            ...LocalServices::installRecipe($service)['steps'],
            ['id' => 'tls-key', 'type' => 'file', 'path' => self::KEY_FILE, 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode($key)],
            ['id' => 'tls-cert', 'type' => 'file', 'path' => self::CERT_FILE, 'mode' => '0644', 'content_b64' => base64_encode($certificate)],
            ['id' => 'listen', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', self::listenScript($engine)], 'description' => self::ENGINES[$engine].' listens on the network, TLS only'],
            ['id' => 'tls-forget', 'type' => 'absent', 'path' => self::KEY_FILE],
        ];
        $log('database', 'installing '.self::ENGINES[$engine].' and turning TLS on');
        try {
            $result = $this->gateway->run($org, $node, 'apply_recipe', ['spec' => ['name' => 'database-server', 'steps' => $steps]], timeoutSeconds: 900, waitSeconds: 930,
                reason: "Set up database cluster {$cluster->slug}");
        } finally {
            try {
                $this->gateway->run($org, $node, 'apply_recipe', ['spec' => ['name' => 'database-server-cleanup', 'steps' => [['id' => 'forget', 'type' => 'absent', 'path' => self::KEY_FILE]]]], timeoutSeconds: 30);
            } catch (Throwable) {
            }
        }
        foreach ($result['detail']['run']['steps'] ?? [] as $step) {
            $log((string) $step['action'], (string) $step['description']);
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            $why = trim((string) ($result['error'] ?? 'no detail'));
            throw new RuntimeException('Setting up the database server failed: '.(mb_strlen($why) > 1800 ? mb_substr($why, 0, 300)."\n…\n".mb_substr($why, -1500) : $why));
        }

        DB::table('server_services')->updateOrInsert(['node_id' => $node, 'service' => $service],
            ['organization_id' => $org, 'state' => 'installed', 'updated_at' => now(), 'created_at' => now()]);
        $cluster = $this->resources->mergeAttributes($cluster, ['host' => $address, 'port' => self::PORT[$engine], 'version' => $engine === 'pg' ? ServerStack::PG_CLIENT : null, 'ca_pem' => $certificate]);
        $this->applyFirewall($cluster);
        $log('firewall', 'closed to everyone but the servers of attached sites');

        return $this->resources->transition($cluster, ResourceState::Active, reason: 'Database server is ready', actor: Actor::system('control-plane'));
    }

    /**
     * Gives the site a database and user, admits its server, and writes the connection into its .env.
     *
     * @return list<string>
     */
    public function attach(stdClass $site, ResourceRecord $cluster): array
    {
        $engine = (string) $cluster->attributes['engine'];
        $service = self::SERVICE[$engine] ?? throw new InvalidArgumentException("{$cluster->slug} runs {$engine}, which a site cannot be attached to.");
        $name = str_replace('-', '_', (string) $site->name);
        $done = [$this->admitServer($site, (string) $site->node_id, $cluster)];

        // A fresh password every attach: re-attaching is also how a site's database password is rotated.
        $password = bin2hex(random_bytes(16));
        $this->local->grantOnNode($site->organization_id, $this->nodeId($site->organization_id, $cluster->slug), $service, $name, $password,
            "Grant {$site->name} its database on {$cluster->slug}", ['%']);
        $done[] = "user {$name} owns database {$name}";

        $this->sites->setInjected($site, [
            'DB_CONNECTION' => $engine === 'pg' ? 'pgsql' : 'mysql',
            'DB_HOST' => (string) $cluster->attributes['host'],
            'DB_PORT' => (string) $cluster->attributes['port'],
            'DB_DATABASE' => $name,
            'DB_USERNAME' => $name,
            'DB_PASSWORD' => $password,
            'DB_SSLMODE' => $engine === 'pg' ? 'require' : null,
            'MYSQL_ATTR_SSL_CA' => $engine === 'mysql' ? Sites::root($site).'/shared/database-ca.crt' : null,
        ], "Database cluster {$cluster->slug}");
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id, 'database_mode' => 'cluster', 'updated_at' => now()]);
        $done[] = '.env points at the cluster; deploy to switch';
        if (($over = $this->sites->overriddenKeys($site, Sites::INJECTABLE['database'])) !== []) {
            $done[] = 'your own variables override '.implode(', ', $over).', so those keep their value; remove the overrides to use the cluster';
        }
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.database_attached', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['cluster' => $cluster->slug, 'database' => $name]);

        return $done;
    }

    /** Lets a server reach the cluster. The same rule twice changes nothing. */
    public function admitServer(stdClass $site, string $nodeId, ResourceRecord $cluster): string
    {
        $address = $this->addressOf($site->organization_id, $nodeId);
        $admitted = (array) ($cluster->attributes['admitted'] ?? []);
        if (in_array($address, $admitted, true)) {
            return "firewall already admits {$address}";
        }
        $cluster = $this->resources->mergeAttributes($cluster, ['admitted' => [...$admitted, $address]]);
        $this->applyFirewall($cluster);

        return "firewall admits {$address}";
    }

    /** The reverse, for a server that stops serving the site. The caller has checked no other site there uses the cluster. */
    public function releaseServer(stdClass $site, string $nodeId, ResourceRecord $cluster): string
    {
        $address = $this->addressOf($site->organization_id, $nodeId);
        $admitted = array_values(array_diff((array) ($cluster->attributes['admitted'] ?? []), [$address]));
        if (count($admitted) === count((array) ($cluster->attributes['admitted'] ?? []))) {
            return "{$cluster->slug} did not admit {$address}";
        }
        $cluster = $this->resources->mergeAttributes($cluster, ['admitted' => $admitted]);
        $this->applyFirewall($cluster);

        return "{$cluster->slug} no longer admits {$address}";
    }

    /**
     * A database and user for something that is not a site's own attachment, such as a clone or a preview. The
     * caller owns the returned credentials.
     *
     * @return array{engine: string, host: string, port: int, database: string, username: string, password: string}
     */
    public function createDatabase(stdClass $site, ResourceRecord $cluster, string $name): array
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,40}$/', $name)) {
            throw new InvalidArgumentException('A database name is lower case letters, digits and underscores, starting with a letter.');
        }
        $engine = (string) $cluster->attributes['engine'];
        $password = bin2hex(random_bytes(16));
        $this->admitServer($site, (string) $site->node_id, $cluster);
        try {
            $this->local->grantOnNode($site->organization_id, $this->nodeId($site->organization_id, $cluster->slug), self::SERVICE[$engine], $name, $password,
                "Create database {$name} on {$cluster->slug}", ['%']);
        } catch (Throwable $e) {
            $this->discardDatabase($site, $cluster, $name);

            throw $e;
        }

        return ['engine' => $engine === 'pg' ? 'pgsql' : 'mysql', 'host' => (string) $cluster->attributes['host'], 'port' => (int) $cluster->attributes['port'],
            'database' => $name, 'username' => $name, 'password' => $password];
    }

    /** Best effort: removes what createDatabase() made. */
    public function discardDatabase(stdClass $site, ResourceRecord $cluster, string $name): void
    {
        try {
            $this->local->dropOnNode($site->organization_id, $this->nodeId($site->organization_id, $cluster->slug),
                self::SERVICE[(string) $cluster->attributes['engine']], $name, "Remove database {$name} from {$cluster->slug}");
        } catch (Throwable) {
        }
    }

    /** @return list<string> */
    public function dropDatabase(stdClass $site, ResourceRecord $cluster): array
    {
        $name = str_replace('-', '_', (string) $site->name);
        $this->local->dropOnNode($site->organization_id, $this->nodeId($site->organization_id, $cluster->slug),
            self::SERVICE[(string) $cluster->attributes['engine']], $name, "Drop {$site->name}'s database");

        return ["user {$name} dropped", "database {$name} dropped"];
    }

    /** Destroys the server, and with it every database on it. Refused while any site is attached. */
    public function destroy(ResourceRecord $cluster): void
    {
        $users = DB::table('sites')->where('database_cluster_id', $cluster->id)->pluck('name')->all();
        if ($users !== []) {
            throw new InvalidArgumentException("{$cluster->slug} is used by ".implode(', ', $users).'. Detach them first.');
        }
        $actor = Actor::system('control-plane');
        $server = $this->resources->find($cluster->organizationId, 'server', self::serverSlug($cluster->slug));
        if ($server !== null && ! in_array($server->state, [ResourceState::Deleted, ResourceState::Deleting], true)) {
            $this->provisioner->destroy($server, $actor);
        }
        $cluster = $this->resources->transition($cluster, ResourceState::Deleting, reason: 'Destroy requested', actor: $actor);
        $this->resources->transition($cluster, ResourceState::Deleted, reason: 'Destroyed', actor: $actor);
    }

    /**
     * What the database server's firewall must allow: the engine's port, from the admitted addresses. Null for any
     * node that is not one. Read by Firewall, so every rebuild of that server's rules keeps them.
     *
     * @return array{port: int, sources: list<string>}|null
     */
    public static function firewallFor(string $nodeId): ?array
    {
        $org = DB::table('nodes')->where('node_id', $nodeId)->value('organization_id');
        if ($org === null) {
            return null;
        }
        $server = DB::table('resources')->where('organization_id', $org)->where('type', 'server')->where('slug', strstr($nodeId, '.', true) ?: $nodeId)->value('attributes');
        $slug = $server === null ? null : ((json_decode((string) $server, true) ?: [])['database_cluster'] ?? null);
        if ($slug === null) {
            return null;
        }
        $cluster = DB::table('resources')->where('organization_id', $org)->where('type', 'database_cluster')->where('slug', $slug)->where('state', '!=', 'deleted')->value('attributes');
        $a = $cluster === null ? [] : (json_decode((string) $cluster, true) ?: []);
        if (! isset(self::PORT[$a['engine'] ?? ''])) {
            return null;
        }

        return ['port' => self::PORT[$a['engine']], 'sources' => array_values((array) ($a['admitted'] ?? []))];
    }

    private function applyFirewall(ResourceRecord $cluster): void
    {
        $node = $this->nodeId($cluster->organizationId, $cluster->slug);
        $result = $this->gateway->run($cluster->organizationId, $node, 'apply_recipe', ['spec' => Firewall::recipe(Firewall::settings($node))], timeoutSeconds: 300,
            reason: "Update who may reach database cluster {$cluster->slug}");
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException('Updating the database server firewall failed: '.mb_substr((string) ($result['error'] ?? 'no detail'), 0, 600));
        }
    }

    /**
     * The address a server reaches the cluster from: the one it was created with, or the one it enrolled from.
     * If it changes, the server loses the cluster until admitted again.
     */
    private function addressOf(string $organizationId, string $nodeId): string
    {
        $server = $this->resources->find($organizationId, 'server', strstr($nodeId, '.', true) ?: $nodeId);
        $address = $server !== null ? ProviderServer::publicAddress($server->attributes) : null;
        $address ??= DB::table('enrollment_grants')->where('node_id', $nodeId)->whereNotNull('consumed_by_ip')->orderByDesc('id')->value('consumed_by_ip');
        if ($address === null || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("{$nodeId} has no public address the database server's firewall could name.");
        }

        return (string) $address;
    }

    /** A shell script, run as root on the server, that points the engine at the certificate and the network. */
    private static function listenScript(string $engine): string
    {
        $v = ServerStack::PG_CLIENT;
        $key = self::KEY_FILE;
        $crt = self::CERT_FILE;

        return $engine === 'pg'
            ? <<<SH
set -e
d=/etc/postgresql/{$v}
install -d -o postgres -g postgres -m 0750 \$d/ssl
install -o postgres -g postgres -m 0600 {$key} \$d/ssl/server.key
install -o postgres -g postgres -m 0644 {$crt} \$d/ssl/server.crt
printf "listen_addresses = '*'\\nssl = on\\nssl_cert_file = '%s'\\nssl_key_file = '%s'\\npassword_encryption = scram-sha-256\\n" "\$d/ssl/server.crt" "\$d/ssl/server.key" > \$d/main/conf.d/cm-remote.conf
grep -q '^# cm-remote' \$d/main/pg_hba.conf || printf '# cm-remote\\nhostssl all all 0.0.0.0/0 scram-sha-256\\nhostssl all all ::/0 scram-sha-256\\n' >> \$d/main/pg_hba.conf
systemctl restart postgresql
SH
            : <<<SH
set -e
install -d -o mysql -g mysql -m 0750 /etc/mysql/ssl
install -o mysql -g mysql -m 0600 {$key} /etc/mysql/ssl/server-key.pem
install -o mysql -g mysql -m 0644 {$crt} /etc/mysql/ssl/server-cert.pem
printf '[mysqld]\\nbind-address = 0.0.0.0\\nrequire_secure_transport = ON\\nssl_cert = /etc/mysql/ssl/server-cert.pem\\nssl_key = /etc/mysql/ssl/server-key.pem\\n' > /etc/mysql/mysql.conf.d/zz-cm-remote.cnf
systemctl restart mysql
SH;
    }

    /**
     * A certificate and key for the database's address, valid ten years. Self-signed: it is its own CA, and
     * the certificate (public) is what a site is given to verify the server by.
     *
     * @return array{0: string, 1: string} certificate PEM, private key PEM
     */
    public static function certificateFor(string $address): array
    {
        $san = filter_var($address, FILTER_VALIDATE_IP) !== false ? "IP:{$address}" : "DNS:{$address}";
        $config = tempnam(sys_get_temp_dir(), 'cmdb');
        file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=v3\nprompt=no\n[dn]\nCN=Unharbored database {$address}\n[v3]\nsubjectAltName={$san}\nbasicConstraints=CA:TRUE\nkeyUsage=digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
        try {
            // Some OpenSSL 3 builds read the curve only from the nested "ec" option, others only from the flat one.
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'ec' => ['curve_name' => 'prime256v1']]);
            $csr = $key !== false ? openssl_csr_new(['commonName' => "Unharbored database {$address}"], $key, ['config' => $config, 'digest_alg' => 'sha256']) : false;
            $cert = $csr !== false ? openssl_csr_sign($csr, null, $key, 3650, ['config' => $config, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256']) : false;
            if ($key === false || $csr === false || $cert === false || ! openssl_x509_export($cert, $certificate) || ! openssl_pkey_export($key, $keyPem)) {
                throw new RuntimeException('Could not make a certificate for the database server: '.(openssl_error_string() ?: 'openssl failed'));
            }
        } finally {
            @unlink($config);
        }

        return [$certificate, $keyPem];
    }
}
