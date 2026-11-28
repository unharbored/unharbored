<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Alerts\Alerts;
use App\Lib\Audit\Actor;
use App\Lib\Cloud\ProviderKeys;
use App\Lib\Dns\Spaceship;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The keys on the Account page: shown by their first characters and last four,
 * never whole; checked with the provider before they replace the old one;
 * with an expiry their owner enters; and reported when they go bad.
 */
final class ProviderKeysTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dop_v1_' . '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abc4f2';

    private bool $doRefuses = false;

    private int $doRefusalStatus = 401;

    private bool $spaceshipRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Cache::store('array')->clear();
        Carbon::setTestNow('2026-11-10 12:00:00');
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory();
        $http->fake(function (Request $r) use ($http) {
            if (str_contains($r->url(), 'digitalocean.com')) {
                return $this->doRefuses ? $http->response(['id' => 'unauthorized'], $this->doRefusalStatus) : $http->response(['account' => ['email' => 'matt@example.com', 'uuid' => 'u-1']]);
            }

            return $this->spaceshipRefuses ? $http->response([], 401) : $http->response(['items' => [['type' => 'A', 'name' => '@', 'address' => '1.2.3.4', 'ttl' => 300]]]);
        });
        $this->app->instance(HttpFactory::class, $http);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function keys(): ProviderKeys
    {
        return $this->app->make(ProviderKeys::class);
    }

    private function actor(): Actor
    {
        return Actor::user('1', 'matt@example.com');
    }

    public function test_a_key_is_shown_by_its_first_characters_and_last_four_only(): void
    {
        $this->assertSame('dop_v1_••••••••c4f2', ProviderKeys::hint(self::TOKEN));
        $this->assertSame('••••••••ef', ProviderKeys::hint('shortkey-ef'));

        $this->keys()->connectCloud('org-1', self::TOKEN, null, $this->actor());
        $row = $this->keys()->cloud('org-1')[0];
        $this->assertSame('dop_v1_••••••••c4f2', $row['hint']);
        $this->assertSame('matt@example.com', $row['account']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($row));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('cloud_credentials')->first()));
    }

    public function test_expiry_is_counted_down_and_coloured(): void
    {
        $this->assertSame(['days' => null, 'state' => 'none'], ProviderKeys::standing(null, null));
        $this->assertSame(['days' => 63, 'state' => 'ok'], ProviderKeys::standing('2027-01-12', null));
        $this->assertSame(['days' => 9, 'state' => 'soon'], ProviderKeys::standing('2026-11-19', null));
        $this->assertSame('expired', ProviderKeys::standing('2026-11-07', null)['state']);
        $this->assertSame('rejected', ProviderKeys::standing('2027-01-12', 'Spaceship refused the key')['state']);
        $this->assertSame(['failed', 'Expired 3 days ago'], ProviderKeys::badge(['state' => 'expired', 'days' => -3]));
    }

    public function test_a_token_with_missing_scopes_is_told_apart_from_one_digitalocean_does_not_know(): void
    {
        $this->doRefuses = true;
        $this->doRefusalStatus = 403;
        try {
            $this->keys()->connectCloud('org-1', 'dop_v1_narrow', null, $this->actor());
            $this->fail('A token without the permissions is not kept.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('accepts that token, but it does not have enough permissions', $e->getMessage());
            $this->assertStringContainsString('Full Access', $e->getMessage());
        }
        $this->assertSame([], $this->keys()->cloud('org-1'));
    }

    public function test_a_refused_token_changes_nothing_and_the_old_one_stays(): void
    {
        $this->keys()->connectCloud('org-1', self::TOKEN, '2027-01-12', $this->actor());

        $this->doRefuses = true;
        try {
            $this->keys()->connectCloud('org-1', 'dop_v1_bad', null, $this->actor());
            $this->fail('A refused token is not kept.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not recognise that token', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }
        $row = $this->keys()->cloud('org-1')[0];
        $this->assertSame('dop_v1_••••••••c4f2', $row['hint']);
        $this->assertSame('2027-01-12', substr((string) $row['expires_at'], 0, 10));
        $this->assertSame(self::TOKEN, $this->app->make(Vault::class)->get('org-1', 'cloud.digitalocean.token'));
    }

    public function test_replacing_the_token_keeps_the_new_one_and_the_expiry_can_change_alone(): void
    {
        $this->keys()->connectCloud('org-1', self::TOKEN, null, $this->actor());
        $new = 'dop_v1_' . 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff9d01';
        $this->keys()->connectCloud('org-1', $new, '2026-11-19', $this->actor());

        $this->assertSame($new, $this->app->make(Vault::class)->get('org-1', 'cloud.digitalocean.token'));
        $this->assertSame('soon', $this->keys()->cloud('org-1')[0]['state']);

        $this->keys()->setExpiry('org-1', 'cloud', '', $this->actor());
        $this->assertSame('none', $this->keys()->cloud('org-1')[0]['state']);
        $this->assertTrue(DB::table('audit_records')->where('action', 'credential.expiry_changed')->exists());
        $this->expectException(InvalidArgumentException::class);
        $this->keys()->setExpiry('org-1', 'cloud', 'next week', $this->actor());
    }

    public function test_the_provider_cannot_be_disconnected_while_resources_need_it(): void
    {
        $this->keys()->connectCloud('org-1', self::TOKEN, null, $this->actor());
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'external_id' => '1', 'desired_state' => 'active', 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertStringContainsString('1 server', (string) $this->keys()->blocked('org-1', 'cloud'));
        try {
            $this->keys()->disconnect('org-1', 'cloud', $this->actor());
            $this->fail('blocked');
        } catch (InvalidArgumentException) {
            $this->assertCount(1, $this->keys()->cloud('org-1'));
        }

        DB::table('resources')->delete();
        $this->keys()->disconnect('org-1', 'cloud', $this->actor());
        $this->assertSame([], $this->keys()->cloud('org-1'));
        $this->assertNull($this->app->make(Vault::class)->get('org-1', 'cloud.digitalocean.token'));
    }

    public function test_a_dns_key_is_checked_listed_with_its_domain_and_disconnected(): void
    {
        $this->spaceshipRefuses = true;
        try {
            $this->keys()->connectDns('org-1', 'example.com', 'KEYKEYKEYKEYKEYK7Qm', 'SECRETSECRETSECRET', null, $this->actor());
            $this->fail('refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }
        $this->assertSame([], $this->keys()->dnsList('org-1'));

        $this->spaceshipRefuses = false;
        $this->keys()->connectDns('org-1', 'Example.com', 'KEYKEYKEYKEYKEYK7Qm', 'SECRETSECRETSECRET', '2026-11-19', $this->actor());
        $row = $this->keys()->dnsList('org-1')[0];
        $this->assertSame(['example.com'], $row['domains']);
        $this->assertSame('KEYKEYK••••••••K7Qm', $row['hint']);
        $this->assertSame('soon', $row['state']);

        $this->keys()->disconnect('org-1', 'dns', $this->actor());
        $this->assertSame([], $this->keys()->dnsList('org-1'));
        $this->assertSame([], $this->app->make(Spaceship::class)->domains('org-1'));
    }

    public function test_dns_domains_are_edited_with_the_saved_key_and_the_list_shows_them_all(): void
    {
        $this->keys()->connectDns('org-1', 'example.com', 'KEYKEYKEYKEYKEYK7Qm', 'SECRETSECRETSECRET', null, $this->actor());

        $this->keys()->setDnsDomains('org-1', 'example.com, example.org');
        $this->assertSame(['example.com', 'example.org'], $this->keys()->dnsList('org-1')[0]['domains']);

        $this->spaceshipRefuses = true;
        try {
            $this->keys()->setDnsDomains('org-1', 'example.net');
            $this->fail('refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }
        $this->assertSame(['example.com', 'example.org'], $this->keys()->dnsList('org-1')[0]['domains']);
    }

    public function test_the_daily_check_marks_a_rejected_key_and_alerts_say_so(): void
    {
        $this->keys()->connectCloud('org-1', self::TOKEN, null, $this->actor());
        $this->keys()->connectDns('org-1', 'example.com', 'KEYKEYKEYKEYKEYK7Qm', 'SECRETSECRETSECRET', '2026-11-19', $this->actor());
        $this->assertSame(0, $this->keys()->verifyAll());
        $this->assertSame(['provider-key:spaceship'], array_keys($this->keys()->alerts('org-1')), 'Nine days left is inside the fortnight.');

        $this->doRefuses = true;
        $this->spaceshipRefuses = true;
        $this->assertSame(2, $this->app->make(ProviderKeys::class)->verifyAll());
        $alerts = $this->keys()->alerts('org-1');
        $this->assertSame('DigitalOcean rejected the key', $alerts['provider-key:digitalocean']['title']);
        $this->assertSame('Spaceship rejected the key', $alerts['provider-key:spaceship']['title']);
        $this->assertSame('rejected', $this->keys()->cloud('org-1')[0]['state']);

        $this->doRefuses = false;
        $this->keys()->connectCloud('org-1', self::TOKEN, null, $this->actor());
        $this->assertArrayNotHasKey('provider-key:digitalocean', $this->keys()->alerts('org-1'), 'A replaced key clears it.');
    }

    public function test_an_expired_key_is_critical(): void
    {
        $this->keys()->connectCloud('org-1', self::TOKEN, '2026-11-07', $this->actor());
        $alert = $this->keys()->alerts('org-1')['provider-key:digitalocean'];
        $this->assertSame('critical', $alert['severity']);
        $this->assertSame('The DigitalOcean key has expired', $alert['title']);
    }

    public function test_the_account_page_lists_both_kinds_and_never_the_key(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $empty = $this->get('/account?tab=providers')->assertOk();
        $this->assertSame([], $empty->inertiaProps('providers.cloud'));
        $this->assertSame([], $empty->inertiaProps('providers.dns'));

        $this->post('/account/providers/cloud', ['token' => self::TOKEN, 'expires_at' => '2027-01-12'])->assertRedirect();
        $this->post('/account/providers/dns', ['domain' => 'example.com', 'key' => 'KEYKEYKEYKEYKEYK7Qm', 'secret' => 'SECRETSECRETSECRET'])->assertRedirect();

        $page = $this->get('/account?tab=providers')->assertOk();
        $cloud = $page->inertiaProps('providers.cloud')[0];
        $dns = $page->inertiaProps('providers.dns')[0];
        $this->assertSame(['dop_v1_••••••••c4f2', 'matt@example.com', 'Expires Jan 12, 2027 · in 63 days'], [$cloud['hint'], $cloud['account'], $cloud['badgeLabel']]);
        $this->assertSame(['example.com'], $dns['domains']);
        $this->assertSame('No expiry set', $dns['badgeLabel']);
        $this->assertStringNotContainsString(self::TOKEN, $page->getContent());
        $this->assertStringNotContainsString('SECRETSECRETSECRET', $page->getContent());

        $this->doRefuses = true;
        $this->post('/account/providers/cloud', ['token' => 'dop_v1_bad'])->assertSessionHasErrors('provider_cloud');
        $this->post('/account/providers/cloud/expiry', ['expires_at' => '2026-11-15'])->assertRedirect();
        $this->assertSame('Expires in 5 days', $this->get('/account?tab=providers')->inertiaProps('providers.cloud')[0]['badgeLabel']);
        $this->post('/account/providers/cloud/test')->assertRedirect();
        $this->assertSame('Rejected', $this->get('/account?tab=providers')->inertiaProps('providers.cloud')[0]['badgeLabel']);

        $this->delete('/account/providers/dns')->assertRedirect();
        $this->delete('/account/providers/cloud')->assertRedirect();
        $this->assertSame([], $this->get('/account?tab=providers')->inertiaProps('providers.cloud'));
    }

    public function test_a_blocked_disconnect_says_why_on_the_button(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $this->post('/account/providers/cloud', ['token' => self::TOKEN]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'external_id' => '1', 'desired_state' => 'active', 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // The page greys the button out and gives this as its reason.
        $this->assertSame('1 server need this key to be managed. Remove them first.', $this->get('/account?tab=providers')->inertiaProps('providers.keyBlocked'));
        $this->delete('/account/providers/cloud')->assertSessionHasErrors('provider_cloud');
    }

    public function test_alerts_include_keys_in_a_normal_check(): void
    {
        $this->keys()->connectCloud('org-1', self::TOKEN, '2026-11-07', $this->actor());
        $gateway = new \App\Lib\Gateway\GatewayClient('http://gw.test', 't', $this->app->make(HttpFactory::class), new \App\Lib\Audit\AuditLog());
        $this->app->instance(\App\Lib\Gateway\GatewayClient::class, $gateway);
        $this->app->make(HttpFactory::class)->fake(fn () => $this->app->make(HttpFactory::class)->response(['nodes' => []]));

        $changes = $this->app->make(Alerts::class)->check('org-1');
        $this->assertContains('opened: The DigitalOcean key has expired', $changes);
    }
}
