<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use App\Lib\Dns\Spaceship;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** A Spaceship key is kept only once Spaceship accepts it, and records change by type and name. */
final class SpaceshipTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->http = new HttpFactory;
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->forgetInstance(Spaceship::class);
    }

    public function test_a_key_spaceship_accepts_is_stored_encrypted_and_one_it_refuses_is_not(): void
    {
        $this->http->fake(['spaceship.dev/api/v1/dns/records/example.com*' => $this->http->sequence()
            ->push(['message' => 'forbidden'], 403)
            ->push(['items' => [['type' => 'A', 'name' => '@', 'address' => '192.0.2.1', 'ttl' => 3600]], 'total' => 1])]);
        $dns = $this->app->make(Spaceship::class);

        try {
            $dns->connect('org-1', 'example.com', 'k-bad', 's-bad');
            $this->fail('A refused key was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dnsrecords:read and dnsrecords:write', $e->getMessage());
        }
        $this->assertSame([], $dns->domains('org-1'), 'Nothing kept.');

        $this->assertSame(1, $dns->connect('org-1', 'Example.com', 'k-good', 's-good'));
        $this->assertSame(['example.com'], $dns->domains('org-1'));
        $this->assertStringNotContainsString('s-good', (string) json_encode(DB::table('secrets')->get()), 'Encrypted at rest.');
        $this->http->assertSent(fn (Request $r) => $r->hasHeader('X-API-Key', 'k-good') && $r->hasHeader('X-API-Secret', 's-good'));
    }

    public function test_records_are_set_and_deleted_by_type_and_name(): void
    {
        $this->http->fake(['*' => $this->http->response(['items' => [], 'total' => 0])]);
        $dns = $this->app->make(Spaceship::class);
        $dns->connect('org-1', 'example.com', 'k', 's');

        $dns->set('org-1', 'example.com', 'A', 'pr-7.shop.preview', '203.0.113.9');
        $dns->delete('org-1', 'example.com', 'A', 'pr-7.shop.preview', '203.0.113.9');

        $this->http->assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://spaceship.dev/api/v1/dns/records/example.com'
            && $r['items'] === [['type' => 'A', 'name' => 'pr-7.shop.preview', 'address' => '203.0.113.9', 'ttl' => 300]] && $r['force'] === true);
        $this->http->assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->data() === [['type' => 'A', 'name' => 'pr-7.shop.preview', 'address' => '203.0.113.9']]);
    }

    public function test_the_domains_can_be_changed_with_the_saved_key_and_a_refused_one_changes_nothing(): void
    {
        $this->http->fake(['spaceship.dev/api/v1/dns/records/shop.example.org*' => $this->http->response(['message' => 'not found'], 404),
            'spaceship.dev/api/v1/dns/records/*' => $this->http->response(['items' => [['type' => 'A', 'name' => '@', 'address' => '192.0.2.1', 'ttl' => 300]], 'total' => 1])]);
        $dns = $this->app->make(Spaceship::class);
        $dns->connect('org-1', 'example.com', 'k', 's');

        $this->assertSame(2, $dns->setDomains('org-1', "Example.com, example.net\nexample.com"));
        $this->assertSame(['example.com', 'example.net'], $dns->domains('org-1'));
        $this->http->assertSent(fn (Request $r) => str_contains($r->url(), 'records/example.net') && $r->hasHeader('X-API-Key', 'k'));

        try {
            $dns->setDomains('org-1', 'example.com shop.example.org');
            $this->fail('A domain Spaceship does not have was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has no domain shop.example.org', $e->getMessage());
        }
        $this->assertSame(['example.com', 'example.net'], $dns->domains('org-1'), 'The list is unchanged.');

        $this->expectException(RuntimeException::class);
        $dns->set('org-1', 'elsewhere.com', 'A', 'x', '203.0.113.9');
    }

    public function test_domains_that_are_not_names_are_refused_before_asking_spaceship(): void
    {
        foreach (['', 'localhost', 'not a domain!', implode(' ', array_map(fn ($i) => "d{$i}.example.com", range(1, 21)))] as $typed) {
            try {
                Spaceship::parseDomains($typed);
                $this->fail("'{$typed}' was accepted.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
