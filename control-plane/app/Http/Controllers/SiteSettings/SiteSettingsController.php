<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Auth\Org;
use App\Lib\Sites\SettingsPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** An application's Settings page: the facts every tab needs and the data of the open tab. */
final class SiteSettingsController extends SiteSettingsBase
{
    public function show(Request $request): Response
    {
        Gate::authorize('view');
        $row = DB::table('sites')->where('organization_id', Org::id())->where('id', (int) $request->query('site'))->first();
        if ($row === null) {
            return Inertia::render('Site/Settings', ['missing' => true, 'tab' => 'env', 'site' => null, 'data' => []]);
        }
        $tab = (string) $request->query('tab', 'env');
        $tab = in_array($tab, SettingsPage::TABS, true) ? $tab : 'env';

        return Inertia::render('Site/Settings', [
            'missing' => false,
            'tab' => $tab,
            'site' => SettingsPage::common($row),
            'data' => SettingsPage::tab($tab, $row),
        ]);
    }
}
