<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Auth\Roles;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Providers;
use App\Lib\Services\Catalogue;
use App\Lib\Services\Services;
use App\Lib\Sites\NewApplications;
use App\Lib\Templates\Definition;
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
            'newServer' => $this->newServerChoices($template),
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
        [$template, $inputs, $node, $project, $acknowledged, $portsApproved, $new] = $this->answers($request);
        $role = (string) Roles::roleOf($request->user(), Org::id());
        try {
            $a = $new === null
                ? app(Services::class)->check(Org::id(), $node, $template, $inputs, $role, $acknowledged, $portsApproved, $project)
                : app(Services::class)->checkOnNewServer(Org::id(), $new['slug'], $template, $inputs, $role, $acknowledged, $portsApproved, $project);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
        $review = app(Catalogue::class)->review(Org::id(), $a, $role);
        if ($new !== null) {
            $review['server'] = "A new {$new['label']} server ({$new['region']}, {$new['size']})";
            $review['newServer'] = true;
            $review['ip'] = null;
        }

        return response()->json($review);
    }

    /** Queues the install and answers with its operation. */
    public function create(Request $request): JsonResponse
    {
        Gate::authorize('infrastructure');
        [$template, $inputs, $node, $project, $acknowledged, $portsApproved, $new] = $this->answers($request);
        $role = (string) Roles::roleOf($request->user(), Org::id());
        if ($new !== null) {
            try {
                $a = app(Services::class)->checkOnNewServer(Org::id(), $new['slug'], $template, $inputs, $role, $acknowledged, $portsApproved, $project);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['form' => $e->getMessage()]);
            }
            $server = ['slug' => $new['slug'], 'provider' => $new['provider'], 'region' => $new['region'], 'size' => $new['size']];

            return response()->json(['operation' => app(OperationRunner::class)->queueServiceCreate(Org::id(), $template, $a['name'], $inputs, $server, $project, $acknowledged,
                $portsApproved, $role, (string) $request->user()->email)]);
        }
        try {
            $id = app(Services::class)->install(Org::id(), $node, $template, $inputs, (string) $request->user()->email, $role, $acknowledged, true, $project, $portsApproved, 'console')['operation_id'];
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        return response()->json(['operation' => $id]);
    }

    /**
     * Where a server could be made for this template, by provider: its regions and the sizes big enough. Nothing is offered
     * for a template that runs in a container (the server must be prepared first) or asks for a secret (held here in the clear).
     *
     * @return array<string, array<string, mixed>>
     */
    private function newServerChoices(string $template): array
    {
        try {
            $def = app(Catalogue::class)->offeredDefinition($template);
        } catch (\InvalidArgumentException) {
            return [];
        }
        if ($def->runtime !== 'native' || array_filter($def->inputs, fn ($i) => $i['type'] === 'secret') !== [] || ! auth()->user()->can('infrastructure')) {
            return [];
        }
        $need = Definition::parseSize($def->memory) ?? 0;
        $out = [];
        foreach (app(Providers::class)->connected(Org::id()) as $p) {
            if ($p->method() !== 'api') {
                continue;
            }
            $c = $p->catalog();
            $sizes = array_filter($c->sizes, fn ($s) => $need <= $s['memory_gb'] * 1024 ** 3);
            if ($sizes !== []) {
                $out[$p->id()] = ['label' => $p->label(), 'regions' => $c->regions, 'region' => $c->defaultRegion,
                    'sizes' => array_map(Catalog::sizeLabel(...), $sizes), 'size' => array_key_first($sizes)];
            }
        }

        return $out;
    }

    /** The server asked for, checked against what is offered; null when an existing server is chosen. @return ?array{slug: string, provider: string, label: string, region: string, size: string} */
    private function newServer(Request $request, string $template): ?array
    {
        $in = $request->input('newServer');
        if (! is_array($in)) {
            return null;
        }
        $provider = (string) ($in['provider'] ?? '');
        $choice = $this->newServerChoices($template)[$provider] ?? throw ValidationException::withMessages(['node' => 'Pick a provider that is connected and can host this service.']);
        $region = (string) ($in['region'] ?? '');
        $size = (string) ($in['size'] ?? '');
        if (! isset($choice['regions'][$region]) || ! isset($choice['sizes'][$size])) {
            throw ValidationException::withMessages(['node' => 'Pick a region and size from the lists.']);
        }
        $p = app(Providers::class)->get($provider);
        $offered = method_exists($p, 'availableSizes') ? $p->availableSizes(Org::id(), $region) : null;
        if ($offered !== null && ! in_array($size, $offered, true)) {
            throw ValidationException::withMessages(['node' => "{$choice['label']} does not offer {$size} in {$region}. Pick another size or region."]);
        }
        $name = strtolower(trim((string) $request->input('values.name')));
        $base = rtrim(substr('svc-'.preg_replace('/[^a-z0-9-]/', '', $name), 0, 28), '-');

        return ['slug' => NewApplications::freeSlug(Org::id(), $base), 'provider' => $provider, 'label' => $choice['label'], 'region' => $region, 'size' => $size];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: string, 3: ?int, 4: bool, 5: bool, 6: ?array<string, string>} */
    private function answers(Request $request): array
    {
        $template = (string) $request->input('template');
        $new = $this->newServer($request, $template);
        $node = $new !== null ? "{$new['slug']}.".Org::id() : (string) $request->input('node');
        $project = (string) $request->input('project');
        $acknowledged = $request->boolean('acknowledged');
        $portsApproved = $request->boolean('portsApproved');
        $inputs = app(Catalogue::class)->prepare($template, (array) $request->input('values', []), (array) $request->input('secrets', []), $node, $acknowledged, $portsApproved);

        return [$template, $inputs, $node, $project !== '' ? (int) $project : Projects::current(), $acknowledged, $portsApproved, $new];
    }
}
