<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Services\Services;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** One service's page: what each role is given, the buttons and their checks, secrets, backups, updates and removal. */
final class ServicePageTest extends ServiceTestCase
{
    private int $id;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->node('web-2.org-2', 'org-2');
        $this->execOutput = fn ($n) => $n === 'stat' ? '3145728' : '';
        $this->id = (int) $this->install()['service_id'];
        $this->operate($this->services()->backup('org-1', 'web-1.org-1', 'files', 'manual', 'm@example.com', 'manager', dispatch: false));
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    /** The page's data for this service. @return array<string, mixed> */
    private function serviceView(?int $id = null): array
    {
        return $this->get('/service?service='.($id ?? $this->id))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Service'))->inertiaProps('view');
    }

    private function send(string $path, array $data = [], ?int $id = null)
    {
        return $this->postJson('/service/'.($id ?? $this->id).'/'.$path, $data);
    }

    private function audits(string $action): array
    {
        return DB::table('audit_records')->where('action', $action)->get()->all();
    }

    private function newer(array $extra = []): void
    {
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + $extra);
    }

    private function hostChange(): array
    {
        return ['https://github.com/rustfs/rustfs/releases/download/1.0.20/' => 'https://downloads.example.net/rustfs/'];
    }

    // ---- what the page is given -----------------------------------------------------------

    public function test_the_service_page_for_each_role(): void
    {
        foreach (['viewer' => [false, false, false], 'developer' => [true, true, false], 'manager' => [true, true, true]] as $role => [$deploy, $secrets, $infrastructure]) {
            $this->member($role);
            $page = $this->get('/service?service='.$this->id)->assertOk();
            $page->assertInertia(fn (Assert $p) => $p->component('Service')->where('can', fn ($can) => [$can['deploy'], $can['view.secrets'], $can['infrastructure']] == [$deploy, $secrets, $infrastructure]));
            $v = $page->inertiaProps('view');
            $this->assertSame(['files', 'RustFS', 1, 'web-1.org-1', 'running'], [$v['name'], $v['template_name'], $v['version'], $v['server'], $v['status']]);
            $this->assertSame('files-s3.example.com', $v['endpoints'][0]['domain']);
            $this->assertTrue($v['endpoints'][0]['tls']);
            $this->assertContains('Install files', array_column($v['operations'], 'title'));
            $this->assertSame(['secret_key'], array_values(array_intersect(['secret_key'], $v['secrets'])));
        }
    }

    public function test_a_failed_service_shows_its_error_and_a_busy_one_says_an_operation_is_running(): void
    {
        DB::table('services')->where('id', $this->id)->update(['status' => 'failed', 'error' => 'the certificate step failed: DNS does not point here']);
        $this->member('developer');
        $v = $this->serviceView();
        $this->assertSame(['failed', 'the certificate step failed: DNS does not point here'], [$v['status'], $v['error']]);

        DB::table('services')->where('id', $this->id)->update(['status' => 'running', 'error' => null]);
        $op = $this->services()->action('org-1', 'web-1.org-1', 'files', 'stop', 'a@example.com', 'manager', dispatch: false);
        $v = $this->serviceView();
        $this->assertTrue($v['busy']);
        $this->assertSame($op, $v['operation']['id']);
    }

    public function test_another_teams_service_cannot_be_opened_or_driven_by_id(): void
    {
        $theirs = $this->install('rustfs', $this->rustfs('theirs'), 'web-2.org-2', 'org-2');
        $id = (int) $theirs['service_id'];
        $this->member('owner');
        $page = $this->get('/service?service='.$id)->assertOk();
        $this->assertSame(['missing' => true], $page->inertiaProps('view'));
        $this->assertStringNotContainsString('theirs', $page->getContent());
        foreach ([['act', ['action' => 'stop']], ['reveal', ['key' => 'secret_key']], ['remove', ['choice' => 'keep']], ['backup', []], ['update', []], ['restore', ['backup' => 1, 'typed' => 'files']]] as [$path, $data]) {
            $this->send($path, $data, $id)->assertNotFound();
        }
        // Not even by id from the other team's own service.
        $this->member('manager', 'org-2');
        $this->send('act', ['action' => 'stop'])->assertNotFound();
        $this->send('backup')->assertNotFound();
        $this->send('update')->assertNotFound();
        $this->send('restore', ['backup' => 1, 'typed' => 'files'])->assertNotFound();
    }

    // ---- start, stop, restart ---------------------------------------------------------------

    public function test_start_stop_restart_queue_an_operation_and_refuse_a_viewer(): void
    {
        $this->member('developer');
        $response = $this->send('act', ['action' => 'restart']);
        $op = DB::table('operations')->where('kind', 'template_service_action')->first();
        $this->assertSame('console', $op->trigger);
        $this->assertSame('restart', json_decode($op->meta, true)['action']);
        $response->assertRedirect("/operation?operation={$op->id}");
        // A second one while it runs is refused with the reason.
        $this->send('act', ['action' => 'stop'])->assertStatus(422)->assertJsonPath('errors.action.0', fn ($m) => str_contains($m, 'Another operation is already running'));

        $this->member('viewer');
        $this->send('act', ['action' => 'stop'])->assertForbidden();
    }

    // ---- secrets -----------------------------------------------------------------------------

    public function test_reveal_answers_with_the_value_once_writes_one_audit_record_without_it_and_the_page_never_carries_it(): void
    {
        $value = app(Vault::class)->get('org-1', Services::secretName($this->id, 'secret_key'));
        $this->assertNotEmpty($value);

        $this->member('developer');
        $page = $this->get('/service?service='.$this->id)->assertOk();
        $this->assertStringNotContainsString($value, $page->getContent());
        $this->assertSame([], $this->audits('service.secret_revealed'));

        $answer = $this->send('reveal', ['key' => 'secret_key'])->assertOk();
        $this->assertSame($value, $answer->json('value'));
        $this->assertStringContainsString('no-store', (string) $answer->headers->get('Cache-Control'));
        $records = $this->audits('service.secret_revealed');
        $this->assertCount(1, $records);
        $this->assertStringNotContainsString($value, json_encode($records[0]));
        $this->assertStringContainsString('secret_key', json_encode($records[0]));

        // Never in the page after it, the stored inputs or the list.
        $this->assertStringNotContainsString($value, $this->get('/service?service='.$this->id)->getContent());
        $this->assertStringNotContainsString($value, $this->service()->inputs);
        $this->assertStringNotContainsString($value, $this->get('/services')->getContent());
        // An unknown key is refused and records nothing.
        $this->send('reveal', ['key' => 'nope'])->assertStatus(422);
        $this->assertCount(1, $this->audits('service.secret_revealed'));
    }

    public function test_a_viewer_cannot_reveal(): void
    {
        $this->member('viewer');
        $this->send('reveal', ['key' => 'secret_key'])->assertForbidden();
        $this->assertSame([], $this->audits('service.secret_revealed'));
    }

    // ---- delete -------------------------------------------------------------------------------

    public function test_delete_asks_for_keep_or_delete(): void
    {
        $this->member('manager');
        // Neither choice made: refused, nothing queued.
        $this->send('remove', ['choice' => '', 'typed' => ''])->assertStatus(422)->assertJsonValidationErrors(['dataChoice']);
        $this->assertSame(0, DB::table('operations')->where('kind', 'template_service_remove')->count());

        $response = $this->send('remove', ['choice' => 'delete']);
        $op = DB::table('operations')->where('kind', 'template_service_remove')->first();
        $this->assertTrue(json_decode($op->meta, true)['delete_data']);
        $response->assertRedirect("/operation?operation={$op->id}");
    }

    public function test_delete_keeping_the_data_needs_no_typing_and_a_developer_cannot_delete(): void
    {
        $this->member('developer');
        $this->send('remove', ['choice' => 'keep', 'typed' => ''])->assertForbidden();
        $this->member('manager');
        $this->send('remove', ['choice' => 'keep', 'typed' => ''])->assertRedirect();
        $op = DB::table('operations')->where('kind', 'template_service_remove')->first();
        $this->assertFalse(json_decode($op->meta, true)['delete_data']);
    }

    // ---- backups and updates ---------------------------------------------------------------

    public function test_with_nothing_newer_there_is_no_update_but_the_backups_list_shows(): void
    {
        $this->member('manager');
        $v = $this->serviceView();
        $this->assertNull($v['update_detail']);
        $this->assertSame('snapshot', $v['backup_choice']);
        $this->assertCount(1, $v['backups']);
        $this->assertSame(['manual', '3 MB', 'this server only'], [$v['backups'][0]['kind'], $v['backups'][0]['size'], $v['backups'][0]['where']]);
    }

    public function test_the_update_lists_what_changes_and_a_host_change_needs_confirming(): void
    {
        $this->newer($this->hostChange());
        $this->member('manager');
        $u = $this->serviceView()['update_detail'];
        $this->assertSame([2, 1, true, true], [(int) $u['version'], (int) $u['from'], $u['allowed'], $u['confirm']]);
        $changes = implode("\n", $u['changes']);
        $this->assertStringContainsString('version 1 to 2', $changes);
        $this->assertStringContainsString('the download host changed from github.com to downloads.example.net', $changes);
        $this->assertStringContainsString('the download checksum (sha256) changed', $changes);
        $this->assertSame([], $u['ports']);
    }

    public function test_without_a_host_change_there_is_no_confirmation_and_new_inputs_are_asked_for(): void
    {
        $this->newer(['  - { key: backup,' => "  - { key: region, type: text, label: Region }\n  - { key: backup,", '    RUSTFS_SECRET_KEY: "{{ secrets.secret_key }}"' => "    RUSTFS_SECRET_KEY: \"{{ secrets.secret_key }}\"\n    REGION: \"{{ inputs.region }}\""]);
        $this->member('manager');
        $u = $this->serviceView()['update_detail'];
        $this->assertFalse($u['confirm']);
        $this->assertSame(['region'], array_column($u['inputs'], 'key'));
        $this->assertSame('Region', $u['inputs'][0]['label']);
        $this->assertStringContainsString('a new input: Region (required)', implode("\n", $u['changes']));
    }

    public function test_extra_ports_and_an_acknowledgement_show_their_approvals(): void
    {
        $this->newer(["\nruns:" => "\nextra_ports:\n  - { port: 2222, proto: tcp, label: Git over SSH }\nacknowledge: This runs code from your repositories.\n\nruns:"]);
        $this->member('manager');
        $u = $this->serviceView()['update_detail'];
        $this->assertStringContainsString('the firewall ports it asks for changed: 2222/tcp', implode("\n", $u['changes']));
        $this->assertSame(['2222/tcp'], $u['ports']);
        $this->assertSame('This runs code from your repositories.', $u['acknowledge']);
    }

    public function test_a_refused_update_says_why(): void
    {
        $this->newer();
        DB::table('services')->update(['installed' => json_encode(['publisher' => 'Mallory'] + json_decode($this->service()->installed, true))]);
        $this->member('manager');
        $u = $this->serviceView()['update_detail'];
        $this->assertFalse($u['allowed']);
        $this->assertStringContainsString('publisher changed from Mallory', $u['refused']);
    }

    public function test_a_running_service_with_a_rolled_back_update_shows_the_failure_plainly(): void
    {
        $this->newer();
        $this->failSteps = ['health' => 'no answer'];
        $this->operate($this->services()->update('org-1', 'web-1.org-1', 'files', [], 'm@example.com', 'manager', dispatch: false));
        $this->member('viewer');
        $v = $this->serviceView();
        $this->assertSame('running', $v['status']);
        $this->assertStringContainsString('The update to version 2 failed and was rolled back', (string) $v['error']);
    }

    public function test_back_up_now_queues_a_manual_backup_and_a_developer_cannot(): void
    {
        $this->member('manager');
        $response = $this->send('backup');
        $op = DB::table('operations')->where('kind', 'template_service_backup')->orderByDesc('id')->first();
        $this->assertSame(['console', 'manual'], [$op->trigger, json_decode($op->meta, true)['kind']]);
        $response->assertRedirect("/operation?operation={$op->id}");
        $this->send('backup')->assertStatus(422)->assertJsonPath('errors.backup.0', fn ($m) => str_contains($m, 'Another operation is already running'));

        $this->member('developer');
        $this->send('backup')->assertForbidden();
    }

    public function test_update_needs_the_ticks_and_then_queues_the_operation(): void
    {
        $this->newer($this->hostChange());
        $this->member('manager');
        $this->send('update', [])->assertStatus(422)->assertJsonPath('errors.update.0', fn ($m) => str_contains($m, 'Confirm it explicitly'));
        $this->assertSame(0, DB::table('operations')->where('kind', 'template_service_update')->count());

        $response = $this->send('update', ['confirmed' => true]);
        $op = DB::table('operations')->where('kind', 'template_service_update')->first();
        $response->assertRedirect("/operation?operation={$op->id}");
        $meta = json_decode($op->meta, true);
        $this->assertSame([2, true, 'console'], [$meta['to_version'], $meta['confirmed'], $op->trigger]);
        $this->assertSame('succeeded', $this->operate((int) $op->id));
        $this->assertSame(2, (int) $this->service()->template_version);
    }

    public function test_restore_asks_for_the_name_and_a_viewer_or_developer_cannot(): void
    {
        $this->member('manager');
        $backup = (int) DB::table('service_backups')->value('id');
        $this->send('restore', ['backup' => $backup, 'typed' => 'nope'])->assertStatus(422)->assertJsonPath('errors.restore.0', fn ($m) => str_contains($m, 'Type files to confirm the restore'));
        $this->assertSame(0, DB::table('operations')->where('kind', 'template_service_restore')->count());
        $this->send('restore', ['backup' => $backup, 'typed' => 'files'])->assertRedirect();
        $this->assertSame(1, DB::table('operations')->where('kind', 'template_service_restore')->count());

        $this->member('developer');
        $this->send('restore', ['backup' => $backup, 'typed' => 'files'])->assertForbidden();
        $this->member('viewer');
        $this->send('update')->assertForbidden();
    }

    public function test_the_page_source_warns_before_the_two_risky_buttons(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/Service.vue'));
        $this->assertStringContainsString('Its addresses stop answering until it is started again.', $source);
        $this->assertStringContainsString('A backup is taken first, and the service restarts briefly.', $source);
        $this->assertStringContainsString('What is there now is backed up first', $source);
        $this->assertStringContainsString('v-if="can.infrastructure"', $source);
    }

    public function test_a_container_service_names_its_container_and_loopback_port(): void
    {
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => now(), 'docker_version' => '29.0.1']);
        $made = $this->install('uptime-kuma', ['name' => 'kuma', 'host' => 'kuma.example.com'], 'web-1.org-1');
        $this->member('manager');
        $v = $this->serviceView((int) $made['service_id']);
        $this->assertSame('container', $v['runtime']);
        $this->assertSame('kuma', $v['name']);
        $this->assertSame(3001, (int) $v['endpoints'][0]['container_port']);
        $this->assertStringStartsWith('127.0.0.1:', (string) $v['endpoints'][0]['listen']);
    }

    public function test_each_button_names_the_permission_it_needs(): void
    {
        $infrastructure = ['backup' => [], 'update' => [], 'restore' => ['backup' => 1, 'typed' => 'files'], 'remove' => ['choice' => 'keep']];
        $this->member('viewer');
        foreach (['act' => ['action' => 'restart'], 'reveal' => ['key' => 'secret_key']] + $infrastructure as $path => $data) {
            $this->send($path, $data)->assertForbidden();
        }
        $this->get('/service?service='.$this->id)->assertOk();

        $this->member('developer');
        foreach ($infrastructure as $path => $data) {
            $this->send($path, $data)->assertForbidden();
        }
        $this->assertNotSame(403, $this->send('reveal', ['key' => 'secret_key'])->status());
        $this->assertNotSame(403, $this->send('act', ['action' => 'restart'])->status());
    }
}
