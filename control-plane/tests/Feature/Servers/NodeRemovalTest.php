<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Gateway\GatewayClient;
use App\Lib\Gateway\OrganisationSync;
use App\Lib\Servers\NodeRemoval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Removing a server has to stop it reaching the platform, whether or not it
 * is reachable to be asked nicely, and must never strand a live site.
 */
final class NodeRemovalTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    private string $certPem = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->http = new HttpFactory();
        $this->app->instance(HttpFactory::class, $this->http);
        foreach ([GatewayClient::class, OrganisationSync::class, NodeRemoval::class] as $c) {
            $this->app->forgetInstance($c);
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'web-9.org-1'], $key, ['private_key_bits' => 2048]), null, $key, 30, [], 0xABCDEF);
        openssl_x509_export($cert, $this->certPem);

        DB::table('organization_certificate_authorities')->insert(['organization_id' => 'org-1', 'certificate_pem' => 'CA PEM', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-9.org-1', 'organization_id' => 'org-1', 'certificate_pem' => $this->certPem, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function remove(): array
    {
        return $this->app->make(NodeRemoval::class)->remove('web-9.org-1', 'test');
    }

    public function test_an_unreachable_node_is_still_revoked_and_the_gateway_told(): void
    {
        $this->http->fake([
            'gw.test/v1/nodes/web-9.org-1' => $this->http->response(['connected' => false]),
            'gw.test/v1/organisations' => $this->http->response(['organisations' => 1, 'disconnected' => 0]),
        ]);

        $r = $this->remove();

        $this->assertTrue($r['revoked']);
        $this->assertFalse($r['uninstalled']);
        $this->assertSame('abcdef', DB::table('revoked_certificates')->value('serial'));
        $this->assertNull(DB::table('nodes')->where('node_id', 'web-9.org-1')->first());
        $this->http->assertSent(fn (Request $req) => str_ends_with($req->url(), '/v1/organisations')
            && $req['organisations'][0]['revoked_serials'] === ['abcdef']);
        $this->assertDatabaseHas('audit_records', ['action' => 'node.removed']);
    }

    public function test_a_connected_node_is_asked_to_uninstall_first(): void
    {
        $this->http->fake([
            'gw.test/v1/nodes/web-9.org-1' => $this->http->response(['connected' => true]),
            'gw.test/v1/nodes/web-9.org-1/commands' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true]),
            'gw.test/v1/organisations' => $this->http->response(['organisations' => 1]),
        ]);

        $this->assertTrue($this->remove()['uninstalled']);
        $this->http->assertSent(fn (Request $req) => str_ends_with($req->url(), '/commands') && $req['args']['spec']['name'] === 'agent-uninstall');
    }

    public function test_a_server_with_sites_is_not_removed(): void
    {
        DB::table('sites')->insert(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => 'web-9.org-1', 'name' => 'shop', 'domain' => 'shop.example.com',
            'repository' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_key_public' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->http->fake();

        try {
            $this->remove();
            $this->fail('Should have refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('still serves shop', $e->getMessage());
        }
        $this->assertNotNull(DB::table('nodes')->where('node_id', 'web-9.org-1')->first());
        $this->assertSame(0, DB::table('revoked_certificates')->count());
    }

    public function test_the_gateway_being_down_does_not_undo_the_revocation(): void
    {
        $this->http->fake(['gw.test/*' => $this->http->response([], 500)]);

        $r = $this->remove();

        $this->assertFalse($r['synced']);
        $this->assertSame(1, DB::table('revoked_certificates')->count(), 'It applies at the next sync, which every gateway start does.');
    }

    public function test_serials_match_how_the_gateway_prints_them(): void
    {
        $this->assertSame('abcdef', OrganisationSync::serialOf($this->certPem));
    }

    private function droplet(string $slug, string $state): void
    {
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => $slug, 'external_id' => '555',
            'state' => $state, 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_stale_node_whose_droplet_is_gone_is_revoked_and_forgotten(): void
    {
        $this->droplet('web-9', 'deleted');
        $this->http->fake(['gw.test/v1/organisations' => $this->http->response(['organisations' => 1])]);

        $this->app->make(NodeRemoval::class)->removeStale('web-9.org-1', 'test');

        $this->assertNull(DB::table('nodes')->where('node_id', 'web-9.org-1')->first());
        $this->assertSame('abcdef', DB::table('revoked_certificates')->where('node_id', 'web-9.org-1')->value('serial'));
        $this->assertDatabaseHas('audit_records', ['action' => 'node.stale_removed']);
    }

    public function test_a_node_whose_droplet_still_exists_is_not_removed_as_stale(): void
    {
        $this->droplet('web-9', 'active');

        try {
            $this->app->make(NodeRemoval::class)->removeStale('web-9.org-1', 'test');
            $this->fail('Should have refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('still exists', $e->getMessage());
        }
        $this->assertNotNull(DB::table('nodes')->where('node_id', 'web-9.org-1')->first());
    }

    public function test_a_stale_node_serving_a_site_is_not_removed(): void
    {
        $this->droplet('web-9', 'deleted');
        DB::table('sites')->insert(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => 'web-9.org-1', 'name' => 'shop', 'domain' => 'shop.example.com',
            'repository' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_key_public' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(NodeRemoval::class)->removeStale('web-9.org-1', 'test');
    }

    public function test_a_departing_pool_member_is_revoked_not_left_behind(): void
    {
        DB::table('nodes')->insert(['node_id' => 'shop-555.org-1', 'organization_id' => 'org-1', 'certificate_pem' => $this->certPem,
            'created_at' => now(), 'updated_at' => now()]);
        $this->droplet('shop-555', 'active');
        DB::table('resources')->where('slug', 'shop-555')->update(['attributes' => json_encode(['pool' => 7])]);
        $siteId = DB::table('sites')->insertGetId(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => 'shop',
            'domain' => 'shop.example.com', 'repository' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_key_public' => 'x',
            'created_at' => now(), 'updated_at' => now()]);
        $this->http->fake(['gw.test/v1/organisations' => $this->http->response(['organisations' => 1])]);

        $runner = $this->app->make(\App\Lib\Operations\OperationRunner::class);
        $leave = new \ReflectionMethod($runner, 'leave');
        $leave->setAccessible(true);
        $leave->invoke($runner, DB::table('sites')->find($siteId), ['shop-555.org-1'], static fn () => null);

        $this->assertNull(DB::table('nodes')->where('node_id', 'shop-555.org-1')->first(),
            'A pool marker must not stop a departing member being revoked.');
        $this->assertSame(1, DB::table('revoked_certificates')->where('node_id', 'shop-555.org-1')->count());
    }
}
