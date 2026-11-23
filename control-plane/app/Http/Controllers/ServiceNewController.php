<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Auth\Roles;
use App\Lib\Services\Catalogue;
use App\Lib\Services\Services;
use App\Lib\Templates\Sources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Installing a template in two steps: the form, generated from the definition's inputs, then a review
 * of everything that will be created. The page keeps what was typed; secrets are sent only to check and
 * create, and are never rendered back.
 */
final class ServiceNewController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('view');
        $template = (string) $request->query('template');

        return Inertia::render('ServiceNew', [
            'template' => $template,
            'form' => $this->form($template),
            'servers' => $this->servers($template, []),
        ]);
    }

    /** The form's fields and the extras it asks for; an unknown or not-offered template is an error line, not a form. */
    private function form(string $template): array
    {
        $manager = auth()->user()->can('infrastructure');
        try {
            $def = app(Catalogue::class)->offeredDefinition($template);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $template === '' ? 'Choose a template from the catalogue first.' : $e->getMessage(), 'canInstall' => $manager];
        }
        $entry = app(Sources::class)->find($def->id);

        return [
            'name' => $def->name, 'description' => $def->description, 'memory' => $def->memory, 'canInstall' => $manager,
            'label' => $entry?->label, 'fields' => app(Catalogue::class)->fields($def),
            'acknowledge' => $def->acknowledge, 'extraPorts' => $def->extraPorts,
            'projects' => count($projects = Projects::listing(Org::id())) > 1 ? $projects : [],
            'currentProject' => Projects::current(),
        ];
    }

    /** The team's servers with the fit for what is typed (the storage size changes the disk check). */
    private function servers(string $template, array $values): array
    {
        try {
            $def = app(Catalogue::class)->offeredDefinition($template);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $storage = '';
        foreach ($def->inputs as $i) {
            if ($i['key'] === 'storage') {
                $storage = (string) (($values['storage'] ?? '') !== '' ? $values['storage'] : ($i['default'] ?? ''));
            }
        }

        return app(Catalogue::class)->servers(Org::id(), $def, $storage);
    }

    public function serversFor(Request $request): JsonResponse
    {
        Gate::authorize('view');

        return response()->json($this->servers((string) $request->input('template'), (array) $request->input('values', [])));
    }

    /** What will be created, checked exactly as the install will check it. Nothing is written. */
    public function check(Request $request): JsonResponse
    {
        Gate::authorize('infrastructure');
        [$template, $inputs, $node, $project, $acknowledged, $portsApproved] = $this->answers($request);
        $role = (string) Roles::roleOf($request->user(), Org::id());
        try {
            $a = app(Services::class)->check(Org::id(), $node, $template, $inputs, $role, $acknowledged, $portsApproved, $project);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        return response()->json(app(Catalogue::class)->review(Org::id(), $a, $role));
    }

    /** Queues the install and answers with its operation. */
    public function create(Request $request): JsonResponse
    {
        Gate::authorize('infrastructure');
        [$template, $inputs, $node, $project, $acknowledged, $portsApproved] = $this->answers($request);
        $role = (string) Roles::roleOf($request->user(), Org::id());
        try {
            $id = app(Services::class)->install(Org::id(), $node, $template, $inputs, (string) $request->user()->email, $role, $acknowledged, true, $project, $portsApproved, 'console')['operation_id'];
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        return response()->json(['operation' => $id]);
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: string, 3: ?int, 4: bool, 5: bool} */
    private function answers(Request $request): array
    {
        $template = (string) $request->input('template');
        $node = (string) $request->input('node');
        $project = (string) $request->input('project');
        $acknowledged = $request->boolean('acknowledged');
        $portsApproved = $request->boolean('portsApproved');
        $inputs = app(Catalogue::class)->prepare($template, (array) $request->input('values', []), (array) $request->input('secrets', []), $node, $acknowledged, $portsApproved);

        return [$template, $inputs, $node, $project !== '' ? (int) $project : Projects::current(), $acknowledged, $portsApproved];
    }
}
