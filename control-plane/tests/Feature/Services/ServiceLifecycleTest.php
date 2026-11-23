<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Services\Services;
use App\Lib\Sites\Sites;
use App\Lib\Vault\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** start, stop, restart, remove; and who may do what. */
final class ServiceLifecycleTest extends ServiceTestCase
{
    private function installed(): int
    {
        $made = $this->install();
        $this->assertSame('succeeded', $made['status'], (string) DB::table('operations')->value('error'));
        $this->recipes = [];

        return $made['service_id'];
    }

    private function act(string $action, string $role = 'developer'): string
    {
        $id = $this->services()->action('org-1', 'web-1.org-1', 'files', $action, 'dev@example.com', $role, dispatch: false);

        return $this->operate($id);
    }

    public function test_stop_start_and_restart_send_their_recipes_and_set_the_status(): void
    {
        $this->installed();

        $this->assertSame('succeeded', $this->act('stop'));
        $this->assertSame(['stopped', 'stopped'], [$this->steps('service-stop')['stop']['state'], $this->service()->status]);
        $this->assertSame('succeeded', $this->act('start'));
        $this->assertSame(['running', 'running'], [$this->steps('service-start')['start']['state'], $this->service()->status]);
        $this->assertSame('succeeded', $this->act('restart'));
        $restart = $this->steps('service-restart');
        $this->assertSame(['restart', 'cm-svc-files'], [$restart['restart']['args'][0], $restart['restart']['args'][1]]);
        $this->assertSame(['enable', 'cm-svc-files'], $restart['enable']['args']);

        $audit = DB::table('audit_records')->where('action', 'service.action')->get();
        $this->assertCount(3, $audit);
        $this->assertSame(['stop', 'start', 'restart'], $audit->map(fn ($a) => json_decode($a->metadata, true)['action'])->all());
        $this->assertSame('rustfs', json_decode($audit[0]->metadata, true)['template']);
        $this->assertSame('3d9d42bb4cb7a1589266090d4f9f8238465506cdd9d5e897193377dd6f610aac', json_decode($audit[0]->metadata, true)['checksum']);
    }

    public function test_remove_keeping_the_data_leaves_the_data_folder(): void
    {
        $id = $this->installed();
        $op = $this->services()->remove('org-1', 'web-1.org-1', 'files', false, 'm@example.com', 'manager', dispatch: false);
        $this->assertSame('succeeded', $this->operate($op), (string) DB::table('operations')->where('id', $op)->value('error'));

        $paths = array_column(array_merge(...array_column($this->recipes, 'steps')), 'path');
        $this->assertContains('/etc/systemd/system/cm-svc-files.service', $paths);
        $this->assertContains('/etc/cloud-manager/services/files.env', $paths);
        $this->assertContains('/etc/nginx/sites-enabled/cm-svc-files-9000.conf', $paths);
        $this->assertContains('/srv/cloud-manager/services/files', $paths);
        $this->assertNotContains('/srv/cloud-manager/data/files', $paths);
        $certs = array_filter(array_merge(...array_column($this->recipes, 'steps')), fn ($s) => str_starts_with($s['id'], 'cert-'));
        $this->assertCount(2, $certs);
        $this->assertNull($this->service());
        $this->assertSame(0, DB::table('service_endpoints')->count());
        $this->assertFalse(app(Vault::class)->has('org-1', Services::secretName($id, 'access_key')));
        $this->assertSame('kept', json_decode((string) DB::table('audit_records')->where('action', 'service.removed')->value('metadata'), true)['data']);
        $this->assertSame(['stop', 'endpoints', 'data'], array_column(json_decode((string) DB::table('operations')->where('id', $op)->value('stages'), true), 'name'));
    }

    public function test_remove_deleting_the_data_removes_the_data_folder_through_an_owned_step(): void
    {
        $this->installed();
        $op = $this->services()->remove('org-1', 'web-1.org-1', 'files', true, 'm@example.com', 'manager', dispatch: false);
        $this->assertSame('succeeded', $this->operate($op));

        $data = $this->steps('service-remove-files')['data'];
        $this->assertSame(['absent', '/srv/cloud-manager/data/files', 'cm-rustfs', '/srv/cloud-manager/data'], [$data['type'], $data['path'], $data['owner'], $data['base']]);
        $this->assertSame('deleted', json_decode((string) DB::table('audit_records')->where('action', 'service.removed')->value('metadata'), true)['data']);
    }

    public function test_remove_never_decides_about_the_data_silently(): void
    {
        $this->installed();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keep it, or delete it');
        try {
            $this->services()->remove('org-1', 'web-1.org-1', 'files', null, 'm@example.com', 'manager', dispatch: false);
        } finally {
            $this->assertSame('running', $this->service()->status, 'nothing changed');
            $this->assertSame(0, DB::table('operations')->where('kind', 'template_service_remove')->count());
        }
    }

    public function test_a_second_operation_on_the_service_is_refused_while_one_is_queued(): void
    {
        $this->installed();
        $this->services()->action('org-1', 'web-1.org-1', 'files', 'stop', 'dev', 'developer', dispatch: false);

        $this->expectExceptionMessage('already running');
        $this->services()->action('org-1', 'web-1.org-1', 'files', 'restart', 'dev', 'developer', dispatch: false);
    }

    public function test_a_second_operation_is_refused_while_the_lock_is_held(): void
    {
        $this->installed();
        $lock = \Illuminate\Support\Facades\Cache::lock('service-operation:org-1:files', 30);
        $this->assertTrue($lock->get());
        try {
            $this->expectExceptionMessage('already running');
            $this->services()->action('org-1', 'web-1.org-1', 'files', 'restart', 'dev', 'developer', dispatch: false);
        } finally {
            $lock->release();
        }
    }

    public function test_a_failed_service_cannot_be_started_only_installed_again_or_removed(): void
    {
        $this->failSteps = ['health' => 'no answer'];
        $this->install();
        $this->expectExceptionMessage('is failed');
        $this->services()->action('org-1', 'web-1.org-1', 'files', 'start', 'dev', 'developer', dispatch: false);
    }

    public function test_roles_a_developer_can_act_but_not_install_or_remove_a_manager_can_a_viewer_nothing(): void
    {
        $this->installed();
        $svc = $this->services();

        // Developer: start/stop/restart yes, install/remove no.
        $this->assertSame('succeeded', $this->operate($svc->action('org-1', 'web-1.org-1', 'files', 'stop', 'd', 'developer', dispatch: false)));
        $this->refused(fn () => $svc->install('org-1', 'web-1.org-1', 'rustfs', $this->rustfs('other'), 'd', 'developer', dispatch: false));
        $this->refused(fn () => $svc->remove('org-1', 'web-1.org-1', 'files', false, 'd', 'developer', dispatch: false));

        // Viewer: nothing.
        $this->refused(fn () => $svc->action('org-1', 'web-1.org-1', 'files', 'restart', 'v', 'viewer', dispatch: false));
        $this->refused(fn () => $svc->install('org-1', 'web-1.org-1', 'rustfs', $this->rustfs('other'), 'v', 'viewer', dispatch: false));
        $this->refused(fn () => $svc->remove('org-1', 'web-1.org-1', 'files', false, 'v', 'viewer', dispatch: false));

        // No role at all is the same as a viewer.
        $this->refused(fn () => $svc->action('org-1', 'web-1.org-1', 'files', 'restart', 'x', '', dispatch: false));

        // Manager can install (on a server with the ports free) and remove.
        $this->node('web-2.org-1', 'org-1');
        $this->assertGreaterThan(0, $svc->install('org-1', 'web-2.org-1', 'rustfs', $this->rustfs('other'), 'm', 'manager', dispatch: false)['operation_id']);
        $this->assertGreaterThan(0, $svc->remove('org-1', 'web-1.org-1', 'files', false, 'm', 'owner', dispatch: false));
    }

    private function refused(\Closure $do): void
    {
        try {
            $do();
            $this->fail('expected a refusal');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_another_teams_server_and_service_cannot_be_used_by_id_or_name(): void
    {
        $this->node('db-1.org-2', 'org-2');
        $this->installed();

        // org-2 cannot install on org-1's server...
        try {
            $this->services()->install('org-2', 'web-1.org-1', 'rustfs', $this->rustfs('mine'), 'x', 'owner', dispatch: false);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no server web-1.org-1 in this team', $e->getMessage());
        }
        // ...or act on or remove org-1's service, by its server or by its name.
        foreach ([['web-1.org-1', 'files'], ['db-1.org-2', 'files']] as [$node, $name]) {
            try {
                $this->services()->action('org-2', $node, $name, 'stop', 'x', 'owner', dispatch: false);
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no service', $e->getMessage());
            }
            try {
                $this->services()->remove('org-2', $node, $name, true, 'x', 'owner', dispatch: false);
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no service', $e->getMessage());
            }
        }
        $this->assertSame([], $this->services()->list('org-2'));
        $this->assertCount(1, $this->services()->list('org-1'));
        $this->assertCount(2, $this->services()->list('org-1')[0]->endpoints);

        // The same name is free in the other team.
        $this->assertSame('succeeded', $this->install('rustfs', $this->rustfs(), 'db-1.org-2', 'org-2')['status']);
    }

    public function test_a_service_and_an_application_cannot_share_a_name_either_way(): void
    {
        $sites = $this->app->make(Sites::class);
        $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        try {
            $this->services()->install('org-1', 'web-1.org-1', 'rustfs', $this->rustfs('shop'), 'm', 'manager', dispatch: false);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('an application called shop already exists', $e->getMessage());
        }

        $this->installed();
        $this->expectExceptionMessage('a service called files already exists');
        $sites->create('org-1', 'web-1.org-1', 'files', 'files-app.example.com', 'git@github.com:acme/files.git');
    }

    public function test_a_domain_that_an_application_uses_is_refused(): void
    {
        $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'files-s3.example.com', 'git@github.com:acme/shop.git');
        $this->expectExceptionMessage('already the address of an application');
        $this->services()->install('org-1', 'web-1.org-1', 'rustfs', $this->rustfs(), 'm', 'manager', dispatch: false);
    }
}
