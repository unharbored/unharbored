<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Present;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Providers;
use App\Lib\Sites\Sites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** One operation: its stages and its log, looked at again every second while it runs. */
final class OperationController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('view');
        $operation = (int) $request->query('operation');

        return Inertia::render('Operation', ['view' => fn () => $this->view($operation)]);
    }

    /** The whole log as plain text, to read in full, search, copy or save; the page itself shows only the latest lines. */
    public function log(Request $request): SymfonyResponse
    {
        Gate::authorize('view');
        $op = DB::table('operations')->where('organization_id', Org::id())->where('id', (int) $request->query('operation'))->first() ?? abort(404);
        $text = "{$op->title} (operation {$op->id}, {$op->status})\n\n".rtrim((string) $op->log)."\n";
        if ($op->error !== null && $op->error !== '' && ! str_contains((string) $op->log, (string) $op->error)) {
            $text .= "\nError: {$op->error}\n";
        }

        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** A failed new application, carried on from the step that failed. */
    public function retry(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $operation = (int) $request->input('operation');
        abort_unless(DB::table('operations')->where('organization_id', Org::id())->where('id', $operation)->exists(), 404);
        try {
            $server = $request->filled('region') && $request->filled('size') ? ['region' => (string) $request->input('region'), 'size' => (string) $request->input('size')] : null;
            app(OperationRunner::class)->queueRetry($operation, (string) $request->user()->email, $server);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function view(int $operation): array
    {
        $op = DB::table('operations')->where('organization_id', Org::id())->where('id', $operation)->first();
        if ($op === null) {
            return ['missing' => true];
        }
        $meta = json_decode((string) $op->meta, true) ?: [];
        $service = isset($meta['service_id']) ? DB::table('services')->where('organization_id', Org::id())->where('id', (int) $meta['service_id'])->first(['id', 'name']) : null;
        $site = $op->site_id ? DB::table('sites')->where('organization_id', Org::id())->where('id', $op->site_id)->first() : null;

        $stages = [];
        foreach (json_decode((string) $op->stages, true) ?: [] as $i => $stage) {
            $stages[] = [
                'n' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'name' => ucfirst($stage['name']),
                'status' => $stage['status'],
                'time' => match ($stage['status']) {
                    'done' => Present::duration($stage['started_at'], $stage['finished_at']),
                    'running' => Present::duration($stage['started_at']).' · running',
                    'failed' => 'failed',
                    default => 'waiting',
                },
            ];
        }

        // Each line is "HH:MM:SS  stage    action  text": split it so the
        // page can colour the parts. Only the latest lines are sent.
        $all = explode("\n", rtrim((string) $op->log));
        $lines = [];
        foreach (array_slice($all, -400) as $i => $line) {
            if ($line === '') {
                continue;
            }
            $lines[] = [
                'i' => $i,
                'time' => substr($line, 0, 8),
                'stage' => rtrim(substr($line, 10, 9)),
                'action' => rtrim(substr($line, 19, 8)),
                'text' => (string) substr($line, 27),
            ];
        }

        $running = in_array($op->status, ['queued', 'running'], true);

        // After a failure, the steps that never started were not run, which is not the same as waiting.
        if ($op->status === 'failed') {
            $failedAt = collect($stages)->search(fn ($s) => $s['status'] === 'failed');
            foreach ($stages as $i => $s) {
                if ($failedAt !== false && $i > $failedAt && ! in_array($s['status'], ['done', 'failed'], true)) {
                    $stages[$i]['status'] = 'skipped';
                    $stages[$i]['time'] = 'not run';
                }
            }
        }

        return [
            'missing' => false,
            'id' => $op->id,
            'title' => $op->title,
            'kind' => $op->kind,
            'status' => $op->status,
            'running' => $running,
            'retryable' => ($op->kind === 'app_create' || ($op->kind === 'database_create' && ($meta['provider'] ?? 'digitalocean') !== 'digitalocean')) && $op->status === 'failed',
            'retryServer' => $op->kind === 'app_create' && $op->status === 'failed' ? $this->serverChoice($op, $meta) : null,
            'badge' => Present::badge($op),
            'elapsed' => Present::duration($op->started_at ?? $op->created_at, $op->finished_at),
            'release' => $op->release_id,
            'commit' => Present::commit($op->commit),
            'by' => Present::startedBy($op),
            'node' => $op->node_id ? (strstr($op->node_id, '.', true) ?: $op->node_id) : null,
            'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
            'awaitingKey' => $site ? Sites::awaitingDeployKey($site) : null,
            'service' => $service ? ['id' => $service->id, 'name' => $service->name] : null,
            'stages' => $stages,
            'lines' => $lines,
            'lineCount' => count($all),
            'error' => $op->error,
        ];
    }

    /**
     * When the step that failed is making a new server, what can be changed before trying again: the region and size,
     * with the sizes that region sells. Null for any other failure.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function serverChoice(object $op, array $meta): ?array
    {
        $failed = collect($meta['steps'] ?? [])->first(fn ($s) => ! in_array($s, $meta['done'] ?? [], true));
        if ($failed !== 'server' || ($meta['server']['mode'] ?? '') !== 'new') {
            return null;
        }
        $provider = app(Providers::class)->get((string) ($meta['provider'] ?? 'digitalocean'));
        $catalog = $provider->catalog();
        $offered = method_exists($provider, 'availableSizes') ? $provider->availableSizes($op->organization_id, (string) $meta['server']['region']) : null;
        $sizes = $offered === null ? $catalog->sizes : array_intersect_key($catalog->sizes, array_flip($offered));

        return ['provider' => $provider->id(), 'region' => $meta['server']['region'], 'size' => $meta['server']['size'], 'regions' => $catalog->regions,
            'sizes' => array_map(Catalog::sizeLabel(...), $sizes)];
    }
}
