<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** An application with no domain of its own can take a free name that points at its server. */
final class FreeAddressTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string> $addresses the server's recorded addresses */
    private function site(array $addresses = ['ipv4' => '203.0.113.7']): object
    {
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->actingAs(User::factory()->create());
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'provider' => 'digitalocean', 'state' => 'active', 'desired_state' => 'active',
            'attributes' => json_encode($addresses), 'created_at' => now(), 'updated_at' => now()]);

        return (new Sites($this->app->make(Vault::class), new AuditLog))->create('org-1', 'web-1.org-1', 'shop', 'shop.pending.internal', 'git@github.com:acme/shop.git');
    }

    public function test_the_free_name_is_offered_and_taking_it_moves_the_site(): void
    {
        Queue::fake();
        $site = $this->site();

        $this->get("/site/settings?site={$site->id}&tab=domain")->assertInertia(fn (Assert $p) => $p->where('site.freeAddress', 'shop.203-0-113-7.sslip.io'));
        $this->post("/site/settings/{$site->id}/domain/free")->assertSessionHasNoErrors();

        $op = DB::table('operations')->where('kind', 'domain')->sole();
        $this->assertSame('shop.203-0-113-7.sslip.io', json_decode($op->meta, true)['domain']);
    }

    public function test_it_is_not_offered_once_the_site_has_one_or_sits_behind_a_balancer(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['domain' => 'shop.203-0-113-7.sslip.io']);
        $this->get("/site/settings?site={$site->id}&tab=domain")->assertInertia(fn (Assert $p) => $p->where('site.freeAddress', null));
        $this->post("/site/settings/{$site->id}/domain/free")->assertSessionHasErrors('newDomain');

        DB::table('sites')->where('id', $site->id)->update(['domain' => 'shop.example.com', 'load_balancer_id' => 1]);
        $this->get("/site/settings?site={$site->id}&tab=domain")->assertInertia(fn (Assert $p) => $p->where('site.freeAddress', null));
    }

    public function test_a_server_with_only_ipv6_gets_a_name_from_that_address(): void
    {
        $site = $this->site(['ipv4' => null, 'ipv6' => '2605:4840:3:18ea::100']);

        $this->get("/site/settings?site={$site->id}&tab=domain")->assertInertia(fn (Assert $p) => $p->where('site.freeAddress', 'shop.2605-4840-3-18ea--100.sslip.io'));
    }
}
