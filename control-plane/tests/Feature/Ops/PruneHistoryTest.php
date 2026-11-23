<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PruneHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function operation(string $status, int $daysAgo): int
    {
        $at = now()->subDays($daysAgo);

        return DB::table('operations')->insertGetId(['organization_id' => 'org-1', 'kind' => 'deploy', 'title' => 't', 'trigger' => 'console', 'actor' => 'a',
            'status' => $status, 'stages' => '[]', 'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? $at : null, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function alert(?int $resolvedDaysAgo): void
    {
        DB::table('alerts')->insert(['organization_id' => 'org-1', 'key' => 'k', 'severity' => 'warn', 'title' => 't', 'opened_at' => now()->subDays(200),
            'resolved_at' => $resolvedDaysAgo === null ? null : now()->subDays($resolvedDaysAgo)]);
    }

    private function consoleSession(string $id, ?int $closedDaysAgo): void
    {
        DB::table('console_sessions')->insert(['id' => $id, 'organization_id' => 'org-1', 'node_id' => 'n', 'as_user' => 'u', 'opened_by_id' => '1', 'opened_by_name' => 'm',
            'opened_at' => now()->subDays(200), 'closed_at' => $closedDaysAgo === null ? null : now()->subDays($closedDaysAgo)]);
    }

    public function test_old_finished_rows_go_and_everything_else_stays(): void
    {
        $this->operation('succeeded', 100);
        $this->operation('failed', 100);
        $this->operation('succeeded', 10);
        $this->operation('queued', 100);
        $this->operation('running', 100);
        $this->alert(100);
        $this->alert(10);
        $this->alert(null);
        $this->consoleSession('old', 100);
        $this->consoleSession('recent', 10);
        $this->consoleSession('open', null);

        $this->artisan('history:prune')->assertSuccessful();

        $this->assertSame(['queued', 'running', 'succeeded'], DB::table('operations')->orderBy('status')->pluck('status')->all());
        $this->assertSame(2, DB::table('alerts')->count());
        $this->assertSame(1, DB::table('alerts')->whereNull('resolved_at')->count());
        $this->assertEqualsCanonicalizing(['recent', 'open'], DB::table('console_sessions')->pluck('id')->all());
    }

    public function test_the_audit_log_is_never_touched(): void
    {
        (new AuditLog())->record('org-1', Actor::system('t'), 'thing.happened');
        DB::statement('DROP TRIGGER audit_records_no_update');
        DB::table('audit_records')->update(['recorded_at' => now()->subDays(1000)]);

        $this->artisan('history:prune', ['--days' => 1])->assertSuccessful();

        $this->assertSame(1, DB::table('audit_records')->count());
    }

    public function test_days_sets_the_cutoff(): void
    {
        $this->operation('succeeded', 40);
        $this->operation('succeeded', 20);

        $this->artisan('history:prune', ['--days' => 30])->assertSuccessful();

        $this->assertSame(1, DB::table('operations')->count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->operation('succeeded', 100);
        $this->alert(100);

        $this->artisan('history:prune', ['--dry-run' => true])->expectsOutputToContain('would delete 1 operations, 1 alerts')->assertSuccessful();

        $this->assertSame(1, DB::table('operations')->count());
        $this->assertSame(1, DB::table('alerts')->count());
    }

    public function test_more_rows_than_one_chunk_all_go(): void
    {
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = ['organization_id' => 'org-1', 'key' => 'k', 'severity' => 'warn', 'title' => 't', 'opened_at' => now()->subDays(200), 'resolved_at' => now()->subDays(100)];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('alerts')->insert($chunk);
        }
        $this->alert(null);

        $this->artisan('history:prune')->expectsOutputToContain('2500 alerts')->assertSuccessful();

        $this->assertSame(1, DB::table('alerts')->count());
    }
}
