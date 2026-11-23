<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Projects;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Composer credentials given once for the team or a project reach every application's deploy. */
final class ComposerSharedTest extends TestCase
{
    use RefreshDatabase;

    private function site(): object
    {
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->actingAs(User::factory()->create());

        $site = (new Sites($this->app->make(Vault::class), new AuditLog))->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@gitlab.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['git_host_key' => 'gitlab.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']);

        return $site;
    }

    private function sent(object $site): ?array
    {
        $step = collect($this->app->make(Sites::class)->setupRecipe(DB::table('sites')->find($site->id))['steps'])->firstWhere('id', 'composer-auth');

        return $step['type'] === 'file' ? json_decode(base64_decode($step['content_b64']), true) : null;
    }

    public function test_team_credentials_are_saved_listed_without_the_password_and_sent_with_the_setup(): void
    {
        $site = $this->site();

        $this->post('/account/composer', ['scope' => 'team', 'host' => ' Nova.Laravel.com ', 'username' => 'me@example.com', 'password' => 'licence'])->assertSessionHasNoErrors();

        $this->assertSame(['http-basic' => ['nova.laravel.com' => ['username' => 'me@example.com', 'password' => 'licence']]], $this->sent($site));
        $this->get('/account?tab=composer')->assertInertia(fn (Assert $p) => $p->where('composer.scopes.0.hosts', [['host' => 'nova.laravel.com', 'username' => 'me@example.com']]));
        $this->assertStringNotContainsString('licence', json_encode(DB::table('audit_records')->get()));
        $this->get("/site/settings?site={$site->id}&tab=build")->assertInertia(fn (Assert $p) => $p->where('data.composerShared', ['team: nova.laravel.com'])->where('data.composerAuth', []));

        $this->delete('/account/composer', ['scope' => 'team', 'host' => 'nova.laravel.com']);
        $this->assertNull($this->sent($site));
    }

    public function test_the_application_wins_over_its_project_and_the_project_over_the_team(): void
    {
        $site = $this->site();
        $sites = $this->app->make(Sites::class);
        $actor = Actor::system('test');
        $sites->setSharedComposerCredential('org-1', null, 'nova.laravel.com', 'team', 'team-pw', $actor);
        $sites->setSharedComposerCredential('org-1', null, 'spark.laravel.com', 'team', 'team-spark', $actor);
        $sites->setSharedComposerCredential('org-1', (int) $site->project_id, 'nova.laravel.com', 'project', 'project-pw', $actor);
        $sites->setComposerAuth(DB::table('sites')->find($site->id), '{"http-basic": {"spark.laravel.com": {"username": "own", "password": "own-pw"}}}');

        $this->assertSame(['http-basic' => [
            'nova.laravel.com' => ['username' => 'project', 'password' => 'project-pw'],
            'spark.laravel.com' => ['username' => 'own', 'password' => 'own-pw'],
        ]], $this->sent($site));
    }

    public function test_bad_input_is_refused_and_a_foreign_project_is_not_a_scope(): void
    {
        $this->site();

        $this->post('/account/composer', ['scope' => 'team', 'host' => 'https://nova.laravel.com/', 'username' => 'a', 'password' => 'b'])->assertSessionHasErrors('composer');
        $this->post('/account/composer', ['scope' => 'team', 'host' => 'nova.laravel.com', 'username' => 'a', 'password' => ''])->assertSessionHasErrors('composer');
        $this->post('/account/composer', ['scope' => '99999', 'host' => 'nova.laravel.com', 'username' => 'a', 'password' => 'b'])->assertStatus(422);
        $this->assertSame([], $this->app->make(Sites::class)->sharedComposerHosts('org-1', null));
    }

    public function test_deleting_a_project_forgets_its_credentials(): void
    {
        $this->site();
        $project = Projects::create('org-1', 'Client', Actor::system('test'));
        $id = is_object($project) ? $project->id : (int) $project;
        $sites = $this->app->make(Sites::class);
        $sites->setSharedComposerCredential('org-1', $id, 'nova.laravel.com', 'a', 'b', Actor::system('test'));

        Projects::delete('org-1', $id, Actor::system('test'));

        $this->assertSame([], $sites->sharedComposerHosts('org-1', $id));
    }
}
