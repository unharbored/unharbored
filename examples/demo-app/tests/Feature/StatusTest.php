<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecordVisit;
use App\Support\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * This application is a deploy target, so its tests are mostly about the
 * endpoints the platform relies on rather than about business logic. If these
 * pass, the app is a usable guinea pig; whether a deploy of it worked is a
 * question for the status page, not for here.
 */
final class StatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_reports_every_check(): void
    {
        Queue::fake();

        $response = $this->getJson('/');

        $response->assertOk()
            ->assertJsonStructure([
                'app',
                'environment',
                'release' => ['id', 'commit', 'deployed_at', 'is_deployed'],
                'checks' => ['database', 'queue', 'storage', 'config', 'scheduler'],
                'served_at',
            ]);
    }

    public function test_health_touches_nothing_but_php(): void
    {
        // Deliberately no database, no queue. A health check that depends on
        // them removes a healthy node from the load balancer during a
        // database restart.
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_ready_reports_dependency_state(): void
    {
        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonStructure(['ready', 'release', 'checks' => ['database', 'storage']]);
    }

    public function test_each_request_dispatches_a_visit(): void
    {
        Queue::fake();

        $this->getJson('/');

        // Dispatching on every request is what makes a stalled queue visible
        // on the status page.
        Queue::assertPushed(RecordVisit::class);
    }

    public function test_the_visit_job_records_which_release_processed_it(): void
    {
        (new RecordVisit('/', '20261104T143022Z-abc1234'))->handle();

        $this->assertDatabaseHas('visits', [
            'path' => '/',
            'release' => '20261104T143022Z-abc1234',
        ]);
    }

    public function test_database_check_fails_loudly_when_migrations_are_missing(): void
    {
        Queue::fake();

        \Illuminate\Support\Facades\Schema::drop('visits');

        $response = $this->getJson('/');

        // The endpoint still answers, but says what is wrong. A deploy that
        // skipped migrations should be diagnosable from the status page
        // rather than from a stack trace in a log.
        $response->assertOk()->assertJsonPath('checks.database.ok', false);
    }

    public function test_release_reports_not_deployed_outside_a_release_directory(): void
    {
        // In development the app does not live in a timestamped directory,
        // and saying so is more useful than inventing a version.
        $this->assertSame('not-deployed', Release::id());
        $this->assertFalse(Release::isDeployed());
    }

    public function test_release_parses_a_deployed_directory_name(): void
    {
        // The format the platform produces: deploy.NewReleaseID in the agent.
        $this->assertMatchesRegularExpression(
            '/^\d{8}T\d{6}Z-[A-Za-z0-9._-]+$/',
            '20261104T143022Z-a1b2c3d'
        );
    }
}
