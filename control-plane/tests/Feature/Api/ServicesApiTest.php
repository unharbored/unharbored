<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Lib\Api\ApiTokens;
use App\Lib\Audit\Actor;
use App\Lib\Auth\Projects;
use App\Lib\Services\Services;
use App\Lib\Vault\Vault;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Services\ServiceTestCase;

/** GET and POST /api/v1/services: read for the list, deploy to start, stop or restart, and never a secret. */
final class ServicesApiTest extends ServiceTestCase
{
    private int $mine;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->insertOrIgnore(['id' => 'org-2', 'name' => 'Globex', 'created_at' => now(), 'updated_at' => now()]);
        $this->node('web-2.org-2', 'org-2');
        $this->mine = $this->install()['service_id'];
        $this->install('rustfs', $this->rustfs('theirs'), 'web-2.org-2', 'org-2');
    }

    private function bearer(string $scope, string $org = 'org-1'): array
    {
        return ['Authorization' => 'Bearer '.$this->app->make(ApiTokens::class)->issue($org, "t {$scope}", $scope, 'me')['token']];
    }

    public function test_a_token_is_needed_and_read_lists_only_the_teams_services(): void
    {
        $this->getJson('/api/v1/services')->assertUnauthorized();
        $r = $this->getJson('/api/v1/services', $this->bearer('read'))->assertOk();
        $this->assertCount(1, $r->json('services'));
        $s = $r->json('services.0');
        $this->assertSame(['id', 'name', 'template', 'version', 'server', 'project', 'status', 'error', 'runtime', 'endpoints', 'update', 'operation'], array_keys($s));
        $this->assertSame(['files', 'rustfs', 1, 'web-1.org-1', 'default', 'running', null], [$s['name'], $s['template'], $s['version'], $s['server'], $s['project'], $s['status'], $s['operation']]);
        $this->assertEqualsCanonicalizing([['domain' => 'files-s3.example.com', 'tls' => true], ['domain' => 'files-ui.example.com', 'tls' => true]], $s['endpoints']);
    }

    public function test_the_project_filter_works_and_an_unknown_slug_is_404(): void
    {
        $client = Projects::create('org-1', 'Client A', Actor::user('1', 'a@example.com'));
        $this->getJson('/api/v1/services?project=client-a', $this->bearer('read'))->assertOk()->assertJsonCount(0, 'services');
        DB::table('services')->where('id', $this->mine)->update(['project_id' => $client]);
        $this->getJson('/api/v1/services?project=client-a', $this->bearer('read'))->assertOk()->assertJsonCount(1, 'services')->assertJsonPath('services.0.project', 'client-a');
        $this->getJson('/api/v1/services?project=default', $this->bearer('read'))->assertOk()->assertJsonCount(0, 'services');
        $this->getJson('/api/v1/services?project=nope', $this->bearer('read'))->assertNotFound();
    }

    public function test_one_service_by_id_or_name_and_another_teams_is_not_found(): void
    {
        $this->getJson("/api/v1/services/{$this->mine}", $this->bearer('read'))->assertOk()->assertJsonPath('name', 'files')->assertJsonStructure(['recent_operations' => [['id', 'kind', 'status']]]);
        $this->getJson('/api/v1/services/files', $this->bearer('read'))->assertOk()->assertJsonPath('id', $this->mine);
        $theirs = (int) DB::table('services')->where('name', 'theirs')->value('id');
        $this->getJson("/api/v1/services/{$theirs}", $this->bearer('read'))->assertNotFound();
        $this->getJson('/api/v1/services/theirs', $this->bearer('read'))->assertNotFound();
        $this->postJson("/api/v1/services/{$theirs}/stop", [], $this->bearer('deploy'))->assertNotFound();
        $this->assertSame(0, DB::table('operations')->where('kind', 'template_service_action')->count());
    }

    public function test_actions_need_the_deploy_scope_and_return_an_operation(): void
    {
        $this->postJson('/api/v1/services/files/restart', [], $this->bearer('read'))->assertForbidden();
        $r = $this->postJson('/api/v1/services/files/restart', [], $this->bearer('deploy'))->assertStatus(202)->assertJsonStructure(['operation', 'follow', 'url']);
        $op = DB::table('operations')->find($r->json('operation'));
        $this->assertSame(['template_service_action', 'api', $this->mine], [$op->kind, $op->trigger, json_decode($op->meta, true)['service_id']]);
        $this->assertSame('restart', json_decode($op->meta, true)['action']);
        $this->assertStringStartsWith('token ', $op->actor);

        // The running operation shows on the service; another action is refused with the reason.
        $this->getJson('/api/v1/services/files', $this->bearer('read'))->assertJsonPath('operation.id', $op->id);
        $this->postJson('/api/v1/services/files/stop', [], $this->bearer('manage'))->assertStatus(409)->assertJsonPath('error', fn ($e) => str_contains($e, 'Another operation'));
        $this->postJson('/api/v1/services/files/explode', [], $this->bearer('deploy'))->assertNotFound();
    }

    public function test_no_response_contains_a_secret(): void
    {
        $vault = $this->app->make(Vault::class);
        $values = [$vault->get('org-1', Services::secretName($this->mine, 'access_key')), $vault->get('org-1', Services::secretName($this->mine, 'secret_key'))];
        $this->assertNotContains(null, $values);
        $bodies = [
            $this->getJson('/api/v1/services', $this->bearer('manage'))->getContent(),
            $this->getJson('/api/v1/services/files', $this->bearer('manage'))->getContent(),
            $this->postJson('/api/v1/services/files/stop', [], $this->bearer('manage'))->getContent(),
        ];
        foreach ($bodies as $body) {
            foreach ($values as $v) {
                $this->assertStringNotContainsString($v, $body);
            }
            $this->assertStringNotContainsString('vault', $body);
            $this->assertStringNotContainsString('service:', $body);
        }
    }
}
