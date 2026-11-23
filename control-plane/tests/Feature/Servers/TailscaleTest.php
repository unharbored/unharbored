<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\Firewall;
use App\Lib\Servers\Tailscale;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TailscaleTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'tskey-auth-kABCDEF1CNTRL-0123456789abcdef';

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    private string $statusOutput = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->statusOutput = json_encode(['BackendState' => 'Running', 'Self' => ['Online' => true, 'DNSName' => 'web-1.tail1234.ts.net.', 'TailscaleIPs' => ['100.101.102.103', 'fd7a::1']]]);

        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            $this->calls[] = ['kind' => $r['kind'] ?? null, 'args' => $r['args'] ?? []];
            $isStatus = ($r['kind'] ?? '') === 'exec' && str_contains((string) json_encode($r['args'] ?? []), 'tailscale status');

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $isStatus ? $this->statusOutput : '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
        $this->app->forgetInstance(OperationRunner::class);
    }

    private function saveKey(): void
    {
        $this->app->make(CredentialStore::class)->connectSecret('org-1', Tailscale::PROVIDER, ['authkey' => self::KEY], 'tskey-auth-kAB…ef', 'auth_key');
    }

    private function recipes(): array
    {
        return array_values(array_filter(array_map(fn ($c) => $c['kind'] === 'apply_recipe' ? $c['args']['spec'] : null, $this->calls)));
    }

    public function test_keys_hostnames_and_status_are_read_strictly(): void
    {
        $this->assertTrue(Tailscale::validKey(self::KEY));
        foreach (['', 'tskey-client-abcdefgh12345', 'tskey-auth-short', 'password', self::KEY.' extra'] as $bad) {
            $this->assertFalse(Tailscale::validKey($bad), $bad);
        }
        $this->assertSame('web-1', Tailscale::hostname('web-1.org-1'));
        $this->assertSame('my-app-box', Tailscale::hostname('My_App Box.org-1'));

        $up = Tailscale::parseStatus($this->statusOutput);
        $this->assertSame(['online' => true, 'address' => '100.101.102.103', 'name' => 'web-1.tail1234.ts.net'], $up);
        $this->assertFalse(Tailscale::parseStatus('')['online']);
        $this->assertFalse(Tailscale::parseStatus(json_encode(['BackendState' => 'NeedsLogin']))['online']);
        $this->assertFalse(Tailscale::parseStatus(json_encode(['BackendState' => 'Running', 'Self' => ['Online' => false, 'TailscaleIPs' => ['100.1.1.1']]]))['online']);
    }

    public function test_the_auth_key_is_only_ever_in_a_private_file_never_an_argument(): void
    {
        $steps = Tailscale::joinRecipe('web-1', self::KEY)['steps'];
        $key = array_column($steps, null, 'id')['ts-key'];

        $this->assertTrue($key['sensitive']);
        $this->assertSame('0600', $key['mode']);
        $this->assertSame(self::KEY."\n", base64_decode($key['content_b64']));
        foreach ($steps as $step) {
            if ($step['id'] !== 'ts-key') {
                $this->assertStringNotContainsString(self::KEY, json_encode($step), $step['id']);
            }
        }
        $this->assertSame('absent', end($steps)['type'], 'The key file is removed at the end.');
        $this->assertContains('--authkey=file:'.Tailscale::KEY_FILE, array_column($steps, null, 'id')['ts-up']['args']);
    }

    public function test_joining_installs_joins_verifies_and_records_the_tailnet_address_and_removes_the_key_file(): void
    {
        $this->saveKey();
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueTailscaleJoin('web-1.org-1', 'console', 'me@example.com');

        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame('100.101.102.103', Tailscale::info('web-1.org-1')['address']);
        $names = array_column($this->recipes(), 'name');
        $this->assertSame(['tailscale-join', 'tailscale-cleanup'], $names);
    }

    public function test_a_failed_join_still_removes_the_key_file_and_records_nothing(): void
    {
        $this->saveKey();
        $this->statusOutput = json_encode(['BackendState' => 'NeedsLogin']);
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueTailscaleJoin('web-1.org-1', 'console', 'me@example.com');

        $this->assertSame('failed', $runner->run($id));
        $this->assertNull(Tailscale::info('web-1.org-1'));
        $this->assertContains('tailscale-cleanup', array_column($this->recipes(), 'name'));
    }

    public function test_tailnet_only_opens_the_port_on_the_tailnet_interface_and_nowhere_else(): void
    {
        $steps = Firewall::recipe(['enabled' => true, 'ssh' => ['203.0.113.7'], 'web' => [], 'ssh_private' => true, 'web_private' => true])['steps'];
        $commands = array_map(fn ($s) => $s['type'] === 'package' ? 'package '.$s['name'] : implode(' ', $s['args']), $steps);

        foreach ([22, 80, 443] as $port) {
            $this->assertContains("allow in on tailscale0 to any port {$port} proto tcp", $commands);
        }
        $this->assertNotContains('allow 22/tcp', $commands);
        $this->assertNotContains('allow from 203.0.113.7 to any port 22 proto tcp', $commands, 'Tailnet only replaces the list.');
        $this->assertNotContains('allow 443/tcp', $commands);
        $this->assertContains('allow from 10.0.0.0/8 to any port 443 proto tcp', $commands, 'A load balancer still reaches it.');
    }

    public function test_the_firewall_is_not_closed_to_the_public_while_the_server_is_off_the_tailnet(): void
    {
        $this->statusOutput = '';
        Firewall::save('web-1.org-1', true, [], [], true, false);
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueFirewall('web-1.org-1', 'console', 'me');

        $this->assertSame('failed', $runner->run($id));
        $this->assertSame([], $this->recipes(), 'No rule was applied.');
    }

    public function test_leaving_switches_tailnet_only_off_first(): void
    {
        Tailscale::save('web-1.org-1', ['address' => '100.1.1.1', 'name' => 'web-1', 'joined_at' => now()->toIso8601String()]);
        Firewall::save('web-1.org-1', true, [], [], true, true);
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueTailscaleLeave('web-1.org-1', 'console', 'me');

        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame(['firewall', 'tailscale-leave'], array_column($this->recipes(), 'name'));
        $fw = Firewall::settings('web-1.org-1');
        $this->assertFalse($fw['ssh_private']);
        $this->assertFalse($fw['web_private']);
        $this->assertNull(Tailscale::info('web-1.org-1'));
    }

    public function test_the_console_refuses_tailnet_only_without_a_join_a_confirmation_or_with_a_certificate(): void
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        $call = fn (array $data) => $this->postJson('/servers/web-1.org-1/firewall', $data);
        $fw = fn (bool $enabled, bool $ssh, bool $web, bool $reach) => ['enabled' => $enabled, 'ssh' => '', 'web' => '', 'sshPrivate' => $ssh, 'webPrivate' => $web, 'reach' => $reach];

        $this->assertSame(422, $call($fw(true, true, false, true))->status(), 'Not on the tailnet.');
        Tailscale::save('web-1.org-1', ['address' => '100.1.1.1', 'name' => 'web-1', 'joined_at' => now()->toIso8601String()]);
        $this->assertSame(422, $call($fw(false, true, false, true))->status(), 'Firewall off.');
        $this->assertSame(422, $call($fw(true, true, false, false))->status(), 'SSH needs the confirmation.');
        DB::table('sites')->insert(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => 'shop', 'domain' => 'shop.test', 'repository' => 'r', 'branch' => 'main', 'deploy_key_public' => 'k', 'provider' => 'digitalocean', 'tls' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(422, $call($fw(true, false, true, false))->status(), 'A certificate needs the web ports open.');
        $this->assertNull(DB::table('nodes')->where('node_id', 'web-1.org-1')->value('firewall'), 'Nothing was saved.');

        $call($fw(true, true, false, true))->assertRedirect();
        $this->assertTrue(Firewall::settings('web-1.org-1')['ssh_private']);
    }

    public function test_joining_needs_a_saved_key_and_the_permission(): void
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        $join = fn () => $this->postJson('/servers/web-1.org-1/tailscale/join');
        $this->assertSame(403, $join()->status());

        DB::table('memberships')->where('user_id', $user->id)->update(['role' => 'manager']);
        $this->assertSame(422, $join()->status(), 'No key saved yet.');
        $this->saveKey();
        $join()->assertRedirect();
    }

    public function test_the_key_is_saved_encrypted_and_shown_only_as_a_hint_and_a_bad_one_is_refused(): void
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        $this->post('/account/tailscale', ['authkey' => 'tskey-client-abcdefghijk'])->assertSessionHasErrors('provider_tailscale');
        $this->assertFalse(DB::table('cloud_credentials')->where('provider', 'tailscale')->exists());

        $this->post('/account/tailscale', ['authkey' => self::KEY])->assertRedirect();
        $row = DB::table('cloud_credentials')->where('provider', 'tailscale')->first();
        $this->assertStringNotContainsString(self::KEY, json_encode($row));
        $response = $this->get('/account?tab=providers');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('providers.tailscale.hint'));

        $this->delete('/account/tailscale')->assertRedirect();
        $this->assertFalse(DB::table('cloud_credentials')->where('provider', 'tailscale')->exists());
    }
}
