<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\AdminAccess;
use App\Lib\Servers\ServerStack;
use App\Lib\Servers\SshKeys;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use Tests\TestCase;

/**
 * The login user every server gets, for its owner: SSH by key, sudo by a
 * password kept in the vault. The organisation's public keys, and which servers
 * carry each.
 */
final class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'spec' => $r['args']['spec'] ?? null];

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
    }

    private function keys(): SshKeys
    {
        return $this->app->make(SshKeys::class);
    }

    private function access(): AdminAccess
    {
        return $this->app->make(AdminAccess::class);
    }

    /** A real public key line. */
    private function key(string $comment = 'me@laptop'): string
    {
        return EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH', ['comment' => $comment]);
    }

    private function step(array $spec, string $id): array
    {
        return array_column($spec['steps'], null, 'id')[$id];
    }

    private function applied(): array
    {
        return array_values(array_filter($this->gateway, fn ($c) => $c['kind'] === 'apply_recipe'));
    }

    // ------------------------------------------------------------------ keys

    public function test_a_public_key_is_accepted_and_fingerprinted_like_ssh_does(): void
    {
        $line = $this->key('me@laptop');
        $found = SshKeys::inspect($line);

        $this->assertSame('ed25519', $found['type']);
        $this->assertStringStartsWith('ssh-ed25519 ', $found['public_key']);
        $this->assertStringEndsWith(' me@laptop', $found['public_key']);
        $this->assertMatchesRegularExpression('/^SHA256:[A-Za-z0-9+\/]{43}$/', $found['fingerprint']);
        $this->assertSame('ecdsa', SshKeys::inspect(EC::createKey('nistp256')->getPublicKey()->toString('OpenSSH'))['type']);
    }

    /** @return array<string, array{0: string}> */
    public static function badKeys(): array
    {
        return [
            'a private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEA\n-----END OPENSSH PRIVATE KEY-----"],
            'two keys' => ["ssh-ed25519 AAAA one\nssh-ed25519 BBBB two"],
            'words' => ['please let me in'],
            'a key type we do not take' => ['ssh-dss AAAAB3NzaC1kc3MAAACB comment'],
            'a broken key' => ['ssh-ed25519 AAAAnotakey comment'],
        ];
    }

    /**
     * @dataProvider badKeys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function test_what_is_not_a_public_key_is_refused(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        SshKeys::inspect($text);
    }

    public function test_a_short_rsa_key_is_refused(): void
    {
        $this->expectExceptionMessage('at least 3072 bits');
        SshKeys::inspect(RSA::createKey(2048)->getPublicKey()->toString('OpenSSH'));
    }

    public function test_the_private_key_message_says_what_to_paste(): void
    {
        $this->expectExceptionMessage('Paste the public half');
        SshKeys::inspect('-----BEGIN OPENSSH PRIVATE KEY-----');
    }

    public function test_the_organisation_keeps_a_key_once_and_audits_it(): void
    {
        $line = $this->key();
        $saved = $this->keys()->add('org-1', 'work laptop', $line, true, 'me@example.com');

        $this->assertSame('work laptop', $saved['name']);
        $this->assertSame(1, (int) $saved['install_on_new_servers']);
        $this->assertDatabaseHas('audit_records', ['action' => 'ssh_key.added']);

        $this->expectExceptionMessage('already here');
        $this->keys()->add('org-1', 'again', $line, false, 'me@example.com');
    }

    public function test_a_key_goes_on_a_server_only_when_asked_and_comes_off_when_asked(): void
    {
        $k = $this->keys()->add('org-1', 'a', $this->key(), false, 'me');
        $this->assertSame([], SshKeys::wanted('web-1.org-1'));

        $this->keys()->setOnServer('org-1', 'web-1.org-1', (int) $k['id'], true, 'me');
        $this->assertSame([$k['public_key']], SshKeys::wanted('web-1.org-1'));
        $this->assertSame('pending', DB::table('server_ssh_keys')->value('state'));

        SshKeys::applied('web-1.org-1');
        $this->assertSame('installed', DB::table('server_ssh_keys')->value('state'));

        $this->keys()->setOnServer('org-1', 'web-1.org-1', (int) $k['id'], false, 'me');
        $this->assertSame([], SshKeys::wanted('web-1.org-1'), 'Asked to go, so no longer wanted.');
        $this->assertSame('removing', DB::table('server_ssh_keys')->value('state'), 'But kept until the server has dropped it.');

        SshKeys::applied('web-1.org-1');
        $this->assertSame(0, DB::table('server_ssh_keys')->count());
    }

    public function test_removing_a_key_from_the_organisation_takes_it_off_every_server_and_then_deletes_it(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $k = $this->keys()->add('org-1', 'a', $this->key(), false, 'me');
        foreach (['web-1.org-1', 'web-2.org-1'] as $node) {
            $this->keys()->setOnServer('org-1', $node, (int) $k['id'], true, 'me');
        }
        SshKeys::applied('web-1.org-1');
        SshKeys::applied('web-2.org-1');

        $servers = $this->keys()->remove('org-1', (int) $k['id'], 'me');

        $this->assertEqualsCanonicalizing(['web-1.org-1', 'web-2.org-1'], $servers);
        $this->assertSame([], SshKeys::wanted('web-1.org-1'));
        $this->assertSame(1, DB::table('ssh_keys')->count(), 'Its text is kept until every server has dropped it.');

        SshKeys::applied('web-1.org-1');
        $this->assertSame(1, DB::table('ssh_keys')->count(), 'One server still has it.');
        SshKeys::applied('web-2.org-1');
        $this->assertSame(0, DB::table('ssh_keys')->count());
    }

    public function test_a_key_nobody_has_is_simply_deleted(): void
    {
        $k = $this->keys()->add('org-1', 'a', $this->key(), false, 'me');

        $this->assertSame([], $this->keys()->remove('org-1', (int) $k['id'], 'me'));
        $this->assertSame(0, DB::table('ssh_keys')->count());
    }

    // ------------------------------------------------------------ the user

    public function test_the_sudo_password_is_made_once_kept_in_the_vault_and_hashed_for_the_server(): void
    {
        $first = $this->access()->credentials('org-1', 'web-1.org-1');
        $again = $this->access()->credentials('org-1', 'web-1.org-1');

        $this->assertSame($first, $again, 'The same one until it is rotated.');
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}(-[a-z2-9]{5}){4}$/', $first['password']);
        $this->assertStringStartsWith('$6$', $first['hash'], 'SHA-512 crypt.');
        $this->assertTrue(hash_equals($first['hash'], crypt($first['password'], $first['hash'])), 'The hash is of the password.');
    }

    public function test_rotating_makes_a_new_password_and_revealing_is_audited_without_it(): void
    {
        $old = $this->access()->credentials('org-1', 'web-1.org-1');
        $this->access()->rotate('org-1', 'web-1.org-1', Actor::user('1', 'me@example.com'));
        $new = $this->access()->credentials('org-1', 'web-1.org-1');

        $this->assertNotSame($old['password'], $new['password']);
        $this->assertNotSame($old['hash'], $new['hash']);

        $shown = $this->access()->reveal('org-1', 'web-1.org-1', Actor::user('1', 'me@example.com'));
        $this->assertSame($new['password'], $shown);
        $this->assertDatabaseHas('audit_records', ['action' => 'node.admin_password_read']);
        $this->assertDatabaseHas('audit_records', ['action' => 'node.admin_password_rotated']);
        $this->assertStringNotContainsString($shown, json_encode(DB::table('audit_records')->get()), 'The password is never in the audit log.');
    }

    public function test_the_recipe_never_carries_the_password_or_its_hash_in_a_command(): void
    {
        $creds = $this->access()->credentials('org-1', 'web-1.org-1');
        $steps = (new ServerStack())->adminSteps('cloudmgr', $creds['hash'], []);

        foreach ($steps as $step) {
            $this->assertStringNotContainsString($creds['password'], json_encode($step['args'] ?? []));
            $this->assertStringNotContainsString($creds['hash'], json_encode($step['args'] ?? []), $step['id'].': command arguments end up in logs.');
        }
        $hash = $this->step(['steps' => $steps], 'admin-hash');
        $this->assertSame('file', $hash['type']);
        $this->assertTrue($hash['sensitive'], 'A sensitive file step is kept out of logs.');
        $this->assertSame('0600', $hash['mode']);
        $this->assertStringContainsString('rm -f /root/.cm-admin-hash', $this->step(['steps' => $steps], 'admin-password')['args'][1], 'And it is removed after use.');
    }

    public function test_the_user_may_use_sudo_but_only_log_in_by_key(): void
    {
        $steps = ['steps' => (new ServerStack())->adminSteps('cloudmgr', '$6$x$y', [])];

        $this->assertSame(['-aG', 'sudo', 'cloudmgr'], $this->step($steps, 'admin-sudo')['args']);
        $config = base64_decode($this->step($steps, 'admin-ssh-config')['content_b64']);
        $this->assertStringContainsString("Match User cloudmgr\n    PasswordAuthentication no\n    AuthenticationMethods publickey", $config, 'A password can never be guessed over the network.');
        $this->assertStringNotContainsString('PermitRootLogin', $config, 'Nobody else\'s login changes.');
        $check = $this->step($steps, 'admin-ssh-check')['args'][1];
        $this->assertStringContainsString('sshd -t', $check);
        $this->assertStringContainsString('rm -f /etc/ssh/sshd_config.d/50-cloud-manager.conf; exit 1', $check, 'A config sshd refuses is removed, so it cannot break the next restart.');
        $ids = array_column($steps['steps'], 'id');
        $this->assertSame('openssh-server', $this->step($steps, 'admin-ssh-server')['name']);
        $this->assertLessThan(array_search('admin-ssh-check', $ids, true), array_search('admin-ssh-server', $ids, true), 'A minimal image (Incus) has no sshd until it is installed.');
    }

    public function test_the_keys_sit_between_markers_so_a_key_added_by_hand_stays(): void
    {
        $steps = ['steps' => (new ServerStack())->adminSteps('cloudmgr', '$6$x$y', ['ssh-ed25519 AAAA one', 'ssh-ed25519 BBBB two'])];
        $script = $this->step($steps, 'admin-keys')['args'][1];

        preg_match('/echo (\S+) \| base64 -d/', $script, $m);
        $this->assertSame("# BEGIN cloud-manager\nssh-ed25519 AAAA one\nssh-ed25519 BBBB two\n# END cloud-manager\n", base64_decode($m[1]));
        $this->assertStringContainsString("sed -i '/^# BEGIN cloud-manager$/,/^# END cloud-manager$/d'", $script, 'Only the managed block is replaced.');
        $this->assertStringContainsString('chmod 600', $script);
        $this->assertSame('2 SSH keys are installed', $this->step($steps, 'admin-keys')['description']);
    }

    // ------------------------------------------------------------ operations

    public function test_building_a_server_creates_the_user_with_the_keys_marked_for_new_servers(): void
    {
        $default = $this->keys()->add('org-1', 'default', $this->key(), true, 'me');
        $this->keys()->add('org-1', 'optional', $this->key(), false, 'me');
        $runner = $this->app->make(OperationRunner::class);

        $id = $runner->queueStack('web-1.org-1', 'console', 'me');
        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $spec = $this->applied()[0]['spec'];
        preg_match('/echo (\S+) \| base64 -d/', $this->step($spec, 'admin-keys')['args'][1], $m);
        $this->assertSame("# BEGIN cloud-manager\n{$default['public_key']}\n# END cloud-manager\n", base64_decode($m[1]), 'Only the one marked for new servers.');
        $this->assertSame('cloudmgr', DB::table('nodes')->where('node_id', 'web-1.org-1')->value('admin_user'), 'The name it was built with is remembered.');
        $this->assertSame('installed', DB::table('server_ssh_keys')->value('state'));
    }

    public function test_a_server_keeps_the_name_it_was_built_with_if_the_default_changes_later(): void
    {
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['admin_user' => 'oldname']);

        $this->assertSame('oldname', AdminAccess::userFor('web-1.org-1'));
        $this->assertSame('cloudmgr', AdminAccess::userFor('web-9.org-1'));
    }

    public function test_installing_a_key_on_an_existing_server_creates_the_user_and_writes_it(): void
    {
        $k = $this->keys()->add('org-1', 'a', $this->key(), false, 'me');
        $this->keys()->setOnServer('org-1', 'web-1.org-1', (int) $k['id'], true, 'me');
        $runner = $this->app->make(OperationRunner::class);

        $id = $runner->queueAdminAccess('web-1.org-1', 'console', 'me');
        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $spec = $this->applied()[0]['spec'];
        $this->assertSame('admin-access', $spec['name']);
        $this->assertContains('admin-user', array_column($spec['steps'], 'id'), 'So it works on a server that has not been repaired yet.');
        $this->assertStringContainsString('1 SSH key is installed', $this->step($spec, 'admin-keys')['description']);
        $this->assertSame('installed', DB::table('server_ssh_keys')->value('state'));
        $this->assertSame('cloudmgr', DB::table('nodes')->where('node_id', 'web-1.org-1')->value('admin_user'));
    }

    // --------------------------------------------------------------- console

    private function signIn(): User
    {
        return tap(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']), fn ($u) => $this->actingAs($u));
    }

    public function test_the_account_page_adds_lists_and_removes_keys(): void
    {
        $this->signIn();
        $this->post('/account/ssh-keys', ['name' => 'laptop', 'public_key' => $this->key(), 'install_on_new_servers' => 1])->assertRedirectContains('/account')->assertSessionHas('status');
        $key = $this->get('/account?tab=keys')->assertOk()->inertiaProps('sshKeys')[0];
        $this->assertSame('laptop', $key['name']);
        $this->assertStringStartsWith('SHA256:', $key['fingerprint']);
        $this->assertSame(0, $key['servers']);

        $this->from('/account')->post('/account/ssh-keys', ['name' => 'oops', 'public_key' => '-----BEGIN OPENSSH PRIVATE KEY-----'])
            ->assertSessionHasErrors('public_key');
        $this->assertSame(1, DB::table('ssh_keys')->count(), 'A private key is never stored.');

        $id = (int) DB::table('ssh_keys')->value('id');
        $this->post("/account/ssh-keys/{$id}/default", ['on' => 0])->assertRedirectContains('/account');
        $this->assertSame(0, (int) DB::table('ssh_keys')->value('install_on_new_servers'));

        $this->keys()->setOnServer('org-1', 'web-1.org-1', $id, true, 'me');
        $this->delete("/account/ssh-keys/{$id}")->assertRedirectContains('/account')->assertSessionHas('status');
        $this->assertSame(1, DB::table('operations')->where('kind', 'admin_access')->count(), 'The server is asked to drop it.');
    }

    public function test_the_keys_need_a_session(): void
    {
        $this->post('/account/ssh-keys', [])->assertRedirect('/login');
        $this->delete('/account/ssh-keys/1')->assertRedirect('/login');
    }

    public function test_the_server_card_shows_the_user_reveals_the_password_audited_and_rotates_it(): void
    {
        $this->signIn();
        $this->keys()->add('org-1', 'laptop', $this->key(), false, 'me');
        $password = $this->access()->credentials('org-1', 'web-1.org-1')['password'];
        $page = $this->get('/servers/web-1.org-1')->assertOk();
        $access = $page->viewData('page')['props']['node']['access'];
        $this->assertSame('cloudmgr', $access['user']);
        $this->assertSame(['laptop', 'none'], [$access['keys'][0]['name'], $access['keys'][0]['state']]);
        $this->assertStringNotContainsString($password, $page->getContent(), 'The page never carries the password.');

        $this->postJson('/servers/web-1.org-1/sudo/reveal')->assertOk()->assertExactJson(['password' => $password]);
        $this->assertDatabaseHas('audit_records', ['action' => 'node.admin_password_read']);

        $this->post('/servers/web-1.org-1/sudo/rotate')->assertRedirectContains('/operation?operation=');
        $this->assertNotSame($password, $this->access()->credentials('org-1', 'web-1.org-1')['password']);
        $this->assertSame(1, DB::table('operations')->where('kind', 'admin_access')->count());
    }

    public function test_a_key_is_installed_or_removed_from_the_server_card(): void
    {
        $this->signIn();
        $k = $this->keys()->add('org-1', 'laptop', $this->key(), false, 'me');

        $this->post("/servers/web-1.org-1/keys/{$k['id']}", ['installed' => true])->assertRedirectContains('/operation?operation=');
        $this->assertSame('pending', DB::table('server_ssh_keys')->value('state'));
        $this->assertSame(1, DB::table('operations')->where('kind', 'admin_access')->count());

        $this->assertSame('pending', $this->get('/servers/web-1.org-1')->viewData('page')['props']['node']['access']['keys'][0]['state']);
        $this->post("/servers/web-1.org-1/keys/{$k['id']}", ['installed' => false]);
        $this->assertSame('removing', DB::table('server_ssh_keys')->value('state'));
    }

    // --------------------------------------------------- keys picked at creation

    private function newNode(string $id): void
    {
        DB::table('nodes')->insert(['node_id' => $id, 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_keys_picked_when_a_server_is_created_replace_the_defaults(): void
    {
        $default = $this->keys()->add('org-1', 'default-key', $this->key('a'), true, 'me');
        $other = $this->keys()->add('org-1', 'other-key', $this->key('b'), false, 'me');
        $this->newNode('picked.org-1');
        $this->newNode('plain.org-1');

        SshKeys::choose('org-1', 'picked.org-1', [(int) $other['id']]);

        $this->assertSame([$other['public_key']], $this->access()->forStack('org-1', 'picked.org-1')['keys'], 'Only what was picked.');
        $this->assertSame([$default['public_key']], $this->access()->forStack('org-1', 'plain.org-1')['keys'], 'A server with no choice still gets the defaults.');
    }

    public function test_choosing_no_keys_means_none_not_the_defaults(): void
    {
        $this->keys()->add('org-1', 'default-key', $this->key('a'), true, 'me');
        $this->newNode('bare.org-1');

        SshKeys::choose('org-1', 'bare.org-1', []);

        $this->assertSame([], $this->access()->forStack('org-1', 'bare.org-1')['keys']);
        $this->assertSame(1, (int) DB::table('nodes')->where('node_id', 'bare.org-1')->value('ssh_keys_chosen'));
    }

    public function test_a_key_that_is_not_the_organisations_is_ignored(): void
    {
        $this->newNode('picked.org-1');
        $foreign = DB::table('ssh_keys')->insertGetId(['organization_id' => 'org-2', 'name' => 'theirs', 'public_key' => 'ssh-ed25519 AAAA x', 'type' => 'ed25519', 'fingerprint' => 'SHA256:x', 'created_by' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        SshKeys::choose('org-1', 'picked.org-1', [$foreign]);

        $this->assertSame(0, DB::table('server_ssh_keys')->where('node_id', 'picked.org-1')->count());
    }

    public function test_the_add_a_server_form_sends_the_picked_keys_or_leaves_the_defaults(): void
    {
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'cloud.digitalocean.token', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn();
        $a = $this->keys()->add('org-1', 'default-key', $this->key('a'), true, 'me');
        $b = $this->keys()->add('org-1', 'other-key', $this->key('b'), false, 'me');
        $catalog = $this->get('/servers')->viewData('page')['props']['catalogs']['digitalocean'];
        $create = fn (string $name, array $extra = []) => $this->postJson('/servers', ['name' => $name, 'region' => $catalog['region'], 'size' => $catalog['size']] + $extra)->assertRedirectContains('/operation?operation=');
        $meta = fn () => json_decode((string) DB::table('operations')->where('kind', 'provision')->latest('id')->value('meta'), true);

        $keys = $this->get('/servers')->viewData('page')['props']['newKeys'];
        $this->assertSame([['default-key', true], ['other-key', false]], array_map(fn ($k) => [$k['name'], $k['default']], $keys), 'The form lists the keys and which are on by default.');

        $create('web-2');
        $this->assertArrayNotHasKey('keys', $meta(), 'Untouched: the organisation\'s defaults apply when the stack is built.');

        $create('web-3', ['chosen' => true, 'keys' => [(int) $a['id'], (int) $b['id']]]);
        $this->assertSame([(int) $a['id'], (int) $b['id']], $meta()['keys']);

        $create('web-4', ['chosen' => true, 'keys' => []]);
        $this->assertSame([], $meta()['keys'], 'None picked is a choice too.');
    }

    // -------------------------------------------------------- locking SSH down

    private function readyServer(): void
    {
        $k = $this->keys()->add('org-1', 'laptop', $this->key(), false, 'me');
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['admin_user' => 'cloudmgr']);
        DB::table('server_ssh_keys')->insert(['node_id' => 'web-1.org-1', 'ssh_key_id' => $k['id'], 'state' => 'installed', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_locking_down_writes_a_checked_drop_in_that_sorts_before_cloud_inits(): void
    {
        $on = $this->app->make(ServerStack::class)->hardeningRecipe(true);
        $file = $this->step($on, 'ssh-hardening');

        $this->assertSame('/etc/ssh/sshd_config.d/40-cloud-manager-hardening.conf', $file['path']);
        $this->assertLessThan(0, strcmp(basename($file['path']), '50-cloud-init.conf'), 'sshd takes the first value it reads, so this must come first.');
        $text = base64_decode($file['content_b64']);
        foreach (['PermitRootLogin no', 'PasswordAuthentication no', 'KbdInteractiveAuthentication no'] as $line) {
            $this->assertStringContainsString($line, $text);
        }
        $this->assertSame(['ssh-hardening', 'ssh-check', 'ssh-reload'], array_column($on['steps'], 'id'));
        $this->assertStringContainsString('sshd -t', $this->step($on, 'ssh-check')['args'][1]);
        $this->assertStringContainsString('rm -f /etc/ssh/sshd_config.d/40-cloud-manager-hardening.conf', $this->step($on, 'ssh-check')['args'][1], 'sshd refusing it removes it again.');

        $off = $this->app->make(ServerStack::class)->hardeningRecipe(false);
        $this->assertSame(['absent', 'command'], array_column($off['steps'], 'type'));
    }

    public function test_it_cannot_be_locked_down_until_a_key_is_installed_for_the_login_user(): void
    {
        $this->assertStringContainsString('login user is not created', (string) AdminAccess::hardenBlock('web-1.org-1'));

        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['admin_user' => 'cloudmgr']);
        $this->assertStringContainsString('locked out', (string) AdminAccess::hardenBlock('web-1.org-1'));

        $k = $this->keys()->add('org-1', 'laptop', $this->key(), false, 'me');
        DB::table('server_ssh_keys')->insert(['node_id' => 'web-1.org-1', 'ssh_key_id' => $k['id'], 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNotNull(AdminAccess::hardenBlock('web-1.org-1'), 'A key that is only asked for does not count.');

        DB::table('server_ssh_keys')->update(['state' => 'installed']);
        $this->assertNull(AdminAccess::hardenBlock('web-1.org-1'));
    }

    public function test_the_console_locks_down_and_unlocks_through_an_operation_and_audits_it(): void
    {
        $this->signIn();
        $node = fn () => $this->get('/servers/web-1.org-1')->assertOk()->viewData('page')['props']['node'];

        $this->assertStringContainsString('The login user is not created yet', $node()['access']['hardenBlock']);
        $this->postJson('/servers/web-1.org-1/harden', ['on' => true])->assertStatus(422)->assertJsonValidationErrors('harden');
        $this->assertSame(0, DB::table('operations')->where('kind', 'ssh_hardening')->count(), 'Refused before anything is queued.');

        $this->readyServer();
        $this->post('/servers/web-1.org-1/harden', ['on' => true])->assertRedirectContains('/operation?operation=');
        $op = DB::table('operations')->where('kind', 'ssh_hardening')->first();
        $this->assertSame('Lock down SSH on web-1.org-1', $op->title);
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run((int) $op->id));

        $spec = $this->applied()[0]['spec'];
        $this->assertSame('ssh-hardening', $spec['name']);
        $this->assertSame(1, (int) DB::table('nodes')->where('node_id', 'web-1.org-1')->value('ssh_hardened'));
        $this->assertDatabaseHas('audit_records', ['action' => 'node.ssh_hardened']);
        $this->assertTrue($node()['access']['hardened']);

        $this->post('/servers/web-1.org-1/harden', ['on' => false])->assertRedirectContains('/operation?operation=');
        $off = DB::table('operations')->where('kind', 'ssh_hardening')->latest('id')->first();
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run((int) $off->id));
        $this->assertSame(0, (int) DB::table('nodes')->where('node_id', 'web-1.org-1')->value('ssh_hardened'));
        $this->assertDatabaseHas('audit_records', ['action' => 'node.ssh_unhardened']);
    }

    public function test_the_key_is_checked_again_when_the_operation_runs(): void
    {
        $this->readyServer();
        $id = $this->app->make(OperationRunner::class)->queueSshHardening('web-1.org-1', true, 'console', 'me');
        DB::table('server_ssh_keys')->delete();

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($id));
        $this->assertStringContainsString('locked out', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame([], $this->applied(), 'Nothing reached the server.');
        $this->assertSame(0, (int) DB::table('nodes')->where('node_id', 'web-1.org-1')->value('ssh_hardened'));
    }
}
