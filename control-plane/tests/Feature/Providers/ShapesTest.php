<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Audit\Actor;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Provider;
use App\Lib\Providers\ProviderScope;
use App\Lib\Providers\Providers;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\Shapes;
use App\Lib\Sites\NewApplications;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/** A provider whose supplies we set, so shape resolution is tested once rather than per provider. */
final class FakeProvider implements Provider
{
    /** @param list<string> $supplies */
    public function __construct(private readonly array $supplies, private readonly string $method = 'api') {}

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fakeville';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function supplies(): array
    {
        return $this->supplies;
    }

    public function catalog(): Catalog
    {
        return new Catalog(['a' => 'A'], ['s' => ['label' => 's', 'vcpu' => 1, 'memory_gb' => 1, 'price' => 5]], 'a', 'img');
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        throw new \LogicException('not needed');
    }

    public function verify(string $organizationId, ?Actor $actor = null): array
    {
        return [];
    }
}

final class ShapesTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_is_always_open_and_a_locked_shape_names_what_is_missing_and_the_provider(): void
    {
        $everything = Shapes::for(new FakeProvider(['servers', 'edge', 'object_storage']));
        $this->assertTrue($everything['multi']['available'] && $everything['autoscale']['available']);

        $noBalancer = Shapes::for(new FakeProvider(['servers']));
        $this->assertTrue($noBalancer['single']['available']);
        $this->assertSame('Multi-server needs a load balancer. Fakeville does not offer one yet.', $noBalancer['multi']['reason']);
        $this->assertSame('Autoscaling needs a load balancer. Fakeville does not offer one yet.', $noBalancer['autoscale']['reason']);

        $noStore = Shapes::for(new FakeProvider(['servers', 'edge']));
        $this->assertSame('Autoscaling needs object storage for builds. Fakeville does not offer it yet.', $noStore['autoscale']['reason']);

        $custom = Shapes::for(new FakeProvider([], 'ssh'));
        $this->assertStringContainsString('cannot create more', $custom['autoscale']['reason']);
    }

    public function test_the_real_provider_profiles_match_the_spec_table(): void
    {
        $providers = $this->app->make(Providers::class);

        $do = Shapes::for($providers->get('digitalocean'));
        $this->assertTrue($do['multi']['available'] && $do['autoscale']['available']);

        $aws = Shapes::for($providers->get('aws'));
        $this->assertTrue($aws['single']['available']);
        $this->assertFalse($aws['autoscale']['available']);
        $this->assertSame('Autoscaling needs a load balancer. AWS does not offer one yet.', $aws['autoscale']['reason']);

        $this->assertFalse(Shapes::for($providers->get('custom'))['multi']['available']);
    }

    public function test_a_mismatched_attachment_is_refused_with_both_providers_named(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cluster main is on digitalocean, but this application is on aws.');
        ProviderScope::assertSame('aws', 'digitalocean', 'Cluster main');
    }

    public function test_digitalocean_only_services_refuse_an_aws_application(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderScope::assertDigitalOcean((object) ['provider' => 'aws'], 'Load balancers');
    }

    private function org(): void
    {
        Queue::fake();
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'k', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'aws', 'vault_key' => 'k2', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'database_cluster', 'slug' => 'main', 'external_id' => 'x', 'state' => 'active', 'desired_state' => 'present',
            'provider' => 'digitalocean', 'attributes' => json_encode(['engine' => 'pg']), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function wizard(array $over = []): array
    {
        return $over + ['name' => 'shop', 'repository' => 'acme/shop', 'branch' => 'main', 'compute' => 'single', 'provider' => 'aws',
            'server' => ['mode' => 'new', 'region' => 'us-east-1', 'size' => 't3.small'], 'database' => 'sqlite'];
    }

    public function test_the_wizard_plans_an_aws_single_server_from_the_aws_catalog_and_price(): void
    {
        $this->org();
        $plan = $this->app->make(NewApplications::class)->plan('org-1', $this->wizard());

        $this->assertSame('aws', $plan['provider']);
        $this->assertSame(['low' => 15, 'high' => 15], $plan['monthly']);
        $this->assertSame('us-east-1', $plan['server']['region']);
    }

    public function test_the_wizard_refuses_what_aws_cannot_do_with_the_reason(): void
    {
        $this->org();
        $plans = $this->app->make(NewApplications::class);

        $cases = [
            [['compute' => 'autoscale'], 'compute: Autoscaling needs a load balancer. AWS does not offer one yet.'],
            [['storage' => 'bucket'], 'storage: AWS does not offer object storage'],
            [['server' => ['mode' => 'new', 'region' => 'nyc3', 'size' => 't3.small']], 'server: pick a region and size from the list.'],
            [['database' => '1'], 'database:'],
        ];
        foreach ($cases as [$over, $message]) {
            try {
                $plans->plan('org-1', $this->wizard($over));
                $this->fail("expected: {$message}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }
    }

    public function test_an_unconnected_provider_is_refused(): void
    {
        Queue::fake();
        $this->expectExceptionMessage('provider: pick a provider that is connected.');
        $this->app->make(NewApplications::class)->plan('org-1', $this->wizard());
    }

    public function test_the_api_lists_connected_providers_with_shapes_and_reasons(): void
    {
        $this->org();
        $token = $this->app->make(\App\Lib\Api\ApiTokens::class)->issue('org-1', 't', 'read', 'test')['token'];

        $r = $this->withToken($token)->getJson('/api/v1/providers')->assertOk();

        $aws = collect($r->json('providers'))->firstWhere('id', 'aws');
        $this->assertFalse($aws['shapes']['autoscale']['available']);
        $this->assertStringContainsString('AWS does not offer one yet', $aws['shapes']['autoscale']['reason']);
        $this->assertSame(15, $aws['sizes']['t3.small']['price']);
    }

    public function test_a_preview_goes_to_a_server_on_its_applications_provider_else_one_the_user_provided(): void
    {
        $add = fn (string $id, string $provider) => DB::table('nodes')->insert(['node_id' => "{$id}.org-1", 'organization_id' => 'org-1', 'role' => 'preview', 'provider' => $provider, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull(\App\Lib\Sites\Previews::server('org-1', 'aws'));

        $add('preview-a', 'custom');
        $this->assertSame('preview-a.org-1', \App\Lib\Sites\Previews::server('org-1', 'aws'), 'A server the user provided will do.');

        $add('preview-b', 'digitalocean');
        $this->assertSame('preview-b.org-1', \App\Lib\Sites\Previews::server('org-1', 'digitalocean'));
        $this->assertSame('preview-a.org-1', \App\Lib\Sites\Previews::server('org-1', 'aws'), 'Never another provider\'s.');

        $add('preview-c', 'aws');
        $this->assertSame('preview-c.org-1', \App\Lib\Sites\Previews::server('org-1', 'aws'));
    }
}
