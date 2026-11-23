<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RecordVisit;
use App\Support\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The whole point of this application.
 *
 * Each value here fails in a different way, so a half-broken deploy is
 * visible rather than something you discover a week later:
 *
 *  - release            did the new code actually become current
 *  - database           did migrations run
 *  - queue              are workers alive and running the current release
 *  - storage            did shared storage survive the deploy
 *  - config             is the shared .env being read
 *  - scheduler          is the cron entry firing
 */
final class StatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // Dispatching on every request is what makes a stalled queue obvious.
        try {
            RecordVisit::dispatch($request->path(), Release::id());
            $queueDispatch = 'ok';
        } catch (Throwable $e) {
            $queueDispatch = 'failed: '.$e->getMessage();
        }

        return response()->json([
            'app' => config('app.name'),
            'environment' => config('app.env'),

            'release' => [
                'id' => Release::id(),
                'commit' => Release::commit(),
                'deployed_at' => Release::deployedAt(),
                'is_deployed' => Release::isDeployed(),
            ],

            'checks' => [
                'database' => $this->database(),
                'queue' => $this->queue($queueDispatch),
                'storage' => $this->storage(),
                'config' => $this->config(),
                'scheduler' => $this->scheduler(),
            ],

            'served_at' => now()->toIso8601String(),
        ], options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Liveness. Deliberately does nothing but confirm PHP is answering.
     *
     * A health check that touches the database fails during a database
     * restart and takes the node out of the load balancer for a reason that
     * has nothing to do with the node.
     */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'release' => Release::id()]);
    }

    /**
     * Readiness. This one may touch dependencies, because the question is
     * whether the node should receive traffic.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'storage' => $this->storage(),
        ];

        $ready = collect($checks)->every(fn (array $c) => ($c['ok'] ?? false) === true);

        return response()->json(
            ['ready' => $ready, 'release' => Release::id(), 'checks' => $checks],
            $ready ? 200 : 503
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            $total = DB::table('visits')->count();
            $recent = DB::table('visits')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->count();

            return [
                'ok' => true,
                'driver' => config('database.default'),
                'visits_total' => $total,
                'visits_last_5_min' => $recent,
            ];
        } catch (Throwable $e) {
            // Most likely cause: migrations did not run.
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(string $dispatch): array
    {
        try {
            $last = DB::table('visits')->orderByDesc('id')->first();

            // A worker still running the previous release means queue:restart
            // was not issued, or supervisor did not pick it up.
            $workerRelease = $last->release ?? null;
            $stale = $workerRelease !== null
                && Release::isDeployed()
                && $workerRelease !== Release::id();

            return [
                'ok' => $dispatch === 'ok',
                'connection' => config('queue.default'),
                'dispatch' => $dispatch,
                'last_processed_at' => $last->created_at ?? null,
                'worker_release' => $workerRelease,
                'worker_running_stale_release' => $stale,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Shared storage must survive a deploy. If it does not, user uploads
     * disappear on every release, which is the single most expensive way to
     * get the shared-path configuration wrong.
     *
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        try {
            $marker = 'deploy-marker.txt';

            if (! Storage::disk('local')->exists($marker)) {
                Storage::disk('local')->put($marker, json_encode([
                    'first_written_by_release' => Release::id(),
                    'at' => now()->toIso8601String(),
                ]));
            }

            $contents = json_decode((string) Storage::disk('local')->get($marker), true);

            return [
                'ok' => true,
                'marker_written_by_release' => $contents['first_written_by_release'] ?? null,
                'marker_written_at' => $contents['at'] ?? null,
                // If this says true after a second deploy, storage is NOT
                // shared and uploads are being lost.
                'survived_a_deploy' => Release::isDeployed()
                    && ($contents['first_written_by_release'] ?? null) !== Release::id(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        // Set in the shared .env by the platform. Proves the environment file
        // is linked and readable, without exposing anything sensitive.
        //
        // Read through config, never env(): once config is cached, which
        // every production deploy does, env() returns null everywhere
        // outside the config files, and this check failed on a perfectly
        // linked .env.
        $marker = config('app.deploy_marker');

        return [
            'ok' => $marker !== null,
            'deploy_marker' => $marker,
            'config_cached' => app()->configurationIsCached(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        $path = storage_path('app/scheduler-heartbeat.txt');

        if (! is_file($path)) {
            return ['ok' => false, 'note' => 'The scheduled task has not run yet.'];
        }

        $at = trim((string) file_get_contents($path));
        $age = now()->diffInSeconds(\Carbon\CarbonImmutable::parse($at), absolute: true);

        return [
            'ok' => $age < 300,
            'last_run_at' => $at,
            'seconds_ago' => $age,
        ];
    }
}
