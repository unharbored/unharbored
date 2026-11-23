<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Providers\Aws\AwsProvider;
use App\Lib\Providers\Aws\Signer;
use App\Lib\Providers\Providers;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\Uncertain;
use App\Lib\Resources\ResourceState;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

final class AwsProviderTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'http://localhost:4566';

    /** @var \Closure(Request): mixed */
    private \Closure $respond;

    protected function setUp(): void
    {
        parent::setUp();
        $this->respond = fn () => Http::response('', 500);
        Http::fake(fn (Request $r) => ($this->respond)($r));
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->app->forgetInstance(CredentialStore::class);
        $this->app->forgetInstance(Providers::class);
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function aws(): AwsProvider
    {
        return $this->app->make(Providers::class)->aws();
    }

    private function connect(array $extra = []): void
    {
        $this->respond = fn () => Http::response('<GetCallerIdentityResponse xmlns="https://sts.amazonaws.com/doc/2011-06-15/"><GetCallerIdentityResult><Arn>arn:aws:iam::000000000000:user/test</Arn><UserId>AID</UserId><Account>000000000000</Account></GetCallerIdentityResult></GetCallerIdentityResponse>');
        $this->aws()->connect('org-1', ['access_key_id' => 'AKIAEXAMPLE1234', 'secret_access_key' => 'secret-value', 'region' => 'us-east-1', 'endpoint' => self::ENDPOINT, 'ami' => 'ami-12345678'] + $extra);
    }

    public function test_the_signer_matches_the_aws_published_vector(): void
    {
        $auth = Signer::authorization('GET', '/', '', ['Host' => 'example.amazonaws.com', 'X-Amz-Date' => '20150830T123600Z'], '', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service', '20150830T123600Z');

        $this->assertSame('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $auth);
    }

    public function test_connecting_checks_the_key_at_the_endpoint_and_keeps_only_a_hint_in_the_table(): void
    {
        $this->connect();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::ENDPOINT) && str_contains($r->body(), 'GetCallerIdentity') && str_contains($r->header('Authorization')[0], '/us-east-1/sts/aws4_request'));
        $row = DB::table('cloud_credentials')->where('provider', 'aws')->first();
        $this->assertSame('AKIA••••••••1234', $row->key_hint);
        $this->assertStringNotContainsString('secret-value', json_encode($row));
        $this->assertTrue($this->app->make(CredentialStore::class)->has('org-1', 'aws'));
    }

    public function test_a_rejected_key_is_not_kept(): void
    {
        $this->respond = fn () => Http::response('<ErrorResponse><Error><Code>InvalidClientTokenId</Code><Message>bad</Message></Error></ErrorResponse>', 403);

        try {
            $this->aws()->connect('org-1', ['access_key_id' => 'AKIABAD', 'secret_access_key' => 'x']);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('InvalidClientTokenId', $e->getMessage());
        }
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'aws'));
    }

    public function test_an_endpoint_must_be_a_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->aws()->connect('org-1', ['access_key_id' => 'AKIA', 'secret_access_key' => 'x', 'endpoint' => 'localhost']);
    }

    /** @return list<string> the Actions sent, in order */
    private function actions(): array
    {
        $seen = [];
        Http::assertSent(function (Request $r) use (&$seen) {
            parse_str($r->body(), $q);
            $seen[] = $q['Action'] ?? '';

            return true;
        });

        return $seen;
    }

    private function fakeEc2(): void
    {
        $this->respond = function (Request $r) {
            parse_str($r->body(), $q);
            $xml = fn (string $body) => Http::response("<R xmlns=\"http://ec2.amazonaws.com/doc/2016-11-15/\">{$body}</R>");

            return match ($q['Action'] ?? '') {
                'DescribeSecurityGroups' => $xml('<securityGroupInfo/>'),
                'CreateSecurityGroup' => $xml('<groupId>sg-1</groupId>'),
                'AuthorizeSecurityGroupIngress' => $xml('<return>true</return>'),
                'RunInstances' => $xml('<instancesSet><item><instanceId>i-abc</instanceId></item></instancesSet>'),
                'DescribeInstances' => $xml('<reservationSet><item><instancesSet><item><instanceId>i-abc</instanceId><instanceState><name>running</name></instanceState><ipAddress>203.0.113.9</ipAddress><privateIpAddress>10.0.0.9</privateIpAddress><tagSet><item><key>Name</key><value>web-1</value></item></tagSet></item></instancesSet></item></reservationSet>'),
                'TerminateInstances' => $xml('<instancesSet/>'),
                default => Http::response('', 400),
            };
        };
    }

    private function spec(string $userData = 'cloud-init'): ServerSpec
    {
        return new ServerSpec('web-1', 'us-east-1', 't3.small', 'ubuntu-24.04', $userData, 'idem-1', ['cloud-manager', 'org:org-1']);
    }

    public function test_create_makes_the_security_group_once_and_sends_the_idempotency_token_and_tags(): void
    {
        $this->connect();
        $this->fakeEc2();

        $id = $this->aws()->servers('org-1')->create($this->spec());

        $this->assertSame('i-abc', $id);
        $run = null;
        Http::assertSent(function (Request $r) use (&$run) {
            parse_str($r->body(), $q);
            if (($q['Action'] ?? '') === 'RunInstances') {
                $run = $q;
            }

            return true;
        });
        $this->assertSame('ami-12345678', $run['ImageId']);
        $this->assertSame('idem-1', $run['ClientToken']);
        $this->assertSame('sg-1', $run['SecurityGroupId_1']);
        $this->assertSame(base64_encode('cloud-init'), $run['UserData']);
        $this->assertSame('cloud-manager', $run['TagSpecification_1_Tag_2_Key']);
    }

    public function test_find_maps_state_and_addresses_and_a_missing_instance_is_null(): void
    {
        $this->connect();
        $this->fakeEc2();
        $server = $this->aws()->servers('org-1')->find('i-abc');
        $this->assertSame(['running', '203.0.113.9', '10.0.0.9', 'web-1'], [$server->state, $server->ipv4, $server->privateIpv4, $server->name]);

        $this->respond = fn () => Http::response('<Response><Errors><Error><Code>InvalidInstanceID.NotFound</Code><Message>gone</Message></Error></Errors></Response>', 400);
        $this->assertNull($this->aws()->servers('org-1')->find('i-abc'));
    }

    public function test_destroy_is_idempotent(): void
    {
        $this->connect();
        $this->respond = fn () => Http::response('<Response><Errors><Error><Code>InvalidInstanceID.NotFound</Code><Message>gone</Message></Error></Errors></Response>', 400);
        $this->aws()->servers('org-1')->destroy('i-abc');
        $this->addToAssertionCount(1);
    }

    public function test_a_refusal_is_rejected_and_a_server_error_or_dead_connection_is_uncertain(): void
    {
        $this->connect();
        $this->respond = fn () => Http::response('<Response><Errors><Error><Code>VcpuLimitExceeded</Code><Message>limit</Message></Error></Errors></Response>', 400);
        try {
            $this->aws()->servers('org-1')->create($this->spec());
            $this->fail();
        } catch (Rejected $e) {
            $this->assertStringContainsString('VcpuLimitExceeded', $e->getMessage());
        }

        $this->respond = fn () => Http::response('boom', 503);
        $this->expectException(Uncertain::class);
        $this->aws()->servers('org-1')->create($this->spec());
    }

    public function test_user_data_over_the_ec2_limit_is_refused_before_any_call(): void
    {
        $this->connect();
        $this->expectException(Rejected::class);
        $this->aws()->servers('org-1')->create($this->spec(str_repeat('x', 20000)));
    }

    public function test_the_provisioner_builds_an_aws_server_end_to_end_and_records_the_provider(): void
    {
        $this->connect();
        $this->fakeEc2();
        $agent = sys_get_temp_dir().'/cm-aws-agent-'.getmypid();
        @mkdir($agent, 0o755, true);
        file_put_contents($agent.'/cloud-manager-agent-linux-amd64', 'pretend');
        config(['control_plane.agent_directory' => $agent, 'control_plane.public_url' => 'https://cp.example.test']);
        $this->app->forgetInstance(\App\Lib\Agent\AgentRelease::class);
        $this->app->forgetInstance(\App\Lib\Cloud\CloudInit::class);
        $this->app->forgetInstance(ServerProvisioner::class);

        $record = $this->app->make(ServerProvisioner::class)->provision('org-1', 'web-1', ['provider' => 'aws', 'region' => 'us-east-1', 'size' => 't3.small']);

        $this->assertSame(ResourceState::Active, $record->state);
        $this->assertSame('aws', $record->provider);
        $this->assertSame('i-abc', $record->externalId);
        $this->assertSame('203.0.113.9', $record->attributes['ipv4']);

        $this->app->make(ServerProvisioner::class)->destroy($record);
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'web-1')->value('state'));
        array_map('unlink', glob($agent.'/*'));
        @rmdir($agent);
    }

    public function test_the_real_cloud_init_fits_in_ec2_user_data(): void
    {
        $agent = sys_get_temp_dir().'/cm-aws-size-'.getmypid();
        @mkdir($agent, 0o755, true);
        file_put_contents($agent.'/cloud-manager-agent-linux-amd64', 'pretend');
        $init = (new \App\Lib\Cloud\CloudInit(new \App\Lib\Agent\AgentRelease($agent, 'test'), 'https://cp.example.test', 'cp.example.test'))
            ->build('web-1.org-1', str_repeat('t', 64), str_repeat("-----BEGIN CERTIFICATE-----\nMIIB".str_repeat('A', 600)."\n-----END CERTIFICATE-----\n", 1), 'web-1');
        array_map('unlink', glob($agent.'/*'));
        @rmdir($agent);

        $this->assertLessThan(\App\Lib\Providers\Aws\AwsServers::USER_DATA_LIMIT, strlen($init));
    }

    public function test_the_registry_offers_custom_always_and_an_api_provider_once_connected(): void
    {
        $registry = $this->app->make(Providers::class);
        $this->assertSame(['custom'], array_keys($registry->connected('org-1')));

        $this->connect();
        $this->assertSame(['aws', 'custom'], array_keys($registry->connected('org-1')));
    }

    public function test_the_account_page_connects_aws_with_an_endpoint_and_blocks_disconnect_while_servers_use_it(): void
    {
        $this->actingAs(\App\Models\User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $this->respond = fn () => Http::response('<R><GetCallerIdentityResult><Arn>arn:aws:iam::000000000000:user/test</Arn><UserId>A</UserId><Account>000000000000</Account></GetCallerIdentityResult></R>');

        $this->post('/account/aws', ['access_key_id' => 'AKIAEXAMPLE1234', 'secret_access_key' => 'shh', 'region' => 'eu-west-1', 'endpoint' => self::ENDPOINT])->assertRedirect();
        $page = $this->get('/account?tab=providers')->assertOk();
        $aws = json_encode($page->inertiaProps('providers.aws'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('AKIA••••••••1234', $aws);
        $this->assertStringContainsString('eu-west-1', $aws);
        $this->assertStringContainsString(self::ENDPOINT, $aws);
        $this->assertStringNotContainsString('shh', $page->getContent());

        $this->post('/account/aws', ['access_key_id' => 'AKIAEXAMPLE1234', 'secret_access_key' => 'x', 'endpoint' => 'not a url'])->assertSessionHasErrors('endpoint');

        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'provider' => 'aws', 'slug' => 'web-1', 'external_id' => 'i-1', 'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->delete('/account/aws')->assertSessionHasErrors('provider_aws');
        $this->assertTrue($this->app->make(CredentialStore::class)->has('org-1', 'aws'));

        DB::table('resources')->delete();
        $this->delete('/account/aws')->assertSessionHasNoErrors();
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'aws'));
    }
}
