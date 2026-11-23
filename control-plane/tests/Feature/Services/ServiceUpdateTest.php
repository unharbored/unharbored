<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Services\Services;
use App\Lib\Vault\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Step 5: updating a service to a newer version of its template (native and container), and what refuses or rolls it back. */
final class ServiceUpdateTest extends ServiceTestCase
{
    private function update(array $inputs = [], bool $confirm = false, bool $ports = false, bool $ack = false, string $role = 'manager', string $name = 'files', string $node = 'web-1.org-1', string $org = 'org-1'): int
    {
        return $this->services()->update($org, $node, $name, $inputs, 'matt@example.com', $role, $confirm, $ports, $ack, dispatch: false);
    }

    private function opError(int $op): string
    {
        return (string) DB::table('operations')->where('id', $op)->value('error');
    }

    private function nativeAt(int $version = 2): int
    {
        $this->execOutput = fn ($n) => $n === 'stat' ? '2048' : '';
        $id = $this->install()['service_id'];
        $this->publish('rustfs', $version, $this->rustfsRelease($version));
        $this->recipes = [];

        return $id;
    }

    private function kuma(int $version = 2): int
    {
        $this->execOutput = fn ($n) => $n === 'stat' ? '4096' : '';
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => now(), 'docker_version' => '29.0.0']);
        $id = $this->install('uptime-kuma', ['name' => 'kuma', 'host' => 'kuma.example.com'])['service_id'];
        $this->publish('uptime-kuma', $version, [self::KUMA_DIGEST => $this->kumaDigest($version)]);
        $this->recipes = [];

        return $id;
    }

    private function indexOf(string $recipe): int
    {
        $at = array_search($recipe, $this->recipeNames(), true);
        $this->assertNotFalse($at, "{$recipe} was not sent; sent: ".implode(', ', $this->recipeNames()));

        return $at;
    }

    // ---- native -------------------------------------------------------------

    public function test_a_native_update_snapshots_fetches_verifies_switches_and_keeps_the_old_release(): void
    {
        $id = $this->nativeAt();
        $vault = app(Vault::class);
        $before = [$vault->get('org-1', Services::secretName($id, 'access_key')), $vault->get('org-1', Services::secretName($id, 'secret_key'))];
        $this->assertSame(1, (int) $this->service()->template_version);

        $op = $this->update();
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $this->assertSame(['snapshot', 'fetch', 'switch', 'health'], array_column(json_decode((string) DB::table('operations')->where('id', $op)->value('stages'), true), 'name'));

        // The order: the old release is checked, the snapshot is taken, the new release is fetched and verified, only then the switch.
        $order = array_map($this->indexOf(...), ['service-update-check-files', 'service-backup-files', 'service-fetch-files', 'service-update-switch-files', 'service-health-files', 'service-update-finish-files']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);

        $fetch = $this->steps('service-fetch')['download'];
        $script = $fetch['args'][1];
        $this->assertStringContainsString('/srv/cloud-manager/services/files/releases/2', $script);
        $this->assertStringContainsString(str_repeat('b', 64).'  ', $script);
        $this->assertStringContainsString('sha256sum -c', $script);
        $this->assertSame(['test', '-e', '/srv/cloud-manager/services/files/releases/2/rustfs'], $fetch['unless']);

        $switch = $this->steps('service-update-switch');
        $this->assertSame(['keep-previous', 'stop', 'unit', 'daemon-reload', 'start'], array_values(array_filter(array_keys($switch), fn ($k) => ! str_starts_with($k, 'dir-'))));
        $this->assertStringContainsString('ExecStart=/srv/cloud-manager/services/files/releases/2/rustfs --address', base64_decode($switch['unit']['content_b64']));
        $this->assertStringContainsString('cp -p', $switch['keep-previous']['args'][1]);
        $this->assertArrayNotHasKey('env', $switch, 'the environment did not change, so its file is not rewritten');

        // The previous release is kept: nothing deletes it by path (the prune script only ever lists the folder).
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $step) {
                $this->assertFalse($step['type'] === 'absent' && str_contains((string) ($step['path'] ?? ''), 'releases'), 'no step removes a release by path');
            }
        }
        $finish = $this->steps('service-update-finish')['prune']['args'][1];
        $this->assertStringContainsString("grep -vx '2'", $finish);
        $this->assertStringContainsString('tail -n +3', $finish);

        $row = $this->service();
        $this->assertSame(2, (int) $row->template_version);
        $this->assertSame('running', $row->status);
        $this->assertNull($row->error);
        $this->assertEquals([['version' => 1, 'ref' => null]], json_decode($row->previous, true));
        $this->assertSame(str_repeat('b', 64), json_decode($row->installed, true)['sha256']);
        // Secrets are the ones from the install.
        $this->assertSame($before, [$vault->get('org-1', Services::secretName($id, 'access_key')), $vault->get('org-1', Services::secretName($id, 'secret_key'))]);

        $audit = json_decode((string) DB::table('audit_records')->where('action', 'service.updated')->value('metadata'), true);
        $this->assertSame([1, 2, str_repeat('b', 64)], [$audit['from'], $audit['to'], $audit['checksum']]);
        $this->assertStringNotContainsString($before[0], json_encode(DB::table('audit_records')->get()).json_encode(DB::table('operations')->get()));
        $this->assertStringNotContainsString($before[1], json_encode(DB::table('audit_records')->get()).json_encode(DB::table('operations')->get()));
        $this->assertNull($this->services()->updateAvailable($this->service()));
    }

    public function test_the_snapshot_is_taken_before_anything_changes_and_its_failure_changes_nothing(): void
    {
        $this->nativeAt();
        $this->failSteps = ['archive' => 'No space left on device'];

        $op = $this->update();
        $this->assertSame('failed', $this->operate($op));
        $this->assertStringContainsString('The snapshot before the update failed, so nothing was changed', $this->opError($op));
        $names = $this->recipeNames();
        $this->assertNotContains('service-fetch-files', $names);
        $this->assertNotContains('service-update-switch-files', $names);
        $row = $this->service();
        $this->assertSame(['running', 1, null], [$row->status, (int) $row->template_version, $row->error]);
        $this->assertSame(0, DB::table('service_backups')->count());
        $this->assertSame(1, DB::table('audit_records')->where('action', 'service.update_failed')->count());
    }

    public function test_a_failed_health_check_switches_back_and_the_service_keeps_running_on_the_old_version(): void
    {
        $this->nativeAt();
        $this->failSteps = ['health' => 'no answer on 127.0.0.1:9000 in 60 seconds'];

        $op = $this->update();
        $this->assertSame('failed', $this->operate($op));
        $this->assertStringContainsString('version 1 was put back and is running', $this->opError($op));

        $back = $this->steps('service-update-back');
        $this->assertSame(['stop', 'put-back', 'daemon-reload', 'start', 'settle'], array_keys($back));
        $this->assertStringContainsString('/update-prev/unit', $back['put-back']['args'][1]);
        $row = $this->service();
        $this->assertSame(['running', 1], [$row->status, (int) $row->template_version]);
        $this->assertStringContainsString('The update to version 2 failed and was rolled back', (string) $row->error);
        $this->assertNull($row->previous);
        $this->assertSame(1, DB::table('audit_records')->where('action', 'service.update_rolled_back')->count());
        $this->assertSame(0, DB::table('audit_records')->where('action', 'service.updated')->count());
        $this->assertNotContains('service-update-finish-files', $this->recipeNames());
    }

    public function test_a_failure_of_the_switch_itself_is_put_back_too(): void
    {
        $this->nativeAt();
        $this->failSteps = ['unit' => 'could not write the unit'];
        $op = $this->update();
        $this->assertSame('failed', $this->operate($op));
        $this->assertContains('service-update-back-files', $this->recipeNames());
        $this->assertSame(['running', 1], [$this->service()->status, (int) $this->service()->template_version]);
    }

    public function test_if_putting_back_fails_too_the_service_is_failed_with_both_errors(): void
    {
        $this->nativeAt();
        $this->failSteps = ['health' => 'no answer', 'settle' => 'the previous release did not stay up'];

        $op = $this->update();
        $this->assertSame('failed', $this->operate($op));
        $row = $this->service();
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->template_version);
        $this->assertStringContainsString('no answer', (string) $row->error);
        $this->assertStringContainsString('did not stay up', (string) $row->error);
        $this->assertStringContainsString('Putting version 1 back failed too', $this->opError($op));
    }

    public function test_a_native_service_whose_previous_release_is_gone_is_told_so_and_nothing_changes(): void
    {
        $this->nativeAt();
        $this->failSteps = ['previous-release' => 'gone'];

        $op = $this->update();
        $this->assertSame('failed', $this->operate($op));
        $this->assertStringContainsString('previous release folder /srv/cloud-manager/services/files/releases/1 is gone', $this->opError($op));
        $this->assertStringContainsString('Nothing was changed', $this->opError($op));
        $this->assertSame(['service-update-check-files'], $this->recipeNames());
        $this->assertSame(1, (int) $this->service()->template_version);
    }

    public function test_the_environment_is_rewritten_only_when_the_new_template_changes_it_and_existing_secrets_are_kept(): void
    {
        $id = $this->nativeAt();
        $vault = app(Vault::class);
        $access = $vault->get('org-1', Services::secretName($id, 'access_key'));
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + [
            "secrets:\n  - { key: access_key, generate: word }" => "secrets:\n  - { key: access_key, generate: word }\n  - { key: admin_token, generate: password }",
            '    RUSTFS_SECRET_KEY: "{{ secrets.secret_key }}"' => "    RUSTFS_SECRET_KEY: \"{{ secrets.secret_key }}\"\n    RUSTFS_ADMIN: \"{{ secrets.admin_token }}\"",
        ]);

        $u = $this->services()->updateAvailable($this->service());
        $this->assertTrue($u['allowed']);
        $this->assertNotEmpty(array_filter($u['changes'], fn ($c) => str_contains($c, 'environment')));

        $op = $this->update();
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $env = $this->steps('service-update-switch')['env'];
        $this->assertTrue($env['sensitive']);
        $this->assertSame('0600', $env['mode']);
        $content = base64_decode($env['content_b64']);
        $this->assertStringContainsString('RUSTFS_ACCESS_KEY="'.$access.'"', $content);
        $this->assertStringContainsString('RUSTFS_ADMIN=', $content);
        $this->assertSame($access, $vault->get('org-1', Services::secretName($id, 'access_key')), 'an existing secret is never regenerated');
        $this->assertTrue($vault->has('org-1', Services::secretName($id, 'admin_token')), 'a secret the new version adds is generated');
        $this->assertStringContainsString('.env', json_encode($this->steps('service-update-switch')['keep-previous']['args']), 'the old env file is kept to put back');
    }

    public function test_updates_keep_two_previous_releases_and_never_prune_the_running_one(): void
    {
        $this->nativeAt();
        foreach ([2, 3, 4] as $v) {
            $this->publish('rustfs', $v, $this->rustfsRelease($v));
            $this->recipes = [];
            $op = $this->update();
            $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
            $finish = $this->steps('service-update-finish')['prune']['args'][1];
            $this->assertStringContainsString("grep -vx '{$v}'", $finish);
            $this->assertStringContainsString('tail -n +3', $finish);
            $this->assertStringContainsString('rm -rf', $finish);
        }
        $row = $this->service();
        $this->assertSame(4, (int) $row->template_version);
        $this->assertSame([3, 2], array_column(json_decode($row->previous, true), 'version'));
    }

    // ---- container ----------------------------------------------------------

    public function test_a_container_update_pulls_the_new_digest_keeps_the_old_container_and_runs_the_same_volumes_and_ports(): void
    {
        $this->kuma();
        $op = $this->update(name: 'kuma');
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $order = array_map($this->indexOf(...), ['service-update-check-kuma', 'service-backup-kuma', 'service-fetch-kuma', 'service-update-switch-kuma', 'service-health-kuma', 'service-update-finish-kuma']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);

        $old = 'louislam/uptime-kuma@sha256:'.self::KUMA_DIGEST;
        $new = 'louislam/uptime-kuma@sha256:'.$this->kumaDigest(2);
        $this->assertSame($old, $this->steps('service-update-check')['previous-image']['args'][4]);
        $this->assertStringContainsString($new, $this->steps('service-fetch')['pull']['args'][1]);
        $switch = $this->steps('service-update-switch');
        $this->assertStringContainsString('docker rename', $switch['stop-old']['args'][1]);
        $this->assertStringNotContainsString('docker rm', $switch['stop-old']['args'][1], 'the old container is kept, not removed');
        $run = $switch['run']['args'];
        $this->assertSame($new, end($run));
        $this->assertContains('127.0.0.1:23000:3001', $run);
        $this->assertContains('cm-svc-kuma-kuma:/app/data', $run);
        $this->assertSame(['--env-file', '/etc/cloud-manager/services/kuma.env'], array_slice($run, array_search('--env-file', $run, true), 2));
        $this->assertArrayNotHasKey('env', $switch);

        $row = $this->service('kuma');
        $this->assertSame([2, $new, 'running'], [(int) $row->template_version, $row->image, $row->status]);
        $this->assertEquals([['version' => 1, 'ref' => $old]], json_decode($row->previous, true));
        $this->assertStringContainsString('docker rm -f', $this->steps('service-update-finish')['prune']['args'][1]);
        $audit = json_decode((string) DB::table('audit_records')->where('action', 'service.updated')->value('metadata'), true);
        $this->assertSame('sha256:'.$this->kumaDigest(2), $audit['digest']);
    }

    public function test_a_container_that_does_not_answer_is_replaced_by_the_old_one_again(): void
    {
        $this->kuma();
        $this->failSteps = ['health' => 'no answer on 127.0.0.1:23000 in 60 seconds'];
        $op = $this->update(name: 'kuma');
        $this->assertSame('failed', $this->operate($op));

        $script = $this->steps('service-update-back')['put-back']['args'][1];
        $this->assertStringContainsString("docker rename 'cm-svc-kuma-previous' 'cm-svc-kuma'", $script);
        $this->assertStringContainsString('docker start', $script);
        $row = $this->service('kuma');
        $this->assertSame(['running', 1, 'louislam/uptime-kuma@sha256:'.self::KUMA_DIGEST], [$row->status, (int) $row->template_version, $row->image]);
        $this->assertStringContainsString('rolled back', (string) $row->error);
        $this->assertNotContains('service-update-finish-kuma', $this->recipeNames());
    }

    public function test_container_updates_keep_two_previous_images_and_drop_older_ones_but_never_the_running_one(): void
    {
        $this->kuma();
        $images = fn (int $n) => 'louislam/uptime-kuma@sha256:'.($n === 1 ? self::KUMA_DIGEST : $this->kumaDigest($n));
        foreach ([2, 3, 4] as $v) {
            $this->publish('uptime-kuma', $v, [self::KUMA_DIGEST => $this->kumaDigest($v)]);
            $this->recipes = [];
            $op = $this->update(name: 'kuma');
            $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
            $finish = $this->steps('service-update-finish')['prune']['args'][1];
            $this->assertStringNotContainsString($images($v), $finish);
            $v === 4 ? $this->assertStringContainsString('docker rmi '."'".$images(1)."'", $finish) : $this->assertStringNotContainsString('docker rmi', $finish);
        }
        $this->assertSame([$images(3), $images(2)], array_column(json_decode($this->service('kuma')->previous, true), 'ref'));
    }

    // ---- what UpdateCheck refuses or asks for ---------------------------------

    public function test_a_new_publisher_for_the_same_template_is_refused(): void
    {
        $this->nativeAt();
        DB::table('services')->update(['installed' => json_encode(['publisher' => 'Mallory'] + json_decode($this->service()->installed, true))]);

        $u = $this->services()->updateAvailable($this->service());
        $this->assertFalse($u['allowed']);
        $this->assertStringContainsString('publisher changed from Mallory to Cloud Manager', $u['refused']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publisher changed');
        $this->update();
    }

    public function test_a_version_that_is_not_newer_offers_nothing_and_is_refused(): void
    {
        $this->install();
        $this->assertNull($this->services()->updateAvailable($this->service()));
        $this->expectExceptionMessage('There is no newer version');
        $this->update();
    }

    public function test_a_changed_download_host_needs_an_explicit_confirmation(): void
    {
        $this->nativeAt();
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + ['https://github.com/rustfs/rustfs/releases/download/1.0.20/' => 'https://downloads.example.net/rustfs/']);
        $u = $this->services()->updateAvailable($this->service());
        $this->assertTrue($u['confirm']);
        $this->assertNotEmpty(array_filter($u['changes'], fn ($c) => str_contains($c, 'download host changed from github.com to downloads.example.net')));
        try {
            $this->update();
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Confirm it explicitly', $e->getMessage());
        }
        $this->assertSame([], $this->recipes);
        $op = $this->update(confirm: true);
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $this->assertSame('downloads.example.net', json_decode($this->service()->installed, true)['download_host']);
    }

    public function test_the_operation_refuses_a_host_change_that_was_not_confirmed(): void
    {
        $this->nativeAt();
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + ['https://github.com/rustfs/rustfs/releases/download/1.0.20/' => 'https://downloads.example.net/rustfs/']);
        $op = $this->update(confirm: true);
        $meta = json_decode((string) DB::table('operations')->where('id', $op)->value('meta'), true);
        $meta['confirmed'] = false;
        DB::table('operations')->where('id', $op)->update(['meta' => json_encode($meta)]);
        $this->assertSame('failed', $this->operate($op));
        $this->assertStringContainsString('was not confirmed', $this->opError($op));
        $this->assertSame([], array_values(array_filter($this->recipeNames(), fn ($n) => str_starts_with($n, 'service-fetch'))));
    }

    public function test_a_changed_image_repository_needs_confirmation(): void
    {
        $this->kuma();
        $this->publish('uptime-kuma', 2, [self::KUMA_DIGEST => $this->kumaDigest(2), 'louislam/uptime-kuma:2.5.11' => 'someone/else:1']);
        $this->assertTrue($this->services()->updateAvailable($this->service('kuma'))['confirm']);
        $this->expectExceptionMessage('Confirm it explicitly');
        $this->update(name: 'kuma');
    }

    public function test_a_new_required_input_is_asked_for_and_kept_and_a_secret_one_goes_to_the_vault(): void
    {
        $id = $this->nativeAt();
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + [
            '  - { key: backup,' => "  - { key: region, type: text, label: Region }\n  - { key: token, type: secret, label: Admin token }\n  - { key: backup,",
            '    RUSTFS_SECRET_KEY: "{{ secrets.secret_key }}"' => "    RUSTFS_SECRET_KEY: \"{{ secrets.secret_key }}\"\n    REGION: \"{{ inputs.region }}\"\n    TOKEN: \"{{ inputs.token }}\"",
        ]);
        $u = $this->services()->updateAvailable($this->service());
        $this->assertSame(['region', 'token'], array_column($u['new_inputs'], 'key'));
        $this->assertNotEmpty(array_filter($u['changes'], fn ($c) => str_contains($c, 'a new input: Region (required)')));

        try {
            $this->update();
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('region is required', $e->getMessage());
        }
        try {
            $this->update(['region' => 'eu', 'token' => 'TOK', 'name' => 'other']);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('name is not a new input', $e->getMessage());
        }

        $op = $this->update(['region' => 'eu', 'token' => 'SECRET-TOKEN-VALUE']);
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $inputs = json_decode($this->service()->inputs, true);
        $this->assertSame('eu', $inputs['region']);
        $this->assertArrayNotHasKey('token', $inputs);
        $this->assertSame('SECRET-TOKEN-VALUE', app(Vault::class)->get('org-1', Services::secretName($id, 'token')));
        $this->assertStringContainsString('TOKEN="SECRET-TOKEN-VALUE"', base64_decode($this->steps('service-update-switch')['env']['content_b64']));
        $everything = json_encode(DB::table('operations')->get()).json_encode(DB::table('audit_records')->get()).json_encode(DB::table('services')->get());
        $this->assertStringNotContainsString('SECRET-TOKEN-VALUE', $everything);
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                $this->assertStringNotContainsString('SECRET-TOKEN-VALUE', json_encode($s['args'] ?? []));
            }
        }
    }

    public function test_new_extra_ports_and_a_new_acknowledgement_need_approval_again(): void
    {
        $this->nativeAt();
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + [
            "\nruns:" => "\nextra_ports:\n  - { port: 2222, proto: tcp, label: Git over SSH }\nacknowledge: This runs code from your repositories.\n\nruns:",
        ]);
        $u = $this->services()->updateAvailable($this->service());
        $this->assertSame(['2222/tcp'], $u['reapprove']['ports']);
        $this->assertSame('This runs code from your repositories.', $u['reapprove']['acknowledge']);

        try {
            $this->update();
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('2222/tcp', $e->getMessage());
        }
        try {
            $this->update(ports: true);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Acknowledge first', $e->getMessage());
        }
        $this->assertSame([], $this->recipes);
        $op = $this->update(ports: true, ack: true);
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $this->assertSame(1, DB::table('audit_records')->where('action', 'service.ports_approved')->count());
        $this->assertSame(['2222/tcp'], json_decode($this->service()->installed, true)['extra_ports']);
    }

    public function test_a_version_that_changes_the_addresses_or_moves_the_data_cannot_be_applied_as_an_update(): void
    {
        $this->nativeAt();
        $this->publish('rustfs', 2, $this->rustfsRelease(2) + ['9001: "{{ inputs.ui_host }}"' => '9002: "{{ inputs.ui_host }}"']);
        $u = $this->services()->updateAvailable($this->service());
        $this->assertFalse($u['allowed']);
        $this->assertStringContainsString('changes the addresses', $u['refused']);

        $this->publish('rustfs', 2, $this->rustfsRelease(2) + ['/srv/cloud-manager/data/{{ name }}' => '/srv/cloud-manager/data/{{ name }}-v2']);
        $this->expectExceptionMessage('moves the data folder');
        $this->update();
    }

    // ---- who, which service, what state ----------------------------------------

    public function test_only_a_manager_updates_and_only_a_running_service(): void
    {
        $this->nativeAt();
        foreach (['developer', 'viewer'] as $role) {
            try {
                $this->update(role: $role);
                $this->fail("{$role} may not update");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        DB::table('services')->update(['status' => 'stopped']);
        $this->expectExceptionMessage('only a running service is updated');
        $this->update();
    }

    public function test_another_teams_service_cannot_be_updated_by_name(): void
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-2', 'name' => 'Globex', 'created_at' => now(), 'updated_at' => now()]);
        $this->nativeAt();
        $this->expectExceptionMessage('There is no service files on web-1.org-1 in this team');
        $this->update(org: 'org-2');
    }

    public function test_a_second_operation_is_refused_while_an_update_is_queued(): void
    {
        $this->nativeAt();
        $this->update();
        $this->expectExceptionMessage('Another operation is already running');
        $this->update();
    }
}
