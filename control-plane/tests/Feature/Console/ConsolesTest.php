<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Lib\Audit\AuditLog;
use App\Lib\Console\ConsoleOutput;
use App\Lib\Console\Consoles;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A shell in the browser: who may open one, who may use it, what is kept.
 */
final class ConsolesTest extends TestCase
{
    use RefreshDatabase;

    private object $site;

    private User $user;

    private bool $offline = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        $this->user = User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']);
        $this->actingAs($this->user);

        $sites = new Sites($this->app->make(Vault::class), new AuditLog());
        $this->site = $sites->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git');
        DB::table('nodes')->updateOrInsert(['node_id' => 'web-1.org-1'], ['organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->updateOrInsert(['id' => 'org-1'], ['name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);

        Http::fake(fn ($r) => str_contains($r->url(), '/terminal')
            ? ($this->offline ? Http::response(['error' => 'not connected'], 409) : Http::response(['sent' => true]))
            : Http::response(['nodes' => [['node_id' => 'web-1.org-1']]]));
    }

    private function enable(): void
    {
        DB::table('organizations')->where('id', 'org-1')->update(['console_enabled' => true]);
    }

    private function open(array $over = [])
    {
        return $this->postJson('/console/open', $over + ['site' => $this->site->id, 'node' => 'web-1.org-1', 'as_user' => 'deploy']);
    }

    public function test_it_is_off_until_the_organisation_turns_it_on(): void
    {
        $this->open()->assertStatus(422)->assertJsonPath('error', fn ($m) => str_contains($m, 'off'));
        $this->assertSame(0, DB::table('console_sessions')->count());

        $this->post('/account/consoles', ['enabled' => '1']);
        $this->open()->assertOk();
    }

    public function test_opening_records_who_where_and_as_whom_and_is_audited(): void
    {
        $this->enable();
        $id = $this->open(['as_user' => 'root'])->json('session');

        $row = DB::table('console_sessions')->where('id', $id)->first();
        $this->assertSame('web-1.org-1', $row->node_id);
        $this->assertSame('root', $row->as_user);
        $this->assertSame('Matt', $row->opened_by_name);
        $this->assertNull($row->closed_at);
        $this->assertTrue(DB::table('audit_records')->where('action', 'console.opened')->exists());
    }

    public function test_it_will_not_open_on_a_server_the_application_does_not_run_on_or_as_someone_else(): void
    {
        $this->enable();
        $this->open(['node' => 'other.org-1'])->assertStatus(422);
        $this->open(['as_user' => 'postgres'])->assertStatus(422);
        $this->assertSame(0, DB::table('console_sessions')->count());
    }

    public function test_a_server_with_no_application_can_still_have_a_console(): void
    {
        $this->enable();
        DB::table('nodes')->insert(['node_id' => 'bare-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->postJson('/console/open', ['node' => 'bare-1.org-1', 'as_user' => 'deploy'])->assertOk()->json('session');

        $row = DB::table('console_sessions')->where('id', $id)->first();
        $this->assertNull($row->site_id);
        $this->assertSame('bare-1.org-1', $row->node_id);
        $this->assertTrue(DB::table('audit_records')->where('action', 'console.opened')->where('resource_id', 'bare-1.org-1')->exists());
    }

    public function test_without_an_application_it_still_refuses_another_teams_server(): void
    {
        $this->enable();
        DB::table('nodes')->insert(['node_id' => 'theirs.org-2', 'organization_id' => 'org-2', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/console/open', ['node' => 'theirs.org-2', 'as_user' => 'deploy'])->assertStatus(422);
        $this->postJson('/console/open', ['node' => 'missing.org-1', 'as_user' => 'deploy'])->assertStatus(422);
        $this->assertSame(0, DB::table('console_sessions')->count());
    }

    public function test_the_shell_starts_only_when_asked_so_the_page_can_listen_first(): void
    {
        $this->enable();
        $id = $this->open()->json('session');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/terminal'));

        $this->postJson("/console/{$id}/start", ['cols' => 100, 'rows' => 30])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/nodes/web-1.org-1/terminal')
            && $r['op'] === 'open' && $r['user'] === 'deploy' && $r['session'] === $id && $r['cols'] === 100);
    }

    public function test_what_is_typed_goes_to_the_server_and_is_not_kept(): void
    {
        $this->enable();
        $id = $this->open()->json('session');

        $this->postJson("/console/{$id}/input", ['data' => base64_encode("ls\n")])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/terminal') && ($r['op'] ?? '') === 'input' && $r['data'] === base64_encode("ls\n"));
        $this->assertStringNotContainsString(base64_encode("ls\n"), json_encode(DB::table('console_sessions')->get()).json_encode(DB::table('audit_records')->get()));
    }

    public function test_only_the_person_who_opened_it_can_use_or_listen(): void
    {
        $this->enable();
        $id = $this->open()->json('session');

        $this->assertTrue(Consoles::belongsTo($id, (string) $this->user->id));

        $other = User::forceCreate(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'correct horse battery']);
        $this->assertFalse(Consoles::belongsTo($id, (string) $other->id));
        $this->actingAs($other);
        $this->postJson("/console/{$id}/input", ['data' => 'eA=='])->assertNotFound();
        $this->postJson("/console/{$id}/close")->assertNotFound();
    }

    public function test_output_is_broadcast_to_that_console_and_the_exit_ends_it(): void
    {
        $this->enable();
        $id = $this->open()->json('session');
        Event::fake([ConsoleOutput::class]);

        app(Consoles::class)->output(['session' => $id, 'seq' => 1, 'data' => base64_encode('$ ')]);
        Event::assertDispatched(ConsoleOutput::class, fn ($e) => $e->session === $id && $e->payload['data'] === base64_encode('$ '));
        $this->assertNull(DB::table('console_sessions')->where('id', $id)->value('closed_at'));

        app(Consoles::class)->output(['session' => $id, 'seq' => 2, 'exit' => 0]);
        $row = DB::table('console_sessions')->where('id', $id)->first();
        $this->assertNotNull($row->closed_at);
        $this->assertSame('exited', $row->ended_because);
        $this->assertSame(0, (int) $row->exit_code);
    }

    public function test_output_for_an_unknown_console_is_ignored(): void
    {
        Event::fake([ConsoleOutput::class]);
        app(Consoles::class)->output(['session' => 'nope', 'seq' => 1, 'data' => 'eA==']);
        Event::assertNotDispatched(ConsoleOutput::class);
    }

    public function test_an_ended_console_refuses_more_input(): void
    {
        $this->enable();
        $id = $this->open()->json('session');
        $this->postJson("/console/{$id}/close")->assertOk();

        $this->postJson("/console/{$id}/input", ['data' => 'eA=='])->assertStatus(410);
        $this->assertTrue(DB::table('audit_records')->where('action', 'console.closed')->exists());
    }

    public function test_a_server_that_is_not_connected_says_so_and_ends_the_console(): void
    {
        $this->enable();
        $id = $this->open()->json('session');
        $this->offline = true;

        $this->postJson("/console/{$id}/start", ['cols' => 80, 'rows' => 24])->assertStatus(422);
        $this->assertNotNull(DB::table('console_sessions')->where('id', $id)->value('closed_at'));
    }

    public function test_the_commands_page_lists_consoles_and_offers_to_open_one(): void
    {
        $this->get("/site/console?site={$this->site->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SiteConsole')->where('enabled', false)->where('sessions', [])->where('site.id', $this->site->id)->etc());

        $this->enable();
        $id = $this->open()->json('session');
        $this->get("/site/console?site={$this->site->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('enabled', true)
            ->has('sessions', 1, fn (Assert $s) => $s->where('id', $id)->where('open', true)->etc())
            ->has('servers')->etc());
    }
}
