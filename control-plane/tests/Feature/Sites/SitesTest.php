<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * Sites carry a private key and an .env onto a root agent, and a deploy
 * builds beside the live release. These tests hold the lines that matter:
 * secrets never land in the database, nothing user supplied can become a git
 * option, and a failure before activation never touches what is live.
 */
final class SitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function github(?HttpFactory $http = null): GitHubApp
    {
        return new GitHubApp($http ?? new HttpFactory, $this->app->make(Vault::class), new AuditLog);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog);
    }

    private function site(): stdClass
    {
        return $this->sites()->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git');
    }

    /** @return array<string, array<string, mixed>> */
    private static function stepsById(array $spec): array
    {
        return array_column($spec['steps'], null, 'id');
    }

    // ---------------------------------------------------------------- create

    public function test_the_private_key_lives_in_the_vault_and_not_the_database(): void
    {
        $site = $this->site();

        $row = (array) DB::table('sites')->where('id', $site->id)->first();
        foreach ($row as $value) {
            $this->assertStringNotContainsString('PRIVATE KEY', (string) $value);
        }

        $this->assertStringStartsWith('ssh-ed25519 ', $site->deploy_key_public);
        $this->assertNotNull(PublicKeyLoader::loadPublicKey($site->deploy_key_public));
    }

    public function test_each_site_gets_its_own_key(): void
    {
        $a = $this->site();
        $b = $this->sites()->create('org-1', 'web-1.org-1', 'other', 'other.example.com', 'git@github.com:acme/other.git');

        $this->assertNotSame($a->deploy_key_public, $b->deploy_key_public);
    }

    public function test_the_starting_env_boots_laravel_in_production(): void
    {
        $env = $this->sites()->env($this->site());

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $env);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('DB_DATABASE=/home/deploy/demo/shared/database.sqlite', $env);
    }

    public function test_creation_is_audited(): void
    {
        $this->site();

        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.created')->count());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function hostileInput(): array
    {
        return [
            'branch read as a git option' => ['demo', 'demo.example.com', 'git@github.com:a/b.git', '--upload-pack=touch /tmp/x'],
            'branch climbing paths' => ['demo', 'demo.example.com', 'git@github.com:a/b.git', 'a/../../b'],
            'repository over plain http' => ['demo', 'demo.example.com', 'http://github.com/a/b.git', 'main'],
            'repository with a path out' => ['demo', 'demo.example.com', 'git@evil.com:a/../b.git', 'main'],
            'repository as a bare name' => ['demo', 'demo.example.com', 'evil', 'main'],
            'repository with a command' => ['demo', 'demo.example.com', 'git@evil.com:a/b.git;rm', 'main'],
            'repository not ending .git' => ['demo', 'demo.example.com', 'git@evil.com:a/b', 'main'],
            'repository as an option' => ['demo', 'demo.example.com', '-oProxyCommand=x', 'main'],
            'name with a slash' => ['../etc', 'demo.example.com', 'git@github.com:a/b.git', 'main'],
            'domain with a space' => ['demo', 'demo.example.com; x', 'git@github.com:a/b.git', 'main'],
        ];
    }

    #[DataProvider('hostileInput')]
    public function test_hostile_input_is_refused($name, $domain, $repository, $branch): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sites::validate($name, $domain, $repository, $branch);
    }

    // ----------------------------------------------------------------- setup

    public function test_secrets_are_marked_sensitive_and_private(): void
    {
        $steps = self::stepsById($this->sites()->setupRecipe($this->site()));

        foreach (['deploy-key', 'env'] as $id) {
            $this->assertTrue($steps[$id]['sensitive'], "{$id} would be logged.");
            $this->assertSame('0600', $steps[$id]['mode']);
        }

        $this->assertStringContainsString('OPENSSH PRIVATE KEY', base64_decode($steps['deploy-key']['content_b64']));
    }

    // Audit 2026-11-08, finding 1: root must not write or chown, by path, anything
    // the application can replace. Every step under the deploy home is an owned
    // step, confined to that home, and nothing chowns.
    public function test_nothing_under_the_deploy_home_is_written_or_chowned_by_path_as_root(): void
    {
        $steps = $this->sites()->setupRecipe($this->site())['steps'];

        foreach ($steps as $step) {
            $this->assertFalse(($step['name'] ?? '') === 'chown' || ($step['name'] ?? '') === 'install', "step {$step['id']} changes ownership by path");
            if (str_starts_with((string) ($step['path'] ?? ''), '/home/deploy')) {
                $this->assertSame('deploy', $step['owner'] ?? null, "step {$step['id']} is not owned");
                $this->assertSame('/home/deploy', $step['base'] ?? null, "step {$step['id']} is not confined");
            }
        }

        $ids = array_column($steps, 'id');
        foreach (['dir-0', 'dir-1', 'dir-2', 'dir-3', 'env', 'deploy-key', 'known-hosts'] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertNotContains('own-secrets', $ids);
    }

    public function test_nginx_is_checked_before_it_is_reloaded(): void
    {
        $ids = array_column($this->sites()->setupRecipe($this->site())['steps'], 'id');

        $this->assertGreaterThan(array_search('nginx-test', $ids, true), array_search('reload-nginx', $ids, true));
    }

    public function test_the_vhost_follows_the_symlink_rather_than_caching_it(): void
    {
        $vhost = base64_decode(self::stepsById($this->sites()->setupRecipe($this->site()))['vhost']['content_b64']);

        $this->assertStringContainsString('server_name demo.example.com;', $vhost);
        $this->assertStringContainsString('root /home/deploy/demo/current/public;', $vhost);
        $this->assertStringContainsString('$realpath_root$fastcgi_script_name', $vhost);
    }

    // ----------------------------------------------------------------- https

    private function vhostOf(stdClass $site): string
    {
        return base64_decode(self::stepsById($this->sites()->setupRecipe($site))['vhost']['content_b64']);
    }

    public function test_before_tls_the_vhost_answers_challenges_and_never_names_a_certificate(): void
    {
        // nginx refuses to load a vhost whose certificate does not exist yet,
        // which would take every site on the node down with it.
        $vhost = $this->vhostOf($this->site());

        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/', $vhost);
        $this->assertStringContainsString('root /var/www/acme;', $vhost);
        $this->assertStringNotContainsString('ssl_certificate', $vhost);
        $this->assertStringNotContainsString('443', $vhost);
    }

    public function test_with_tls_port_80_still_answers_challenges_and_redirects_everything_else(): void
    {
        $site = $this->sites()->markTls($this->site());
        $vhost = $this->vhostOf($site);

        $this->assertStringContainsString('listen 443 ssl http2;', $vhost);
        $this->assertStringContainsString('ssl_certificate /etc/letsencrypt/live/demo.example.com/fullchain.pem;', $vhost);
        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/', $vhost, 'Renewal would fail.');
        $this->assertStringContainsString('return 301 https://$host$request_uri;', $vhost);
        $this->assertStringContainsString('root /home/deploy/demo/current/public;', $vhost);
    }

    public function test_only_the_front_controller_executes(): void
    {
        $vhost = $this->vhostOf($this->site());

        $this->assertStringContainsString('location = /index.php {', $vhost);
        $this->assertMatchesRegularExpression('/location ~ \\\\.php\\$ \\{\\s*rewrite \\^ \\/index\\.php last;\\s*\\}/', $vhost);
        $this->assertSame(1, substr_count($vhost, 'fastcgi_pass'), 'Only one location may reach PHP-FPM.');
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.4-fpm.sock;', $vhost);
    }

    public function test_marking_tls_moves_app_url_to_https(): void
    {
        $site = $this->sites()->markTls($this->site());

        $this->assertTrue((bool) $site->tls);
        $this->assertStringContainsString('APP_URL=https://demo.example.com', $this->sites()->env($site));
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.tls_enabled')->count());
    }

    public function test_certificate_issuance_is_guarded_and_names_the_lineage_after_the_domain(): void
    {
        $step = $this->sites()->certificateRecipe($this->site())['steps'][0];

        $this->assertSame(['test', '-f', '/etc/letsencrypt/live/demo.example.com/fullchain.pem'], $step['unless']);
        $this->assertContains('--cert-name', $step['args']);
        $this->assertContains('--non-interactive', $step['args']);
        $this->assertContains('--deploy-hook', $step['args']);
    }

    public function test_securing_switches_tls_on_only_after_the_certificate_step(): void
    {
        $site = $this->site();
        [$deployer, $http] = $this->deployerAgainst(array_fill(0, 3, ['status' => 'succeeded']));

        $deployer->secure($site);

        $vhosts = [];
        $http->assertSent(function (Request $r) use (&$vhosts) {
            $steps = array_column($r['args']['spec']['steps'], null, 'id');
            $vhosts[] = isset($steps['vhost']) ? base64_decode($steps['vhost']['content_b64']) : (isset($steps['certbot']) ? 'certbot' : '?');

            return true;
        });

        $this->assertCount(3, $vhosts);
        $this->assertStringNotContainsString('ssl_certificate', $vhosts[0]);
        $this->assertSame('certbot', $vhosts[1]);
        $this->assertStringContainsString('ssl_certificate', $vhosts[2]);
    }

    public function test_a_failed_certificate_leaves_tls_off(): void
    {
        $site = $this->site();
        [$deployer] = $this->deployerAgainst([
            ['status' => 'succeeded'],
            ['status' => 'failed', 'error' => 'recipe step "certbot" failed: unauthorized'],
        ]);

        try {
            $deployer->secure($site);
            $this->fail('Should have failed.');
        } catch (RuntimeException) {
        }

        $this->assertFalse((bool) DB::table('sites')->where('id', $site->id)->value('tls'));
    }

    // ---------------------------------------------------------------- deploy

    public function test_release_ids_match_what_the_agent_builds(): void
    {
        $fixtures = json_decode((string) file_get_contents(base_path('tests/fixtures/conformance.json')), true);

        foreach ($fixtures['release_ids'] as $case) {
            $this->assertSame(
                $case['id'],
                Deployer::releaseId(gmdate('Ymd\THis\Z', $case['unix_sec']), $case['ref']),
                'Ref '.var_export($case['ref'], true),
            );
        }
    }

    public function test_a_repository_on_any_host_is_accepted_in_the_forms_git_takes(): void
    {
        foreach (['git@github.com:a/b.git', 'git@gitlab.com:group/sub/b.git', 'ssh://git@git.example.com:2222/team/app.git', 'https://codeberg.org/a/b.git'] as $repository) {
            Sites::validate('demo', 'demo.example.com', $repository, 'main');
        }
        $this->addToAssertionCount(1);
    }

    public function test_the_clone_pins_github_and_ends_options_before_the_repository(): void
    {
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, $this->github());
        $clone = self::stepsById($deployer->fetchRecipe($this->site(), '/home/deploy/demo/releases/r1'))['clone'];
        $args = $clone['args'];

        $this->assertSame(['-u', 'deploy', '--'], array_slice($args, 0, 3), 'git must run as the deploy user.');
        $this->assertSame(['--', 'git@github.com:acme/demo.git', '/home/deploy/demo/releases/r1'], array_slice($args, -3));
        $this->assertStringContainsString('StrictHostKeyChecking=yes', implode(' ', $args));
        $this->assertStringContainsString('IdentitiesOnly=yes', implode(' ', $args));
        $this->assertNotEmpty($clone['unless']);
    }

    public function test_shared_storage_gets_the_framework_directories_even_if_the_repository_lacks_them(): void
    {
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, $this->github());
        $steps = $deployer->fetchRecipe($this->site(), '/home/deploy/demo/releases/r1')['steps'];
        $ids = array_column($steps, 'id');
        $args = implode(' ', self::stepsById(['steps' => $steps])['storage-dirs']['args']);

        foreach (['framework/views', 'framework/sessions', 'framework/cache/data', 'logs'] as $dir) {
            $this->assertStringContainsString("/home/deploy/demo/shared/storage/{$dir}", $args);
        }
        $this->assertGreaterThan(array_search('seed-storage', $ids, true), array_search('storage-dirs', $ids, true));
    }

    /**
     * @param  list<array<string, mixed>>  $responses
     * @return array{0: Deployer, 1: HttpFactory}
     */
    private function deployerAgainst(array $responses): array
    {
        $http = new HttpFactory;
        $sequence = $http->sequence();
        foreach ($responses as $r) {
            $sequence->push($r + ['outcome_known' => true], 200);
        }
        $http->fake(['*' => $sequence]);

        $gateway = new GatewayClient('http://gateway.test', 'secret', $http, new AuditLog);

        return [new Deployer($this->sites(), $gateway, new AuditLog, $this->github()), $http];
    }

    public function test_a_deploy_runs_every_stage_in_order_and_records_the_release(): void
    {
        $site = $this->site();
        [$deployer, $http] = $this->deployerAgainst([
            ['status' => 'succeeded'],                                          // setup
            ['status' => 'succeeded'],                                          // fetch
            ['status' => 'succeeded'],                                          // prepare
            ['status' => 'succeeded'],                                          // which PHP the packages need
            ['status' => 'succeeded'],                                          // build
            ['status' => 'succeeded', 'detail' => ['output' => "abc123\n"]],     // rev-parse
            ['status' => 'succeeded'],                                          // activate
            ['status' => 'succeeded'],                                          // after switch
            ['status' => 'succeeded', 'detail' => ['deleted' => []]],           // prune
        ]);

        $result = $deployer->deploy($site, now: '20261105T190000Z');

        $this->assertSame(['release' => '20261105T190000Z-main', 'commit' => 'abc123'], $result);

        $kinds = [];
        $http->assertSent(function (Request $r) use (&$kinds) {
            $kinds[] = $r['kind'];

            return true;
        });
        $this->assertSame(['apply_recipe', 'apply_recipe', 'deploy', 'exec', 'apply_recipe', 'exec', 'activate_release', 'apply_recipe', 'prune_releases'], $kinds);

        $this->assertSame('20261105T190000Z-main', DB::table('sites')->where('id', $site->id)->value('current_release'));
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.deployed')->count());
    }

    // ------------------------------------------------- workers and scheduler

    private function afterSwitch(stdClass $site): array
    {
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, $this->github());

        return self::stepsById($deployer->afterSwitchRecipe($site));
    }

    public function test_workers_restart_only_after_the_new_release_is_live(): void
    {
        // queue:restart before the swap brings workers back up on the old
        // code, which is what the demo app's stale-worker check catches.
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, $this->github());
        $build = implode(' ', array_merge(...array_column($deployer->buildRecipe('/r')['steps'], 'args')));

        $this->assertStringNotContainsString('queue:restart', $build);
        $this->assertSame(
            ['-u', 'deploy', '--', '/usr/bin/php8.4', '/home/deploy/demo/current/artisan', 'queue:restart'],
            $this->afterSwitch($this->site())['queue-restart']['args'],
        );
    }

    public function test_workers_still_on_the_previous_release_are_told_to_restart_too(): void
    {
        // A deploy that moves the cache (a database-backed cache, moved to a
        // managed database) writes the restart signal where the old workers
        // never look. The previous release reads the old store.
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, $this->github());
        $steps = array_column($deployer->afterSwitchRecipe($this->site(), '20261106T020144Z-main')['steps'], null, 'id');

        $this->assertStringContainsString('/home/deploy/demo/releases/20261106T020144Z-main/artisan queue:restart', $steps['queue-restart-previous']['args'][1]);
        $this->assertStringEndsWith('|| true', $steps['queue-restart-previous']['args'][1], 'A pruned or broken old release must not fail the deploy.');
        $ids = array_keys($steps);
        $this->assertGreaterThan(array_search('queue-restart', $ids, true), array_search('queue-restart-previous', $ids, true));
    }

    public function test_an_unexpected_previous_release_never_reaches_the_shell(): void
    {
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, $this->github());

        $steps = array_column($deployer->afterSwitchRecipe($this->site(), 'x; rm -rf /')['steps'], null, 'id');

        $this->assertArrayNotHasKey('queue-restart-previous', $steps);
    }

    public function test_workers_run_as_the_deploy_user_from_the_live_release(): void
    {
        $conf = base64_decode($this->afterSwitch($this->site())['supervisor-conf']['content_b64']);

        $this->assertStringContainsString('[program:cm-demo-worker]', $conf);
        $this->assertStringContainsString('command=/usr/bin/php8.4 /home/deploy/demo/current/artisan queue:work', $conf);
        $this->assertStringContainsString('user=deploy', $conf);
        $this->assertStringContainsString('numprocs=1', $conf);
        $this->assertStringContainsString('stdout_logfile=/var/log/cloud-manager/demo/worker.log', $conf);
    }

    // Supervisor opens its log files as root, so they must not sit anywhere the
    // application can write (audit 2026-11-08, finding 2).
    public function test_supervisor_logs_are_outside_the_applications_reach(): void
    {
        $steps = $this->afterSwitch($this->site());
        $conf = base64_decode($steps['supervisor-conf']['content_b64']);

        $this->assertStringNotContainsString('/home/deploy', implode("\n", array_filter(explode("\n", $conf), fn ($l) => str_starts_with($l, 'stdout_logfile') || str_starts_with($l, 'stderr_logfile'))));
        $this->assertSame(['dir', '/var/log/cloud-manager', '0755'], [$steps['supervisor-log-root']['type'], $steps['supervisor-log-root']['path'], $steps['supervisor-log-root']['mode']]);
        $this->assertSame('/var/log/cloud-manager/demo', $steps['supervisor-log-dir']['path']);
        // The folder exists before supervisor is told about the program.
        $order = array_keys($steps);
        $this->assertLessThan(array_search('supervisor-conf', $order, true), array_search('supervisor-log-dir', $order, true));
    }

    public function test_zero_workers_removes_the_program(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['queue_workers' => 0]);

        $step = $this->afterSwitch($this->sites()->find($site->node_id, $site->name))['supervisor-conf'];

        $this->assertSame('absent', $step['type'], 'With no workers or daemons there is nothing for supervisor to run.');
    }

    public function test_the_scheduler_runs_every_minute_as_the_deploy_user(): void
    {
        $cron = $this->afterSwitch($this->site())['scheduler'];

        // cron ignores files in cron.d whose names contain a dot.
        $this->assertStringNotContainsString('.', basename($cron['path']));
        $this->assertSame(
            "# Managed by cloud-manager.\n* * * * * deploy cd /home/deploy/demo/current && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1\n",
            base64_decode($cron['content_b64']),
        );
    }

    public function test_a_failure_after_the_swap_says_the_release_is_live(): void
    {
        $site = $this->site();
        [$deployer] = $this->deployerAgainst([
            ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'],
            ['status' => 'succeeded', 'detail' => ['output' => 'abc']],
            ['status' => 'succeeded'],
            ['status' => 'failed', 'error' => 'recipe step "supervisor-update" failed'],
        ]);

        try {
            $deployer->deploy($site, now: '20261105T190000Z');
            $this->fail('Should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('20261105T190000Z-main is live', $e->getMessage());
            $this->assertStringNotContainsString('unchanged', $e->getMessage());
        }

        $this->assertSame('20261105T190000Z-main', DB::table('sites')->where('id', $site->id)->value('current_release'));
    }

    public function test_rollback_brings_workers_along(): void
    {
        $site = $this->site();
        [$deployer, $http] = $this->deployerAgainst([
            ['status' => 'succeeded', 'detail' => ['current' => 'older']],
            ['status' => 'succeeded'],
        ]);

        $this->assertSame('older', $deployer->rollback($site));

        $kinds = [];
        $http->assertSent(function (Request $r) use (&$kinds) {
            $kinds[] = $r['kind'] === 'apply_recipe' ? $r['args']['spec']['name'] : $r['kind'];

            return true;
        });
        $this->assertSame(['rollback', 'after-switch-demo'], $kinds);
        $this->assertSame('older', DB::table('sites')->where('id', $site->id)->value('current_release'));
    }

    public function test_a_rollback_is_audited_under_the_person_who_asked_and_names_the_release(): void
    {
        $site = $this->site();
        [$deployer] = $this->deployerAgainst([
            ['status' => 'succeeded', 'detail' => ['current' => 'older']],
            ['status' => 'succeeded'],
        ]);

        $deployer->actingAs(Actor::user('matt@example.com', 'matt@example.com'))->rollback($site);

        $row = DB::table('audit_records')->where('action', 'site.rolled_back')->first();
        $this->assertSame('matt@example.com', $row->actor_id);
        $this->assertSame('older', json_decode((string) $row->metadata, true)['release']);
    }

    public function test_without_a_person_the_audit_names_the_control_plane(): void
    {
        $site = $this->site();
        [$deployer] = $this->deployerAgainst([['status' => 'succeeded', 'detail' => ['current' => 'older']], ['status' => 'succeeded']]);

        $deployer->rollback($site);

        $this->assertSame('control-plane', DB::table('audit_records')->where('action', 'site.rolled_back')->value('actor_label'));
    }

    public function test_a_rollback_the_server_does_not_name_leaves_the_live_release_alone(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['current_release' => 'newer']);
        [$deployer] = $this->deployerAgainst([['status' => 'succeeded'], ['status' => 'succeeded']]);

        try {
            $deployer->rollback(DB::table('sites')->where('id', $site->id)->first());
            $this->fail('A blank release should not be accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not say which release', $e->getMessage());
        }
        $this->assertSame('newer', DB::table('sites')->where('id', $site->id)->value('current_release'));
    }

    // ---------------------------------------------------------------- domain

    public function test_changing_domain_moves_every_reference_and_turns_tls_off_until_reissued(): void
    {
        $site = $this->sites()->markTls($this->site());
        $this->sites()->setEnvValues($site, ['SOCKET_URL' => 'wss://demo.example.com/app/key']);

        $site = $this->sites()->changeDomain($site, 'demo.example.org');
        $env = $this->sites()->env($site);

        $this->assertSame('demo.example.org', $site->domain);
        $this->assertFalse((bool) $site->tls, 'The vhost must not name a certificate that does not exist yet.');
        $this->assertStringContainsString('APP_URL=http://demo.example.org', $env);
        $this->assertStringContainsString('SOCKET_URL=wss://demo.example.org/app/key', $env);
        $this->assertStringNotContainsString('demo.example.com', $env);
        $this->assertStringContainsString('server_name demo.example.org;', $this->vhostOf($site));
    }

    public function test_an_invalid_domain_changes_nothing(): void
    {
        $site = $this->site();

        try {
            $this->sites()->changeDomain($site, 'not a domain');
            $this->fail('Should have refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame('demo.example.com', $this->sites()->find($site->node_id, $site->name)->domain);
    }

    public function test_moving_an_https_site_reissues_then_retires_the_old_certificate(): void
    {
        $site = $this->sites()->markTls($this->site());
        [$deployer, $http] = $this->deployerAgainst(array_fill(0, 5, ['status' => 'succeeded']));

        $site = $deployer->moveDomain($site, 'demo.example.org');

        $names = [];
        $http->assertSent(function (Request $r) use (&$names) {
            if ($r['kind'] === 'exec') {
                return true; // Reading the new certificate's expiry.
            }
            $names[] = $r['args']['spec']['name'];

            return true;
        });
        $this->assertSame(['site-setup-demo', 'certificate-demo', 'site-setup-demo', 'retire-certificate-demo.example.com'], $names);
        $this->assertTrue((bool) $site->tls);
        $this->assertStringContainsString('APP_URL=https://demo.example.org', $this->sites()->env($site));
    }

    public function test_retiring_a_certificate_is_guarded(): void
    {
        $step = $this->sites()->retireCertificateRecipe('old.example.com')['steps'][0];

        $this->assertSame(['delete', '--cert-name', 'old.example.com', '--non-interactive'], $step['args']);
        $this->assertSame(['test', '!', '-e', '/etc/letsencrypt/renewal/old.example.com.conf'], $step['unless']);
    }

    // ------------------------------------------------------ daemons, reverb

    public function test_a_daemon_runs_from_the_live_release_and_restarts_after_the_switch(): void
    {
        $site = $this->sites()->setDaemon($this->site(), 'horizon', 'php artisan horizon', 2);
        $steps = $this->afterSwitch($site);
        $conf = base64_decode($steps['supervisor-conf']['content_b64']);

        $this->assertStringContainsString('[program:cm-demo-horizon]', $conf);
        $this->assertStringContainsString('command=/usr/bin/php8.4 artisan horizon', $conf);
        $this->assertStringContainsString('numprocs=2', $conf);
        $this->assertSame(['restart', 'cm-demo-horizon:*'], $steps['restart-cm-demo-horizon']['args']);

        $ids = array_keys($steps);
        $this->assertGreaterThan(array_search('supervisor-update', $ids, true), array_search('restart-cm-demo-horizon', $ids, true));
    }

    public function test_a_daemon_command_cannot_inject_supervisor_directives(): void
    {
        // A newline in the command would add lines to the ini file, such as
        // user=root.
        $this->expectException(InvalidArgumentException::class);

        $this->sites()->setDaemon($this->site(), 'evil', "php artisan x\nuser=root");
    }

    public function test_percent_signs_reach_the_command_literally(): void
    {
        $site = $this->sites()->setDaemon($this->site(), 'fmt', 'php artisan run --format=%d');
        $conf = base64_decode($this->afterSwitch($site)['supervisor-conf']['content_b64']);

        $this->assertStringContainsString('--format=%%d', $conf);
    }

    public function test_removing_a_daemon_drops_its_program(): void
    {
        $site = $this->sites()->setDaemon($this->site(), 'horizon', 'php artisan horizon');
        $site = $this->sites()->removeDaemon($site, 'horizon');

        $this->assertStringNotContainsString('horizon', base64_decode($this->afterSwitch($site)['supervisor-conf']['content_b64']));
    }

    public function test_changing_the_domain_or_turning_on_https_never_copies_what_is_injected_into_the_users_variables(): void
    {
        $site = $this->site();
        $this->sites()->setInjected($site, ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_PASSWORD' => 'sekret', 'REDIS_HOST' => 'cache.example.com'], 'cluster');
        $this->assertSame('', $this->sites()->customEnv($site), 'Nothing of theirs to begin with.');

        $site = $this->sites()->changeDomain($site, 'new.example.com');
        $site = $this->sites()->markTls($site);

        $this->assertSame('', $this->sites()->customEnv($site), 'Still none of theirs: nothing was copied into them.');
        $injected = $this->sites()->injected($site);
        $this->assertSame('https://new.example.com', $injected['APP_URL']['value'], 'The address follows the domain, where it lives.');
        $this->assertSame('db.example.com', $injected['DB_HOST']['value']);
        $this->assertStringContainsString('APP_URL=https://new.example.com', $this->sites()->env($site));
    }

    public function test_a_users_own_app_url_follows_a_domain_change_and_https_and_the_injected_one_is_left_to_theirs(): void
    {
        $site = $this->site();
        $this->sites()->setEnv($site, "APP_NAME=Demo\nAPP_URL=http://demo.example.com\n");
        $this->sites()->moveGeneratedDefaults($site);

        $site = $this->sites()->changeDomain($site, 'new.example.com');
        $this->sites()->markTls($site);

        $this->assertStringContainsString("APP_URL=https://new.example.com\n", $this->sites()->customEnv($site));
    }

    public function test_copies_an_earlier_fault_left_in_the_users_variables_are_found_and_removed_but_their_own_choices_are_not(): void
    {
        $site = $this->site();
        // What the fault wrote: the default SQLite values, then a cluster's, with the comment that came with them.
        $this->sites()->setEnv($site, implode("\n", [
            'APP_NAME=Demo', 'DB_CONNECTION=sqlite', 'DB_DATABASE=/home/deploy/demo/shared/database.sqlite', 'SESSION_DRIVER=database', 'CACHE_STORE=database',
            'DB_HOST=db.example.com', 'REDIS_HOST=cache.example.com', 'LOG_LEVEL=debug', '', '# Injected by cloud-manager. Set the same name in your own variables to override one.', 'DB_PORT=25060', '',
        ]));
        $this->sites()->setInjected($site, ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_PORT' => '25060', 'REDIS_HOST' => 'cache.example.com', 'CACHE_STORE' => 'redis'], 'cluster');
        // One the user chose themselves: a value that is neither the injected one nor a default.
        $this->sites()->setEnvValues($site, ['REDIS_HOST' => 'my-own-redis.example.com']);

        $copies = $this->sites()->envCopies($this->sites()->find($site->node_id, $site->name));
        $this->assertEqualsCanonicalizing(['DB_CONNECTION', 'DB_DATABASE', 'SESSION_DRIVER', 'CACHE_STORE', 'DB_HOST', 'DB_PORT'], $copies);
        $this->assertNotContains('REDIS_HOST', $copies, 'Theirs.');
        $this->assertNotContains('LOG_LEVEL', $copies);

        $removed = $this->sites()->removeEnvCopies($this->sites()->find($site->node_id, $site->name));
        $this->assertEqualsCanonicalizing($copies, $removed);

        $custom = $this->sites()->customEnv($site);
        $this->assertStringContainsString('APP_NAME=Demo', $custom);
        $this->assertStringContainsString('LOG_LEVEL=debug', $custom);
        $this->assertStringContainsString('REDIS_HOST=my-own-redis.example.com', $custom);
        $this->assertStringNotContainsString('DB_CONNECTION', $custom);
        $this->assertStringNotContainsString('Injected by cloud-manager', $custom);
        $effective = $this->sites()->env($this->sites()->find($site->node_id, $site->name));
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $effective, 'The cluster applies at last.');
        $this->assertStringContainsString('REDIS_HOST=my-own-redis.example.com', $effective);
        $this->assertSame([], $this->sites()->envCopies($this->sites()->find($site->node_id, $site->name)));
        $this->assertSame([], $this->sites()->removeEnvCopies($this->sites()->find($site->node_id, $site->name)), 'Nothing left to remove.');
    }

    public function test_a_process_with_a_port_is_told_it_and_gets_a_public_path_over_the_sites_domain(): void
    {
        $site = $this->sites()->setDaemon($this->site(), 'outpost', 'sh -c "cd outpost/server && ./run --port={port}"', 1, 8082, '/game-ws/');

        $conf = base64_decode($this->afterSwitch($site)['supervisor-conf']['content_b64']);
        $this->assertStringContainsString('command=sh -c "cd outpost/server && ./run --port=8082"', $conf);
        $this->assertStringContainsString('environment=HOME="/home/deploy",PORT="8082"', $conf);

        $vhost = $this->vhostOf($site);
        $this->assertStringContainsString('location = /game-ws {', $vhost);
        $this->assertStringContainsString('location ^~ /game-ws/ {', $vhost);
        $this->assertSame(2, substr_count($vhost, 'proxy_pass http://127.0.0.1:8082/;'), 'The prefix is removed, so the process sees /.');
        $this->assertSame(2, substr_count($vhost, 'proxy_set_header Upgrade $http_upgrade;'), 'WebSockets pass through.');
        $this->assertEquals(['port' => 8082, 'path' => '/game-ws'], array_intersect_key(Sites::daemons($site)[0], ['port' => 1, 'path' => 1]), 'The path is kept without its trailing slash.');
    }

    public function test_a_process_with_a_port_but_no_path_stays_private_and_one_without_a_port_is_as_before(): void
    {
        $site = $this->sites()->setDaemon($this->site(), 'api', 'php artisan serve --port={port}', 1, 9100);
        $site = $this->sites()->setDaemon($site, 'plain', 'php artisan pulse:check');

        $this->assertStringNotContainsString('proxy_pass http://127.0.0.1:9100', $this->vhostOf($site));
        $conf = base64_decode($this->afterSwitch($site)['supervisor-conf']['content_b64']);
        $this->assertStringContainsString('artisan serve --port=9100', $conf);
        $this->assertSame(1, substr_count($conf, 'PORT='), 'Only the process with a port is given one.');
    }

    public function test_ports_and_paths_that_would_clash_or_hijack_are_refused(): void
    {
        $site = $this->site();
        $other = $this->sites()->setDaemon($this->sites()->create('org-1', 'web-1.org-1', 'other', 'other.example.com', 'git@github.com:acme/other.git'), 'game', 'run', 1, 8082, '/ws');

        foreach ([
            [['b', 'run', 1, 80, null], 'Pick a port'],
            [['b', 'run', 1, 5432, null], 'Pick a port'],
            [['b', 'run', 1, 20005, null], 'Pick a port'],
            [['b', 'run', 1, 23000, null], 'Pick a port'],
            [['b', 'run', 1, 23999, null], 'Pick a port'],
            [['b', 'run', 1, 8082, null], "already used by {$other->name}'s game"],
            [['b', 'run', 2, 8090, null], 'runs once'],
            [['b', 'run', 1, null, '/ws'], 'needs the port'],
            [['b', 'run {port}', 1, null, null], 'give the port'],
            [['b', 'run', 1, 8090, '/app'], 'The path is one to three'],
            [['b', 'run', 1, 8090, '/Bad Path'], 'The path is one to three'],
            [['b', 'run', 1, 8090, '/a/b/c/d'], 'The path is one to three'],
        ] as [$args, $message]) {
            try {
                $this->sites()->setDaemon($site, ...$args);
                $this->fail("accepted {$message}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        // Changing its own is not a clash with itself.
        $same = $this->sites()->setDaemon($other, 'game', 'run again', 1, 8082, '/ws');
        $this->assertSame('run again', Sites::daemons($same)[0]['command']);

        $this->expectExceptionMessage('already goes to game');
        $this->sites()->setDaemon($same, 'second', 'run', 1, 8091, '/ws');
    }

    public function test_reverb_listens_on_loopback_on_a_port_of_its_own(): void
    {
        $a = $this->sites()->enableReverb($this->site());
        $b = $this->sites()->enableReverb($this->sites()->create('org-1', 'web-1.org-1', 'other', 'other.example.com', 'git@github.com:acme/other.git'));

        $this->assertNotSame(Sites::reverbPort($a), Sites::reverbPort($b));

        $conf = base64_decode($this->afterSwitch($a)['supervisor-conf']['content_b64']);
        $port = Sites::reverbPort($a);
        $this->assertStringContainsString("reverb:start --host=127.0.0.1 --port={$port}", $conf);

        $env = $this->sites()->env($a);
        $this->assertStringContainsString('BROADCAST_CONNECTION=reverb', $env);
        $this->assertStringContainsString("REVERB_SERVER_PORT={$port}", $env);
        $this->assertSame(1, substr_count($env, 'BROADCAST_CONNECTION='), 'An existing key must be replaced, not duplicated.');
    }

    public function test_reenabling_reverb_keeps_its_credentials(): void
    {
        // New credentials would disconnect every client holding the old key.
        $site = $this->sites()->enableReverb($this->site());
        preg_match('/^REVERB_APP_KEY=(.+)$/m', $this->sites()->env($site), $first);

        $site = $this->sites()->enableReverb($site);
        preg_match('/^REVERB_APP_KEY=(.+)$/m', $this->sites()->env($site), $second);

        $this->assertSame($first[1], $second[1]);
    }

    public function test_nginx_proxies_reverb_with_upgrade_headers_on_http_and_https(): void
    {
        $site = $this->sites()->enableReverb($this->site());
        $port = Sites::reverbPort($site);

        foreach ([$site, $this->sites()->markTls($site)] as $variant) {
            $vhost = $this->vhostOf($variant);
            $this->assertStringContainsString("location ^~ /app/ {\n        proxy_pass http://127.0.0.1:{$port};", $vhost);
            $this->assertStringContainsString('location ^~ /apps/ {', $vhost);
            $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $vhost);
        }

        // On TLS the proxy belongs to the https server, not the redirect.
        $tls = $this->vhostOf($this->sites()->markTls($site));
        $this->assertGreaterThan(strpos($tls, 'listen 443'), strpos($tls, 'location ^~ /app/'));
    }

    public function test_without_reverb_nothing_is_proxied(): void
    {
        $this->assertStringNotContainsString('proxy_pass', $this->vhostOf($this->site()));
    }

    private function packagesNeeding(string $constraint): array
    {
        return ['status' => 'succeeded', 'detail' => ['output' => json_encode(['composer' => ['require' => ['php' => '^8.2'], 'config' => ['platform' => ['php' => null]]],
            'lock' => ['packages' => [['require' => ['php' => $constraint]]]]])]];
    }

    public function test_a_lock_that_needs_a_newer_php_than_the_sites_moves_it_to_an_installed_branch_that_is_new_enough(): void
    {
        $site = $this->site();
        DB::table('server_php_versions')->insert(['node_id' => $site->node_id, 'version' => '8.5', 'created_at' => now(), 'updated_at' => now()]);
        [$deployer] = $this->deployerAgainst([
            ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'],
            $this->packagesNeeding('>=8.5'),
            ['status' => 'succeeded'],
            ['status' => 'succeeded', 'detail' => ['output' => 'abc']],
            ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded', 'detail' => ['deleted' => []]],
        ]);

        $deployer->deploy($site, now: '20261105T190000Z');

        $this->assertSame('8.5', DB::table('sites')->where('id', $site->id)->value('php_version'));
    }

    public function test_a_lock_that_needs_a_newer_php_the_server_lacks_stops_the_deploy_before_building_and_says_which(): void
    {
        $site = $this->site();
        [$deployer, $http] = $this->deployerAgainst([
            ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'],
            $this->packagesNeeding('>=8.5'),
        ]);

        try {
            $deployer->deploy($site, now: '20261105T190000Z');
            $this->fail('The deploy should have stopped.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('need PHP 8.5 or newer', $e->getMessage());
            $this->assertStringContainsString('Install PHP 8.5', $e->getMessage());
        }
        $http->assertSentCount(4);
        $this->assertSame('8.4', DB::table('sites')->where('id', $site->id)->value('php_version'));
    }

    public function test_a_php_newer_than_the_packages_declare_is_left_alone(): void
    {
        $site = $this->site();
        [$deployer, $http] = $this->deployerAgainst([
            ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'],
            $this->packagesNeeding('8.0 - 8.3'),
            ['status' => 'succeeded'],
            ['status' => 'succeeded', 'detail' => ['output' => 'abc']],
            ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded', 'detail' => ['deleted' => []]],
        ]);

        $deployer->deploy($site, now: '20261105T190000Z');

        $this->assertSame('8.4', DB::table('sites')->where('id', $site->id)->value('php_version'));
        $http->assertSent(fn (Request $r) => $r['kind'] === 'apply_recipe' && str_contains(json_encode($r->data()), 'COMPOSER_IGNORE_PLATFORM_REQS=php+'));
    }

    public function test_a_failed_prune_does_not_fail_a_live_deploy(): void
    {
        // An agent too old to know prune_releases answers "unsupported".
        $site = $this->site();
        [$deployer] = $this->deployerAgainst([
            ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'], ['status' => 'succeeded'],
            ['status' => 'succeeded', 'detail' => ['output' => 'abc']],
            ['status' => 'succeeded'],
            ['status' => 'succeeded'],
            ['status' => 'failed', 'error' => 'agentcore: unsupported command "prune_releases"'],
        ]);

        $result = $deployer->deploy($site, now: '20261105T190000Z');

        $this->assertSame('20261105T190000Z-main', $result['release']);
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.deployed')->count());
    }

    public function test_a_failed_build_never_activates_and_leaves_the_live_release_alone(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['current_release' => 'previous']);

        [$deployer, $http] = $this->deployerAgainst([
            ['status' => 'succeeded'],
            ['status' => 'succeeded'],
            ['status' => 'succeeded'],
            ['status' => 'succeeded'],
            ['status' => 'failed', 'error' => 'recipe step "composer" failed'],
        ]);

        try {
            $deployer->deploy($site, now: '20261105T190000Z');
            $this->fail('The deploy should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('live release is unchanged', $e->getMessage());
            $this->assertStringContainsString('composer', $e->getMessage());
        }

        $http->assertNotSent(fn (Request $r) => $r['kind'] === 'activate_release');
        $this->assertSame('previous', DB::table('sites')->where('id', $site->id)->value('current_release'));
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.deploy_failed')->count());
    }

    public function test_connection_details_carry_the_parts_and_a_database_on_the_server_has_no_tls(): void
    {
        $remote = Sites::connectionDetails(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_PORT' => '25060', 'DB_DATABASE' => 'app_db', 'DB_USERNAME' => 'app', 'DB_PASSWORD' => 'p@ss']);
        $this->assertSame(['postgresql', 'db.example.com', '25060', 'app_db', 'app', 'p@ss', false], [$remote['engine'], $remote['host'], $remote['port'], $remote['database'], $remote['username'], $remote['password'], $remote['local']]);
        $this->assertStringEndsWith('?sslmode=require', $remote['url']);

        // MySQL or PostgreSQL that Unharbored set up on the server itself: loopback, no TLS to ask for.
        $mysql = Sites::connectionDetails(['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'shop', 'DB_USERNAME' => 'shop', 'DB_PASSWORD' => 'pw']);
        $this->assertTrue($mysql['local']);
        $this->assertSame('mysql://shop:pw@127.0.0.1:3306/shop', $mysql['url']);
        $pg = Sites::connectionDetails(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'shop', 'DB_USERNAME' => 'shop', 'DB_PASSWORD' => 'pw']);
        $this->assertSame('postgresql://shop:pw@127.0.0.1:5432/shop?sslmode=disable', $pg['url']);

        $this->assertNull(Sites::connectionDetails(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/x/db.sqlite']));
    }

    public function test_a_connection_url_is_built_from_the_database_values(): void
    {
        $this->assertSame(
            'postgresql://app:p%40ss%2Fw0rd@db.example.com:25060/app_db?sslmode=require',
            Sites::connectionUrl(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_PORT' => '25060', 'DB_DATABASE' => 'app_db', 'DB_USERNAME' => 'app', 'DB_PASSWORD' => 'p@ss/w0rd']),
        );
        $this->assertSame(
            'mysql://u:pw@h:3306/d?ssl-mode=REQUIRED',
            Sites::connectionUrl(['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'h', 'DB_DATABASE' => 'd', 'DB_USERNAME' => 'u', 'DB_PASSWORD' => 'pw']),
        );
        // A file or nothing has no address to connect to.
        $this->assertNull(Sites::connectionUrl(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/x/db.sqlite']));
        $this->assertNull(Sites::connectionUrl([]));
    }

    // Audit 2026-11-08, finding 7: the database is named after the application, so the name is the organisation's.
    public function test_an_application_name_is_unique_across_the_organisation_not_just_one_server(): void
    {
        $this->site();
        DB::table('nodes')->insertOrIgnore(['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists in this team');
        $this->sites()->create('org-1', 'web-2.org-1', 'demo', 'other.example.com', 'git@github.com:acme/demo.git');
    }
}
