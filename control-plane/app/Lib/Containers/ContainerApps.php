<?php

declare(strict_types=1);

namespace App\Lib\Containers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Operations\RunOperation;
use App\Lib\Auth\Projects;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\Sites;
use App\Lib\Templates\Definition;
use App\Lib\Vault\Vault;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Applications from a Dockerfile or a Docker image (docs/specs/templates.md, step 6): everything that is not a recipe
 * (AppRecipes) and not the deploy itself (Deployer::deployContainer). They are `sites` rows with the runtime `dockerfile` or
 * `image`; what is specific to them lives in the `sites.container` JSON (state()):
 *
 *   kind, dockerfile, context, container_port, memory, read_only, health, volumes   what the person chose
 *   image_ref, digest, registry {server, user}                                      an image application (the token is in the vault)
 *   host_port, pending_port                                                          the live container's port; a deploy's new one
 *   images                                                                           the live image first, then up to two previous, for rollback
 *   candidate, pending_digest                                                        an image update: seen, then confirmed
 *
 * One scope rule runs through it: a container application has no scheduler, queue workers, background processes, Reverb,
 * previews, load balancer or autoscaling pool in this version. refuse() says so in plain words wherever one is asked for.
 */
final class ContainerApps
{
    /** How many images are kept besides the live one, so a rollback has somewhere to go. */
    public const KEEP_PREVIOUS = 2;

    /** Under this much memory a build can starve the server: Docker builds with the server's own memory. About 2 GB less what the system reports. */
    public const BUILD_MEMORY_BYTES = 1825361100;

    public const DEFAULTS = ['dockerfile' => 'Dockerfile', 'context' => '.', 'container_port' => 8080, 'memory' => '512MB', 'read_only' => false, 'health' => '',
        'volumes' => [], 'image_ref' => null, 'digest' => null, 'registry' => null, 'host_port' => null, 'pending_port' => null, 'images' => [], 'candidate' => null, 'pending_digest' => null];

    public function __construct(
        private readonly Sites $sites,
        private readonly Operations $operations,
        private readonly AuditLog $audit,
        private readonly Vault $vault,
        private readonly GatewayClient $gateway,
    ) {}

    // ---- state -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function state(stdClass $site): array
    {
        $own = json_decode((string) ($site->container ?? 'null'), true);

        return array_replace(self::DEFAULTS, is_array($own) ? $own : []) + ['kind' => Runtimes::runtime($site)];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> the whole state after */
    public static function merge(int $siteId, array $changes): array
    {
        $row = DB::table('sites')->where('id', $siteId)->first();
        $state = array_replace(self::state($row), $changes);
        unset($state['kind']);
        DB::table('sites')->where('id', $siteId)->update(['container' => json_encode($state), 'updated_at' => now()]);

        return $state + ['kind' => Runtimes::runtime($row)];
    }

    /** The host ports this application holds on its server: the live one, and a deploy's new one while it runs. @return list<int> */
    public static function ports(stdClass $site): array
    {
        if (! Runtimes::isContainer($site)) {
            return [];
        }
        $s = self::state($site);

        return array_values(array_map('intval', array_filter([$s['host_port'], $s['pending_port']], fn ($p) => $p !== null)));
    }

    /** The content ids of the images this application keeps on the server. @return list<string> */
    public static function imageIds(stdClass $site): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($i) => (string) ($i['id'] ?? ''), (array) self::state($site)['images']))));
    }

    public static function registrySecret(object $site): string
    {
        return 'site:'.($site->home_node_id ?? $site->node_id).":{$site->name}:registry";
    }

    // ---- what the person may choose ---------------------------------------------

    /**
     * Checks the settings both kinds share and returns them as they are stored.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function settings(array $in, string $kind): array
    {
        $out = [];
        if ($kind === Runtimes::DOCKERFILE) {
            $out['dockerfile'] = self::relativePath((string) ($in['dockerfile'] ?? ''), 'Dockerfile', 'dockerfile', 'The Dockerfile path');
            $out['context'] = self::relativePath((string) ($in['context'] ?? ''), '.', 'context', 'The build context');
        }
        $port = (int) ($in['container_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('container_port: the port the application listens on inside the container, 1 to 65535.');
        }
        $out['container_port'] = $port;
        $health = trim((string) ($in['health'] ?? ''));
        if ($health !== '' && ! preg_match('#^/[A-Za-z0-9._~/%?=&-]{0,200}$#', $health)) {
            throw new InvalidArgumentException('health: a path that starts with /, such as /health, or blank to check that the port answers.');
        }
        $out['health'] = $health;
        $memory = trim((string) ($in['memory'] ?? '')) ?: self::DEFAULTS['memory'];
        $bytes = Definition::parseSize($memory);
        if ($bytes === null || $bytes < 64 * 1024 ** 2 || $bytes > 512 * 1024 ** 3) {
            throw new InvalidArgumentException('memory: a limit such as 512MB or 2GB, at least 64MB.');
        }
        $out['memory'] = strtoupper(str_replace(' ', '', $memory));
        $out['read_only'] = (bool) ($in['read_only'] ?? false);
        $volumes = [];
        foreach (self::lines($in['volumes'] ?? []) as $path) {
            if (! preg_match('#^/[A-Za-z0-9._/-]{0,198}$#', $path) || str_contains($path, '..') || in_array(rtrim($path, '/'), ['', '/proc', '/sys', '/dev', '/etc', '/var/run/docker.sock'], true) || str_contains($path, 'docker.sock')) {
                throw new InvalidArgumentException("volumes: {$path} is not a folder inside the container that can hold data (an absolute path such as /data).");
            }
            $volumes[] = rtrim($path, '/');
        }
        if (count($volumes) > 8 || count($volumes) !== count(array_unique($volumes))) {
            throw new InvalidArgumentException('volumes: up to eight folders, each once.');
        }
        $out['volumes'] = $volumes;

        return $out;
    }

    /** @return list<string> */
    private static function lines(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\R/', (string) $value);

        return array_values(array_filter(array_map(fn ($l) => trim((string) $l), $items ?: []), fn ($l) => $l !== ''));
    }

    private static function relativePath(string $value, string $default, string $field, string $label): string
    {
        $value = trim($value) ?: $default;
        if ($value === '.' && $field === 'context') {
            return '.';
        }
        $value = ltrim($value, '/') === $value ? $value : throw new InvalidArgumentException("{$field}: {$label} is a path inside the repository, not an absolute one.");
        $value = preg_replace('#^\./#', '', $value) ?? $value;
        if (! preg_match('#^[A-Za-z0-9._@+-]+(/[A-Za-z0-9._@+-]+)*$#', $value) || in_array('..', explode('/', $value), true)) {
            throw new InvalidArgumentException("{$field}: {$label} is a path inside the repository such as docker/Dockerfile: letters, numbers and . _ - @ + only, no ..");
        }

        return $value;
    }

    /**
     * An image reference: name:tag (or name@sha256:...), nothing Docker would read as an option or a shell word. A tag is required: an
     * application is run by the digest that tag has on the first pull.
     *
     * @return array{ref: string, digest: ?string}
     */
    public static function imageReference(string $ref): array
    {
        $ref = trim($ref);
        $digest = null;
        if (preg_match('/^(.+)@(sha256:[0-9a-f]{64})$/', $ref, $m)) {
            [$name, $digest] = [$m[1], $m[2]];
            $tagged = true;
        } else {
            $name = $ref;
            $tagged = preg_match('#:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$#', $ref) === 1 && substr_count(substr($ref, (int) strrpos($ref, '/')), ':') === 1;
        }
        $component = '[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*';
        $host = '(?:[a-zA-Z0-9.-]+(?::\d+)?/)?';
        if (strlen($ref) > 255 || ! preg_match("#^{$host}{$component}(?:/{$component})*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[0-9a-f]{64})?\$#", $ref)) {
            throw new InvalidArgumentException('image: not an image reference. Use the form name:tag, such as nginx:1.27 or ghcr.io/acme/app:2.1.');
        }
        if (! $tagged) {
            throw new InvalidArgumentException('image: give a tag, such as nginx:1.27. The application is run by the digest that tag has when it is first pulled.');
        }

        return ['ref' => $ref, 'digest' => $digest];
    }

    // ---- the wizard ----------------------------------------------------------------

    /**
     * The container wizard's answers, checked. Throws InvalidArgumentException naming the field at fault ("field: message").
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function plan(string $organizationId, array $in): array
    {
        $kind = (string) ($in['kind'] ?? '');
        if (! in_array($kind, [Runtimes::DOCKERFILE, Runtimes::IMAGE], true)) {
            throw new InvalidArgumentException('kind: from a Dockerfile or from a Docker image.');
        }
        $name = strtolower(trim((string) ($in['name'] ?? '')));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $name)) {
            throw new InvalidArgumentException('name: lowercase letters, digits and dashes, up to 30.');
        }
        if (($why = Sites::nameInUse($organizationId, $name)) !== null) {
            throw new InvalidArgumentException("name: {$why}.");
        }
        $node = (string) ($in['node'] ?? '');
        if (! in_array($node, array_column(NewApplications::servers($organizationId), 'id'), true)) {
            throw new InvalidArgumentException('node: pick one of the servers listed.');
        }
        $row = DB::table('nodes')->where('organization_id', $organizationId)->where('node_id', $node)->first();
        if ($row === null || $row->containers_ready_at === null) {
            throw new InvalidArgumentException('node: this server is not prepared for containers. Prepare it first from its Containers page.');
        }

        $domain = strtolower(trim((string) ($in['domain'] ?? '')));
        if ($domain !== '') {
            try {
                Sites::validate($name, $domain, null, 'main');
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('domain: '.$e->getMessage());
            }
            if (DB::table('sites')->where('domain', $domain)->exists() || DB::table('service_endpoints')->where('domain', $domain)->exists()) {
                throw new InvalidArgumentException("domain: {$domain} is already used by something on this install.");
            }
        }

        $settings = self::settings($in, $kind);
        $plan = ['kind' => $kind, 'name' => $name, 'node' => $node, 'domain' => $domain, 'settings' => $settings, 'warnings' => [], 'git' => null, 'repository' => null, 'branch' => null,
            'image_ref' => null, 'digest' => null, 'registry' => null, 'registry_token' => null];

        if ($kind === Runtimes::DOCKERFILE) {
            [$repository, $git] = NewApplications::resolveGit($in);
            $plan['repository'] = $repository;
            $plan['git'] = $git;
            $plan['branch'] = trim((string) ($in['branch'] ?? '')) ?: 'main';
            Sites::validate($name, $domain === '' ? 'placeholder.example.com' : $domain, $git['url'], $plan['branch']);
            $mem = (int) DB::table('node_metrics')->where('node_id', $node)->orderByDesc('collected_at')->value('mem_total');
            if ($mem > 0 && $mem < self::BUILD_MEMORY_BYTES) {
                $plan['warnings'][] = sprintf('This server has %s of memory. Docker builds on the server and uses its memory, so a large build can run out and fail, and while it runs the live application is short of memory too. 2 GB or more is comfortable.', self::human($mem));
            }
        } else {
            try {
                $ref = self::imageReference((string) ($in['image'] ?? ''));
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException($e->getMessage());
            }
            $plan['image_ref'] = $ref['ref'];
            $plan['digest'] = $ref['digest'];
            $plan['warnings'][] = 'Unharbored has not reviewed this image. It runs as a container with no added privileges and a memory limit, and is reachable only through the web server, but its code is whoever published it.';
            $user = trim((string) ($in['registry_user'] ?? ''));
            $token = (string) ($in['registry_token'] ?? '');
            if ($user !== '' || $token !== '') {
                if (! preg_match('/^[A-Za-z0-9._@+:\\\\-]{1,200}$/', $user) || $token === '' || preg_match('/[\r\n\0]/', $token) || strlen($token) > 4096) {
                    throw new InvalidArgumentException('registry_user: a registry login needs a user name and a token, each on one line.');
                }
                $plan['registry'] = ['server' => AppRecipes::registry($ref['ref']), 'user' => $user];
                $plan['registry_token'] = $token;
            }
        }
        $plan['project'] = Projects::resolve($organizationId, (int) ($in['project'] ?? 0) ?: null);
        $env = [];
        foreach ((array) ($in['env'] ?? []) as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
                throw new InvalidArgumentException("env: {$key} is not a variable name.");
            }
            if ((string) $value !== '') {
                $env[(string) $key] = (string) $value;
            }
        }
        $plan['env'] = $env;
        $plan['steps'] = [...($domain === '' ? ['address'] : []), 'deploy', 'https'];

        return $plan;
    }

    /**
     * Records the application and queues what makes it: the `app_create` operation with the steps of this plan (address when no
     * domain was given, deploy, https).
     *
     * @param  array<string, mixed>  $plan  from plan()
     * @return array{site: int, operation: int}
     */
    public function start(string $organizationId, array $plan, string $actor): array
    {
        $domain = $plan['domain'] !== '' ? $plan['domain'] : "{$plan['name']}.pending.internal";
        $git = $plan['git'];
        $site = $this->sites->create($organizationId, $plan['node'], $plan['name'], $domain, $git['url'] ?? null, $plan['branch'] ?? 'main', null, $plan['project']);
        $state = $plan['settings'] + ['image_ref' => $plan['image_ref'], 'digest' => $plan['digest'], 'registry' => $plan['registry']];
        $site = $this->sites->makeContainer($site, $plan['kind'], $state);
        if ($git !== null) {
            $site = $this->sites->setGitSource($site, $git['source'], true);
        }
        DB::table('sites')->where('id', $site->id)->update(['compute' => 'single', 'provider' => DB::table('nodes')->where('node_id', $plan['node'])->value('provider') ?: 'custom', 'updated_at' => now()]);
        if ($plan['registry_token'] !== null) {
            $this->vault->put($organizationId, self::registrySecret($site), (string) $plan['registry_token']);
        }
        if ($plan['env'] !== []) {
            $this->sites->setEnvValues($site, $plan['env']);
        }
        $meta = ['steps' => $plan['steps'], 'done' => [], 'children' => [], 'kind' => $plan['kind']];
        $id = $this->operations->create($organizationId, 'app_create', "Create {$plan['name']} (".($plan['kind'] === Runtimes::IMAGE ? 'Docker image' : 'Dockerfile').')', 'console', $actor, $plan['steps'], $site->id, meta: $meta);
        RunOperation::dispatch($id);
        $this->audit->record($organizationId, Actor::user($actor), 'site.application_created', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['kind' => $plan['kind'], 'image' => $plan['image_ref'], 'repository' => $plan['repository'], 'private_registry' => $plan['registry'] !== null, 'domain' => $plan['domain'] ?: null]);

        return ['site' => (int) $site->id, 'operation' => $id];
    }

    // ---- after creation --------------------------------------------------------------

    /**
     * Changes the settings of an existing container application. They take effect on the next deploy, which is marked as waiting.
     *
     * @param  array<string, mixed>  $in
     */
    public function updateSettings(stdClass $site, array $in, string $actor): void
    {
        $state = self::state($site);
        $changes = self::settings($in + ['dockerfile' => $state['dockerfile'], 'context' => $state['context']], $state['kind']);
        self::merge((int) $site->id, $changes);
        $this->sites->markPending($site);
        $this->audit->record($site->organization_id, Actor::user($actor), 'site.container_settings_changed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['fields' => array_keys(array_filter($changes, fn ($v, $k) => ($state[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH))]);
    }

    /** Sets or clears the registry login of an image application. The token goes to the vault and nowhere else. */
    public function setRegistry(stdClass $site, ?string $user, #[\SensitiveParameter] ?string $token, string $actor): void
    {
        $state = self::state($site);
        if ($state['kind'] !== Runtimes::IMAGE) {
            throw new InvalidArgumentException('Only an application from a Docker image has a registry login.');
        }
        if ($user === null || trim($user) === '') {
            self::merge((int) $site->id, ['registry' => null]);
            $this->vault->forget($site->organization_id, self::registrySecret($site));
            $this->audit->record($site->organization_id, Actor::user($actor), 'site.registry_login_removed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}");

            return;
        }
        if (! preg_match('/^[A-Za-z0-9._@+:\\\\-]{1,200}$/', $user)) {
            throw new InvalidArgumentException('registry_user: the user name of the registry login.');
        }
        if ($token === null || $token === '') {
            if (($state['registry'] ?? null) === null) {
                throw new InvalidArgumentException('registry_token: a registry login needs a token.');
            }
        } elseif (preg_match('/[\r\n\0]/', $token) || strlen($token) > 4096) {
            throw new InvalidArgumentException('registry_token: the token is one line.');
        } else {
            $this->vault->put($site->organization_id, self::registrySecret($site), $token);
        }
        self::merge((int) $site->id, ['registry' => ['server' => AppRecipes::registry((string) $state['image_ref']), 'user' => $user]]);
        $this->audit->record($site->organization_id, Actor::user($actor), 'site.registry_login_set', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['server' => AppRecipes::registry((string) $state['image_ref']), 'user' => $user]);
    }

    /** @return array{server: string, user: string, token: string}|null */
    public static function registry(stdClass $site): ?array
    {
        $r = self::state($site)['registry'];
        if (! is_array($r)) {
            return null;
        }
        $token = app(Vault::class)->get($site->organization_id, self::registrySecret($site));
        if ($token === null || $token === '') {
            throw new RuntimeException('This application has a registry login but its token is missing from the vault. Set the login again.');
        }

        return ['server' => (string) $r['server'], 'user' => (string) $r['user'], 'token' => (string) $token];
    }

    /**
     * Pull $ref (with the application's registry login, if it has one) and report the digest and image id Docker now holds for it. The
     * login lives in a per-application DOCKER_CONFIG that is removed whether or not the pull worked.
     *
     * @param  Closure(array<string, mixed>, ?int): void  $recipe  applies a recipe on the application's server
     * @param  Closure(string, list<string>): string  $exec  runs one command there and returns what it printed
     * @return array{digest: string, id: string}
     *
     * @throws RuntimeException with a message for the person when the pull fails or Docker reports no digest
     */
    public static function pull(stdClass $site, string $ref, Closure $recipe, Closure $exec): array
    {
        $cred = self::registry($site);
        try {
            if ($cred !== null) {
                $recipe(AppRecipes::login($site->name, $cred['server'], $cred['user'], $cred['token']), 120);
            }
            $recipe(AppRecipes::pull($site->name, $ref, $cred !== null), 1200);
        } catch (Throwable $e) {
            throw new RuntimeException("Could not pull {$ref}: ".self::scrub($e->getMessage(), $cred['token'] ?? null), previous: $e);
        } finally {
            if ($cred !== null) {
                try {
                    $recipe(AppRecipes::forget($site->name), 60);
                } catch (Throwable) {
                }
            }
        }
        $digest = AppRecipes::parseDigest($exec('docker', AppRecipes::digestArguments($ref)), $ref);
        if ($digest === null) {
            throw new RuntimeException("Docker pulled {$ref} but reports no digest for it, so it cannot be pinned. An image that was only ever built on this server has none; push it to a registry first.");
        }

        return ['digest' => $digest, 'id' => trim($exec('docker', AppRecipes::idArguments($ref)))];
    }

    /** An error message with the token taken out, should a tool have echoed it. */
    private static function scrub(string $message, ?string $token): string
    {
        return $token === null || $token === '' ? $message : str_replace($token, '***', $message);
    }

    /**
     * Image applications only: pull the tag again and report its digest beside the pinned one, without changing anything. The person
     * sees both and confirms with update(). The pulled image stays on the server, so confirming starts it without pulling again.
     *
     * @return array{ref: string, old: string, new: string, changed: bool}
     *
     * @throws InvalidArgumentException when it is not an image application or is busy
     * @throws RuntimeException when the tag cannot be resolved
     */
    public function checkUpdate(stdClass $site, string $actor): array
    {
        $state = self::state($site);
        if ($state['kind'] !== Runtimes::IMAGE || ! is_string($state['digest'])) {
            throw new InvalidArgumentException('Only an application from a Docker image that has been deployed can be updated this way.');
        }
        $this->assertIdle($site);
        $node = $site->node_id;
        $recipe = function (array $spec, ?int $timeout) use ($site, $node): void {
            $r = $this->gateway->run($site->organization_id, $node, 'apply_recipe', ['spec' => $spec], timeoutSeconds: $timeout, waitSeconds: ($timeout ?? 60) + 30, reason: "Check {$site->name} for a newer image");
            if (($r['status'] ?? null) !== 'succeeded') {
                throw new RuntimeException((string) ($r['error'] ?? 'the command failed'));
            }
        };
        $exec = function (string $name, array $args) use ($site, $node): string {
            $r = $this->gateway->run($site->organization_id, $node, 'exec', ['name' => $name, 'args' => $args], reason: "Check {$site->name} for a newer image");
            if (($r['status'] ?? null) !== 'succeeded') {
                throw new RuntimeException((string) ($r['error'] ?? 'the command failed'));
            }

            return (string) ($r['detail']['output'] ?? '');
        };
        $found = self::pull($site, (string) $state['image_ref'], $recipe, $exec);
        $candidate = ['digest' => $found['digest'], 'id' => $found['id'], 'checked_at' => now()->toIso8601String()];
        self::merge((int) $site->id, ['candidate' => $candidate]);
        $this->audit->record($site->organization_id, Actor::user($actor), 'site.image_update_checked', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['image' => $state['image_ref'], 'old' => $state['digest'], 'new' => $found['digest']]);

        return ['ref' => (string) $state['image_ref'], 'old' => (string) $state['digest'], 'new' => $found['digest'], 'changed' => $found['digest'] !== $state['digest']];
    }

    /**
     * Run the digest checkUpdate() reported. $confirm must be that digest, exactly: the person confirms what they were shown, and a
     * tag that moved again since is not run. Queues an ordinary deploy that pins the new digest when it is healthy.
     *
     * @throws InvalidArgumentException
     */
    public function update(stdClass $site, string $confirm, string $actor, bool $dispatch = true): int
    {
        $state = self::state($site);
        $candidate = $state['candidate'];
        if ($state['kind'] !== Runtimes::IMAGE || ! is_array($candidate)) {
            throw new InvalidArgumentException('Check for an update first; it shows the new digest to confirm.');
        }
        if ($confirm !== $candidate['digest']) {
            throw new InvalidArgumentException('That is not the digest that was shown. Check for an update again.');
        }
        if ($candidate['digest'] === $state['digest']) {
            throw new InvalidArgumentException('The tag still points at the digest this application runs. There is nothing to update.');
        }
        $this->assertIdle($site);
        self::merge((int) $site->id, ['pending_digest' => $candidate['digest']]);
        $this->audit->record($site->organization_id, Actor::user($actor), 'site.image_update_confirmed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['image' => $state['image_ref'], 'from' => $state['digest'], 'to' => $candidate['digest']]);
        $runner = app(OperationRunner::class);

        return $dispatch ? $runner->queueDeploy((int) $site->id, 'console', $actor) : $runner->recordDeploy((int) $site->id, 'console', $actor);
    }

    private function assertIdle(stdClass $site): void
    {
        if (DB::table('operations')->where('site_id', $site->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new InvalidArgumentException("Another operation is running for {$site->name}. Wait for it to finish.");
        }
    }

    /** The stages of a deploy's operation: an image application has nothing to fetch or build, only a pull. @return list<string> */
    public static function deployStages(stdClass $site): array
    {
        return match (Runtimes::runtime($site)) {
            Runtimes::IMAGE => ['setup', 'pull', 'start', 'health', 'switch', 'prune'],
            Runtimes::DOCKERFILE => ['setup', 'fetch', 'build', 'start', 'health', 'switch', 'prune'],
            default => \App\Lib\Sites\Deployer::STAGES,
        };
    }

    // ---- the scope rule ---------------------------------------------------------------

    /** What a container application cannot have, by the word the person used. */
    private const REFUSED = [
        'scheduler' => 'a scheduler', 'queue workers' => 'queue workers', 'background processes' => 'background processes', 'reverb' => 'Reverb',
        'previews' => 'previews', 'load balancer' => 'a load balancer', 'autoscaling' => 'an autoscaling pool', 'servers' => 'a second server', 'server script' => 'a server script',
        'runtime' => 'a different type',
    ];

    /** @throws InvalidArgumentException when $site is a container application */
    public static function refuse(object $site, string $feature): void
    {
        if (Runtimes::isContainer($site)) {
            throw new InvalidArgumentException('An application from a '.(Runtimes::runtime($site) === Runtimes::IMAGE ? 'Docker image' : 'Dockerfile').' cannot have '.(self::REFUSED[$feature] ?? $feature)
                .' in this version. It runs as one container on one server; run that work inside the container, or as a separate application.');
        }
    }

    // ---- environment --------------------------------------------------------------------

    /**
     * The application's variables (its own, then the ones Unharbored injects, as on every runtime), as `docker run --env-file`
     * reads them: one KEY=value per line, literal. Surrounding quotes in a person's .env style are taken off, as a shell or dotenv
     * would; a value that needs a line break cannot be given to Docker this way and is refused.
     *
     * @return array<string, string>
     */
    public static function environment(string $env): array
    {
        $out = [];
        foreach (preg_split('/\R/', $env) ?: [] as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                continue;
            }
            $value = rtrim($m[2]);
            if (preg_match('/^"(.*)"$/', $value, $q) || preg_match("/^'(.*)'\$/", $value, $q)) {
                $value = $q[1];
            } elseif (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                throw new InvalidArgumentException("The value of {$m[1]} starts a quote that does not end on the same line; a value with a line break cannot be given to Docker.");
            }
            $out[$m[1]] = $value;
        }

        return $out;
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : round($bytes / 1024 ** 2).' MB';
    }
}
