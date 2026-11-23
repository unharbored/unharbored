<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Org;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\ServerStack;
use App\Lib\Sites\Sites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** What the application is and how it is built and started, and which PHP it runs on. */
final class SiteBuildController extends SiteSettingsBase
{
    /** Saves what the application is and how it builds and starts, and rewrites the web server to match. */
    public function runtime(Request $request, int $site): Response|RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $rt = (array) $request->input('rt', []);
        $runtime = (string) ($rt['runtime'] ?? 'php');
        try {
            // Blank means the runtime's own default, so it is not stored.
            $changes = array_filter(array_intersect_key($rt, array_flip(['version', 'install', 'build', 'release', 'start', 'output', 'health', 'port', 'spa'])),
                fn ($v) => $v !== '' && $v !== null && $v !== false);
            // A change of runtime starts from that runtime's presets; a change within one replaces what was set.
            $row = app(Sites::class)->setRuntime(app(Sites::class)->clearRuntime($row), $runtime, $changes);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['runtime' => $e->getMessage()]);
        }
        try {
            $moved = Sites::rootDir($row) !== Sites::cleanRootDir((string) ($rt['root'] ?? ''));
            $row = app(Sites::class)->setRootDir($row, (string) ($rt['root'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['root' => $e->getMessage()]);
        }
        // A live site's web server would point at a folder the running release does not have until the next deploy writes it.
        if ($moved && $row->current_release !== null) {
            return $this->saved('Saved. The next deploy builds and serves from the new folder.');
        }

        return $this->toOperation(app(OperationRunner::class)->queueWebServer((int) $row->id, 'console', $this->email()));
    }

    /** The commands run in each new release after Composer, and the Node version they run under. */
    public function buildScript(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        try {
            app(Sites::class)->setBuildScript($row, (string) $request->input('script', ''), (string) $request->input('node', ''));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['buildScript' => $e->getMessage()]);
        }

        $default = in_array(trim((string) $request->input('script', '')), ['', Sites::DEFAULT_BUILD_SCRIPT], true);

        return $this->saved($default ? 'Back to the default build commands.' : 'Saved. The next deploy runs these in place of the default.');
    }

    /** Keeps the Composer credentials, or forgets them when sent empty. They travel to the servers with the next deploy. */
    public function composerAuth(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        try {
            app(Sites::class)->setComposerAuth($row, (string) $request->input('auth', ''));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['composerAuth' => $e->getMessage()]);
        }

        return $this->saved(trim((string) $request->input('auth', '')) === '' ? 'Composer credentials removed. The next deploy removes them from the servers.' : 'Composer credentials saved. The next deploy puts them on the servers.');
    }

    /** Saves the PHP branch the site runs, to take effect on the next deploy. */
    public function php(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $version = (string) $request->input('version', '');
        if (! ServerStack::supportsPhp($version)) {
            throw ValidationException::withMessages(['phpVersion' => 'Choose a supported PHP version.']);
        }
        $missing = [];
        foreach (Sites::nodes($row) as $node) {
            $installed = $version === ServerStack::PHP_VERSION
                || DB::table('server_php_versions')->where('node_id', $node)->where('version', $version)->exists();
            if (! $installed) {
                $missing[] = strstr($node, '.', true) ?: $node;
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(['phpVersion' => "Install PHP {$version} on ".implode(', ', $missing).' first, on the Servers page.']);
        }
        $from = Sites::phpVersion($row);
        DB::table('sites')->where('organization_id', Org::id())->where('id', $row->id)->update(['php_version' => $version, 'updated_at' => now()]);
        app(Sites::class)->markPending($row);
        app(AuditLog::class)->record($row->organization_id, $this->actor(),
            'site.php_version_changed', resourceType: 'site', resourceId: "{$row->node_id}/{$row->name}",
            metadata: ['from' => $from, 'to' => $version]);

        return $this->saved("PHP version saved: {$version}. It applies on the next deploy.");
    }
}
