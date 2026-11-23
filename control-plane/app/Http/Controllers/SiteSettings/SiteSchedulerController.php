<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Heartbeats;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The Laravel scheduler, its heartbeat, and the application's own cron jobs. */
final class SiteSchedulerController extends SiteSettingsBase
{
    /** Switches the Laravel scheduler and queues the operation that writes or removes its cron entry. */
    public function toggle(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $row = app(Sites::class)->setScheduler($this->row($site), $request->boolean('on'));

        return $this->toOperation(app(OperationRunner::class)->queueScheduler((int) $row->id, 'console', $this->email()));
    }

    /** Starts or stops heartbeat monitoring of the Laravel scheduler, and queues the operation that adds or drops the ping. */
    public function monitor(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $request->boolean('on') ? Heartbeats::enable((int) $row->id, null) : Heartbeats::disable((int) $row->id, null);

        return $this->toOperation(app(OperationRunner::class)->queueScheduler((int) $row->id, 'console', $this->email()));
    }

    /** Asks the live release what its code schedules. Read-only, so it runs now rather than as an operation. */
    public function tasks(int $site): JsonResponse
    {
        Gate::authorize('edit');
        try {
            return response()->json(['tasks' => app(Deployer::class)->readSchedule($this->row($site))]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['scheduler' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }

    /** Adds or changes a job and queues the operation that writes the cron file. */
    public function saveJob(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        try {
            $saved = app(Sites::class)->saveJob($row, $request->integer('id'), [
                'name' => (string) $request->input('name', ''), 'command' => (string) $request->input('command', ''),
                'frequency' => (string) $request->input('frequency', ''), 'at' => (string) $request->input('at', ''),
                'cron' => (string) $request->input('cron', ''), 'everywhere' => $request->boolean('everywhere')]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['job' => $e->getMessage()]);
        }
        $request->boolean('monitored') ? Heartbeats::enable((int) $row->id, $saved) : Heartbeats::disable((int) $row->id, $saved);

        return $this->toOperation(app(OperationRunner::class)->queueScheduler((int) $row->id, 'console', $this->email()));
    }

    public function pauseJob(Request $request, int $site, int $job): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        app(Sites::class)->setJobPaused($row, $job, $request->boolean('paused'));

        return $this->toOperation(app(OperationRunner::class)->queueScheduler((int) $row->id, 'console', $this->email()));
    }

    public function dropJob(int $site, int $job): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        app(Sites::class)->deleteJob($row, $job);

        return $this->toOperation(app(OperationRunner::class)->queueScheduler((int) $row->id, 'console', $this->email()));
    }

    /** What a job last printed, read from its log on the primary. */
    public function output(int $site, int $job): JsonResponse
    {
        Gate::authorize('view');
        $row = $this->row($site);
        $found = collect(Sites::scheduledJobs($row))->firstWhere('id', $job);
        abort_if($found === null, 404);

        return response()->json(['id' => $job, 'title' => 'Last output of '.$found['name'], 'text' => app(Deployer::class)->jobOutput($row, $job) ?: 'It has not printed anything yet.']);
    }

    /** Runs the job's command once now and returns what it printed. */
    public function run(int $site, int $job): JsonResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $found = collect(Sites::scheduledJobs($row))->firstWhere('id', $job);
        abort_if($found === null, 404);

        return response()->json(['id' => $job, 'title' => 'Ran '.$found['name'].' just now', 'text' => app(Deployer::class)->runJob($row, (string) $found['command']) ?: 'It finished without printing anything.']);
    }

    public function next(int $site, int $job): JsonResponse
    {
        Gate::authorize('view');
        $found = collect(Sites::scheduledJobs($this->row($site)))->firstWhere('id', $job);
        abort_if($found === null, 404);

        return response()->json(['id' => $job, 'title' => 'Next runs of '.$found['name'], 'text' => implode("\n", Sites::nextRuns((string) $found['cron'])) ?: 'No upcoming runs.']);
    }
}
