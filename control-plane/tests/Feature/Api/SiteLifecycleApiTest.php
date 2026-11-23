<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Lib\Api\ApiTokens;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** An application is made and deleted over the API, and so is the server it was on: what a live test needs to clean up after itself. */
final class SiteLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'cloud.digitalocean.token', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'provider' => 'digitalocean', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'external_id' => 'x-web-1', 'state' => 'active',
            'desired_state' => 'present', 'attributes' => json_encode(['ipv4' => '203.0.113.7']), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function as(string $scope): array
    {
        return ['Authorization' => 'Bearer '.$this->app->make(ApiTokens::class)->issue('org-1', "test {$scope}", $scope, 'me')['token']];
    }

    private function body(): array
    {
        return ['name' => 'docs', 'repository' => 'acme/docs', 'branch' => 'main', 'runtime' => 'static', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1']];
    }

    public function test_an_application_is_made_with_a_manage_token_and_a_bad_answer_names_its_field(): void
    {
        $this->postJson('/api/v1/sites', $this->body(), $this->as('deploy'))->assertForbidden();

        $response = $this->postJson('/api/v1/sites', $this->body(), $this->as('manage'))->assertStatus(202)->assertJsonPath('site', 'docs');
        $this->assertSame('static', DB::table('sites')->where('name', 'docs')->value('runtime'));
        $this->assertSame('token test manage', DB::table('operations')->where('id', $response->json('operation'))->value('actor'));

        $this->postJson('/api/v1/sites', ['name' => 'Bad Name!'] + $this->body(), $this->as('manage'))->assertStatus(422)->assertJsonPath('field', 'name');
    }

    public function test_deleting_an_application_needs_manage_and_a_server_goes_once_nothing_is_on_it(): void
    {
        $this->postJson('/api/v1/sites', $this->body(), $this->as('manage'))->assertStatus(202);
        DB::table('operations')->update(['status' => 'succeeded']);

        $this->deleteJson('/api/v1/sites/docs', [], $this->as('deploy'))->assertForbidden();
        $this->deleteJson('/api/v1/servers/web-1.org-1', [], $this->as('manage'))->assertStatus(422);

        $response = $this->deleteJson('/api/v1/sites/docs?data=1', [], $this->as('manage'))->assertStatus(202);
        $this->assertTrue(json_decode((string) DB::table('operations')->where('id', $response->json('operation'))->value('meta'), true)['delete_data']);

        DB::table('sites')->where('name', 'docs')->delete();
        DB::table('operations')->update(['status' => 'succeeded']);
        $this->deleteJson('/api/v1/servers/web-1.org-1', [], $this->as('manage'))->assertStatus(202);
        $this->deleteJson('/api/v1/servers/nope.org-1', [], $this->as('manage'))->assertNotFound();
    }
}
