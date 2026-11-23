<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Vault\Vault;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * A copy of a site running one branch, on the preview server, at
 * <branch>.<site>.<preview domain>: made on demand, kept until deleted or
 * idle too long.
 *
 * A preview is a site in its own right (deploys, logs, artisan and the API
 * work on it unchanged), with its parent's repository and .env, made safe:
 * its own SQLite database, its own app key, cache, sessions and queue in
 * that database, files on its own disk, mail written to the log, and none
 * of the keys the parent marks as production only. It never touches the
 * parent's database, Valkey, bucket or mail.
 */
final class Previews
{
    public const DEFAULT_IDLE_DAYS = 7;

    /**
     * Where a preview's data comes from. fresh: an empty database of its
     * own, of the parent's kind (on the parent's cluster), migrated and
     * seeded if the parent names a seeder. clone: the same, filled with a
     * copy of the parent's data instead of seeded. shared: the parent's own
     * database; migrations and seeders never run, so only for branches that
     * do not change the schema. sqlite: an empty SQLite file on the preview
     * server, whatever the parent uses.
     *
     * A parent on SQLite, or on a database no cluster here holds, has its
     * fresh previews on SQLite: there is nowhere to make another of its kind.
     */
    public const DATABASES = ['fresh', 'clone', 'shared', 'sqlite'];

    /** What the override placeholders stand for; see overrides(). */
    public const PLACEHOLDERS = ['{domain}', '{url}', '{branch}', '{slug}', '{site}'];

    /** Keys the preview's own .env sets, whatever the parent has. */
    private const REPLACED = ['APP_ENV', 'APP_KEY', 'APP_URL', 'DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'FILESYSTEM_DISK', 'MAIL_MAILER'];

    /** Keys that would reach the parent's services: never copied. */
    private const DROPPED = '/^(DB_(HOST|PORT|USERNAME|PASSWORD|SSLMODE|URL)|REDIS_\w+|CACHE_PREFIX|AWS_\w+|MAIL_(HOST|PORT|USERNAME|PASSWORD|SCHEME|ENCRYPTION)|POSTMARK_\w+|RESEND_\w+|MAILGUN_\w+|SES_\w+)$/';

    public function __construct(
        private readonly Sites $sites,
        private readonly GatewayClient $gateway,
        private readonly AuditLog $audit,
        private readonly Vault $vault,
    ) {}

    /** The vault name of a parent's preview overrides: they often hold secrets. */
    public static function overridesSecret(stdClass $parent): string
    {
        return "site:{$parent->id}:preview_env";
    }

    /** The parent's overrides, as written: KEY=value lines, placeholders and all. */
    public function overrides(stdClass $parent): string
    {
        return (string) $this->vault->get($parent->organization_id, self::overridesSecret($parent));
    }

    /**
     * Saves the overrides every new preview of the site gets, after
     * everything else in its .env. {domain}, {url}, {branch}, {slug} and
     * {site} are filled in per preview, so an OAuth redirect can be
     * https://{domain}/auth/callback.
     */
    public function setOverrides(stdClass $parent, string $overrides): void
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($overrides)) as $n => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $line)) {
                throw new InvalidArgumentException('Line '.($n + 1).' is not KEY=value.');
            }
            $lines[] = $line;
        }
        if ($lines === []) {
            $this->vault->forget($parent->organization_id, self::overridesSecret($parent));
        } else {
            $this->vault->put($parent->organization_id, self::overridesSecret($parent), implode("\n", $lines)."\n");
        }
        $this->audit->record($parent->organization_id, Actor::system('control-plane'), 'site.preview_overrides_changed', resourceType: 'site',
            resourceId: "{$parent->node_id}/{$parent->name}", metadata: ['keys' => array_map(fn ($l) => strstr($l, '=', true), $lines)]);
    }

    /** What a parent's previews get unless asked otherwise. */
    public static function setDefaultDatabase(stdClass $parent, string $database): void
    {
        self::checkDatabase($parent, $database);
        DB::table('sites')->where('id', $parent->id)->update(['preview_database' => $database, 'updated_at' => now()]);
    }

    private static function checkDatabase(stdClass $parent, string $database): void
    {
        if (! in_array($database, self::DATABASES, true)) {
            throw new InvalidArgumentException('A preview\'s database is fresh, clone, shared or sqlite.');
        }
        if (in_array($database, ['clone', 'shared'], true) && $parent->database_cluster_id === null && ! self::onSqlite($parent)) {
            throw new InvalidArgumentException("{$parent->name}'s database is not on a managed cluster here, so previews cannot ".($database === 'clone' ? 'copy' : 'reach').' it.');
        }
        if ($database === 'shared' && self::onSqlite($parent)) {
            throw new InvalidArgumentException("{$parent->name} uses SQLite on its own server, which a preview cannot share. Use clone for a copy of it.");
        }
    }

    /**
     * Whether a preview made this way is on SQLite: asked for, or its parent
     * is, or it would be a fresh one of a database with no cluster here.
     */
    public static function onSqliteFor(stdClass $parent, string $database): bool
    {
        return match ($database) {
            'sqlite' => true,
            'shared' => false,
            default => $parent->database_cluster_id === null || self::onSqlite($parent),
        };
    }

    /** Whether the parent's .env says SQLite. */
    private static function onSqlite(stdClass $parent): bool
    {
        $env = app(Sites::class)->env($parent);

        return ! preg_match('/^DB_CONNECTION=["\']?(pgsql|mysql)/m', $env);
    }

    /**
     * The server previews go to, or null if there is none yet. A preview lives
     * on its application's provider, so one on that provider is preferred; a
     * server the user provided (custom) will do for any, as the first preview
     * server always has.
     */
    public static function server(string $organizationId, ?string $provider = null): ?string
    {
        return DB::table('nodes')->where('organization_id', $organizationId)->where('role', 'preview')
            ->when($provider !== null, fn ($q) => $q->whereIn('provider', [$provider, 'custom'])->orderByRaw('provider = ? desc', [$provider]))
            ->orderBy('node_id')->value('node_id');
    }

    /**
     * Records the preview; the first deploy builds it. $idleDays null keeps
     * it until deleted; $database null takes the parent's default.
     */
    public function create(stdClass $parent, string $branch, bool $followPushes, ?int $idleDays = self::DEFAULT_IDLE_DAYS, ?string $database = null): stdClass
    {
        if ($parent->parent_site_id !== null) {
            throw new InvalidArgumentException('A preview cannot have previews of its own.');
        }
        \App\Lib\Containers\ContainerApps::refuse($parent, 'previews');
        $node = self::server($parent->organization_id, (string) ($parent->provider ?? 'digitalocean'))
            ?? throw new InvalidArgumentException(self::server($parent->organization_id) === null ? 'There is no preview server yet.' : "There is no preview server on {$parent->provider} yet: a preview lives on its application's provider.");
        if ((string) config('control_plane.preview_domain') === '') {
            throw new InvalidArgumentException('Set PREVIEW_DOMAIN in the control plane\'s .env (previews answer at <branch>.<app>.<that domain>).');
        }
        $branch = trim($branch);
        if ($idleDays !== null && ($idleDays < 1 || $idleDays > 90)) {
            throw new InvalidArgumentException('Keep a preview between 1 and 90 idle days, or until deleted.');
        }
        $database ??= (string) ($parent->preview_database ?? 'fresh');
        self::checkDatabase($parent, $database);
        $slug = self::slug($branch);
        $name = $this->freeName($node, substr($parent->name, 0, 18).'-'.substr($slug, 0, 21));
        $domain = substr($name, strlen(substr($parent->name, 0, 18)) + 1).".{$parent->name}.".config('control_plane.preview_domain');

        $site = $this->sites->create($parent->organization_id, $node, $name, $domain, $parent->repository, $branch, projectId: (int) $parent->project_id);
        DB::table('sites')->where('id', $site->id)->update(['parent_site_id' => $parent->id, 'preview_idle_days' => $idleDays,
            'push_to_deploy' => $followPushes, 'queue_workers' => min(1, (int) $parent->queue_workers), 'reverb' => false, 'preview_database' => $database,
            // Shared: the parent's cluster, so its firewall admits the preview server.
            'database_cluster_id' => $database === 'shared' ? $parent->database_cluster_id : null, 'updated_at' => now()]);
        $site = $this->sites->find($node, $name);
        $this->sites->copyGitAccess($parent, $site);
        $site = $this->sites->find($node, $name);
        $this->sites->setEnv($site, $this->env($parent, $site), "preview of {$parent->name} at {$branch}");
        // A preview whose database comes from a cluster starts with none: the
        // default SQLite values would otherwise sit in its environment until the
        // cluster replaces them. A SQLite preview keeps its own, written above.
        if (! self::onSqliteFor($parent, $database)) {
            $this->sites->setInjected($site, ['DB_CONNECTION' => null, 'DB_DATABASE' => null], 'Preview');
        }

        $this->audit->record($parent->organization_id, Actor::system('control-plane'), 'site.preview_created', resourceType: 'site',
            resourceId: "{$node}/{$name}", metadata: ['parent' => $parent->name, 'branch' => $branch, 'follow' => $followPushes, 'idle_days' => $idleDays, 'database' => $database]);

        return $this->sites->find($node, $name);
    }

    /**
     * Pull requests into $base get a preview of their branch; null stops
     * new ones (those open keep theirs until they close).
     */
    public static function setPullRequestBase(stdClass $parent, ?string $base): void
    {
        $base = $base !== null ? trim($base) : null;
        if ($base !== null && ($base === '' || ! preg_match('#^[A-Za-z0-9._/-]+$#', $base))) {
            throw new InvalidArgumentException('A branch name, like main.');
        }
        if ($base !== null && $parent->parent_site_id !== null) {
            throw new InvalidArgumentException('A preview cannot have previews of its own.');
        }
        DB::table('sites')->where('id', $parent->id)->update(['pr_previews_base' => $base, 'updated_at' => now()]);
    }

    /**
     * What a pull_request delivery asks for. Opened (or reopened, or
     * retargeted onto the base) makes a preview that follows the branch's
     * pushes, or takes over a branch preview already running; closed
     * deletes the one it made and lets a taken-over one be. Pushes to the
     * branch redeploy it through the push hook, like any preview that
     * follows pushes, so synchronize needs nothing here.
     *
     * A pull request from a fork is never previewed: its code is someone
     * else's, and a preview runs with the site's .env.
     *
     * @param  array<string, mixed>  $payload
     * @return array{action: string, previews: list<string>}
     */
    public function pullRequest(string $organizationId, array $payload): array
    {
        $action = (string) ($payload['action'] ?? '');
        $pr = $payload['pull_request'] ?? [];
        $number = (int) ($pr['number'] ?? $payload['number'] ?? 0);
        $repository = strtolower((string) ($pr['base']['repo']['full_name'] ?? $payload['repository']['full_name'] ?? ''));
        $head = (string) ($pr['head']['ref'] ?? '');
        $base = (string) ($pr['base']['ref'] ?? '');
        if ($number < 1 || $head === '' || $repository === '') {
            return ['action' => 'ignored: not a pull request', 'previews' => []];
        }
        if (strtolower((string) ($pr['head']['repo']['full_name'] ?? '')) !== $repository) {
            return ['action' => 'ignored: from a fork', 'previews' => []];
        }

        $runner = app(\App\Lib\Operations\OperationRunner::class);
        $actor = (string) ($payload['sender']['login'] ?? 'github');
        $parents = DB::table('sites')->where('organization_id', $organizationId)->whereNull('parent_site_id')->get()
            ->filter(function ($site) use ($repository) {
                if ($site->repository === null) {
                    return false; // an image application has no repository
                }
                try {
                    return strtolower(\App\Lib\GitHub\GitHubApp::fullName($site->repository)) === $repository;
                } catch (Throwable) {
                    return false;
                }
            });

        $done = [];
        foreach ($parents as $parent) {
            $existing = DB::table('sites')->where('parent_site_id', $parent->id)->where('pr_number', $number)->first();
            $wanted = in_array($action, ['opened', 'reopened', 'edited', 'ready_for_review'], true) && $parent->pr_previews_base === $base;

            if ($wanted && $existing === null) {
                // A branch preview of it already running is taken over, not doubled.
                $branchPreview = DB::table('sites')->where('parent_site_id', $parent->id)->where('branch', $head)->whereNull('pr_number')->orderBy('id')->first();
                if ($branchPreview !== null) {
                    DB::table('sites')->where('id', $branchPreview->id)->update(['pr_number' => $number, 'push_to_deploy' => true, 'updated_at' => now()]);
                    $done[] = "{$branchPreview->name} (now PR #{$number})";

                    continue;
                }
                try {
                    $id = $runner->queuePreviewCreate((int) $parent->id, $head, true, null, 'pull_request', $actor);
                } catch (InvalidArgumentException $e) {
                    $done[] = "{$parent->name}: no preview, {$e->getMessage()}";

                    continue;
                }
                $siteId = (int) DB::table('operations')->where('id', $id)->value('site_id');
                DB::table('sites')->where('id', $siteId)->update(['pr_number' => $number, 'pr_created' => true, 'updated_at' => now()]);
                $done[] = (string) DB::table('sites')->where('id', $siteId)->value('name');
            } elseif (! $wanted && $existing !== null && ($action === 'closed' || ($action === 'edited' && $parent->pr_previews_base !== $base))) {
                if ((bool) $existing->pr_created) {
                    $runner->queuePreviewDestroy((int) $existing->id, 'pull_request', $actor);
                    $done[] = "{$existing->name} (deleted)";
                } else {
                    DB::table('sites')->where('id', $existing->id)->update(['pr_number' => null, 'updated_at' => now()]);
                    $done[] = "{$existing->name} (kept: it was a branch preview first)";
                }
            }
        }

        return ['action' => $action, 'previews' => $done];
    }

    /** A branch as a DNS label: feature/New_Checkout becomes feature-new-checkout. */
    public static function slug(string $branch): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($branch)), '-');

        return $slug !== '' ? $slug : 'branch';
    }

    /**
     * The parent's .env, with everything that would reach its services
     * replaced or left out, then the parent's overrides. A shared preview
     * keeps the parent's database keys; a cloned one on a cluster gets its
     * own when the cluster is attached, before the first deploy.
     */
    public function env(stdClass $parent, stdClass $preview): string
    {
        $database = $preview->preview_database ?? 'fresh';
        $sqlite = self::onSqliteFor($parent, $database);
        $exclude = json_decode((string) ($parent->preview_exclude ?? '[]'), true) ?: [];
        $kept = [];
        foreach (preg_split('/\R/', $this->sites->env($parent)) as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $line, $m)) {
                $databaseKey = str_starts_with($m[1], 'DB_') || $m[1] === 'MYSQL_ATTR_SSL_CA';
                if ($database === 'shared' && $databaseKey) {
                    $kept[] = $line;

                    continue;
                }
                if ($databaseKey || in_array($m[1], self::REPLACED, true) || preg_match(self::DROPPED, $m[1]) || in_array($m[1], $exclude, true)) {
                    continue;
                }
            }
            $kept[] = $line;
        }
        $root = '/home/'.\App\Lib\Servers\ServerStack::DEPLOY_USER."/{$preview->name}/shared";
        $about = ['fresh' => 'its own empty database', 'clone' => 'a copy of its database', 'shared' => "{$parent->name}'s own database (no migrations)", 'sqlite' => 'its own SQLite database'][$database];

        $env = rtrim(implode("\n", $kept))."\n\n# Preview of {$parent->name}: {$about}, its own cache, files and app key; mail goes to the log.\n"
            .'APP_ENV=preview'."\n"
            .'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n"
            ."APP_URL=https://{$preview->domain}\n"
            .($sqlite ? "DB_CONNECTION=sqlite\nDB_DATABASE={$root}/database.sqlite\n" : '')
            ."CACHE_STORE=database\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database\n"
            ."FILESYSTEM_DISK=local\nMAIL_MAILER=log\n";

        return $this->applyOverrides($env, $parent, $preview);
    }

    /** The parent's overrides, placeholders filled in, replacing any key they name. */
    private function applyOverrides(string $env, stdClass $parent, stdClass $preview): string
    {
        $overrides = trim($this->overrides($parent));
        if ($overrides === '') {
            return $env;
        }
        $values = ["https://{$preview->domain}", $preview->domain, (string) $preview->branch, self::slug((string) $preview->branch), $parent->name];
        $overrides = str_replace(['{url}', '{domain}', '{branch}', '{slug}', '{site}'], $values, $overrides);
        $keys = array_map(fn ($l) => strstr($l, '=', true), preg_split('/\R/', $overrides));
        $lines = array_filter(preg_split('/\R/', rtrim($env)), fn ($l) => ! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $l, $m) || ! in_array($m[1], $keys, true));

        return implode("\n", $lines)."\n\n# {$parent->name}'s preview overrides\n{$overrides}\n";
    }

    /**
     * Removes the preview from its server (vhost, workers, schedule, files,
     * certificate) and every record and secret of it here.
     *
     * @return list<string>
     */
    public function destroy(stdClass $preview): array
    {
        if ($preview->parent_site_id === null) {
            throw new InvalidArgumentException("{$preview->name} is not a preview; sites are not deleted this way.");
        }
        $done = [];
        try {
            $result = $this->gateway->run($preview->organization_id, $preview->node_id, 'apply_recipe', ['spec' => $this->sites->removalRecipe($preview)],
                timeoutSeconds: 300, reason: "Removing preview {$preview->name}");
            if (($result['status'] ?? null) !== 'succeeded') {
                throw new RuntimeException((string) ($result['error'] ?? 'the removal did not run'));
            }
            $done[] = 'nginx site, workers, schedule and files removed';
            $this->gateway->run($preview->organization_id, $preview->node_id, 'exec',
                ['name' => 'sh', 'args' => ['-c', 'certbot delete --non-interactive --cert-name '.escapeshellarg($preview->domain).' >/dev/null 2>&1; true']],
                reason: "Removing preview {$preview->name}'s certificate");
            $done[] = 'certificate removed';
            $clusters = app(\App\Lib\Cloud\DatabaseClusters::class);
            // Its own database on the cluster, never a shared one: that is the parent's.
            if (($preview->preview_database ?? 'fresh') !== 'shared') {
                array_push($done, ...$clusters->dropDatabase($preview));
            }
            array_push($done, ...$clusters->releaseServer($preview, $preview->node_id));
        } catch (Throwable $e) {
            throw new RuntimeException("Could not clean {$preview->name} off ".(strstr($preview->node_id, '.', true) ?: $preview->node_id).": {$e->getMessage()}. Nothing was forgotten; try again.", previous: $e);
        }
        $this->sites->forget($preview);
        $done[] = 'its records, .env, versions and deploy key deleted';

        return $done;
    }

    /**
     * Previews idle longer than they keep for: no deploy within their days.
     *
     * @return list<stdClass>
     */
    public function idle(): array
    {
        $out = [];
        foreach (DB::table('sites')->whereNotNull('parent_site_id')->whereNotNull('preview_idle_days')->get() as $p) {
            $last = DB::table('operations')->where('site_id', $p->id)->whereIn('kind', ['deploy', 'preview_create'])->max('created_at') ?? $p->created_at;
            if (\Illuminate\Support\Carbon::parse($last)->addDays((int) $p->preview_idle_days)->isPast()) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private function freeName(string $node, string $base): string
    {
        $base = trim($base, '-');
        $name = $base;
        for ($i = 2; DB::table('sites')->where('node_id', $node)->where('name', $name)->exists(); $i++) {
            $name = substr($base, 0, 37)."-{$i}";
        }

        return $name;
    }
}
