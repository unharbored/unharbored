<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Services\Catalogue;
use App\Lib\Services\Services;
use App\Lib\Templates\Sources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The team's template services (narrowed by the project switcher), and the catalogue to install one from. */
final class ServicesController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('view');
        // ?project=<slug> sets the project filter (an unknown slug is ignored).
        if ($request->query->has('project')) {
            Projects::chooseSlug((string) $request->query('project'));
        }

        return Inertia::render('Services', [
            // The project filter that is on (its name), or null.
            'filter' => fn () => ($id = Projects::current()) === null ? null : ['name' => (string) DB::table('projects')->where('id', $id)->value('name')],
            'services' => fn () => app(Services::class)->describe(Org::id(), Projects::current()),
        ]);
    }

    /**
     * Search, category chips, one card each. Only the ones in config('templates.offered')
     * that run natively can be installed; the rest say why not.
     */
    public function templates(Request $request): Response
    {
        Gate::authorize('view');
        $manager = $request->user()->can('infrastructure');
        $cards = app(Catalogue::class)->cards(Org::id());

        return Inertia::render('Templates', [
            'cards' => $cards,
            'categories' => array_values(array_unique(array_column($cards, 'category'))),
            'canInstall' => $manager,
            // Only a Manager sees why a template was left out: the file and source names are theirs to fix.
            'failures' => $manager ? app(Sources::class)->failures() : [],
        ]);
    }
}
