<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Every change to a site's .env is kept, encrypted, and can be put back;
 * what the history shows is which keys changed, never their values.
 */
final class EnvVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog);
    }

    public function test_changes_are_kept_and_an_earlier_version_comes_back(): void
    {
        $sites = $this->sites();
        $site = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $original = $sites->customEnv($site);

        $sites->setEnvValues($site, ['MAIL_PASSWORD' => 'hunter2', 'APP_DEBUG' => 'true']);
        $afterFirst = $sites->customEnv($site);
        $sites->setEnv($site, str_replace('MAIL_PASSWORD=hunter2', 'MAIL_PASSWORD=swordfish', $sites->customEnv($site)), 'edited in the console');

        $versions = $sites->envVersions($site);
        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertEquals(['added' => [], 'removed' => [], 'changed' => ['MAIL_PASSWORD']], $versions[0]['changes']);
        $this->assertContains('MAIL_PASSWORD', $versions[1]['changes']['added']);
        $this->assertSame([], $versions[1]['changes']['removed'], 'There was nothing before it.');
        $this->assertStringNotContainsString('hunter2', json_encode($versions).DB::table('site_env_versions')->pluck('changes')->implode(''), 'Values never leave the vault.');

        $sites->restoreEnvVersion($site, 1);
        $this->assertSame($afterFirst, $sites->customEnv($site));
        $this->assertSame('', $original, 'They started with none of their own.');
        $this->assertSame('restored version 1', $sites->envVersions($site)[0]['reason']);
        $this->assertSame(3, $sites->envVersions($site)[0]['version'], 'A restore is a version of its own, so it can be undone.');
    }

    public function test_saving_the_same_contents_is_not_a_version(): void
    {
        $sites = $this->sites();
        $site = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $sites->setEnv($site, $sites->customEnv($site));
        $this->assertSame([], $sites->envVersions($site));
    }

    public function test_only_the_newest_twenty_are_kept(): void
    {
        $sites = $this->sites();
        $site = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        for ($i = 1; $i <= 25; $i++) {
            $sites->setEnvValues($site, ['COUNTER' => (string) $i]);
        }
        $versions = $sites->envVersions($site);
        $this->assertCount(Sites::ENV_VERSIONS_KEPT, $versions);
        $this->assertSame(25, $versions[0]['version']);
        $this->assertFalse($this->app->make(Vault::class)->has('org-1', 'site:web-1.org-1:shop:env:v1'), 'A dropped version leaves the vault too.');

        $this->expectException(InvalidArgumentException::class);
        $sites->restoreEnvVersion($site, 1);
    }
}
