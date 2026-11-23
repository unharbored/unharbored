<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\PhpSettings;
use App\Lib\Servers\ServerStack;
use App\Lib\Sites\Sites;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * php.ini settings per server and PHP branch: what they may be, where they are
 * written, and that PHP-FPM checks them and the old files come back if it objects.
 */
final class PhpSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    private ?string $failure = null;

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

            return $this->failure !== null && ($r['kind'] ?? null) === 'apply_recipe'
                ? $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => $this->failure])
                : $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
    }

    private function step(array $spec, string $id): array
    {
        return array_column($spec['steps'], null, 'id')[$id];
    }

    public function test_a_branch_with_no_settings_of_its_own_runs_on_the_defaults(): void
    {
        $this->assertSame(PhpSettings::DEFAULTS, PhpSettings::for('web-1.org-1', '8.4'));
        $this->assertFalse(PhpSettings::isCustom('web-1.org-1', '8.4'));
        $this->assertSame('64M', PhpSettings::DEFAULTS['upload_max_filesize'], 'Not PHP\'s own 2M, which stops most uploads.');
    }

    public function test_settings_are_kept_per_server_and_branch(): void
    {
        PhpSettings::save('web-1.org-1', '8.4', ['upload_max_filesize' => '128m', 'post_max_size' => '128M', 'pm_max_children' => 20, 'extra' => "opcache.memory_consumption=256\n"]);

        $saved = PhpSettings::for('web-1.org-1', '8.4');
        $this->assertSame('128M', $saved['upload_max_filesize'], 'Normalised.');
        $this->assertSame(20, $saved['pm_max_children']);
        $this->assertSame('opcache.memory_consumption=256', $saved['extra']);
        $this->assertTrue(PhpSettings::isCustom('web-1.org-1', '8.4'));
        $this->assertFalse(PhpSettings::isCustom('web-1.org-1', '8.3'), 'Each branch has its own.');
        $this->assertSame(PhpSettings::DEFAULTS, PhpSettings::for('web-2.org-1', '8.4'), 'Each server has its own.');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badSettings(): array
    {
        return [
            'a size with no number' => [['upload_max_filesize' => 'big']],
            'a size in gigabytes of nonsense' => [['memory_limit' => '12Q']],
            'a post limit under the upload limit' => [['upload_max_filesize' => '64M', 'post_max_size' => '8M']],
            'no workers' => [['pm_max_children' => 0]],
            'too many workers' => [['pm_max_children' => 500]],
            'a negative time limit' => [['max_execution_time' => -1]],
            'a line that is not a directive' => [['extra' => 'just some words']],
            'loading an extension' => [['extra' => 'extension=evil.so']],
            'loading a zend extension, in any case' => [['extra' => 'Zend_Extension = /tmp/x.so']],
            'a prepended file' => [['extra' => 'auto_prepend_file=/tmp/x.php']],
            'too long' => [['extra' => str_repeat("a=b\n", 2000)]],
        ];
    }

    /**
     * @dataProvider badSettings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSettings')]
    public function test_settings_that_are_wrong_or_load_code_are_refused_and_nothing_is_saved(array $bad): void
    {
        try {
            PhpSettings::save('web-1.org-1', '8.4', $bad);
            $this->fail('Accepted.');
        } catch (InvalidArgumentException) {
            $this->assertFalse(PhpSettings::isCustom('web-1.org-1', '8.4'));
        }
    }

    public function test_unlimited_memory_and_ordinary_directives_are_allowed(): void
    {
        $s = PhpSettings::normalize(['memory_limit' => '-1', 'extra' => "; a comment\n[Date]\ndate.timezone = UTC\nopcache.enable=1"]);

        $this->assertSame('-1', $s['memory_limit']);
        $this->assertStringContainsString('date.timezone = UTC', $s['extra']);
    }

    public function test_sizes_are_compared_as_megabytes(): void
    {
        $this->assertSame(64.0, PhpSettings::megabytes('64M'));
        $this->assertSame(1024.0, PhpSettings::megabytes('1G'));
        $this->assertSame(0.5, PhpSettings::megabytes('512K'));
        $this->assertSame(1.0, PhpSettings::megabytes((string) (1024 * 1024)), 'A plain number is bytes.');
        $this->assertSame(16.0, PhpSettings::uploadLimitMb(['upload_max_filesize' => '64M', 'post_max_size' => '16M']), 'The smaller of the two.');
    }

    public function test_the_stack_writes_the_pool_and_both_ini_files_with_the_servers_settings(): void
    {
        $stack = new ServerStack();
        $default = $stack->spec();
        $this->assertStringContainsString('pm.max_children = 10', base64_decode($this->step($default, 'php-fpm-pool')['content_b64']));
        $this->assertStringContainsString("upload_max_filesize = 64M\n", base64_decode($this->step($default, 'php-ini-fpm')['content_b64']));
        $this->assertSame('/etc/php/8.4/fpm/conf.d/99-cloud-manager.ini', $this->step($default, 'php-ini-fpm')['path'], 'A file PHP updates never replace.');

        $custom = $stack->spec(PhpSettings::normalize(['upload_max_filesize' => '200M', 'post_max_size' => '200M', 'pm_max_children' => 1, 'extra' => 'date.timezone=UTC']));
        $pool = base64_decode($this->step($custom, 'php-fpm-pool')['content_b64']);
        $this->assertStringContainsString('pm.max_children = 1', $pool);
        $this->assertStringContainsString('pm.start_servers = 1', $pool, 'FPM refuses to start if the spares exceed the limit.');
        $this->assertStringContainsString('pm.max_spare_servers = 1', $pool);

        $fpm = base64_decode($this->step($custom, 'php-ini-fpm')['content_b64']);
        $cli = base64_decode($this->step($custom, 'php-ini-cli')['content_b64']);
        $this->assertStringContainsString('upload_max_filesize = 200M', $fpm);
        $this->assertStringContainsString('date.timezone=UTC', $fpm);
        $this->assertStringContainsString('date.timezone=UTC', $cli, 'The user\'s own directives reach the command line too.');
        $this->assertStringNotContainsString('max_execution_time', $cli, 'A queue worker must not inherit a web request\'s time limit.');
        $this->assertStringNotContainsString('upload_max_filesize', $cli);
    }

    public function test_a_php_branch_installed_later_gets_the_servers_settings_for_it(): void
    {
        $recipe = (new ServerStack())->phpInstallRecipe('8.3', PhpSettings::normalize(['memory_limit' => '1G']));

        $this->assertStringContainsString('memory_limit = 1G', base64_decode($this->step($recipe, 'php-ini-fpm')['content_b64']));
        $this->assertSame('/etc/php/8.3/fpm/conf.d/99-cloud-manager.ini', $this->step($recipe, 'php-ini-fpm')['path']);
    }

    public function test_applying_backs_up_writes_checks_puts_the_old_files_back_on_failure_and_reloads(): void
    {
        $recipe = (new ServerStack())->phpSettingsRecipe('8.4', PhpSettings::DEFAULTS);

        $this->assertSame(['php-settings-backup', 'php-fpm-pool', 'php-ini-fpm', 'php-ini-cli', 'php-settings-check', 'reload-php8.4-fpm'], array_column($recipe['steps'], 'id'));
        $backup = implode(' ', $this->step($recipe, 'php-settings-backup')['args']);
        $check = implode(' ', $this->step($recipe, 'php-settings-check')['args']);
        foreach (['/etc/php/8.4/fpm/pool.d/www.conf', '/etc/php/8.4/fpm/conf.d/99-cloud-manager.ini', '/etc/php/8.4/cli/conf.d/99-cloud-manager.ini'] as $path) {
            $this->assertStringContainsString("cp -p {$path} /var/backups/cloud-manager/php/8.4/", $backup);
            $this->assertStringContainsString("cp -p /var/backups/cloud-manager/php/8.4/", $check, 'Each old file goes back.');
            $this->assertStringContainsString("rm -f {$path}", $check, 'Or goes, if there was none.');
        }
        $this->assertStringContainsString('php-fpm8.4 -t', $check);
        $this->assertStringContainsString("PHP:|syntax error", $check, 'FPM prints a bad ini line as a warning and still exits 0.');
        $this->assertStringEndsWith('exit 1', $check, 'The step fails, so the operation does.');
        $this->assertStringContainsString('rm -f /var/backups/cloud-manager/php/8.4/', $backup, 'A stale backup of a file that is not there is dropped.');

        $this->expectException(InvalidArgumentException::class);
        (new ServerStack())->phpSettingsRecipe('5.6', PhpSettings::DEFAULTS);
    }

    public function test_the_operation_writes_the_saved_settings_and_a_stack_repair_keeps_them(): void
    {
        PhpSettings::save('web-1.org-1', '8.4', ['upload_max_filesize' => '300M', 'post_max_size' => '300M']);
        $runner = $this->app->make(OperationRunner::class);

        $id = $runner->queuePhpSettings('web-1.org-1', '8.4', 'console', 'me');
        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $applied = array_values(array_filter($this->gateway, fn ($c) => $c['kind'] === 'apply_recipe'))[0]['spec'];
        $this->assertSame('php-settings-8.4', $applied['name']);
        $this->assertStringContainsString('upload_max_filesize = 300M', base64_decode($this->step($applied, 'php-ini-fpm')['content_b64']));

        $this->gateway = [];
        $stack = $runner->queueStack('web-1.org-1', 'console', 'me');
        $runner->run($stack);
        $spec = array_values(array_filter($this->gateway, fn ($c) => $c['kind'] === 'apply_recipe'))[0]['spec'];
        $this->assertStringContainsString('upload_max_filesize = 300M', base64_decode($this->step($spec, 'php-ini-fpm')['content_b64']), 'Repair does not put the defaults back.');
    }

    public function test_when_php_fpm_refuses_the_operation_fails_with_its_message(): void
    {
        $this->failure = 'PHP:  syntax error, unexpected end of file in /etc/php/8.4/fpm/conf.d/99-cloud-manager.ini on line 5';
        $runner = $this->app->make(OperationRunner::class);

        $id = $runner->queuePhpSettings('web-1.org-1', '8.4', 'console', 'me');

        $this->assertSame('failed', $runner->run($id));
        $this->assertStringContainsString('syntax error', (string) DB::table('operations')->where('id', $id)->value('error'));
    }

    public function test_the_console_loads_saves_and_queues_and_refuses_a_bad_setting(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $this->assertContains('8.4', $this->get('/servers/web-1.org-1')->viewData('page')['props']['node']['php']);

        $this->getJson('/servers/web-1.org-1/php/8.4')->assertOk()->assertJsonPath('upload_max_filesize', '64M');

        $form = ['upload' => '256M', 'post' => '256M', 'memory' => '256M', 'time' => 60, 'children' => 15, 'extra' => ''];
        $this->post('/servers/web-1.org-1/php/8.4/settings', $form)->assertRedirectContains('/operation?operation=');
        $this->assertSame('256M', PhpSettings::for('web-1.org-1', '8.4')['upload_max_filesize']);
        $this->assertSame(1, DB::table('operations')->where('kind', 'php_settings')->count());

        $this->postJson('/servers/web-1.org-1/php/8.4/settings', ['extra' => 'extension=evil.so'] + $form)->assertStatus(422)->assertJsonValidationErrors('phpSettings');
        $this->assertSame(1, DB::table('operations')->where('kind', 'php_settings')->count(), 'A refused setting queues nothing.');
    }

    public function test_a_limit_that_nginx_and_php_disagree_on_is_pointed_out_on_both_pages(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $sites = $this->app->make(Sites::class);
        $site = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $sites->setNginx($site, 16, null, null);

        $this->assertSame(['shop: nginx accepts 16 MB, PHP accepts 64 MB. The smaller one wins.'], $this->get('/servers/web-1.org-1')->viewData('page')['props']['node']['phpWarnings']['8.4']);
        $this->get("/site/settings?site={$site->id}&tab=web")->assertInertia(fn (Assert $page) => $page
            ->where('data.webPhpNote', fn ($note) => str_contains($note, 'accepts uploads of 64 MB and nginx here accepts 16 MB')));

        $sites->setNginx($sites->find('web-1.org-1', 'shop'), 64, null, null);
        $this->assertSame([], $this->get('/servers/web-1.org-1')->viewData('page')['props']['node']['phpWarnings']);
    }
}
