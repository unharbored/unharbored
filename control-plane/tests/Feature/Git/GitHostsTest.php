<?php

declare(strict_types=1);

namespace Tests\Feature\Git;

use App\Http\Controllers\GitWebhookController;
use App\Lib\Git\GitPush;
use App\Lib\Git\GitRemote;
use App\Lib\Git\GitRepo;
use App\Lib\Git\GitSources;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use phpseclib3\Crypt\EC;
use stdClass;
use Tests\TestCase;

/**
 * Code from any Git host. The parts that touch strangers' addresses are
 * tested hardest: what an address may be, which hosts the control plane will
 * talk to, and who may ask for a deploy.
 */
final class GitHostsTest extends TestCase
{
    use RefreshDatabase;

    private const FP_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGG';

    private HttpFactory $http;

    /** @var array<string, string> host => its addresses, comma separated */
    private array $dns = ['git.example.com' => '203.0.113.5', 'github.com' => '140.82.112.3', 'gitlab.com' => '198.51.100.9', 'raw.githubusercontent.com' => '185.199.108.133'];

    /** @var list<Request> */
    private array $sent = [];

    /** @var array<string, mixed> url prefix => ['status', 'type', 'body', 'location'] */
    private array $web = [];

    private bool $accepted = false;

    /** What a host shows a client that logged in with the deploy key: its branches, as git's advertisement. Null: not given. */
    private ?string $keyedAdvertisement = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        $this->http = new HttpFactory();
        $this->app->instance(HttpFactory::class, $this->http);
        $this->http->fake(function (Request $r) {
            $this->sent[] = $r;
            foreach ($this->web as $prefix => $answer) {
                if (str_starts_with($r->url(), $prefix)) {
                    return $this->http->response($answer['body'] ?? '', $answer['status'] ?? 200, ($answer['type'] ?? null ? ['Content-Type' => $answer['type']] : []) + (isset($answer['location']) ? ['Location' => $answer['location']] : []));
                }
            }

            return $this->http->response('', 404);
        });
        $this->app->instance(GitRemote::class, new GitRemote(
            $this->http,
            fn (string $host) => isset($this->dns[$host]) ? explode(',', $this->dns[$host]) : [],
            fn (string $host, int $port) => $host === 'down.example.com' ? false : self::FP_KEY,
            fn () => $this->accepted,
            fn () => $this->keyedAdvertisement ?? $this->advertisement(),
        ));
        foreach ([GitSources::class, NewApplications::class, OperationRunner::class] as $c) {
            $this->app->forgetInstance($c);
        }
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'provider' => 'digitalocean', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'k', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function advertisement(array $branches = ['main' => 'a', 'dev' => 'b'], string $default = 'main'): string
    {
        $pkt = fn (string $s) => sprintf('%04x', strlen($s) + 4).$s;
        $out = $pkt("# service=git-upload-pack\n").'0000';
        $first = true;
        foreach ($branches as $name => $c) {
            $sha = str_repeat($c, 40);
            $out .= $pkt($first ? "{$sha} refs/heads/{$name}\0multi_ack symref=HEAD:refs/heads/{$default} agent=git/2\n" : "{$sha} refs/heads/{$name}\n");
            $first = false;
        }

        return $out.'0000';
    }

    private function publicRepo(string $host = 'git.example.com', string $path = 'team/app'): void
    {
        $this->web["https://{$host}/{$path}.git/info/refs"] = ['status' => 200, 'type' => 'application/x-git-upload-pack-advertisement', 'body' => $this->advertisement()];
    }

    // ---- addresses ------------------------------------------------------------------------

    public function test_every_form_of_address_is_understood_and_normalised(): void
    {
        foreach ([
            ['acme/shop', 'github.com', 'acme/shop', false, 'https://github.com/acme/shop.git', 'git@github.com:acme/shop.git'],
            ['https://gitlab.com/group/sub/app', 'gitlab.com', 'group/sub/app', false, 'https://gitlab.com/group/sub/app.git', 'git@gitlab.com:group/sub/app.git'],
            ['https://Git.Example.com/team/app.git/', 'git.example.com', 'team/app', false, 'https://git.example.com/team/app.git', 'git@git.example.com:team/app.git'],
            ['git@bitbucket.org:team/app.git', 'bitbucket.org', 'team/app', true, 'https://bitbucket.org/team/app.git', 'git@bitbucket.org:team/app.git'],
            ['ssh://forgejo@git.example.com:2222/team/app.git', 'git.example.com', 'team/app', true, 'https://git.example.com/team/app.git', 'ssh://forgejo@git.example.com:2222/team/app.git'],
        ] as [$in, $host, $path, $ssh, $https, $sshUrl]) {
            $r = GitRepo::parse($in);
            $this->assertSame([$host, $path, $ssh, $https, $sshUrl], [$r->host, $r->path, $r->viaSsh, $r->httpsUrl(), $r->sshUrl()], $in);
        }
        $this->assertSame('[git.example.com]:2222', GitRepo::parse('ssh://git@git.example.com:2222/a/b.git')->knownHostsName());
        $this->assertSame('git.example.com', GitRepo::parse('git@git.example.com:a/b.git')->knownHostsName());
    }

    public function test_hostile_addresses_are_refused(): void
    {
        foreach (['', 'http://github.com/a/b', 'https://github.com/a/../b', 'https://github.com:8443/a/b', 'git@github.com:a/b.git;rm -rf', 'git@github.com:-oProxyCommand=x/b.git',
            'ssh://git@localhost/a/b.git', 'file:///etc/passwd', 'git@github.com:a', 'git@github.com:a/b/c.git', 'a/b/c', 'git@999.1.1.1:a/b.git', 'git@203.0.113.5:../b.git', 'https://github.com/a/b?x=1', 'https://github.com/a/ b', "https://github.com/a/b\nHost: evil", 'git@github.com:a//b.git'] as $bad) {
            try {
                GitRepo::parse($bad);
                $this->fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- what the control plane will talk to ----------------------------------------------

    public function test_it_will_not_connect_to_a_private_address_however_it_is_reached(): void
    {
        $remote = $this->app->make(GitRemote::class);
        // An address typed in as a number is as private as a name that points to one.
        foreach (['10.0.0.1', '127.0.0.1', '169.254.169.254', '192.168.0.9'] as $ip) {
            try {
                $remote->publicRefs(GitRepo::parse("https://{$ip}/a/b"));
                $this->fail($ip);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a public address', $e->getMessage());
            }
        }
        $this->dns += ['evil.example.com' => '10.0.0.5', 'meta.example.com' => '169.254.169.254', 'loop.example.com' => '127.0.0.1', 'mixed.example.com' => '203.0.113.9,192.168.1.1'];
        foreach (['evil', 'meta', 'loop', 'mixed'] as $name) {
            try {
                $remote->publicRefs(GitRepo::parse("https://{$name}.example.com/a/b"));
                $this->fail($name);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a public address', $e->getMessage());
            }
        }
        $this->assertSame([], $this->sent, 'Not one request was made.');

        $this->expectExceptionMessage('Could not find');
        $remote->publicRefs(GitRepo::parse('https://unknown.example.com/a/b'));
    }

    public function test_a_redirect_to_a_private_address_is_not_followed(): void
    {
        $this->dns['trap.example.com'] = '10.1.1.1';
        $this->web['https://git.example.com/team/app.git/info/refs'] = ['status' => 302, 'location' => 'https://trap.example.com/steal'];

        $this->expectException(RuntimeException::class);
        $this->app->make(GitRemote::class)->publicRefs(GitRepo::parse('https://git.example.com/team/app'));
    }

    public function test_a_redirect_to_a_public_https_host_is_followed_but_not_to_plain_http(): void
    {
        $this->dns['moved.example.com'] = '203.0.113.7';
        $this->web['https://git.example.com/team/app.git/info/refs'] = ['status' => 301, 'location' => 'https://moved.example.com/team/app.git/info/refs?service=git-upload-pack'];
        $this->web['https://moved.example.com/'] = ['status' => 200, 'type' => 'application/x-git-upload-pack-advertisement', 'body' => $this->advertisement()];
        $this->assertSame('main', $this->app->make(GitRemote::class)->publicRefs(GitRepo::parse('https://git.example.com/team/app'))['default']);

        $this->web['https://git.example.com/team/app.git/info/refs'] = ['status' => 301, 'location' => 'http://moved.example.com/x'];
        $this->assertNull($this->app->make(GitRemote::class)->publicRefs(GitRepo::parse('https://git.example.com/team/app')));
    }

    public function test_the_reference_advertisement_gives_branches_default_first_and_their_commits(): void
    {
        $refs = GitRemote::parseRefs($this->advertisement(['dev' => 'b', 'main' => 'a', 'feature/x' => 'c'], 'main'));

        $this->assertSame(['main', 'dev', 'feature/x'], $refs['branches']);
        $this->assertSame('main', $refs['default']);
        $this->assertSame(str_repeat('a', 40), $refs['shas']['main']);
    }

    public function test_only_a_real_git_answer_counts_as_public(): void
    {
        $remote = $this->app->make(GitRemote::class);
        $repo = GitRepo::parse('https://git.example.com/team/app');

        $this->web['https://git.example.com/'] = ['status' => 401, 'body' => 'login'];
        $this->assertNull($remote->publicRefs($repo), 'A login wall is private.');

        $this->web['https://git.example.com/'] = ['status' => 200, 'type' => 'text/html', 'body' => '<html>sign in</html>'];
        $this->assertNull($remote->publicRefs($repo), 'A sign-in page that answers 200 is not git.');

        $this->publicRepo();
        $this->web = array_reverse($this->web);
        $this->assertSame(['main', 'dev'], $remote->publicRefs($repo)['branches']);
    }

    // ---- how a repository is reached ------------------------------------------------------

    public function test_a_public_repository_is_cloned_with_no_login_and_its_branches_are_listed(): void
    {
        $this->publicRepo();

        $found = $this->app->make(GitSources::class)->inspect('org-1', 'https://git.example.com/team/app');

        $this->assertSame(['https', 'https://git.example.com/team/app.git', 'main', ['main', 'dev'], null], [$found['source'], $found['url'], $found['default'], $found['branches'], $found['fingerprint']]);
        $this->assertStringContainsString('every five minutes', $found['note']);
    }

    public function test_a_private_repository_gets_a_deploy_key_and_the_hosts_fingerprint(): void
    {
        $found = $this->app->make(GitSources::class)->inspect('org-1', 'git@git.example.com:team/app.git');

        $this->assertSame(['deploy_key', 'git@git.example.com:team/app.git'], [$found['source'], $found['url']]);
        $this->assertMatchesRegularExpression('/^SHA256:[A-Za-z0-9+\/]{43}$/', $found['fingerprint']);
        $this->assertStringContainsString($found['fingerprint'], $found['note']);
    }

    public function test_a_host_that_cannot_be_reached_says_so(): void
    {
        $this->dns['down.example.com'] = '203.0.113.50';

        $this->expectExceptionMessage('could not be reached over SSH');
        $this->app->make(GitSources::class)->inspect('org-1', 'https://down.example.com/team/app');
    }

    public function test_a_github_repository_the_app_can_read_uses_the_app(): void
    {
        DB::table('github_apps')->insert(['organization_id' => 'org-1', 'app_id' => 1, 'slug' => 's', 'owner' => 'acme-dev', 'html_url' => 'h', 'created_at' => now(), 'updated_at' => now()]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        app(Vault::class)->put('org-1', 'github:app_private_key', $pem);
        $this->web['https://api.github.com/repos/acme-dev/shop/installation'] = ['status' => 200, 'body' => json_encode(['id' => 7, 'account' => ['login' => 'acme-dev']]), 'type' => 'application/json'];
        $this->app->forgetInstance(\App\Lib\GitHub\GitHubApp::class);
        $this->app->forgetInstance(GitSources::class);

        $found = $this->app->make(GitSources::class)->inspect('org-1', 'acme-dev/shop');

        $this->assertSame(['github_app', 'git@github.com:acme-dev/shop.git'], [$found['source'], $found['url']]);
    }

    public function test_a_public_repository_is_read_for_what_it_holds_where_the_host_serves_raw_files(): void
    {
        $this->web['https://gitlab.com/group/app/-/raw/main/composer.json'] = ['status' => 200, 'body' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0']])];
        $this->web['https://gitlab.com/group/app/-/raw/main/.env.example'] = ['status' => 200, 'body' => "APP_NAME=x\nSTRIPE_KEY=\n"];

        $found = $this->app->make(GitSources::class)->detect('org-1', 'https://gitlab.com/group/app.git', 'https', 'main');

        $this->assertSame(['Laravel 12.0', '^8.3', ['APP_NAME' => 'x', 'STRIPE_KEY' => '']], [$found['framework'], $found['php'], $found['env']]);
        $this->assertSame(['framework' => null, 'php' => null, 'frontend' => null, 'env' => []], $this->app->make(GitSources::class)->detect('org-1', 'https://git.example.com/team/app.git', 'https', 'main'), 'A host with no known raw address tells us nothing.');
    }

    // ---- the application ------------------------------------------------------------------

    private function answers(array $git, array $over = []): array
    {
        return $over + ['name' => 'shop', 'repository' => '', 'git' => $git, 'branch' => 'main', 'compute' => 'single', 'provider' => 'digitalocean',
            'server' => ['mode' => 'existing', 'node' => 'web-1.org-1'], 'database' => 'sqlite'];
    }

    public function test_a_public_repository_application_has_no_key_no_access_step_and_polls(): void
    {
        $this->publicRepo();
        $git = $this->app->make(GitSources::class)->inspect('org-1', 'https://git.example.com/team/app');
        $plans = $this->app->make(NewApplications::class);

        $plan = $plans->plan('org-1', $this->answers($git));
        $this->assertNotContains('access', $plan['steps']);

        $started = $plans->start('org-1', $plan, 'matt');
        $site = DB::table('sites')->find($started['site']);
        $this->assertSame(['https', 'https://git.example.com/team/app.git', 1, null], [$site->git_source, $site->repository, (int) $site->push_to_deploy, $site->git_host_key]);
    }

    public function test_a_private_repository_on_another_host_pins_the_host_key_and_waits_for_the_deploy_key(): void
    {
        $git = $this->app->make(GitSources::class)->inspect('org-1', 'git@git.example.com:team/app.git');
        $plans = $this->app->make(NewApplications::class);

        $plan = $plans->plan('org-1', $this->answers($git));
        $this->assertContains('access', $plan['steps']);
        $this->assertSame('access', $plan['steps'][array_search('access', $plan['steps'], true)]);
        $this->assertLessThan(array_search('deploy', $plan['steps'], true), array_search('access', $plan['steps'], true), 'Before the first deploy.');

        $site = DB::table('sites')->find($plans->start('org-1', $plan, 'matt')['site']);
        $this->assertSame(['deploy_key', 'git.example.com '.self::FP_KEY], [$site->git_source, $site->git_host_key]);
    }

    public function test_a_host_key_that_changed_since_it_was_shown_stops_the_application_being_made(): void
    {
        $git = $this->app->make(GitSources::class)->inspect('org-1', 'git@git.example.com:team/app.git');
        $git['fingerprint'] = 'SHA256:'.str_repeat('x', 43);
        $plans = $this->app->make(NewApplications::class);
        $plan = $plans->plan('org-1', $this->answers($git));

        try {
            $plans->start('org-1', $plan, 'matt');
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not the SHA256:xxxx', $e->getMessage());
        }
        $this->assertSame(0, DB::table('sites')->count());
    }

    public function test_choices_that_cannot_work_are_refused(): void
    {
        $plans = $this->app->make(NewApplications::class);
        $this->publicRepo();
        $git = $this->app->make(GitSources::class)->inspect('org-1', 'https://git.example.com/team/app');

        foreach ([
            [['url' => 'https://git.example.com/team/app.git', 'source' => 'weird'], [], 'repository: choose the repository again'],
            [['url' => 'https://git.example.com/team/app.git', 'source' => 'github_app'], [], 'repository: choose the repository again'],
            [['url' => 'file:///etc/passwd', 'source' => 'https'], [], 'repository:'],
            [$git, ['previews' => true], 'previews: pull request previews need the GitHub App'],
        ] as [$g, $over, $message]) {
            try {
                $plans->plan('org-1', $this->answers($g, $over));
                $this->fail($message);
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }
    }

    private function site(string $repository, string $source, ?string $hostKey = null): stdClass
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', $repository, 'main', $hostKey);
        DB::table('sites')->where('id', $site->id)->update(['git_source' => $source]);

        return DB::table('sites')->find($site->id);
    }

    public function test_the_setup_recipe_writes_a_key_and_the_pinned_host_only_where_they_are_used(): void
    {
        $sites = $this->app->make(Sites::class);
        $ids = fn (stdClass $s) => array_column($sites->setupRecipe($s)['steps'], 'id');

        $this->assertNotContains('deploy-key', $ids($this->site('https://git.example.com/team/app.git', 'https')), 'A public repository needs no key on the server.');
        $this->assertNotContains('known-hosts', $ids(DB::table('sites')->find(DB::table('sites')->value('id'))));
        DB::table('sites')->delete();

        $custom = $this->site('git@git.example.com:team/app.git', 'deploy_key', 'git.example.com '.self::FP_KEY);
        $steps = collect($sites->setupRecipe($custom)['steps']);
        $this->assertSame('git.example.com '.self::FP_KEY."\n", base64_decode($steps->firstWhere('id', 'known-hosts')['content_b64']));
        $this->assertStringEndsWith('known_hosts_git', $steps->firstWhere('id', 'known-hosts')['path']);
        $this->assertSame(['deploy', '/home/deploy'], [$steps->firstWhere('id', 'known-hosts')['owner'], $steps->firstWhere('id', 'known-hosts')['base']]);
        DB::table('sites')->delete();

        $github = $this->site('git@github.com:acme/shop.git', 'deploy_key');
        $this->assertStringContainsString('github.com ssh-ed25519', base64_decode(collect($sites->setupRecipe($github)['steps'])->firstWhere('id', 'known-hosts')['content_b64']));
    }

    public function test_a_host_with_no_pinned_key_is_never_trusted_by_default(): void
    {
        $custom = $this->site('git@git.example.com:team/app.git', 'deploy_key');

        $this->expectExceptionMessage('No SSH host key is pinned');
        $this->app->make(Sites::class)->setupRecipe($custom);
    }

    public function test_the_clone_matches_the_source(): void
    {
        $deployer = $this->app->make(Deployer::class);
        $public = collect($deployer->fetchRecipe($this->site('https://git.example.com/team/app.git', 'https'), '/r')['steps'])->firstWhere('id', 'clone');

        $this->assertContains('GIT_TERMINAL_PROMPT=0', $public['args']);
        $this->assertContains('credential.helper=', $public['args']);
        $this->assertStringNotContainsString('core.sshCommand', implode(' ', $public['args']));
        $this->assertSame(['--', 'https://git.example.com/team/app.git', '/r'], array_slice($public['args'], -3));
        DB::table('sites')->delete();

        $private = collect($deployer->fetchRecipe($this->site('ssh://git@git.example.com:2222/team/app.git', 'deploy_key', '[git.example.com]:2222 '.self::FP_KEY), '/r')['steps'])->firstWhere('id', 'clone');
        $this->assertStringContainsString('known_hosts_git', implode(' ', $private['args']));
        $this->assertSame(['--', 'ssh://git@git.example.com:2222/team/app.git', '/r'], array_slice($private['args'], -3));
    }

    // ---- who may ask for a deploy ---------------------------------------------------------

    public function test_each_hosts_push_is_understood_and_its_proof_checked(): void
    {
        $secret = str_repeat('ab', 20);
        $sha = str_repeat('c', 40);
        $github = json_encode(['ref' => 'refs/heads/main', 'after' => $sha]);

        $this->assertSame(['main', $sha, 'github'], array_values(GitPush::parse(['x-github-event' => 'push', 'x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $github, $secret)], $github, json_decode($github, true), $secret)));
        $this->assertNull(GitPush::parse(['x-github-event' => 'push', 'x-hub-signature-256' => 'sha256='.hash_hmac('sha256', $github, 'wrong')], $github, json_decode($github, true), $secret), 'A signature made with another secret.');

        $gitea = json_encode(['ref' => 'refs/heads/main', 'after' => $sha]);
        $this->assertSame('gitea', GitPush::parse(['x-gitea-event' => 'push', 'x-gitea-signature' => hash_hmac('sha256', $gitea, $secret)], $gitea, json_decode($gitea, true), $secret)['host']);
        $this->assertNull(GitPush::parse(['x-gitea-event' => 'push', 'x-gitea-signature' => 'deadbeef'], $gitea, json_decode($gitea, true), $secret));

        $gitlab = json_encode(['ref' => 'refs/heads/main', 'checkout_sha' => $sha]);
        $this->assertSame('gitlab', GitPush::parse(['x-gitlab-event' => 'Push Hook', 'x-gitlab-token' => $secret], $gitlab, json_decode($gitlab, true), $secret)['host']);
        $this->assertNull(GitPush::parse(['x-gitlab-event' => 'Push Hook', 'x-gitlab-token' => 'nope'], $gitlab, json_decode($gitlab, true), $secret));
        $this->assertNull(GitPush::parse(['x-gitlab-event' => 'Tag Push Hook'], $gitlab, json_decode($gitlab, true), $secret), 'Not a push.');

        $bitbucket = json_encode(['push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => $sha]]]]]]);
        $this->assertSame(['main', $sha, 'bitbucket'], array_values(GitPush::parse([], $bitbucket, json_decode($bitbucket, true), $secret)));

        $deleted = json_encode(['ref' => 'refs/heads/main', 'deleted' => true]);
        $this->assertNull(GitPush::parse([], $deleted, json_decode($deleted, true), $secret));
        $tag = json_encode(['ref' => 'refs/tags/v1']);
        $this->assertNull(GitPush::parse([], $tag, json_decode($tag, true), $secret));
    }

    public function test_the_webhook_address_deploys_the_configured_branch_only_with_its_secret(): void
    {
        $site = $this->site('git@git.example.com:team/app.git', 'deploy_key', 'git.example.com '.self::FP_KEY);
        DB::table('sites')->where('id', $site->id)->update(['push_to_deploy' => true, 'current_release' => 'r1']);
        $secret = $this->app->make(Sites::class)->webhookSecret($site);
        $sha = str_repeat('d', 40);
        $post = fn (string $sec, array $body, array $headers = []) => $this->call('POST', "/api/git/hook/{$site->id}/{$sec}", [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));

        $post(str_repeat('0', 40), ['ref' => 'refs/heads/main', 'after' => $sha])->assertNotFound();
        $this->assertSame(0, DB::table('operations')->count());

        $post($secret, ['ref' => 'refs/heads/other', 'after' => $sha])->assertOk()->assertJsonPath('ignored', 'not the branch this application deploys');
        $this->assertSame(0, DB::table('operations')->count());

        $post($secret, ['ref' => 'refs/heads/main', 'after' => $sha], ['HTTP_X_GITLAB_EVENT' => 'Push Hook', 'HTTP_X_GITLAB_TOKEN' => 'wrong'])->assertOk()->assertJsonPath('ignored', 'not a push to a branch, or its signature was wrong');
        $this->assertSame(0, DB::table('operations')->count());

        $post($secret, ['ref' => 'refs/heads/main', 'after' => $sha], ['HTTP_X_GITLAB_EVENT' => 'Push Hook', 'HTTP_X_GITLAB_TOKEN' => $secret])->assertStatus(202);
        $this->assertSame(1, DB::table('operations')->where('kind', 'deploy')->count());
        $this->assertSame($secret, $this->app->make(Sites::class)->webhookSecret($site), 'The address is stable.');
    }

    // ---- public repositories we cannot be told about -------------------------------------

    public function test_a_public_repository_is_polled_and_a_new_commit_deploys(): void
    {
        $site = $this->site('https://git.example.com/team/app.git', 'https');
        DB::table('sites')->where('id', $site->id)->update(['push_to_deploy' => true, 'current_release' => 'r1']);
        $this->publicRepo();

        $this->artisan('sites:poll-git')->assertSuccessful();
        $this->assertSame([str_repeat('a', 40), 0], [DB::table('sites')->value('git_seen_sha'), DB::table('operations')->count()], 'The first look only records where it is.');

        $this->artisan('sites:poll-git')->assertSuccessful();
        $this->assertSame(0, DB::table('operations')->count(), 'Unchanged: nothing to do.');

        DB::table('sites')->update(['git_seen_sha' => str_repeat('9', 40)]);
        $this->artisan('sites:poll-git')->expectsOutputToContain('is new')->assertSuccessful();
        $this->assertSame([1, str_repeat('a', 40)], [DB::table('operations')->where('kind', 'deploy')->count(), DB::table('sites')->value('git_seen_sha')]);
    }

    public function test_polling_leaves_alone_a_site_that_does_not_deploy_on_push_and_one_never_deployed(): void
    {
        $this->site('https://git.example.com/team/app.git', 'https');
        $this->publicRepo();
        DB::table('sites')->update(['push_to_deploy' => false, 'current_release' => 'r1', 'git_seen_sha' => str_repeat('9', 40)]);
        $this->artisan('sites:poll-git')->assertSuccessful();
        DB::table('sites')->update(['push_to_deploy' => true, 'current_release' => null]);
        $this->artisan('sites:poll-git')->assertSuccessful();

        $this->assertSame(0, DB::table('operations')->count());
    }

    // ---- the wait for a deploy key ---------------------------------------------------------

    public function test_a_new_private_application_waits_for_its_key_then_carries_on(): void
    {
        $site = $this->site('git@git.example.com:team/app.git', 'deploy_key', 'git.example.com '.self::FP_KEY);
        $id = $this->app->make(\App\Lib\Operations\Operations::class)->create('org-1', 'app_create', 'Create shop', 'console', 'matt', ['access'], $site->id, $site->node_id,
            meta: ['steps' => ['access'], 'done' => [], 'children' => []]);
        $runner = $this->app->make(OperationRunner::class);

        $this->assertSame('waiting', $runner->run($id));
        $this->assertSame('waiting', $runner->run($id));
        $log = (string) DB::table('operations')->find($id)->log;
        $this->assertSame(1, substr_count($log, 'Add this as a read-only deploy key'), 'Said once, not every time it looks.');
        $this->assertStringContainsString(trim((string) $site->deploy_key_public), $log);

        $this->accepted = true;
        $this->assertSame('succeeded', $runner->run($id));
        $this->assertStringContainsString('has accepted the deploy key', (string) DB::table('operations')->find($id)->log);
    }

    public function test_once_the_key_works_a_branch_the_repository_lacks_is_said_and_waited_on_until_it_is_changed(): void
    {
        $site = $this->site('git@git.example.com:team/app.git', 'deploy_key', 'git.example.com '.self::FP_KEY);
        $id = $this->app->make(\App\Lib\Operations\Operations::class)->create('org-1', 'app_create', 'Create shop', 'console', 'matt', ['access'], $site->id, $site->node_id,
            meta: ['steps' => ['access'], 'done' => [], 'children' => []]);
        $runner = $this->app->make(OperationRunner::class);
        $this->accepted = true;
        $this->keyedAdvertisement = $this->advertisement(['master' => 'a', 'dev' => 'b'], 'master');

        $this->assertSame('waiting', $runner->run($id));
        $this->assertSame('waiting', $runner->run($id));
        $log = (string) DB::table('operations')->find($id)->log;
        $this->assertSame(1, substr_count($log, 'has no branch called main. It has: master, dev.'), 'Said once.');
        $this->assertStringContainsString('has no branch called main', Sites::awaitingDeployKey(DB::table('sites')->find($site->id))['problem']);

        DB::table('sites')->where('id', $site->id)->update(['branch' => 'master']);
        $this->assertSame('succeeded', $runner->run($id));
        $this->assertStringContainsString('and master exists', (string) DB::table('operations')->find($id)->log);
    }

    public function test_the_wait_for_a_key_gives_up_after_an_hour_and_says_what_to_do(): void
    {
        $site = $this->site('git@git.example.com:team/app.git', 'deploy_key', 'git.example.com '.self::FP_KEY);
        $id = $this->app->make(\App\Lib\Operations\Operations::class)->create('org-1', 'app_create', 'Create shop', 'console', 'matt', ['access'], $site->id, $site->node_id,
            meta: ['steps' => ['access'], 'done' => [], 'children' => []]);
        DB::table('operations')->where('id', $id)->update(['created_at' => now()->subMinutes(61)]);

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($id));
        $this->assertStringContainsString('was not added', DB::table('operations')->find($id)->error);
    }

    public function test_the_webhook_secret_is_forgotten_with_the_site(): void
    {
        $site = $this->site('git@git.example.com:team/app.git', 'deploy_key', 'git.example.com '.self::FP_KEY);
        $sites = $this->app->make(Sites::class);
        $sites->webhookSecret($site);
        $this->assertTrue(app(Vault::class)->has('org-1', 'git-webhook:web-1.org-1:shop'));

        $sites->forget($site);

        $this->assertFalse(app(Vault::class)->has('org-1', 'git-webhook:web-1.org-1:shop'));
    }

    public function test_a_server_with_only_an_ip_address_and_a_plain_path_is_understood_and_keeps_its_meaning(): void
    {
        foreach ([
            ['git@203.0.113.5:/srv/git/app.git', 'git@203.0.113.5:/srv/git/app.git', 'srv/git/app'],
            ['git@203.0.113.5:app.git', 'git@203.0.113.5:app.git', 'app'],
            ['ssh://git@203.0.113.5/srv/git/app.git', 'git@203.0.113.5:/srv/git/app.git', 'srv/git/app'],
            ['ssh://git@203.0.113.5:2222/srv/git/app.git', 'ssh://git@203.0.113.5:2222/srv/git/app.git', 'srv/git/app'],
        ] as [$in, $url, $path]) {
            $r = GitRepo::parse($in);
            $this->assertSame([$url, $path], [$r->sshUrl(), $r->path], $in);
            Sites::validate('demo', 'demo.example.com', $r->sshUrl(), 'main');
        }
        $this->assertSame('git@github.com:acme/shop.git', GitRepo::parse('ssh://git@github.com/acme/shop.git')->sshUrl(), 'GitHub never needs the slash.');
    }

    public function test_an_ip_address_server_gets_a_pinned_key_and_a_known_hosts_name(): void
    {
        
        $found = $this->app->make(GitSources::class)->inspect('org-1', 'git@203.0.113.5:/srv/git/app.git');

        $this->assertSame(['deploy_key', 'git@203.0.113.5:/srv/git/app.git'], [$found['source'], $found['url']]);
        $this->assertSame('203.0.113.5 '.self::FP_KEY, $this->app->make(GitRemote::class)->hostKey(GitRepo::parse($found['url']))['line']);
    }

    public function test_the_post_receive_hook_posts_what_the_webhook_expects(): void
    {
        $script = GitPush::postReceiveScript('main', 'https://cp.example.com/api/git/hook/7/'.str_repeat('ab', 20));

        $path = sys_get_temp_dir().'/hook-'.getmypid().'.sh';
        file_put_contents($path, $script);
        $syntax = new \Symfony\Component\Process\Process(['sh', '-n', $path]);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());

        // Run it with curl replaced by a recorder.
        $bin = sys_get_temp_dir().'/hookbin-'.getmypid();
        @mkdir($bin);
        file_put_contents("{$bin}/curl", "#!/bin/sh\necho \"\$@\" > {$bin}/out\n");
        chmod("{$bin}/curl", 0o755);
        $run = new \Symfony\Component\Process\Process(['sh', $path], null, ['PATH' => "{$bin}:/usr/bin:/bin"], str_repeat('0', 40).' '.str_repeat('e', 40)." refs/heads/main\n".str_repeat('0', 40).' '.str_repeat('f', 40)." refs/heads/other\n");
        $run->run();
        $sent = (string) @file_get_contents("{$bin}/out");
        array_map('unlink', glob("{$bin}/*"));
        @rmdir($bin);
        unlink($path);

        $this->assertStringContainsString('"ref":"refs/heads/main","after":"'.str_repeat('e', 40).'"', $sent);
        $this->assertStringNotContainsString(str_repeat('f', 40), $sent, 'Only the deployed branch asks.');
        $this->assertStringContainsString('/api/git/hook/7/', $sent);

        $parsed = GitPush::parse([], '{}', ['ref' => 'refs/heads/main', 'after' => str_repeat('e', 40)], 'x');
        $this->assertSame('main', $parsed['branch']);

        $this->expectException(InvalidArgumentException::class);
        GitPush::postReceiveScript("main'; rm -rf /; '", 'https://cp.example.com/x');
    }
}
