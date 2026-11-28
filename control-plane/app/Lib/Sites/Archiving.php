<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Servers\ServerArchiving;
use Illuminate\Support\Facades\DB;

/**
 * Archiving an application: Unharbored stops managing it, the server is not touched. The row, its history,
 * deployments, backups records, .env and audit trail stay; `archived_at` is the only change, and it is set on the
 * application's previews too (they are part of it). Restoring clears it and, again, does nothing on the server.
 * Everything that changes a server for an application goes through Operations::create (refused here) or a console
 * route (refused by EnsureSiteActive); the background commands filter `archived_at` where they pick their sites.
 */
final class Archiving
{
    public function archive(string $organizationId, int $siteId, Actor $actor): void
    {
        $site = $this->application($organizationId, $siteId);
        if ($site->archived_at !== null) {
            return;
        }
        DB::table('sites')->where(fn ($q) => $q->where('id', $site->id)->orWhere('parent_site_id', $site->id))
            ->update(['archived_at' => now(), 'updated_at' => now()]);
        $this->audit($organizationId, $actor, 'site.archived', $site);
    }

    /** @param  bool  $checkHost  false when it is only brought back to be deleted: the server or cluster under it may stay archived */
    public function restore(string $organizationId, int $siteId, Actor $actor, bool $checkHost = true): void
    {
        $site = $this->application($organizationId, $siteId);
        if ($site->archived_at === null) {
            return;
        }
        if ($checkHost) {
            ServerArchiving::assertCanHost($site);
        }
        DB::table('sites')->where(fn ($q) => $q->where('id', $site->id)->orWhere('parent_site_id', $site->id))
            ->update(['archived_at' => null, 'updated_at' => now()]);
        $this->audit($organizationId, $actor, 'site.restored', $site);
    }

    /** Whether the application (or preview) is archived. */
    public static function isArchived(int $siteId): bool
    {
        return DB::table('sites')->where('id', $siteId)->whereNotNull('archived_at')->exists();
    }

    /** @throws SiteArchived */
    public static function refuseIfArchived(int $siteId): void
    {
        $name = DB::table('sites')->where('id', $siteId)->whereNotNull('archived_at')->value('name');
        if ($name !== null) {
            throw new SiteArchived($name);
        }
    }

    private function application(string $organizationId, int $siteId): object
    {
        $site = DB::table('sites')->where('organization_id', $organizationId)->where('id', $siteId)->whereNull('parent_site_id')->first();
        abort_if($site === null, 404);

        return $site;
    }

    private function audit(string $organizationId, Actor $actor, string $action, object $site): void
    {
        app(AuditLog::class)->record($organizationId, $actor, $action, resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['site_id' => (int) $site->id]);
    }
}
