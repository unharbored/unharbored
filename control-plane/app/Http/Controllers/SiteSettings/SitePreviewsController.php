<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Auth\Org;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Previews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Preview deployments of branches and pull requests, and the settings they start from. */
final class SitePreviewsController extends SiteSettingsBase
{
    public function create(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $days = $request->integer('days');
        $database = (string) $request->input('database', '');
        try {
            return $this->toOperation(app(OperationRunner::class)->queuePreviewCreate($this->row($site)->id, (string) $request->input('branch', ''), $request->boolean('follow'), $days > 0 ? $days : null, 'console', $this->email(), $database === '' ? null : $database));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['previewBranch' => $e->getMessage()]);
        }
    }

    public function delete(int $site, int $preview): Response
    {
        Gate::authorize('edit');
        abort_unless(DB::table('sites')->where('organization_id', Org::id())->where('id', $preview)->where('parent_site_id', $this->row($site)->id)->exists(), 404);

        return $this->toOperation(app(OperationRunner::class)->queuePreviewDestroy($preview, 'console', $this->email()));
    }

    public function settings(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $prBase = (string) $request->input('prBase', '');
        $database = (string) $request->input('database', 'fresh');
        $overrides = (string) $request->input('overrides', '');
        try {
            Previews::setPullRequestBase($row, trim($prBase) === '' ? null : $prBase);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['previewPrBase' => $e->getMessage()]);
        }
        try {
            Previews::setDefaultDatabase($row, $database);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['previewDefaultDatabase' => $e->getMessage()]);
        }
        try {
            app(Previews::class)->setOverrides($row, $overrides);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['previewOverrides' => $e->getMessage()]);
        }
        $seeder = trim((string) $request->input('seeder', ''));
        if ($seeder !== '' && ! preg_match('/^[A-Za-z0-9_\\\\]+$/', $seeder)) {
            throw ValidationException::withMessages(['previewSeeder' => 'A seeder class, like PreviewSeeder or Database\\Seeders\\PreviewSeeder.']);
        }
        $keys = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', strtoupper((string) $request->input('exclude', ''))))));
        foreach ($keys as $k) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                throw ValidationException::withMessages(['previewExclude' => "{$k} is not a key name."]);
            }
        }
        DB::table('sites')->where('organization_id', Org::id())->where('id', $row->id)->update(['preview_seeder' => $seeder === '' ? null : $seeder,
            'preview_exclude' => $keys === [] ? null : json_encode($keys), 'updated_at' => now()]);

        return $this->saved('Preview settings saved. They apply to previews made from now on.');
    }
}
