<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Agent\AgentRelease;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CloudInit;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\Exceptions\CredentialNotFound;
use App\Lib\Cloud\Exceptions\ProvisioningFailed;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Providers\Providers;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ServerProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    private ResourceManager $resources;

    private CredentialStore $credentials;

    private string $agentDir;

    protected function setUp(): void
    {
        parent::setUp();

        // A stand-in agent build, so cloud-init has a real file to checksum.
        $this->agentDir = sys_get_temp_dir().'/cm-provisioner-agent-'.getmypid();
        @mkdir($this->agentDir, 0o755, true);
        file_put_contents($this->agentDir.'/cloud-manager-agent-linux-amd64', 'pretend binary');

        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        Cache::store('array')->clear();
        $this->http = new HttpFactory;
        $this->resources = new ResourceManager(new AuditLog);

        $this->credentials = new CredentialStore(
            vault: $this->app->make(Vault::class),
            http: $this->http,
            cache: Cache::store('array'),
            audit: new AuditLog,
        );

        DB::table('organizations')->insert([
            'id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->credentials->connect('org-1', 'dop_v1_test_token');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->agentDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->agentDir);

        parent::tearDown();
    }

    private function provisioner(): ServerProvisioner
    {
        return new ServerProvisioner(
            providers: new Providers($this->credentials, $this->http),
            resources: $this->resources,
            enrollment: new EnrollmentService($this->app->make(Vault::class), new AuditLog),
            cloudInit: new CloudInit(
                release: new AgentRelease($this->agentDir, 'test'),
                baseUrl: 'https://cp.example.test',
                serverName: 'cp.example.test',
            ),
            // Never actually sleep in tests.
            sleeper: static function (int $s): void {},
        );
    }

    /**
     * @param  array<string, mixed>  $droplet
     */
    private function fakeCreateThenActive(array $droplet = []): void
    {
        $droplet = array_merge([
            'id' => 123456,
            'status' => 'active',
            'networks' => ['v4' => [
                ['type' => 'public', 'ip_address' => '203.0.113.10'],
                ['type' => 'private', 'ip_address' => '10.0.0.5'],
            ], 'v6' => [
                ['type' => 'public', 'ip_address' => '2001:db8:4::10'],
            ]],
        ], $droplet);

        $this->http->fake(function (Request $request) use ($droplet) {
            if ($request->method() === 'POST') {
                return $this->http->response(['droplet' => $droplet], 202);
            }

            return $this->http->response(['droplet' => $droplet], 200);
        });
    }

    // ---- happy path ----

    public function test_provisioning_reaches_active_and_records_the_droplet(): void
    {
        $this->fakeCreateThenActive();

        $resource = $this->provisioner()->provision('org-1', 'web-1', ['region' => 'lon1']);

        $this->assertSame(ResourceState::Active, $resource->state);
        $this->assertSame('123456', $resource->externalId);
        $this->assertSame('203.0.113.10', $resource->attributes['ipv4']);
        $this->assertSame('10.0.0.5', $resource->attributes['private_ipv4']);
        $this->assertSame('2001:db8:4::10', $resource->attributes['ipv6']);
    }

    /**
     * The row must exist before the API call, so a crash anywhere after it
     * leaves something reconciliation can find.
     */
    public function test_the_resource_is_recorded_before_the_api_call(): void
    {
        $seenDuringCall = null;

        $this->http->fake(function (Request $request) use (&$seenDuringCall) {
            // At the moment DigitalOcean is called, what does our database
            // already know?
            $seenDuringCall = DB::table('resources')->where('slug', 'web-1')->first();

            return $this->http->response(['droplet' => ['id' => 1, 'status' => 'active']], 202);
        });

        $this->provisioner()->provision('org-1', 'web-1', []);

        $this->assertNotNull($seenDuringCall, 'No resource row existed when the create was issued.');
        $this->assertSame('provisioning', $seenDuringCall->state);
        $this->assertNotNull($seenDuringCall->idempotency_key);
    }

    public function test_the_create_carries_the_idempotency_key_from_the_resource(): void
    {
        $this->fakeCreateThenActive();

        $resource = $this->provisioner()->provision('org-1', 'web-1', []);

        $this->http->assertSent(fn (Request $r) => $r->method() !== 'POST'
            || $r->hasHeader('Idempotency-Key', $resource->idempotencyKey));
    }

    public function test_every_droplet_is_tagged_for_reconciliation(): void
    {
        $this->fakeCreateThenActive();

        $this->provisioner()->provision('org-1', 'web-1', []);

        $this->http->assertSent(function (Request $r): bool {
            if ($r->method() !== 'POST') {
                return true;
            }
            $tags = $r->data()['tags'] ?? [];

            return in_array('cloud-manager', $tags, true)
                && in_array('org:org-1', $tags, true)
                && in_array('resource:web-1', $tags, true);
        });
    }

    public function test_cloud_init_carries_the_token_and_ca(): void
    {
        $this->fakeCreateThenActive();

        $this->provisioner()->provision('org-1', 'web-1', []);

        $this->http->assertSent(function (Request $r): bool {
            if ($r->method() !== 'POST') {
                return true;
            }
            $userData = $r->data()['user_data'] ?? '';

            return str_contains($userData, '#cloud-config')
                && str_contains($userData, 'BEGIN CERTIFICATE')
                && str_contains($userData, 'enroll.token')
                && str_contains($userData, 'cloud-manager-agent.service');
        });
    }

    public function test_provisioning_twice_does_not_create_a_second_droplet(): void
    {
        $this->fakeCreateThenActive();

        $first = $this->provisioner()->provision('org-1', 'web-1', []);
        $second = $this->provisioner()->provision('org-1', 'web-1', []);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('resources', 1);

        $posts = 0;
        $this->http->assertSent(function (Request $r) use (&$posts): bool {
            if ($r->method() === 'POST') {
                $posts++;
            }

            return true;
        });
        $this->assertSame(1, $posts, 'A second create was issued for an already active resource.');
    }

    // ---- the expensive failure modes ----

    /**
     * A create that times out may have succeeded. Marking it Failed would
     * invite a retry that creates a second Droplet beside one nobody is
     * watching, so it becomes Orphaned instead.
     */
    public function test_a_timed_out_create_leaves_the_resource_orphaned(): void
    {
        $this->http->fake(fn () => throw new ConnectionException('timed out'));

        try {
            $this->provisioner()->provision('org-1', 'web-1', []);
            $this->fail('Expected ProvisioningFailed.');
        } catch (ProvisioningFailed $e) {
            $this->assertTrue(
                $e->mayHaveCreatedResource,
                'A timed-out create must be reported as possibly having created something.'
            );
        }

        $resource = $this->resources->find('org-1', 'server', 'web-1');

        $this->assertSame(ResourceState::Orphaned, $resource->state);
        $this->assertTrue($resource->isUntrackable());
        $this->assertTrue($resource->state->mayIncurCost());
    }

    /**
     * A 422 means DigitalOcean refused outright. Nothing was created, so this
     * is the cheap kind of failure and must be retryable.
     */
    public function test_a_rejected_create_fails_cleanly_and_is_retryable(): void
    {
        $this->http->fake(['*' => $this->http->response(['message' => 'size is invalid'], 422)]);

        try {
            $this->provisioner()->provision('org-1', 'web-1', []);
            $this->fail('Expected ProvisioningFailed.');
        } catch (ProvisioningFailed $e) {
            $this->assertFalse($e->mayHaveCreatedResource);
        }

        $resource = $this->resources->find('org-1', 'server', 'web-1');

        $this->assertSame(ResourceState::Failed, $resource->state);
        // Failed can go back to Provisioning, so a corrected request works.
        $this->assertTrue($resource->state->canTransitionTo(ResourceState::Provisioning));
    }

    /**
     * Accepted, but we were told nothing we can address it by. Something
     * probably exists and we cannot manage it.
     */
    public function test_a_create_returning_no_id_is_orphaned(): void
    {
        $this->http->fake(['*' => $this->http->response(['droplet' => ['status' => 'new']], 202)]);

        try {
            $this->provisioner()->provision('org-1', 'web-1', []);
            $this->fail('Expected ProvisioningFailed.');
        } catch (ProvisioningFailed $e) {
            $this->assertTrue($e->mayHaveCreatedResource);
        }

        $this->assertSame(
            ResourceState::Orphaned,
            $this->resources->find('org-1', 'server', 'web-1')->state
        );
    }

    /**
     * A Droplet that takes longer than we waited is not a failure. It exists,
     * we hold its id, and it stays tracked so staleness picks it up. Marking
     * it Failed would invite a duplicate.
     */
    public function test_a_slow_droplet_stays_tracked_rather_than_failing(): void
    {
        $this->http->fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return $this->http->response(['droplet' => ['id' => 999, 'status' => 'new']], 202);
            }

            // Never becomes active.
            return $this->http->response(['droplet' => ['id' => 999, 'status' => 'new']], 200);
        });

        try {
            $this->provisioner()->provision('org-1', 'web-1', [], readyTimeoutSeconds: 0);
            $this->fail('Expected ProvisioningFailed.');
        } catch (ProvisioningFailed $e) {
            $this->assertTrue($e->mayHaveCreatedResource);
            $this->assertStringContainsString('999', $e->getMessage());
        }

        $resource = $this->resources->find('org-1', 'server', 'web-1');

        $this->assertSame(ResourceState::Provisioning, $resource->state);
        $this->assertSame('999', $resource->externalId, 'The id must be retained so it can be found later.');
        $this->assertFalse($resource->isUntrackable());
    }

    public function test_an_errored_droplet_is_marked_failed(): void
    {
        $this->http->fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return $this->http->response(['droplet' => ['id' => 55, 'status' => 'new']], 202);
            }

            return $this->http->response(['droplet' => ['id' => 55, 'status' => 'errored']], 200);
        });

        $this->provisioner()->provision('org-1', 'web-1', []);

        $this->assertSame(
            ResourceState::Failed,
            $this->resources->find('org-1', 'server', 'web-1')->state
        );
    }

    public function test_provisioning_without_a_credential_fails_before_anything_is_created(): void
    {
        DB::table('organizations')->insert([
            'id' => 'org-2', 'name' => 'No Credential', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->http->fake();

        $this->expectException(CredentialNotFound::class);

        try {
            $this->provisioner()->provision('org-2', 'web-1', []);
        } finally {
            $this->http->assertNothingSent();
        }
    }

    public function test_refuses_to_provision_over_an_active_resource(): void
    {
        $this->fakeCreateThenActive();
        $this->provisioner()->provision('org-1', 'web-1', []);

        // A second call returns the existing resource rather than acting.
        $again = $this->provisioner()->provision('org-1', 'web-1', []);
        $this->assertSame(ResourceState::Active, $again->state);
    }

    public function test_refuses_to_provision_over_an_orphan(): void
    {
        $resource = $this->resources->declare('org-1', 'server', 'web-1');
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $this->resources->transition($resource, ResourceState::Orphaned, 'timed out');

        $this->http->fake();

        try {
            $this->provisioner()->provision('org-1', 'web-1', []);
            $this->fail('Expected ProvisioningFailed.');
        } catch (ProvisioningFailed $e) {
            // Creating a second droplet beside one we have lost track of is
            // exactly the thing to refuse.
            $this->assertTrue($e->mayHaveCreatedResource);
        }

        $this->http->assertNothingSent();
    }

    // ---- teardown ----

    public function test_destroy_marks_the_resource_deleted(): void
    {
        $this->fakeCreateThenActive();
        $resource = $this->provisioner()->provision('org-1', 'web-1', []);

        $this->http->fake(['*' => $this->http->response([], 204)]);

        $destroyed = $this->provisioner()->destroy($resource);

        $this->assertSame(ResourceState::Deleted, $destroyed->state);
        $this->assertFalse($destroyed->state->mayIncurCost());
    }

    /**
     * Deleting something already gone is the desired end state.
     */
    public function test_destroying_an_already_deleted_droplet_succeeds(): void
    {
        $this->fakeCreateThenActive();
        $resource = $this->provisioner()->provision('org-1', 'web-1', []);

        $this->http->fake(['*' => $this->http->response(['message' => 'not found'], 404)]);

        $destroyed = $this->provisioner()->destroy($resource);

        $this->assertSame(ResourceState::Deleted, $destroyed->state);
    }

    // ---- identifiers ----

    /**
     * These values become DigitalOcean tags and a certificate common name,
     * and both arrive from user-supplied names. Stripping slashes alone is
     * not enough: "org/../evil" becomes "org..evil", which is harmless as a
     * tag and not as an identifier that something may later join onto a path.
     */
    public function test_node_ids_and_tags_drop_unsafe_characters(): void
    {
        $provisioner = $this->provisioner();

        $hostile = [
            ['org/../evil', 'web 1; rm -rf'],
            ['../../etc', '$(whoami)'],
            ['...', '..'],
            ['', ''],
            ['org\\..\\evil', 'web`1`'],
        ];

        foreach ($hostile as [$org, $slug]) {
            foreach ($provisioner->tags($org, $slug) as $tag) {
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9:._-]+$/', $tag);
                $this->assertStringNotContainsString('..', $tag);
            }

            $nodeId = $provisioner->nodeId($org, $slug);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9:._-]+$/', $nodeId);
            $this->assertStringNotContainsString('..', $nodeId);
            $this->assertStringNotContainsString('/', $nodeId);
            $this->assertStringNotContainsString('\\', $nodeId);
        }
    }

    public function test_ordinary_names_survive_sanitisation_intact(): void
    {
        $provisioner = $this->provisioner();

        // Over-aggressive sanitising would be its own bug: node ids appear in
        // certificates and logs, and mangling them makes operators distrust
        // what they are reading.
        $this->assertSame('web-1.org-1', $provisioner->nodeId('org-1', 'web-1'));
        $this->assertSame(
            ['cloud-manager', 'org:org-1', 'resource:web-1'],
            $provisioner->tags('org-1', 'web-1')
        );
    }

    // ---- audit ----

    public function test_the_whole_provision_is_auditable(): void
    {
        $this->fakeCreateThenActive();

        $this->provisioner()->provision('org-1', 'web-1', []);

        $actions = DB::table('audit_records')
            ->where('organization_id', 'org-1')
            ->orderBy('sequence')
            ->pluck('action')
            ->all();

        // Credential access must be attributable, which PLAN.md requires of
        // every DigitalOcean call.
        $this->assertContains('credential.accessed', $actions);
        $this->assertContains('enrollment.token_issued', $actions);
        $this->assertContains('resource.provisioning', $actions);
        $this->assertContains('resource.active', $actions);

        $this->assertTrue((new AuditLog)->verify('org-1')->intact);
    }

    public function test_the_credential_access_record_says_why(): void
    {
        $this->fakeCreateThenActive();
        $this->provisioner()->provision('org-1', 'web-1', []);

        $record = DB::table('audit_records')->where('action', 'credential.accessed')->first();

        $this->assertStringContainsString('web-1', (string) $record->reason);
    }
}
