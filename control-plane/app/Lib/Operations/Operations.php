<?php

declare(strict_types=1);

namespace App\Lib\Operations;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The record of a long action: its stages, its log, how it ended.
 *
 * One writer per operation (the job running it), so stages and log are
 * read, changed and written back without locking.
 */
final class Operations
{
    /** Longest log kept per operation; earlier lines are dropped first. */
    private const LOG_LIMIT = 200_000;

    /**
     * @param  list<string>  $stages
     * @param  array<string, mixed>  $meta
     */
    public function create(
        string $organizationId,
        string $kind,
        string $title,
        string $trigger,
        string $actor,
        array $stages,
        ?int $siteId = null,
        ?string $nodeId = null,
        ?string $releaseId = null,
        ?string $commit = null,
        array $meta = [],
    ): int {
        $id = DB::table('operations')->insertGetId([
            'organization_id' => $organizationId,
            'kind' => $kind,
            'title' => $title,
            'trigger' => $trigger,
            'actor' => $actor,
            'site_id' => $siteId,
            'node_id' => $nodeId,
            'status' => 'queued',
            'stages' => json_encode(array_map(fn ($n) => ['name' => $n, 'status' => 'waiting', 'started_at' => null, 'finished_at' => null], $stages)),
            'log' => '',
            'release_id' => $releaseId,
            'commit' => $commit,
            'meta' => $meta === [] ? null : json_encode($meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public function find(int $id): ?stdClass
    {
        return DB::table('operations')->where('id', $id)->first();
    }

    /** Idempotent: a waiting operation is started again on each check. */
    public function start(int $id): void
    {
        DB::table('operations')->where('id', $id)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
    }

    /** When a stage started, if it has. */
    public function stageStartedAt(int $id, string $name): ?string
    {
        foreach ($this->stages($id) as $stage) {
            if ($stage['name'] === $name) {
                return $stage['started_at'];
            }
        }

        return null;
    }

    /** The stage currently running, if any. */
    public function currentStage(int $id): ?string
    {
        foreach ($this->stages($id) as $stage) {
            if ($stage['status'] === 'running') {
                return $stage['name'];
            }
        }

        return null;
    }

    /**
     * Moves to a stage: the running one finishes, this one starts. A stage
     * not declared up front is added, so the record never loses one.
     */
    public function stage(int $id, string $name): void
    {
        if ($this->currentStage($id) === $name) {
            return;
        }
        $stages = $this->stages($id);
        $now = now()->toIso8601String();
        $found = false;

        foreach ($stages as &$stage) {
            if ($stage['status'] === 'running') {
                $stage['status'] = 'done';
                $stage['finished_at'] = $now;
            }
            if ($stage['name'] === $name) {
                $stage['status'] = 'running';
                $stage['started_at'] = $now;
                $found = true;
            }
        }
        unset($stage);

        if (! $found) {
            $stages[] = ['name' => $name, 'status' => 'running', 'started_at' => $now, 'finished_at' => null];
        }

        $this->update($id, ['stages' => json_encode($stages)]);
    }

    /** A failed operation queued again, to carry on where it stopped. */
    public function reopen(int $id): void
    {
        $this->update($id, ['status' => 'queued', 'error' => null, 'finished_at' => null]);
    }

    /** @param array<string, mixed> $values Merged into the operation's meta. */
    public function mergeMeta(int $id, array $values): void
    {
        $meta = json_decode((string) ($this->find($id)->meta ?? ''), true) ?: [];
        $this->update($id, ['meta' => json_encode(array_replace($meta, $values))]);
    }

    /** One log line: time, the running stage, what happened. */
    public function log(int $id, string $action, string $text): void
    {
        $operation = $this->find($id);
        if ($operation === null) {
            return;
        }

        $stage = '';
        foreach (json_decode($operation->stages, true) as $s) {
            if ($s['status'] === 'running') {
                $stage = $s['name'];
            }
        }

        $line = sprintf("%s  %-8s %-7s %s\n", now()->format('H:i:s'), $stage, $action, str_replace("\n", ' ', $text));
        $log = $operation->log.$line;
        if (strlen($log) > self::LOG_LIMIT) {
            $log = "…\n".substr($log, -self::LOG_LIMIT);
        }

        $this->update($id, ['log' => $log]);
    }

    public function succeed(int $id, array $fields = []): void
    {
        $this->finish($id, 'succeeded', null, $fields);
    }

    public function fail(int $id, string $error): void
    {
        $this->finish($id, 'failed', $error);
    }

    /** @param array<string, mixed> $fields */
    private function finish(int $id, string $status, ?string $error, array $fields = []): void
    {
        $stages = $this->stages($id);
        $now = now()->toIso8601String();
        foreach ($stages as &$stage) {
            if ($stage['status'] === 'running') {
                $stage['status'] = $status === 'succeeded' ? 'done' : 'failed';
                $stage['finished_at'] = $now;
            }
        }
        unset($stage);

        $this->update($id, $fields + [
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
            'stages' => json_encode($stages),
            'finished_at' => now(),
        ]);
    }

    /** @return list<array{name: string, status: string, started_at: ?string, finished_at: ?string}> */
    private function stages(int $id): array
    {
        return json_decode((string) DB::table('operations')->where('id', $id)->value('stages'), true) ?: [];
    }

    /** @param array<string, mixed> $fields */
    private function update(int $id, array $fields): void
    {
        DB::table('operations')->where('id', $id)->update($fields + ['updated_at' => now()]);

    }
}
