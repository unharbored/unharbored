<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\Exceptions\CredentialNotFound;
use App\Lib\Cloud\Exceptions\CredentialRevoked;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A token here can create, destroy and read a customer's entire
 * infrastructure, and a compromise is not something we can undo on their
 * behalf. These tests are mostly about what must never happen.
 */
final class CredentialStoreTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    private CredentialStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        Cache::store('array')->clear();
        $this->http = new HttpFactory();

        $this->store = new CredentialStore(
            vault: $this->app->make(Vault::class),
            http: $this->http,
            cache: Cache::store('array'),
            audit: new AuditLog(),
        );

        DB::table('organizations')->insert([
            'id' => 'org-1', 'name' => 'Test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_connect_then_build_a_client(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');

        $this->assertTrue($this->store->has('org-1'));

        $this->http->fake(['*' => $this->http->response(['droplets' => []], 200)]);
        $client = $this->store->clientFor('org-1', 'testing');
        $client->get('droplets');

        $this->http->assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer dop_v1_secret'));
    }

    /**
     * The secret belongs in the vault. This table holds a lookup key and
     * non-secret context so an operator can tell two credentials apart
     * without decrypting either.
     */
    public function test_the_token_is_never_stored_in_the_credentials_table(): void
    {
        $this->store->connect('org-1', 'dop_v1_very_distinctive_secret');

        $row = DB::table('cloud_credentials')->first();
        $serialised = (string) json_encode($row);

        $this->assertStringNotContainsString('dop_v1_very_distinctive_secret', $serialised);
        $this->assertSame('cloud.digitalocean.token', $row->vault_key);
    }

    public function test_the_token_is_encrypted_in_the_vault(): void
    {
        $this->store->connect('org-1', 'dop_v1_very_distinctive_secret');

        $secret = DB::table('secrets')->first();

        $this->assertStringNotContainsString(
            'dop_v1_very_distinctive_secret',
            (string) $secret->ciphertext
        );
    }

    public function test_an_unknown_organisation_has_no_credential(): void
    {
        $this->expectException(CredentialNotFound::class);
        $this->store->clientFor('org-nobody', 'testing');
    }

    /**
     * A row pointing at a secret that does not exist looks connected and is
     * not, which is worse than no row at all.
     */
    public function test_a_credential_row_without_its_secret_fails_loudly(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->app->make(Vault::class)->forget('org-1', 'cloud.digitalocean.token');

        $this->expectException(CredentialNotFound::class);
        $this->store->clientFor('org-1', 'testing');
    }

    // ---- revocation ----

    /**
     * Customers revoke access whenever they like. The platform has to survive
     * it without losing track of what it already provisioned.
     */
    public function test_a_revoked_credential_fails_closed(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->store->markRevoked('org-1', 'Customer revoked the grant');

        $this->assertFalse($this->store->has('org-1'));

        $this->expectException(CredentialRevoked::class);
        $this->store->clientFor('org-1', 'testing');
    }

    /**
     * Resources provisioned under a revoked credential still exist and still
     * cost money. Deleting the record would erase the evidence of a situation
     * somebody has to resolve.
     */
    public function test_revoking_keeps_the_record_and_the_secret(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->store->markRevoked('org-1', 'Customer revoked the grant');

        $this->assertDatabaseCount('cloud_credentials', 1);
        $this->assertDatabaseCount('secrets', 1);

        $row = DB::table('cloud_credentials')->first();
        $this->assertNotNull($row->revoked_at);
        $this->assertSame('Customer revoked the grant', $row->last_error);
    }

    public function test_a_rejected_token_is_marked_revoked_during_verification(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->http->fake(['*' => $this->http->response(['message' => 'Unable to authenticate'], 401)]);

        $this->assertFalse($this->store->verify('org-1'));
        $this->assertFalse($this->store->has('org-1'));
    }

    public function test_verification_records_which_account_it_is(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->http->fake(['*' => $this->http->response([
            'account' => ['email' => 'ops@example.com', 'uuid' => 'acct-uuid-1'],
        ], 200)]);

        $this->assertTrue($this->store->verify('org-1'));

        $row = DB::table('cloud_credentials')->first();
        $this->assertSame('ops@example.com', $row->account_email);
        $this->assertSame('acct-uuid-1', $row->account_uuid);
        $this->assertNotNull($row->verified_at);
    }

    public function test_disconnect_removes_both_the_record_and_the_secret(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->store->disconnect('org-1');

        $this->assertDatabaseCount('cloud_credentials', 0);
        $this->assertDatabaseCount('secrets', 0);

        // The audit trail survives the credential.
        $this->assertNotNull(
            DB::table('audit_records')->where('action', 'credential.disconnected')->first()
        );
    }

    // ---- attribution ----

    /**
     * PLAN.md requires every DigitalOcean API call to be attributable to an
     * actor, an organisation and a reason. This is the only way to obtain a
     * usable token, so it is where that starts.
     */
    public function test_every_retrieval_is_audited_with_a_reason(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');

        $this->store->clientFor('org-1', 'Provisioning droplet web-1', Actor::user('u1', 'matt@example.com'));

        $record = DB::table('audit_records')->where('action', 'credential.accessed')->first();

        $this->assertNotNull($record);
        $this->assertSame('Provisioning droplet web-1', $record->reason);
        $this->assertSame('user', $record->actor_type);
        $this->assertSame('u1', $record->actor_id);
    }

    public function test_a_denied_retrieval_is_audited(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->store->markRevoked('org-1', 'revoked');

        try {
            $this->store->clientFor('org-1', 'should not work');
        } catch (CredentialRevoked) {
            // expected
        }

        $record = DB::table('audit_records')->where('action', 'credential.access_denied')->first();

        $this->assertNotNull($record);
        $this->assertSame('denied', $record->outcome);
    }

    public function test_the_token_never_reaches_the_audit_log(): void
    {
        $this->store->connect('org-1', 'dop_v1_very_distinctive_secret');
        $this->store->clientFor('org-1', 'testing');

        $records = DB::table('audit_records')->get();

        foreach ($records as $record) {
            $this->assertStringNotContainsString(
                'dop_v1_very_distinctive_secret',
                (string) json_encode($record)
            );
        }
    }

    public function test_the_audit_chain_stays_intact_across_credential_operations(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret');
        $this->store->clientFor('org-1', 'one');
        $this->store->markRevoked('org-1', 'revoked');
        $this->store->connect('org-1', 'dop_v1_new_secret');

        $this->assertTrue((new AuditLog())->verify('org-1')->intact);
    }

    // ---- reconnecting ----

    public function test_reconnecting_replaces_the_token_and_clears_revocation(): void
    {
        $this->store->connect('org-1', 'dop_v1_old');
        $this->store->markRevoked('org-1', 'revoked');

        $this->store->connect('org-1', 'dop_v1_new');

        $this->assertTrue($this->store->has('org-1'));
        $this->assertDatabaseCount('cloud_credentials', 1);

        $this->http->fake(['*' => $this->http->response([], 200)]);
        $this->store->clientFor('org-1', 'testing')->get('droplets');

        $this->http->assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer dop_v1_new'));
    }

    /**
     * Rate limit budgets are per DigitalOcean account, not per organisation.
     * Two organisations could connect the same account and would share a real
     * limit whether or not we modelled it.
     */
    public function test_the_rate_limit_key_follows_the_account_when_known(): void
    {
        $this->store->connect('org-1', 'dop_v1_secret', account: ['uuid' => 'shared-account']);

        $this->assertSame(
            'shared-account',
            DB::table('cloud_credentials')->first()->rate_limit_key
        );
    }

    public function test_credentials_are_scoped_per_organisation(): void
    {
        DB::table('organizations')->insert([
            'id' => 'org-2', 'name' => 'Other', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->store->connect('org-1', 'dop_v1_one');
        $this->store->connect('org-2', 'dop_v1_two');

        $this->http->fake(['*' => $this->http->response([], 200)]);
        $this->store->clientFor('org-2', 'testing')->get('droplets');

        $this->http->assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer dop_v1_two'));
    }
}
