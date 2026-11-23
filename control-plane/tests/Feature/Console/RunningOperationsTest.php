<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Every console page carries the operations still running, so leaving an operation's page is not losing it. */
final class RunningOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function operation(string $status, string $trigger = 'console', ?string $finished = null, string $title = 'Deploy shop'): int
    {
        return DB::table('operations')->insertGetId([
            'organization_id' => 'org-1', 'kind' => 'deploy', 'title' => $title, 'trigger' => $trigger, 'actor' => 'dev@example.com', 'status' => $status,
            'stages' => json_encode([['name' => 'build', 'status' => $status === 'running' ? 'running' : 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => null]]),
            'finished_at' => $finished, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_running_and_recently_finished_operations_are_on_every_page_but_scheduled_and_old_ones_are_not(): void
    {
        $this->actingAs(User::factory()->create());
        $running = $this->operation('running');
        $failed = $this->operation('failed', finished: now()->subMinutes(3)->toDateTimeString(), title: 'Deploy blog');
        $this->operation('succeeded', finished: now()->subMinutes(30)->toDateTimeString(), title: 'Old');
        $this->operation('running', trigger: 'schedule', title: 'Nightly');

        $this->get('/sites')->assertInertia(fn (Assert $p) => $p->has('operations', 2)
            ->where('operations.0.id', $failed)->where('operations.0.status', 'failed')
            ->where('operations.1.id', $running)->where('operations.1.stage', 'build'));
    }
}
