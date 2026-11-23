<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Lib\Alerts\Alerts;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The Alerts page: what is open, where alerts go, and who may change either. */
final class AlertsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function signIn(string $role = 'manager'): User
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    public function test_it_lists_open_alerts_and_the_tab_shows_how_many(): void
    {
        $this->signIn();
        DB::table('alerts')->insert(['organization_id' => 'org-1', 'key' => 'disk:web-1', 'severity' => 'warning', 'title' => "web-1's disk is 93% full",
            'detail' => '1.2 GB left.', 'link' => '/servers', 'opened_at' => now()]);

        $this->get('/alerts')->assertInertia(fn (Assert $page) => $page
            ->component('Alerts')
            ->has('open', 1, fn (Assert $a) => $a->where('title', "web-1's disk is 93% full")->etc())
            ->where('webhookSet', false)
            ->where('shell.openAlerts', 1)
            ->where('can.infrastructure', true));
    }

    public function test_the_webhook_is_kept_in_the_vault_and_never_sent_back_to_the_page(): void
    {
        $this->signIn();

        $this->post('/alerts/webhook', ['url' => 'https://hooks.slack.com/services/T/B/secret'])->assertRedirect()->assertSessionHas('status', 'Webhook saved.');

        $this->assertSame('https://hooks.slack.com/services/T/B/secret', app(Vault::class)->get('org-1', Alerts::WEBHOOK_SECRET));
        $response = $this->get('/alerts');
        $response->assertInertia(fn (Assert $page) => $page->where('webhookSet', true));
        $this->assertStringNotContainsString('T/B/secret', $response->getContent());
    }

    public function test_a_webhook_that_is_not_https_is_refused(): void
    {
        $this->signIn();

        $this->post('/alerts/webhook', ['url' => 'http://hooks.example.com/x'])->assertSessionHasErrors('webhook');
        $this->assertFalse(app(Vault::class)->has('org-1', Alerts::WEBHOOK_SECRET));
    }

    public function test_an_email_is_saved_and_a_bad_one_refused(): void
    {
        $this->signIn();

        $this->post('/alerts/email', ['email' => 'oops'])->assertSessionHasErrors('email');
        $this->post('/alerts/email', ['email' => 'ops@example.com'])->assertSessionHas('status', 'Email saved.');
        $this->assertSame('ops@example.com', DB::table('organizations')->where('id', 'org-1')->value('alert_email'));
    }

    public function test_thresholds_are_saved_and_out_of_range_ones_refused(): void
    {
        $this->signIn();
        $rules = ['offline_minutes' => 5, 'disk_percent' => 80, 'cpu_percent' => 90, 'cpu_minutes' => 10, 'certificate_days' => 14, 'failed_operations' => true];

        $this->post('/alerts/rules', $rules)->assertSessionHas('status');
        $this->assertSame(80, Alerts::settings('org-1')['disk_percent']);

        $this->post('/alerts/rules', ['disk_percent' => 500] + $rules)->assertSessionHasErrors('rules');
        $this->assertSame(80, Alerts::settings('org-1')['disk_percent']);
    }

    public function test_a_viewer_sees_the_page_but_cannot_change_anything(): void
    {
        $this->signIn('viewer');

        $this->get('/alerts')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.infrastructure', false));
        $this->post('/alerts/webhook', ['url' => 'https://x.example.com'])->assertForbidden();
        $this->post('/alerts/email', ['email' => 'a@example.com'])->assertForbidden();
        $this->post('/alerts/rules', [])->assertForbidden();
        $this->post('/alerts/test')->assertForbidden();
    }

    public function test_a_developer_cannot_change_where_alerts_go(): void
    {
        $this->signIn('developer');

        $this->post('/alerts/webhook', ['url' => 'https://x.example.com'])->assertForbidden();
    }
}
