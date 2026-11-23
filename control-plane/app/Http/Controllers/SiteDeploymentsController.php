<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Containers\ContainerApps;
use App\Lib\Operations\Present;
use App\Lib\Sites\Runtimes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Every deploy and rollback of an application, newest first. Deploy and Roll back post to SiteController. */
final class SiteDeploymentsController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('view');
        $site = (int) $request->query('site');
        $limit = (int) $request->query('limit', 25);

        return Inertia::render('SiteDeployments', ['view' => fn () => $this->view($site, $limit)]);
    }

    /** @return array<string, mixed> */
    private function view(int $site, int $limit): array
    {
        $row = DB::table('sites')->where('organization_id', Org::id())->where('id', $site)->first();
        if ($row === null) {
            return ['missing' => true];
        }

        $history = DB::table('operations')->where('organization_id', Org::id())->where('site_id', $row->id)->whereIn('kind', ['deploy', 'rollback']);
        $total = (clone $history)->count();
        $ops = $history->orderByDesc('id')->limit(min(max($limit, 25), 500))->get();

        // The agent rolls back to the release before the current one, so a
        // rollback is offered only while the live release is the newest.
        $succeeded = DB::table('operations')->where('organization_id', Org::id())->where('site_id', $row->id)->where('kind', 'deploy')->where('status', 'succeeded')->orderByDesc('id')->limit(2)->get();
        $liveOp = $succeeded->firstWhere('release_id', $row->current_release);
        $previous = ($liveOp !== null && $succeeded->first()?->id === $liveOp->id) ? $succeeded->get(1) : null;
        // A container application rolls back to the image it kept, whichever deploy made it.
        if (Runtimes::isContainer($row)) {
            $kept = ContainerApps::state($row)['images'][1]['release'] ?? null;
            $previous = $kept === null ? null : $succeeded->firstWhere('release_id', $kept);
        }
        $running = DB::table('operations')->where('organization_id', Org::id())->where('site_id', $row->id)->whereIn('kind', ['deploy', 'rollback'])->whereIn('status', ['queued', 'running'])->orderByDesc('id')->first();

        return [
            'missing' => false,
            'canDeploy' => Gate::allows('deploy'),
            'id' => $row->id,
            'name' => $row->name,
            'branch' => $row->branch,
            'total' => $total,
            'running' => $running ? ['id' => $running->id, 'title' => $running->title] : null,
            'rows' => $ops->map(function ($op) use ($row, $previous) {
                $badge = Present::badge($op);
                $isLive = $op->kind === 'deploy' && $op->status === 'succeeded' && $op->release_id === $row->current_release;

                return [
                    'id' => $op->id,
                    'kind' => $op->kind,
                    'release' => $op->release_id,
                    'commit' => Present::commit($op->commit),
                    'by' => Present::startedBy($op),
                    'state' => match (true) {
                        in_array($op->status, ['queued', 'running'], true) => 'running', $op->status === 'failed' => 'failed', default => 'ok'
                    },
                    'label' => $op->status === 'failed' ? 'Failed at '.(Present::currentStage($op) ?? 'start') : ($isLive ? 'Live' : ($previous !== null && $op->id === $previous->id ? 'Previous' : ($op->kind === 'rollback' ? 'Rollback' : ($op->status === 'succeeded' ? '' : $badge['label'])))),
                    'class' => $op->status === 'failed' ? 'failed' : ($isLive ? 'live' : (in_array($op->status, ['queued', 'running'], true) ? 'running' : '')),
                    'ago' => Present::ago($op->finished_at ?? $op->started_at ?? $op->created_at),
                    'took' => $op->finished_at ? Present::duration($op->started_at, $op->finished_at) : '',
                    'rollback' => $previous !== null && $op->id === $previous->id,
                ];
            })->all(),
        ];
    }
}
