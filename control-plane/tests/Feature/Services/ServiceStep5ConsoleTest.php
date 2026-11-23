<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Api\ApiTokens;
use App\Lib\Services\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Step 5 in the console and the API: the update panel, the backups list, the buttons per role, and the reserved port for services. */
final class ServiceStep5ConsoleTest extends ServiceTestCase
{
    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->node('web-2.org-2', 'org-2');
        $this->execOutput = fn ($n) => $n === 'stat' ? '3145728' : '';
        $this->id = $this->install()['service_id'];
        $this->operate($this->services()->backup('org-1', 'web-1.org-1', 'files', 'manual', 'm@example.com', 'manager', dispatch: false));
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function newer(array $extra = []): void
    {
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + $extra);
    }

    private function hostChange(): array
    {
        return ['https://github.com/rustfs/rustfs/releases/download/1.0.20/' => 'https://downloads.example.net/rustfs/'];
    }

    // ---- the service page ---------------------------------------------------------------

    public function test_the_services_list_shows_an_update_badge(): void
    {
        $this->member('viewer');
        $this->assertFalse((bool) ($this->get('/services')->inertiaProps('services')[0]['update']['allowed'] ?? false));
        $this->newer();
        $update = $this->get('/services')->inertiaProps('services')[0]['update'];
        $this->assertTrue($update['allowed']);
        $this->assertSame('2', (string) $update['version']);
    }

    // ---- the buttons -----------------------------------------------------------------------

    // ---- the API ---------------------------------------------------------------------------------

    private function bearer(string $scope, string $org = 'org-1'): array
    {
        return ['Authorization' => 'Bearer '.$this->app->make(ApiTokens::class)->issue($org, "t {$scope}", $scope, 'me')['token']];
    }

    public function test_the_api_lists_backups_with_no_secrets_and_only_the_teams_own(): void
    {
        $r = $this->getJson("/api/v1/services/{$this->id}/backups", $this->bearer('read'))->assertOk();
        $this->assertCount(1, $r->json('backups'));
        $this->assertSame(['id', 'name', 'kind', 'bytes', 'where', 'created_at'], array_keys($r->json('backups.0')));
        $this->assertSame(['manual', 3145728, 'this server only'], [$r->json('backups.0.kind'), $r->json('backups.0.bytes'), $r->json('backups.0.where')]);
        $this->getJson("/api/v1/services/{$this->id}/backups")->assertUnauthorized();
        $this->getJson("/api/v1/services/{$this->id}/backups", $this->bearer('read', 'org-2'))->assertNotFound();
        $this->assertStringNotContainsString('object', json_encode($r->json()));
    }

    public function test_the_api_backs_up_with_the_manage_scope_only(): void
    {
        $this->postJson("/api/v1/services/{$this->id}/backup", [], $this->bearer('deploy'))->assertForbidden();
        $this->postJson("/api/v1/services/{$this->id}/backup", [], $this->bearer('manage', 'org-2'))->assertNotFound();
        $r = $this->postJson("/api/v1/services/{$this->id}/backup", [], $this->bearer('manage'))->assertStatus(202);
        $op = DB::table('operations')->find($r->json('operation'));
        $this->assertSame(['template_service_backup', 'api', 'manual'], [$op->kind, $op->trigger, json_decode($op->meta, true)['kind']]);
        $this->postJson("/api/v1/services/{$this->id}/backup", [], $this->bearer('manage'))->assertStatus(409);
    }

    public function test_the_api_update_needs_confirm_true_when_the_host_changes(): void
    {
        $this->newer($this->hostChange());
        $this->getJson("/api/v1/services/{$this->id}", $this->bearer('read'))->assertOk()->assertJsonPath('update.version', 2)->assertJsonPath('update.confirm', true);
        $this->postJson("/api/v1/services/{$this->id}/update", [], $this->bearer('deploy'))->assertForbidden();
        $this->postJson("/api/v1/services/{$this->id}/update", [], $this->bearer('manage', 'org-2'))->assertNotFound();
        $this->postJson("/api/v1/services/{$this->id}/update", [], $this->bearer('manage'))->assertStatus(409)->assertSee('Confirm it explicitly');
        $this->assertSame(0, DB::table('operations')->where('kind', 'template_service_update')->count());

        $r = $this->postJson("/api/v1/services/{$this->id}/update", ['confirm' => true], $this->bearer('manage'))->assertStatus(202);
        $this->assertSame('succeeded', $this->operate((int) $r->json('operation')));
        $this->getJson("/api/v1/services/{$this->id}", $this->bearer('read'))->assertJsonPath('version', 2)->assertJsonPath('update', null);
    }

    public function test_the_api_update_refuses_a_missing_input_and_nothing_newer(): void
    {
        $this->postJson("/api/v1/services/{$this->id}/update", [], $this->bearer('manage'))->assertStatus(409)->assertSee('no newer version');
        $this->newer(['  - { key: backup,' => "  - { key: region, type: text, label: Region }\n  - { key: backup,", '    RUSTFS_SECRET_KEY: "{{ secrets.secret_key }}"' => "    RUSTFS_SECRET_KEY: \"{{ secrets.secret_key }}\"\n    REGION: \"{{ inputs.region }}\""]);
        $this->postJson("/api/v1/services/{$this->id}/update", [], $this->bearer('manage'))->assertStatus(409)->assertSee('region is required');
        $this->postJson("/api/v1/services/{$this->id}/update", ['inputs' => 'eu'], $this->bearer('manage'))->assertStatus(422);
        $this->postJson("/api/v1/services/{$this->id}/update", ['inputs' => ['region' => 'eu']], $this->bearer('manage'))->assertStatus(202);
    }

    // ---- the reserved band for native services ----------------------------------------------------

    public function test_a_native_service_may_not_ask_for_a_port_in_the_container_band(): void
    {
        $this->publish('rustfs', 1, ['9000: "{{ inputs.s3_host }}"' => '23500: "{{ inputs.s3_host }}"', 'health: { port: 9000 }' => 'health: { port: 23500 }']);
        $this->node('web-9.org-1', 'org-1');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not 20000 to 23999');
        $this->services()->install('org-1', 'web-9.org-1', 'rustfs', $this->rustfs('band'), 'm@example.com', 'manager', acknowledged: true, dispatch: false);
    }
}
