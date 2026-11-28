<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Cloud\CloudInit;
use App\Lib\Operations\OperationRunner;
use App\Lib\Pki\CertificateAuthority;
use App\Lib\Servers\ServerStack;
use App\Lib\Servers\Tailscale;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ubuntu 26.04 is supported beside 24.04. The ondrej/php PPA has nothing for it, so PHP there is Ubuntu's own 8.5 and
 * the default branch follows the server; every server so far has no recorded release and keeps 8.4.
 */
final class Ubuntu2604Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function node(string $id, ?string $os): void
    {
        DB::table('nodes')->insert(['node_id' => $id, 'organization_id' => 'org-1', 'os_version' => $os, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Runs the install script's own release check, as sh would, on a machine described by os-release. */
    private function installCheck(string $id, string $version, bool $anyOs = false): bool
    {
        $dir = sys_get_temp_dir().'/cm-os-'.getmypid();
        @mkdir($dir, 0o755, true);
        file_put_contents($dir.'/cloud-manager-agent-linux-amd64', 'x');
        file_put_contents($dir.'/cloud-manager-agent-linux-arm64', 'x');
        $script = (new CloudInit(new AgentRelease($dir, 'test'), 'https://cp.example.test', 'cp.example.test'))
            ->installScript('web-1.org-1', 'token', CertificateAuthority::create('CA')->certificatePem());
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);

        preg_match('/^\[ "\$\{ID:-\}" = ubuntu \].*\n.*die .*$/m', $script, $m);
        $this->assertNotEmpty($m, 'the release check is in the script');
        $check = 'die() { echo "$*"; exit 1; }; ID='.escapeshellarg($id).'; VERSION_ID='.escapeshellarg($version).'; PRETTY_NAME=x; '.($anyOs ? 'CM_ANY_OS=1; ' : '').$m[0];
        exec('sh -c '.escapeshellarg($check).' 2>&1', $out, $code);

        return $code === 0;
    }

    public function test_the_install_script_accepts_22_04_24_04_and_26_04_and_nothing_else_unless_forced(): void
    {
        $this->assertTrue($this->installCheck('ubuntu', '22.04'));
        $this->assertTrue($this->installCheck('ubuntu', '24.04'));
        $this->assertTrue($this->installCheck('ubuntu', '26.04'));
        $this->assertFalse($this->installCheck('ubuntu', '20.04'));
        $this->assertFalse($this->installCheck('debian', '12'));
        $this->assertFalse($this->installCheck('ubuntu', '25.10'));
        $this->assertTrue($this->installCheck('ubuntu', '20.04', anyOs: true));
    }

    public function test_a_server_with_no_recorded_release_keeps_php_8_4_and_every_branch(): void
    {
        $this->node('web-1.org-1', null);

        $this->assertSame('8.4', ServerStack::defaultPhpFor('web-1.org-1'));
        $this->assertSame(['8.4', '8.3', '8.5', '8.2'], ServerStack::phpVersionsFor('web-1.org-1'));
        foreach (['22.04', '24.04'] as $os) {
            $this->node("old-{$os}.org-1", $os);
            $this->assertSame('8.4', ServerStack::defaultPhpFor("old-{$os}.org-1"));
            $this->assertFalse(ServerStack::phpFromSource($os, '8.2'), 'The PPA serves every branch for 22.04 and 24.04.');
        }
        $this->assertSame('8.4', ServerStack::defaultPhpFor('unknown.org-1'));
    }

    public function test_a_26_04_server_defaults_to_php_8_5_and_every_branch_is_a_package(): void
    {
        $this->node('new-1.org-1', '26.04');

        $this->assertSame('8.5', ServerStack::defaultPhpFor('new-1.org-1'));
        $this->assertSame(['8.5', '8.4', '8.3', '8.2'], ServerStack::phpVersionsFor('new-1.org-1'));
        foreach (['8.5', '8.4', '8.3', '8.2'] as $v) {
            $this->assertFalse(ServerStack::phpFromSource('26.04', $v), 'packages.sury.org serves every branch for 26.04.');
            $this->assertNull(ServerStack::phpRefusal('new-1.org-1', $v));
        }
        $this->assertSame('PHP 7.4 is not offered.', ServerStack::phpRefusal('new-1.org-1', '7.4'));
    }

    public function test_another_branch_on_26_04_comes_from_the_sury_repository_as_packages(): void
    {
        $stack = new ServerStack;
        $steps = array_column($stack->phpInstallRecipe('8.4', os: '26.04')['steps'], null, 'id');

        $repository = $steps['php-repository'];
        $this->assertStringContainsString('https://packages.sury.org/php/apt.gpg', $repository['args'][1]);
        $this->assertStringContainsString('signed-by=/usr/share/keyrings/deb.sury.org-php.gpg', $repository['args'][1], 'Its key is trusted for that repository alone.');
        $this->assertStringContainsString('$VERSION_CODENAME', $repository['args'][1]);
        $this->assertSame(['test', '-f', '/etc/apt/sources.list.d/php.list'], $repository['unless']);
        $this->assertSame('php8.4-fpm', $steps['pkg-php-fpm']['name']);
        $this->assertArrayNotHasKey('php-ppa', $steps);
        $this->assertArrayNotHasKey('php-build-8.4', $steps);
        // What the rest of the stack relies on: the settings files and service of a packaged branch.
        $this->assertSame('/etc/php/8.4/fpm/pool.d/www.conf', $steps['php-fpm-pool']['path']);
        $this->assertArrayHasKey('svc-php8.4-fpm', $steps);

        $ppa = array_column($stack->phpInstallRecipe('8.3', os: '24.04')['steps'], null, 'id');
        $this->assertArrayHasKey('php-ppa', $ppa);
        $this->assertArrayNotHasKey('php-repository', $ppa);
        $this->assertSame('php8.3-fpm', $ppa['pkg-php-fpm']['name']);
    }

    public function test_the_source_build_fallback_is_a_checked_download_laid_out_like_the_packages(): void
    {
        $steps = array_column((new ServerStack)->phpBuildSteps('8.4'), null, 'id');

        $this->assertSame('/usr/local/sbin/cm-build-php', $steps['php-build-8.4']['name']);
        $this->assertSame(['8.4'], $steps['php-build-8.4']['args']);
        $script = base64_decode($steps['php-build-script']['content_b64']);
        $this->assertStringContainsString('www.php.net/distributions', $script);
        $this->assertStringContainsString('sha256sum -c', $script, 'The source is checked before it is built.');
    }

    public function test_the_stack_on_26_04_installs_php_8_5_from_the_sury_repository(): void
    {
        $steps = array_column((new ServerStack)->spec(os: '26.04')['steps'], null, 'id');

        $this->assertArrayHasKey('php-repository', $steps);
        $this->assertArrayNotHasKey('php-ppa', $steps, 'The PPA has nothing for 26.04.');
        $this->assertSame('php8.5-fpm', $steps['pkg-php-fpm']['name']);
        $this->assertSame('php8.5-redis', $steps['pkg-php-redis']['name']);
        $this->assertArrayHasKey('svc-php8.5-fpm', $steps);
        $this->assertStringNotContainsString('8.4', (string) json_encode($steps));

        $default = array_column((new ServerStack)->spec()['steps'], null, 'id');
        $this->assertSame('php8.4-fpm', $default['pkg-php-fpm']['name'], 'Nothing changes for 24.04.');
        $this->assertArrayHasKey('php-ppa', $default);
    }

    public function test_an_application_with_no_php_choice_runs_the_default_of_its_server(): void
    {
        $this->node('new-1.org-1', '26.04');
        $this->node('old-1.org-1', null);
        $sites = $this->app->make(Sites::class);
        $new = $sites->create('org-1', 'new-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $old = $sites->create('org-1', 'old-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');

        $this->assertSame('8.5', Sites::phpVersion($new));
        $this->assertSame('8.4', Sites::phpVersion($old));
        // A site with nothing recorded (made before this) follows its server too.
        DB::table('sites')->where('id', $new->id)->update(['php_version' => '']);
        $this->assertSame('8.5', Sites::phpVersion($sites->find('new-1.org-1', 'shop')));
    }

    public function test_installing_another_branch_on_a_26_04_server_no_longer_says_it_is_built_from_source(): void
    {
        $this->node('new-1.org-1', '26.04');
        $id = $this->app->make(OperationRunner::class)->queueInstallPhp('new-1.org-1', '8.4', 'console', 'a@b.c');

        $this->assertStringNotContainsString('built from source', DB::table('operations')->find($id)->title);
    }

    public function test_installs_first_make_apt_safe_on_a_server_whose_kernel_has_no_modules(): void
    {
        $stack = new ServerStack;

        foreach ([$stack->phpInstallRecipe('8.4', os: '26.04')['steps'], $stack->phpInstallRecipe('8.3', os: '24.04')['steps']] as $steps) {
            $this->assertSame('apt-initramfs', $steps[0]['id'], 'It runs before any package is installed.');
        }
        $this->assertContains('apt-initramfs', array_column($stack->spec(os: '26.04')['steps'], 'id'));

        $step = $stack->aptHealthStep();
        $this->assertStringContainsString('update_initramfs=no', $step['args'][1]);
        $this->assertStringContainsString('dpkg --configure -a', $step['args'][1], 'The package it left half configured is finished.');
        $this->assertStringContainsString('! -d "/lib/modules/$(uname -r)"', $step['args'][1], 'A server with kernel modules is left alone.');
        $this->assertStringContainsString('/lib/modules/$(uname -r)', $step['unless'][2]);
    }

    public function test_tailscale_uses_the_repository_of_the_release_the_server_runs(): void
    {
        $steps = array_column(Tailscale::joinRecipe('web-1', 'tskey')['steps'], null, 'id');
        $both = $steps['ts-keyring']['args'][1].' '.$steps['ts-source']['args'][1];

        $this->assertStringContainsString('$VERSION_CODENAME', $both);
        $this->assertStringNotContainsString('noble', $both);
        $this->assertSame('command', $steps['ts-source']['type']);
    }
}
