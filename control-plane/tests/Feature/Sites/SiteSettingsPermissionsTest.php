<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every action on an application's Settings page names the permission it needs, and the role table decides
 * before the application is even looked up.
 */
final class SiteSettingsPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** Lookups a Viewer may make: they only read. */
    private const READS = ['site.settings.jobs.output', 'site.settings.jobs.next', 'site.settings.script.version'];

    /** What a Developer may not change: infrastructure, and creating or deleting applications. */
    private const MANAGER_ONLY = [
        'site.settings.database.attach', 'site.settings.database.detach', 'site.settings.storage', 'site.settings.storage.existing', 'site.settings.clones.sql', 'site.settings.clones.create', 'site.settings.clones.drop',
        'site.settings.servers.add', 'site.settings.servers.new', 'site.settings.servers.remove', 'site.settings.balancer', 'site.settings.pool', 'site.settings.pool.remove',
        'site.settings.project', 'site.settings.delete',
    ];

    private function member(string $role): User
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    /** @return list<array{string, string}> route name, URL with every parameter set to 1 */
    private function actions(): array
    {
        $actions = [];
        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (str_starts_with($name, 'site.settings.') && in_array('POST', $route->methods(), true)) {
                $actions[] = [$name, '/'.preg_replace('/\{\w+\}/', '1', $route->uri())];
            }
        }

        return $actions;
    }

    public function test_the_actions_are_all_found(): void
    {
        $this->assertGreaterThan(50, count($this->actions()));
    }

    public function test_a_viewer_can_read_but_no_action_changes_anything(): void
    {
        $this->actingAs($this->member('viewer'));

        foreach ($this->actions() as [$name, $url]) {
            if (in_array($name, self::READS, true)) {
                $this->assertNotSame(403, $this->postJson($url)->status(), "{$name} is a read");

                continue;
            }
            $this->assertSame(403, $this->postJson($url)->status(), "{$name} should be refused to a Viewer");
        }
    }

    public function test_a_developer_cannot_change_infrastructure_or_create_and_delete(): void
    {
        $this->actingAs($this->member('developer'));

        foreach ($this->actions() as [$name, $url]) {
            if (in_array($name, self::MANAGER_ONLY, true)) {
                $this->assertSame(403, $this->postJson($url)->status(), "{$name} should be refused to a Developer");
            }
        }
    }

    public function test_a_developer_edits_and_deploys_but_finds_nothing_at_an_unknown_site(): void
    {
        $this->actingAs($this->member('developer'));

        foreach (['workers' => ['workers' => 2], 'deploy' => [], 'push' => ['on' => true]] as $path => $data) {
            $this->postJson("/site/settings/999/{$path}", $data)->assertNotFound();
        }
    }
}
