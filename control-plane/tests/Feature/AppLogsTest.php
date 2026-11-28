<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** An application's own logs: only its lines, clearable by someone who can edit it, and the page says which one it is. */
final class AppLogsTest extends TestCase
{
    use RefreshDatabase;

    private object $shop;

    private object $blog;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $sites = new Sites($this->app->make(Vault::class), new AuditLog);
        $this->shop = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $this->blog = $sites->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        foreach (['laravel:shop' => 'shop broke', 'worker:shop' => 'shop job', 'nginx.error:shop' => 'shop 502', 'laravel:blog' => 'blog broke', 'php-fpm' => 'fpm warning', 'nginx.error' => 'server-wide nginx'] as $source => $text) {
            DB::table('log_lines')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'source' => $source, 'text' => $text, 'logged_at' => now()]);
        }
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::forceCreate(['name' => $role, 'email' => "{$role}-{$org}@example.com", 'password' => 'correct horse battery']);
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_an_applications_page_names_the_application_and_shows_only_its_lines(): void
    {
        $this->actingAs($this->member('developer'));

        $page = $this->get("/site/logs?site={$this->shop->id}")->assertOk();
        $this->assertSame('shop', $page->inertiaProps('app'));
        $this->assertTrue($page->inertiaProps('canClear'));
        $texts = collect($page->inertiaProps('lines.rows'))->pluck('text')->all();
        $this->assertEqualsCanonicalizing(['shop broke', 'shop job', 'shop 502', 'server-wide nginx'], $texts, 'Not the blog, and not php-fpm.');

        $team = $this->get('/logs')->assertOk();
        $this->assertNull($team->inertiaProps('app'));
        $this->assertFalse($team->inertiaProps('canClear'));
    }

    public function test_clearing_deletes_only_that_applications_own_lines_and_is_recorded(): void
    {
        $this->actingAs($this->member('developer'));

        $this->post('/site/logs/clear', ['site' => $this->shop->id])->assertRedirect("/site/logs?site={$this->shop->id}");

        $left = DB::table('log_lines')->pluck('text')->all();
        $this->assertEqualsCanonicalizing(['blog broke', 'fpm warning', 'server-wide nginx'], $left, 'The shared server logs and the other application stay.');
        $this->assertSame(3, json_decode((string) DB::table('audit_records')->where('action', 'site.logs_cleared')->value('metadata'), true)['lines']);
    }

    public function test_a_viewer_cannot_clear_and_nobody_clears_another_organisations_logs(): void
    {
        $this->actingAs($this->member('viewer'));
        $this->post('/site/logs/clear', ['site' => $this->shop->id])->assertForbidden();
        $this->assertSame(6, DB::table('log_lines')->count());

        $this->actingAs($this->member('owner', 'org-2'));
        $this->post('/site/logs/clear', ['site' => $this->shop->id])->assertSessionHasErrors('site');
        $this->assertSame(6, DB::table('log_lines')->count());
    }
}
