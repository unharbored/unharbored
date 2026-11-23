<?php

namespace App\Lib\Auth;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Projects: named folders of applications inside an organisation
 * (docs/specs/projects.md). In v1 a grouping and a filter, not a security
 * boundary. Reads of "which applications" go through sites(), so a later
 * project_members table narrows them in one place (visible()).
 */
final class Projects
{
    public const SESSION_KEY = 'project_id';

    public const DEFAULT_NAME = 'Default';

    /** The project filter chosen in the switcher, or null for all projects. Checked against the current organisation on every call, never trusted from the session. */
    public static function current(): ?int
    {
        $chosen = app()->bound('session.store') ? (int) session(self::SESSION_KEY, 0) : 0;
        if ($chosen === 0) {
            return null;
        }
        $visible = self::visible();

        return DB::table('projects')->where('organization_id', Org::id())->where('id', $chosen)
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))->exists() ? $chosen : null;
    }

    /** Project ids the person may see, or null for all (always null in v1). @return list<int>|null */
    public static function visible(): ?array
    {
        return null;
    }

    /** The organisation's applications (and previews), narrowed by the current project filter. */
    public static function sites(): Builder
    {
        $visible = self::visible();

        return DB::table('sites')->where('sites.organization_id', Org::id())
            ->when(self::current() !== null, fn ($q) => $q->where('sites.project_id', self::current()))
            ->when($visible !== null, fn ($q) => $q->whereIn('sites.project_id', $visible));
    }

    /**
     * The single door for shared things (server and cluster cards, blockers
     * that name applications): the organisation's applications the person may
     * see, whatever the switcher says. Narrowing later filters here, in one place.
     */
    public static function visibleSites(): Builder
    {
        $visible = self::visible();

        return DB::table('sites')->where('sites.organization_id', Org::id())
            ->when($visible !== null, fn ($q) => $q->whereIn('sites.project_id', $visible));
    }

    /** Sets (or, with null, clears) the switcher's choice. A project of another organisation clears it. */
    public static function choose(?int $id): void
    {
        $ok = $id !== null && $id > 0 && DB::table('projects')->where('organization_id', Org::id())->where('id', $id)->exists();
        $ok ? session([self::SESSION_KEY => $id]) : session()->forget(self::SESSION_KEY);
    }

    /** Sets the filter from a project slug of the current organisation; an empty one clears it, an unknown slug changes nothing. */
    public static function chooseSlug(string $slug): void
    {
        if ($slug === '') {
            self::choose(null);

            return;
        }
        $id = DB::table('projects')->where('organization_id', Org::id())->where('slug', $slug)->value('id');
        if ($id !== null) {
            self::choose((int) $id);
        }
    }

    /** The organisation's Default project, made if it has none yet. */
    public static function ensureDefault(string $organizationId): int
    {
        $id = DB::table('projects')->where('organization_id', $organizationId)->where('slug', 'default')->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        DB::table('projects')->insertOrIgnore(['organization_id' => $organizationId, 'name' => self::DEFAULT_NAME, 'slug' => 'default', 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('projects')->where('organization_id', $organizationId)->where('slug', 'default')->value('id');
    }

    /** The project id an application is made in: the one asked for if it is this organisation's, else Default. */
    public static function resolve(string $organizationId, ?int $projectId): int
    {
        if ($projectId === null || $projectId === 0) {
            return self::ensureDefault($organizationId);
        }
        if (! DB::table('projects')->where('organization_id', $organizationId)->where('id', $projectId)->exists()) {
            throw new InvalidArgumentException('project: choose one of this team\'s projects.');
        }

        return $projectId;
    }

    /** @return list<array{id: int, name: string, slug: string, applications: int}> Applications counts production rows, not previews. */
    public static function listing(string $organizationId): array
    {
        self::ensureDefault($organizationId);
        $counts = DB::table('sites')->where('organization_id', $organizationId)->whereNull('parent_site_id')->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');

        return DB::table('projects')->where('organization_id', $organizationId)->orderByRaw("slug = 'default' desc")->orderBy('name')->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name, 'slug' => $p->slug, 'applications' => (int) ($counts[$p->id] ?? 0)])->all();
    }

    public static function create(string $organizationId, string $name, Actor $actor): int
    {
        $name = self::cleanName($organizationId, $name);
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        for ($i = 2; DB::table('projects')->where('organization_id', $organizationId)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        $id = (int) DB::table('projects')->insertGetId(['organization_id' => $organizationId, 'name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        self::audit($organizationId, $actor, 'project.created', $id, ['name' => $name, 'slug' => $slug]);

        return $id;
    }

    /** Changes the name only, never the slug. The Default project keeps its name. */
    public static function rename(string $organizationId, int $id, string $name, Actor $actor): void
    {
        $project = self::find($organizationId, $id);
        if ($project->slug === 'default') {
            throw new InvalidArgumentException('name: the Default project keeps its name.');
        }
        $name = self::cleanName($organizationId, $name, $id);
        DB::table('projects')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
        self::audit($organizationId, $actor, 'project.renamed', $id, ['from' => $project->name, 'to' => $name]);
    }

    public static function delete(string $organizationId, int $id, Actor $actor): void
    {
        $project = self::find($organizationId, $id);
        if ($project->slug === 'default') {
            throw new InvalidArgumentException('project: the Default project cannot be deleted.');
        }
        if (DB::table('sites')->where('project_id', $id)->exists()) {
            throw new InvalidArgumentException('project: move or delete its applications first.');
        }
        DB::table('projects')->where('id', $id)->delete();
        app(\App\Lib\Vault\Vault::class)->forget($organizationId, 'composer:project:'.$id);
        self::audit($organizationId, $actor, 'project.deleted', $id, ['name' => $project->name]);
    }

    /** Moves a production application, and its previews with it, to another of the organisation's projects. */
    public static function move(string $organizationId, int $siteId, int $projectId, Actor $actor): void
    {
        $site = DB::table('sites')->where('organization_id', $organizationId)->where('id', $siteId)->whereNull('parent_site_id')->first();
        abort_if($site === null, 404);
        $to = self::find($organizationId, $projectId);
        if ((int) $site->project_id === $projectId) {
            return;
        }
        $from = DB::table('projects')->where('id', $site->project_id)->first();
        DB::transaction(function () use ($site, $projectId) {
            DB::table('sites')->where('id', $site->id)->orWhere('parent_site_id', $site->id)->update(['project_id' => $projectId, 'updated_at' => now()]);
        });
        // Per the spec: the resource is the destination project; the application is in the metadata.
        self::audit($organizationId, $actor, 'site.moved', $projectId, ['site' => "{$site->node_id}/{$site->name}", 'site_id' => (int) $site->id,
            'from' => $from?->name, 'to' => $to->name, 'from_project_id' => (int) $site->project_id]);
    }

    private static function find(string $organizationId, int $id): object
    {
        $project = DB::table('projects')->where('organization_id', $organizationId)->where('id', $id)->first();
        abort_if($project === null, 404);

        return $project;
    }

    private static function cleanName(string $organizationId, string $name, ?int $except = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('name: give it a name of up to 60 characters.');
        }
        if (DB::table('projects')->where('organization_id', $organizationId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw new InvalidArgumentException("name: there is already a project called {$name}.");
        }

        return $name;
    }

    private static function audit(string $organizationId, Actor $actor, string $action, int $id, array $metadata): void
    {
        app(AuditLog::class)->record($organizationId, $actor, $action, resourceType: 'project', resourceId: (string) $id, metadata: $metadata + ['project_id' => $id]);
    }
}
