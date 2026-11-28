<?php

namespace Tests\Feature;

use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A member of one organisation sees nothing of another's: not in a list, and
 * not by guessing an id (docs/specs/organisations-and-roles.md).
 */
class OrganisationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $outsider;

    private object $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Http::fake(['*' => Http::response(['nodes' => [], 'connected' => false])]);

        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Acme has a server, an application and an alert.
        DB::table('nodes')->insert(['node_id' => 'acme-web.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->site = (new Sites($this->app->make(Vault::class), new AuditLog()))->create('org-1', 'acme-web.org-1', 'acmeapp', 'acmeapp.example.com', 'git@github.com:acme/app.git');

        // The outsider belongs to Globex only.
        $this->outsider = User::forceCreate(['name' => 'Gina', 'email' => 'gina@example.com', 'password' => 'correct horse battery']);
        DB::table('memberships')->insert(['user_id' => $this->outsider->id, 'organization_id' => 'org-2', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_another_organisations_servers_and_applications_are_not_listed(): void
    {
        $this->actingAs($this->outsider);

        $this->get('/servers')->assertOk()->assertDontSee('acme-web')->assertDontSee('acmeapp');
        $this->get('/sites')->assertOk()->assertDontSee('acmeapp');
        $this->get('/databases')->assertOk()->assertDontSee('acmeapp');
    }

    public function test_another_organisations_application_cannot_be_opened_by_its_id(): void
    {
        $this->actingAs($this->outsider);

        foreach (['/site', '/site/settings', '/site/deployments', '/site/logs'] as $path) {
            $this->get("{$path}?site={$this->site->id}")->assertDontSee('acmeapp', false);
        }
        $this->get("/site/console?site={$this->site->id}")->assertNotFound();
    }

    public function test_its_own_organisation_still_sees_its_things(): void
    {
        $insider = User::forceCreate(['name' => 'Al', 'email' => 'al@example.com', 'password' => 'correct horse battery']);
        DB::table('memberships')->insert(['user_id' => $insider->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($insider)->get('/sites')->assertOk()->assertSee('acmeapp');
        $this->get('/servers')->assertOk()->assertSee('acme-web');
    }

    public function test_the_connection_string_is_audited_needs_secret_access_and_stays_in_its_organisation(): void
    {
        DB::table('sites')->where('id', $this->site->id)->update(['database_cluster_id' => null]);
        (new Sites($this->app->make(Vault::class), new AuditLog()))->setEnv($this->site,
            "DB_CONNECTION=pgsql\nDB_HOST=db.example.com\nDB_PORT=25060\nDB_DATABASE=app\nDB_USERNAME=u\nDB_PASSWORD=pw\n", 'test');

        $call = fn () => $this->postJson("/site/settings/{$this->site->id}/database/connection");

        $viewer = User::forceCreate(['name' => 'Vic', 'email' => 'vic@example.com', 'password' => 'correct horse battery']);
        DB::table('memberships')->insert(['user_id' => $viewer->id, 'organization_id' => 'org-1', 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
        $dev = User::forceCreate(['name' => 'Dee', 'email' => 'dee@example.com', 'password' => 'correct horse battery']);
        DB::table('memberships')->insert(['user_id' => $dev->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);

        // A Viewer cannot see secrets; another organisation's member finds no such application.
        $this->actingAs($viewer);
        $this->assertSame(403, $call()->status());
        $this->actingAs($this->outsider);
        $this->assertSame(404, $call()->status());

        // A Developer can, and it is recorded.
        $this->actingAs($dev);
        $response = $call();
        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertSame('postgresql://u:pw@db.example.com:25060/app?sslmode=require', $response->json('url'));
        $this->assertSame(['host' => 'db.example.com', 'port' => '25060', 'database' => 'app', 'username' => 'u', 'password' => 'pw'], collect($response->json('details'))->only(['host', 'port', 'database', 'username', 'password'])->all());
        $this->assertDatabaseHas('audit_records', ['action' => 'site.database_connection_read', 'organization_id' => 'org-1']);
    }
}
