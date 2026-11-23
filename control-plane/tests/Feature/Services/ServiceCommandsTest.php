<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The service:* commands, which stand in for the console at this step. */
final class ServiceCommandsTest extends ServiceTestCase
{
    public function test_install_list_action_and_remove_through_the_commands(): void
    {
        $this->artisan('service:install web-1.org-1 rustfs files --input=s3_host=a.example.com --input=ui_host=b.example.com --input=storage=5GB')->assertSuccessful();
        $this->assertSame('running', $this->service()->status);
        $this->artisan('service:list')->expectsOutputToContain('files')->assertSuccessful();
        $this->artisan('service:action web-1.org-1 files stop')->assertSuccessful();
        $this->assertSame('stopped', $this->service()->status);
        $this->artisan('service:remove web-1.org-1 files --keep-data')->assertSuccessful();
        $this->assertNull($this->service());
    }

    public function test_a_secret_input_is_asked_for_hidden_and_refused_as_an_argument(): void
    {
        $this->node('ci.org-1', 'org-1');
        $this->artisan('service:install ci.org-1 github-runner ci --acknowledge --input=url=https://github.com/acme/app --input=token=abc')
            ->expectsOutputToContain('token is a secret')->assertFailed();

        $this->artisan('service:install ci.org-1 github-runner ci --acknowledge --input=url=https://github.com/acme/app')
            ->expectsQuestion('Registration token (hidden)', 'REAL-TOKEN-VALUE')->assertSuccessful();
        $this->assertSame('running', $this->service('ci')->status);
        $this->assertStringNotContainsString('REAL-TOKEN-VALUE', json_encode(DB::table('operations')->get()));
    }

    public function test_remove_must_be_told_what_to_do_with_the_data_and_cannot_be_told_both(): void
    {
        $this->install();
        $this->artisan('service:remove web-1.org-1 files')->expectsOutputToContain('keep it, or delete it')->assertFailed();
        $this->artisan('service:remove web-1.org-1 files --keep-data --delete-data')->expectsOutputToContain('not both')->assertFailed();
        $this->assertSame('running', $this->service()->status);
        $this->artisan('service:remove web-1.org-1 files --delete-data')->assertSuccessful();
        $this->assertNull($this->service());
    }

    public function test_as_applies_that_users_role_in_the_servers_team(): void
    {
        $this->install();
        $viewer = User::factory()->create();
        $dev = User::factory()->create();
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$viewer, 'viewer'], [$dev, 'developer']] as [$u, $role]) {
            DB::table('memberships')->insert(['user_id' => $u->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->artisan("service:action web-1.org-1 files stop --as={$viewer->email}")->expectsOutputToContain('needs the deploy permission')->assertFailed();
        $this->artisan("service:action web-1.org-1 files stop --as={$dev->email}")->assertSuccessful();
        $this->artisan("service:remove web-1.org-1 files --keep-data --as={$dev->email}")->expectsOutputToContain('needs the infrastructure permission')->assertFailed();
        $this->artisan('service:action web-1.org-1 files start --as=nobody@example.com')->expectsOutputToContain('There is no user')->assertFailed();
    }

    public function test_queue_leaves_the_operation_for_the_worker(): void
    {
        $this->artisan('service:install web-1.org-1 rustfs files --queue --input=s3_host=a.example.com --input=ui_host=b.example.com --input=storage=5GB')->assertSuccessful();
        $this->assertSame('installing', $this->service()->status);
        $this->assertSame('queued', DB::table('operations')->value('status'));
    }
}
