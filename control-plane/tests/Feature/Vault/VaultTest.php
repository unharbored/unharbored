<?php

declare(strict_types=1);

namespace Tests\Feature\Vault;

use App\Lib\Vault\Exceptions\DecryptionFailed;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VaultTest extends TestCase
{
    use RefreshDatabase;

    private Vault $vault;

    protected function setUp(): void
    {
        parent::setUp();

        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        $this->vault = $this->app->make(Vault::class);
    }

    public function test_put_and_get_round_trip(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'dop_v1_secret_token');

        $this->assertSame('dop_v1_secret_token', $this->vault->get('org-1', 'do.oauth'));
    }

    public function test_get_returns_null_for_an_unknown_secret(): void
    {
        $this->assertNull($this->vault->get('org-1', 'nothing-here'));
    }

    public function test_put_replaces_an_existing_secret(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'first');
        $this->vault->put('org-1', 'do.oauth', 'second');

        $this->assertSame('second', $this->vault->get('org-1', 'do.oauth'));
        $this->assertDatabaseCount('secrets', 1);
    }

    public function test_replacing_a_secret_records_when_it_rotated(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'first');
        $this->assertNull(DB::table('secrets')->first()->rotated_at);

        $this->vault->put('org-1', 'do.oauth', 'second');
        $this->assertNotNull(DB::table('secrets')->first()->rotated_at);
    }

    /**
     * The database must never hold the plaintext. This is the single claim
     * the whole class exists to make.
     */
    public function test_plaintext_is_never_stored(): void
    {
        $secret = 'dop_v1_a_very_distinctive_token_value';
        $this->vault->put('org-1', 'do.oauth', $secret);

        $row = DB::table('secrets')->first();
        $serialised = json_encode($row);

        $this->assertStringNotContainsString($secret, (string) $serialised);
        $this->assertStringNotContainsString($secret, (string) $row->ciphertext);
        $this->assertStringNotContainsString(base64_encode($secret), (string) $serialised);
    }

    public function test_encryption_is_non_deterministic(): void
    {
        $this->vault->put('org-1', 'a', 'same value');
        $this->vault->put('org-1', 'b', 'same value');

        $rows = DB::table('secrets')->orderBy('name')->get();

        // Equal plaintexts must not produce equal ciphertexts, or an observer
        // can tell which organisations share a credential.
        $this->assertNotEquals($rows[0]->ciphertext, $rows[1]->ciphertext);
        $this->assertNotEquals($rows[0]->nonce, $rows[1]->nonce);
    }

    /**
     * Tenant isolation. One organisation's ciphertext must be useless under
     * another organisation's key, so a bug that crosses the boundary fails
     * closed rather than leaking.
     */
    public function test_one_organisation_cannot_read_another_with_its_own_key(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'org-one-token');
        $this->vault->put('org-2', 'do.oauth', 'org-two-token');

        $this->assertSame('org-one-token', $this->vault->get('org-1', 'do.oauth'));
        $this->assertSame('org-two-token', $this->vault->get('org-2', 'do.oauth'));

        // Different data keys, not a shared one.
        $keys = DB::table('organization_data_keys')->pluck('wrapped_key')->all();
        $this->assertCount(2, $keys);
        $this->assertNotEquals($keys[0], $keys[1]);
    }

    /**
     * Moving a ciphertext row between organisations in the database must
     * fail to decrypt, not hand one customer another customer's credential.
     * This is the property the AEAD associated data exists to provide.
     */
    public function test_a_ciphertext_moved_between_organisations_fails_to_decrypt(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'org-one-token');
        $this->vault->put('org-2', 'placeholder', 'unrelated');

        // Give org-2 a row carrying org-1's ciphertext, as a database-level
        // attacker or a bad migration might.
        $stolen = DB::table('secrets')->where('organization_id', 'org-1')->first();
        DB::table('secrets')->where('organization_id', 'org-2')->update([
            'name' => 'do.oauth',
            'key_version' => $stolen->key_version,
            'nonce' => $stolen->nonce,
            'ciphertext' => $stolen->ciphertext,
        ]);

        $this->expectException(DecryptionFailed::class);
        $this->vault->get('org-2', 'do.oauth');
    }

    /**
     * Renaming a row must break decryption too. Without name binding, a
     * secret could be quietly promoted into a slot something else reads.
     */
    public function test_a_renamed_ciphertext_fails_to_decrypt(): void
    {
        $this->vault->put('org-1', 'staging.token', 'staging-value');

        DB::table('secrets')
            ->where('organization_id', 'org-1')
            ->update(['name' => 'production.token']);

        $this->expectException(DecryptionFailed::class);
        $this->vault->get('org-1', 'production.token');
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'token');

        $row = DB::table('secrets')->first();
        $ciphertext = (string) $row->ciphertext;
        $ciphertext[0] = chr(ord($ciphertext[0]) ^ 0xFF);
        DB::table('secrets')->where('id', $row->id)->update(['ciphertext' => $ciphertext]);

        $this->expectException(DecryptionFailed::class);
        $this->vault->get('org-1', 'do.oauth');
    }

    public function test_has_and_forget(): void
    {
        $this->assertFalse($this->vault->has('org-1', 'do.oauth'));

        $this->vault->put('org-1', 'do.oauth', 'token');
        $this->assertTrue($this->vault->has('org-1', 'do.oauth'));

        $this->assertTrue($this->vault->forget('org-1', 'do.oauth'));
        $this->assertFalse($this->vault->has('org-1', 'do.oauth'));
        $this->assertFalse($this->vault->forget('org-1', 'do.oauth'));
    }

    public function test_names_are_scoped_to_the_organisation(): void
    {
        $this->vault->put('org-1', 'b', 'x');
        $this->vault->put('org-1', 'a', 'x');
        $this->vault->put('org-2', 'c', 'x');

        $this->assertSame(['a', 'b'], $this->vault->names('org-1'));
        $this->assertSame(['c'], $this->vault->names('org-2'));
    }

    public function test_metadata_is_stored_alongside_but_not_encrypted(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'token', ['account_email' => 'ops@example.com']);

        $row = DB::table('secrets')->first();
        $this->assertSame(['account_email' => 'ops@example.com'], json_decode((string) $row->metadata, true));
    }

    public function test_a_data_key_is_created_once_per_organisation(): void
    {
        $this->vault->put('org-1', 'a', 'x');
        $this->vault->put('org-1', 'b', 'y');

        $this->assertDatabaseCount('organization_data_keys', 1);
    }

    /**
     * The data key is wrapped at rest. A database leak alone must not be
     * enough to decrypt anything.
     */
    public function test_data_keys_are_stored_wrapped(): void
    {
        $this->vault->put('org-1', 'a', 'x');

        $row = DB::table('organization_data_keys')->first();

        $this->assertStringStartsWith('lkm1.', $row->wrapped_key);
        $this->assertSame('local', $row->master_key_id);
    }

    // ---- rotation ----

    public function test_rotating_the_data_key_re_encrypts_every_secret(): void
    {
        $this->vault->put('org-1', 'a', 'value-a');
        $this->vault->put('org-1', 'b', 'value-b');

        $before = DB::table('secrets')->orderBy('name')->pluck('ciphertext')->all();

        $count = $this->vault->rotateDataKey('org-1');

        $this->assertSame(2, $count);
        $this->assertSame('value-a', $this->vault->get('org-1', 'a'));
        $this->assertSame('value-b', $this->vault->get('org-1', 'b'));

        $after = DB::table('secrets')->orderBy('name')->pluck('ciphertext')->all();
        $this->assertNotEquals($before, $after);

        // The old key version stays readable until nothing references it.
        $this->assertDatabaseCount('organization_data_keys', 2);
        $this->assertSame(2, (int) DB::table('secrets')->first()->key_version);
        $this->assertSame(1, DB::table('organization_data_keys')->where('is_primary', true)->count());
    }

    public function test_rotating_one_organisation_leaves_others_alone(): void
    {
        $this->vault->put('org-1', 'a', 'one');
        $this->vault->put('org-2', 'a', 'two');

        $this->vault->rotateDataKey('org-1');

        $this->assertSame('two', $this->vault->get('org-2', 'a'));
        $this->assertSame(1, DB::table('organization_data_keys')->where('organization_id', 'org-2')->count());
    }

    /**
     * A master key rotation must cost one row per organisation, not one row
     * per secret. That difference is the entire reason for envelope
     * encryption, so it is worth pinning.
     */
    public function test_rewrapping_changes_data_keys_but_not_ciphertexts(): void
    {
        $this->vault->put('org-1', 'a', 'value-a');
        $this->vault->put('org-2', 'b', 'value-b');

        $ciphertextsBefore = DB::table('secrets')->orderBy('name')->pluck('ciphertext')->all();
        $wrappedBefore = DB::table('organization_data_keys')->orderBy('id')->pluck('wrapped_key')->all();

        // The retiring key. A rotation has to hold both at once: the old one
        // to unwrap what is on disk, the new one to re-wrap it.
        $previous = $this->app->make(KeyManager::class);

        config([
            'vault.master_key' => LocalKeyManager::generateMasterKey(),
            'vault.master_key_id' => 'local-v2',
        ]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $rotated = $this->app->make(Vault::class);

        $this->assertSame(2, $rotated->pendingRewrapCount());

        $count = $rotated->rewrapDataKeys($previous);

        $this->assertSame(2, $count);
        $this->assertSame(0, $rotated->pendingRewrapCount());

        // Secrets are untouched. That is the whole economic argument for
        // envelope encryption: rotation is O(organisations), not O(secrets).
        $this->assertSame($ciphertextsBefore, DB::table('secrets')->orderBy('name')->pluck('ciphertext')->all());
        $this->assertNotEquals($wrappedBefore, DB::table('organization_data_keys')->orderBy('id')->pluck('wrapped_key')->all());
        $this->assertSame(2, DB::table('organization_data_keys')->where('master_key_id', 'local-v2')->count());

        // And everything still reads under the new master key.
        $this->assertSame('value-a', $rotated->get('org-1', 'a'));
        $this->assertSame('value-b', $rotated->get('org-2', 'b'));
    }

    /**
     * A rotation interrupted halfway must be resumable, so re-running after
     * every row has moved is a no-op rather than an error.
     */
    public function test_rewrapping_is_resumable(): void
    {
        $this->vault->put('org-1', 'a', 'x');
        $previous = $this->app->make(KeyManager::class);

        config([
            'vault.master_key' => LocalKeyManager::generateMasterKey(),
            'vault.master_key_id' => 'local-v2',
        ]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $rotated = $this->app->make(Vault::class);

        $this->assertSame(1, $rotated->rewrapDataKeys($previous));
        $this->assertSame(0, $rotated->rewrapDataKeys($previous));
        $this->assertSame('x', $rotated->get('org-1', 'a'));
    }

    /**
     * Rotating the key without changing its id would leave rotated and
     * un-rotated rows indistinguishable, so a partial rotation could never
     * be resumed. Refuse rather than silently create that state.
     */
    public function test_rewrapping_refuses_an_unchanged_master_key_id(): void
    {
        $this->vault->put('org-1', 'a', 'x');

        $this->expectException(\InvalidArgumentException::class);
        $this->vault->rewrapDataKeys($this->app->make(KeyManager::class));
    }

    /**
     * Losing the master key must look like a loud failure, not a silent
     * empty result that something downstream treats as "no credential".
     */
    public function test_a_lost_master_key_fails_loudly(): void
    {
        $this->vault->put('org-1', 'do.oauth', 'token');

        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        $this->expectException(\App\Lib\Vault\Exceptions\UnwrapFailed::class);
        $this->app->make(Vault::class)->get('org-1', 'do.oauth');
    }

    public function test_handles_binary_and_long_values(): void
    {
        $binary = random_bytes(4096);
        $this->vault->put('org-1', 'binary', $binary);
        $this->assertSame($binary, $this->vault->get('org-1', 'binary'));

        $long = str_repeat('a', 200_000);
        $this->vault->put('org-1', 'long', $long);
        $this->assertSame($long, $this->vault->get('org-1', 'long'));

        $this->vault->put('org-1', 'empty', '');
        $this->assertSame('', $this->vault->get('org-1', 'empty'));
    }
}
