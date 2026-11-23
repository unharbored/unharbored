<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\Spaces;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Sites\SiteBackups;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

/**
 * A site's database is copied off its primary to the backup bucket, kept
 * two weeks, and put back with every server in maintenance mode.
 */
final class SiteBackupsTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    /** @var list<array{node: string, args: list<string>}> */
    private array $commands = [];

    /** @var list<Request> */
    private array $spaces = [];

    /** @var array<string, int> what the bucket lists */
    private array $listed = [];

    private ?string $failOn = null;

    private string $forkStatus = 'creating';

    /** @var list<Request> */
    private array $api = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Cache::store('array')->clear();
        $this->http = new HttpFactory();
        $this->http->fake(function (Request $r) {
            if (str_contains($r->url(), 'gw.test')) {
                preg_match('#/v1/nodes/([^/]+)/commands#', $r->url(), $m);
                $args = $r['args']['args'] ?? [];
                $this->commands[] = ['node' => $m[1], 'args' => $args];
                if ($this->failOn !== null && in_array('-r', $args, true) && str_contains(implode(' ', $args), $this->failOn)) {
                    // As the script reports it: the gateway drops a failed command's output.
                    return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => 'CM-FAILED: pg_restore exited 1: pg_restore: error: could not connect']]);
                }

                return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => in_array('-r', $args, true) ? '{"bytes":4096}' : '']]);
            }
            if (str_contains($r->url(), 'api.digitalocean.com')) {
                $this->api[] = $r;

                return match (true) {
                    $r->method() === 'POST' && str_ends_with($r->url(), '/databases') => $this->http->response(['database' => ['id' => 'fork-1']], 201),
                    str_ends_with($r->url(), '/databases/fork-1') && $r->method() === 'GET' => $this->http->response(['database' => ['status' => $this->forkStatus, 'connection' => ['host' => 'fork.db.ondigitalocean.com', 'port' => 25060]]]),
                    str_ends_with($r->url(), '/databases/main-uuid/backups') => $this->http->response(['backups' => [
                        ['created_at' => '2026-11-05T06:01:12Z', 'size_gigabytes' => 0.03], ['created_at' => '2026-11-06T06:01:15Z', 'size_gigabytes' => 0.03]]]),
                    str_ends_with($r->url(), '/ca') => $this->http->response(['ca' => ['certificate' => base64_encode('pem')]]),
                    default => $this->http->response([], 204),
                };
            }
            $this->spaces[] = $r;
            if ($r->method() === 'GET' && str_contains($r->url(), 'list-type=2')) {
                return $this->http->response('<ListBucketResult>'.implode('', array_map(fn ($k, $n) => "<Contents><Key>{$k}</Key><LastModified>x</LastModified><Size>{$n}</Size></Contents>", array_keys($this->listed), $this->listed)).'</ListBucketResult>');
            }

            return $this->http->response('', 204);
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()));
        $this->app->instance(CredentialStore::class, new CredentialStore($this->app->make(Vault::class), $this->http, Cache::store('array'), new AuditLog()));

        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        $resources = new ResourceManager(new AuditLog());
        $bucket = $resources->declare('org-1', 'backup_bucket', 'control-plane', ['region' => 'sfo3', 'bucket' => 'cm-backups-test', 'key_id' => 'AKB']);
        $bucket = $resources->transition($bucket, ResourceState::Provisioning, reason: 't');
        $resources->transition($bucket, ResourceState::Active, reason: 't');
        $this->app->make(Vault::class)->put('org-1', Spaces::BACKUP_SECRET, 'SKB');
    }

    private function sites(): Sites
    {
        return $this->app->make(Sites::class);
    }

    private function backups(): SiteBackups
    {
        return $this->app->make(SiteBackups::class);
    }

    private function site(string $connection = 'pgsql'): stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => 'r1']);
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);
        $site = $this->sites()->find('web-1.org-1', 'shop');
        if ($connection !== 'sqlite') {
            $this->sites()->setEnvValues($site, ['DB_CONNECTION' => $connection, 'DB_PASSWORD' => 'hunter2']);
        }

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    public function test_a_backup_is_dumped_on_the_primary_and_put_straight_into_the_bucket(): void
    {
        $site = $this->site();
        $taken = $this->backups()->take($site, 'manual');

        $this->assertMatchesRegularExpression('#^sites/\d+-shop/\d{8}T\d{6}Z-manual\.pgdump$#', $taken['object']);
        $this->assertSame(4096, $taken['bytes']);
        [$call] = $this->commands;
        $this->assertSame('web-1.org-1', $call['node']);
        $this->assertSame(['-u', 'deploy', '--'], array_slice($call['args'], 0, 3));
        $this->assertSame('pgsql', $call['args'][9]);
        $this->assertStringStartsWith("https://sfo3.digitaloceanspaces.com/cm-backups-test/{$taken['object']}?", $call['args'][10], 'A URL for that one object; the server holds no key.');
        $this->assertStringContainsString('X-Amz-Credential=AKB', $call['args'][10]);
        $this->assertStringContainsString('X-Amz-Expires=3600', $call['args'][10], 'The URL is signed before the dump starts, which may take most of the half hour the command is allowed.');
        $this->assertStringNotContainsString('hunter2', implode(' ', $call['args']), 'The password is read on the server, never sent in a command.');
    }

    public function test_backups_are_listed_newest_first_and_those_past_two_weeks_deleted(): void
    {
        $site = $this->site();
        $p = SiteBackups::prefix($site);
        $old = $p.now()->subDays(15)->format('Ymd\THis\Z').'-nightly.pgdump';
        $new = $p.now()->subDay()->format('Ymd\THis\Z').'-deploy.pgdump';
        $this->listed = [$old => 2048, $new => 1048576, $p.'stray.txt' => 1];

        $list = $this->backups()->list($site);
        $this->assertSame([$new, $old], array_column($list, 'object'));
        $this->assertSame(['deploy', 'nightly'], array_column($list, 'kind'));

        $this->assertSame([$old], $this->backups()->prune($site));
        $deletes = array_values(array_filter($this->spaces, fn ($r) => $r->method() === 'DELETE'));
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString(rawurlencode(basename($old)), $deletes[0]->url());
    }

    public function test_a_restore_keeps_what_is_there_and_runs_with_every_server_down(): void
    {
        $site = $this->site();
        $object = SiteBackups::prefix($site).'20261101T031700Z-nightly.pgdump';
        $lines = [];
        $this->backups()->restore($site, $object, function ($a, $t) use (&$lines) { $lines[] = $a; });

        $steps = array_map(fn ($c) => $c['node'].' '.(in_array('-r', $c['args'], true) ? 'php:'.$c['args'][9] : implode(' ', array_slice($c['args'], 5))), $this->commands);
        $this->assertSame([
            'web-1.org-1 php:pgsql',
            'web-1.org-1 down --retry=30',
            'web-2.org-1 down --retry=30',
            'web-1.org-1 php:pgsql',
            'web-1.org-1 up',
            'web-2.org-1 up',
        ], $steps);
        $this->assertStringContainsString('-restore.pgdump?', $this->commands[0]['args'][10], 'First, a backup of what is there now.');
        $this->assertStringContainsString(basename($object), $this->commands[3]['args'][10]);
        $this->assertSame(['backup', 'down', 'restore', 'up'], $lines);
    }

    public function test_the_site_comes_back_up_even_when_the_restore_fails(): void
    {
        $site = $this->site();
        $this->failOn = '20261101T031700Z';

        try {
            $this->backups()->restore($site, SiteBackups::prefix($site).'20261101T031700Z-nightly.pgdump', fn () => null);
            $this->fail('Should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not connect', $e->getMessage());
        }
        $ups = array_filter($this->commands, fn ($c) => ($c['args'][5] ?? null) === 'up');
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], array_values(array_column($ups, 'node')));
    }

    public function test_only_the_sites_own_backups_of_its_current_engine_restore(): void
    {
        $site = $this->site();
        foreach (['sites/99-other/20261101T031700Z-nightly.pgdump', SiteBackups::prefix($site).'../99-other/x.pgdump', SiteBackups::prefix($site).'20261101T031700Z-nightly.sqlite.gz'] as $object) {
            try {
                $this->backups()->restore($site, $object, fn () => null);
                $this->fail("{$object} should be refused.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->commands, 'Refused before touching any server.');
    }

    public function test_sqlite_sites_are_backed_up_as_a_file(): void
    {
        $taken = $this->backups()->take($this->site('sqlite'), 'nightly');
        $this->assertStringEndsWith('-nightly.sqlite.gz', $taken['object']);
    }

    public function test_the_nightly_command_queues_one_backup_per_deployed_site(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $shop = $this->site();
        $this->sites()->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        DB::table('sites')->where('id', $shop->id)->update(['backups' => true]);

        $this->artisan('sites:backup')->assertSuccessful();

        $op = DB::table('operations')->where('kind', 'site_backup')->sole();
        $this->assertSame('nightly', json_decode($op->meta, true)['kind']);
        $this->assertSame($shop->id, (int) $op->site_id);
    }

    public function test_backups_are_off_for_a_new_application_until_switched_on(): void
    {
        $site = $this->site();
        $this->assertFalse((bool) DB::table('sites')->find($site->id)->backups);

        $this->actingAs(\App\Models\User::factory()->create());
        $this->post("/site/settings/{$site->id}/backup/setting", ['on' => true])->assertSessionHasNoErrors();
        $this->assertTrue((bool) DB::table('sites')->find($site->id)->backups);
        $this->post("/site/settings/{$site->id}/backup/setting", ['on' => false]);
        $this->assertFalse((bool) DB::table('sites')->find($site->id)->backups);
    }

    private function managedSite(): stdClass
    {
        $this->app->make(\App\Lib\Cloud\CredentialStore::class)->connect('org-1', 'dop_v1_test_token');
        $resources = new ResourceManager(new AuditLog());
        $cluster = $resources->declare('org-1', 'database_cluster', 'main', ['engine' => 'pg', 'region' => 'nyc3', 'size' => 'db-s-1vcpu-1gb', 'version' => '18']);
        $cluster = $resources->transition($cluster, ResourceState::Provisioning, reason: 't');
        $cluster = $resources->recordExternalId($cluster, 'main-uuid');
        $resources->transition($cluster, ResourceState::Active, reason: 't');
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id]);

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    public function test_a_cluster_backup_restore_copies_from_a_temporary_cluster_and_destroys_it(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $site = $this->managedSite();
        $this->assertSame(['2026-11-06T06:01:15Z', '2026-11-05T06:01:12Z'], array_column($this->app->make(\App\Lib\Cloud\DatabaseClusters::class)
            ->backups((new ResourceManager(new AuditLog()))->findById((int) $site->database_cluster_id)), 'created_at'), 'Newest first.');
        $runner = $this->app->make(\App\Lib\Operations\OperationRunner::class);
        $id = $runner->queueSiteRestoreFromClusterBackup($site->id, '2026-11-06T06:01:15Z', 'console', 'me');

        $this->assertSame('waiting', $runner->run($id), 'The copy takes minutes; the worker is released meanwhile.');
        $post = collect($this->api)->first(fn ($r) => $r->method() === 'POST');
        $this->assertSame(['database_name' => 'cm-main', 'backup_created_at' => '2026-11-06T06:01:15Z'], $post['backup_restore'], 'The backup is named exactly as DigitalOcean gave it.');
        $this->assertSame(['pg', '18', 'nyc3', 'db-s-1vcpu-1gb'], [$post['engine'], $post['version'], $post['region'], $post['size']]);
        $this->assertSame([], $this->commands, 'Nothing touches the site until the copy is online.');

        $this->forkStatus = 'online';
        $this->assertSame('succeeded', $runner->run($id));
        $load = collect($this->commands)->last(fn ($c) => in_array('-r', $c['args'], true));
        $this->assertSame('db://fork.db.ondigitalocean.com:25060', $load['args'][10]);
        $this->assertTrue(collect($this->api)->contains(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/databases/fork-1')), 'The copy is destroyed.');
    }

    public function test_the_temporary_cluster_is_destroyed_even_when_the_copy_fails(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $site = $this->managedSite();
        $runner = $this->app->make(\App\Lib\Operations\OperationRunner::class);
        $id = $runner->queueSiteRestoreFromClusterBackup($site->id, '2026-11-05T06:01:12Z', 'console', 'me');
        $runner->run($id);
        $this->forkStatus = 'online';
        $this->failOn = 'db://';

        $this->assertSame('failed', $runner->run($id));
        $this->assertTrue(collect($this->api)->contains(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/databases/fork-1')));
    }

    public function test_only_a_backup_digitalocean_lists_can_be_restored(): void
    {
        $site = $this->managedSite();
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(\App\Lib\Operations\OperationRunner::class)->queueSiteRestoreFromClusterBackup($site->id, '2026-11-06T12:00:00Z', 'console', 'me');
    }

    public function test_a_cluster_backup_older_than_the_sites_database_is_not_offered(): void
    {
        $site = $this->managedSite();
        $this->dropAuditTriggers();
        DB::table('audit_records')->delete();
        app(AuditLog::class)->record('org-1', \App\Lib\Audit\Actor::system('t'), 'site.database_attached', resourceType: 'site', resourceId: 'web-1.org-1/shop');
        DB::table('audit_records')->where('action', 'site.database_attached')->update(['recorded_at' => '2026-11-06 00:00:00']);

        $cluster = (new ResourceManager(new AuditLog()))->findById((int) $site->database_cluster_id);
        $this->assertSame(['2026-11-06T06:01:15Z'], array_column($this->app->make(\App\Lib\Cloud\DatabaseClusters::class)->backups($cluster, Sites::attachedAt($site)), 'created_at'));

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(\App\Lib\Operations\OperationRunner::class)->queueSiteRestoreFromClusterBackup($site->id, '2026-11-05T06:01:12Z', 'console', 'me');
    }
}
