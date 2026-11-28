<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Cloud\Spaces;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\LocalServices;
use App\Lib\Sites\CloneConversion;
use App\Lib\Sites\DatabaseClones;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\SiteBackups;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The database, its backups and copies, the cluster it lives on, and uploads on Spaces. */
final class SiteDatabaseController extends SiteSettingsBase
{
    /**
     * The site's database as one connection URL and as its parts (host, port, database, user, password), to paste into a client.
     * Carries the password, so it needs the right to see secrets and is audited.
     */
    public function connection(Request $request, int $site): JsonResponse
    {
        Gate::authorize('view.secrets');
        $row = $this->row($site);
        $sites = app(Sites::class);
        $clone = $request->integer('clone');
        if ($clone > 0) {
            $values = app(DatabaseClones::class)->reveal($row, $clone, $this->actor());
        } else {
            // What the application really uses: what is injected, then the user's own values over it.
            $values = array_map(fn ($e) => $e['value'], $sites->injected($row));
            foreach (Sites::envKeys($sites->customEnv($row)) as $key => $value) {
                $values[$key] = $value;
            }
            app(AuditLog::class)->record($row->organization_id, $this->actor(),
                'site.database_connection_read', resourceType: 'site', resourceId: "{$row->node_id}/{$row->name}");
        }
        $details = Sites::connectionDetails($values);
        if ($details === null) {
            throw ValidationException::withMessages(['database' => 'This database has no network address to connect to (SQLite is a file on the server).']);
        }

        return response()->json(['url' => $details['url'], 'details' => $details]);
    }

    public function attach(Request $request, int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $cluster = $request->integer('cluster');
        $ok = DB::table('resources')->where('id', $cluster)->where('organization_id', $row->organization_id)
            ->where('type', 'database_cluster')->where('state', 'active')->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['cluster' => 'Pick an online cluster.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueDatabaseAttach($row->id, $cluster, 'console', $this->email()));
    }

    /** MySQL or PostgreSQL on the application's own server: installed if it is not there, with a database and user of its own. */
    public function local(Request $request, int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $service = (string) $request->input('service');
        if ((LocalServices::SERVICES[$service]['kind'] ?? null) !== 'database') {
            throw ValidationException::withMessages(['local' => 'Choose MySQL or PostgreSQL.']);
        }
        if (($row->compute ?? 'single') !== 'single' || Runtimes::isContainer($row)) {
            throw ValidationException::withMessages(['local' => 'A database on the server is for an application on one server. Several servers need a cluster.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueLocalService($row->id, $service, 'console', $this->email()));
    }

    /** Stops managing the site's database; the database, its data and the connection in .env stay. */
    public function detach(int $site): RedirectResponse
    {
        Gate::authorize('infrastructure');
        try {
            app(DatabaseClusters::class)->detach($this->row($site), $this->email());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['database' => $e->getMessage()]);
        }

        return $this->saved('Database detached. It and its data stay on the cluster; the app keeps using the connection in its .env.');
    }

    public function storage(int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        if ($row->bucket_id !== null) {
            throw ValidationException::withMessages(['storage' => 'This site already has a bucket.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueStorageAttach($row->id, 'console', $this->email()));
    }

    public function removeStorage(int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        if ($row->bucket_id === null) {
            throw ValidationException::withMessages(['storage' => 'This site has no bucket.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueStorageDetach($row->id, 'console', $this->email()));
    }

    /** Uses a bucket that already exists, at any S3-compatible service. Tried with a read-only request; nothing is created. */
    public function existingStorage(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate([
            'endpoint' => 'required|string|max:200', 'bucket' => 'required|string|max:63', 'access_key' => 'required|string|max:200',
            'secret_key' => 'required|string|max:400', 'region' => 'nullable|string|max:30', 'url' => 'nullable|string|max:200',
        ]);
        try {
            app(Spaces::class)->useExisting($this->row($site), $data['endpoint'], $data['bucket'], $data['access_key'], $data['secret_key'],
                ($data['region'] ?? '') ?: 'us-east-1', $data['url'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['storage' => $e->getMessage()]);
        }

        return $this->saved("Uploads use {$data['bucket']}. Nothing was changed there. Deploy to switch; files already uploaded are not copied.");
    }

    /** Switches the automatic backups on or off. */
    public function backups(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $on = $request->boolean('on');
        app(Sites::class)->setBackups($this->row($site), $on);

        return $this->saved($on ? 'Backups are on: nightly at 03:47 UTC and before every deploy\'s migrations.' : 'Backups are off. Back up now still works.');
    }

    public function backUp(int $site): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        if ($row->current_release === null) {
            throw ValidationException::withMessages(['backup' => 'Deploy the site first: there is nothing to back up yet.']);
        }
        if (! app(SiteBackups::class)->enabled()) {
            throw ValidationException::withMessages(['backup' => 'There is no backup bucket yet.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueSiteBackup($row->id, 'manual', 'console', $this->email()));
    }

    public function restore(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $object = (string) $request->input('object', '');
        if (! str_starts_with($object, SiteBackups::prefix($row))) {
            throw ValidationException::withMessages(['backup' => 'That backup is not one of this site\'s.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueSiteRestore($row->id, $object, 'console', $this->email()));
    }

    public function restoreFromCluster(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        try {
            return $this->toOperation(app(OperationRunner::class)->queueSiteRestoreFromClusterBackup($this->row($site)->id, (string) $request->input('createdAt', ''), 'console', $this->email()));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['backup' => $e->getMessage()]);
        }
    }

    /** Saves the conversion queries as the application's own, or clears them when empty. */
    public function saveCloneSql(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $sql = trim((string) $request->input('sql', ''));
        if ($sql !== '') {
            try {
                CloneConversion::prepare($sql, app(SiteBackups::class)->engine($row));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                throw ValidationException::withMessages(['clone' => $e->getMessage()]);
            }
        }
        DB::table('sites')->where('id', $row->id)->update(['clone_conversion' => $sql === '' ? null : $sql]);

        return $this->saved($sql === '' ? 'The saved conversion queries were cleared.' : 'Saved. Every clone of this application starts with these queries.');
    }

    /** Checks the request, then queues the copy as an operation. */
    public function clone(Request $request, int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $name = (string) $request->input('name', '');
        $sql = (string) $request->input('sql', '');
        if (! preg_match(DatabaseClones::NAME, $name)) {
            throw ValidationException::withMessages(['clone' => 'A name is lower case letters, digits and underscores, starting with a letter.']);
        }
        if (DB::table('database_clones')->where('site_id', $row->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['clone' => "There is already a clone called {$name}."]);
        }
        if (trim($sql) !== '') {
            try {
                CloneConversion::prepare($sql, app(SiteBackups::class)->engine($row));
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                throw ValidationException::withMessages(['clone' => $e->getMessage()]);
            }
        }

        return $this->toOperation(app(OperationRunner::class)->queueDatabaseClone((int) $row->id, $name, $request->boolean('schemaOnly'), 'console', $this->email(), $sql));
    }

    /** A copy's connection, as .env lines. Audited. */
    public function revealClone(int $site, int $clone): JsonResponse
    {
        Gate::authorize('view.secrets');
        try {
            return response()->json(['lines' => app(DatabaseClones::class)->reveal($this->row($site), $clone, $this->actor())]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['clone' => $e->getMessage()]);
        }
    }

    public function dropClone(int $site, int $clone): RedirectResponse
    {
        Gate::authorize('infrastructure');
        try {
            app(DatabaseClones::class)->remove($this->row($site), $clone);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['clone' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return $this->saved('The copy was deleted.');
    }
}
