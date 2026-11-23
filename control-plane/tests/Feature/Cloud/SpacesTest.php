<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\Spaces;
use App\Lib\Resources\ResourceManager;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

/**
 * A site's uploads go to a bucket only it can reach, and making that bucket
 * leaves no full-access key behind.
 */
final class SpacesTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.digitalocean.com/v2';

    private HttpFactory $http;

    private ResourceManager $resources;

    private Sites $sites;

    private bool $refuseBucket = false;

    /** @var list<string> what the backup bucket lists */
    private array $listed = [];

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Cache::store('array')->clear();
        $this->http = new HttpFactory();
        $this->resources = new ResourceManager(new AuditLog());
        $this->sites = new Sites($this->app->make(Vault::class), new AuditLog());
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->credentials()->connect('org-1', 'dop_v1_test_token');

        $keys = 0;
        $this->http->fake(function (Request $r) use (&$keys) {
            $this->sent[] = $r;
            if (str_contains($r->url(), 'digitaloceanspaces.com') && $r->method() === 'GET') {
                return $this->http->response('<ListBucketResult>'.implode('', array_map(fn ($k) => "<Contents><Key>{$k}</Key></Contents>", $this->listed)).'</ListBucketResult>', 200);
            }
            if (str_contains($r->url(), 'digitaloceanspaces.com')) {
                return $this->refuseBucket
                    ? $this->http->response('<Error><Code>TooManyBuckets</Code></Error>', 400)
                    : $this->http->response('', 200);
            }
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/spaces/keys')) {
                $keys++;

                return $this->http->response(['key' => ['access_key' => "AK{$keys}", 'secret_key' => "SK{$keys}"]], 201);
            }

            return $this->http->response([], 204);
        });
    }

    private function credentials(): CredentialStore
    {
        return new CredentialStore(vault: $this->app->make(Vault::class), http: $this->http, cache: Cache::store('array'), audit: new AuditLog());
    }

    private function spaces(): Spaces
    {
        return new Spaces($this->credentials(), $this->resources, $this->sites, new AuditLog(), $this->http, $this->app->make(Vault::class));
    }

    private function site(): stdClass
    {
        $droplet = $this->resources->declare('org-1', 'server', 'web-1', ['region' => 'nyc3']);
        $this->resources->recordExternalId($droplet, '616267966');

        return $this->sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    public function test_the_site_gets_its_own_bucket_and_a_key_for_that_bucket_only(): void
    {
        $site = $this->site();
        $this->spaces()->attach($site);

        $creates = array_values(array_filter($this->sent, fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/spaces/keys')));
        $this->assertSame('fullaccess', $creates[0]['grants'][0]['permission'], 'Only the setup key may make buckets.');
        $bucket = $creates[1]['grants'][0]['bucket'];
        $this->assertMatchesRegularExpression('/^cm-shop-[0-9a-f]{6}$/', $bucket);
        $this->assertSame([['bucket' => $bucket, 'permission' => 'readwrite']], $creates[1]['grants']);

        $put = array_values(array_filter($this->sent, fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), 'digitaloceanspaces.com')))[0];
        $this->assertSame("https://nyc3.digitaloceanspaces.com/{$bucket}", $put->url());
        $this->assertStringContainsString('Credential=AK1/', $put->header('Authorization')[0], 'The bucket is made with the setup key.');

        $deleted = array_map(fn ($r) => $r->url(), array_filter($this->sent, fn ($r) => $r->method() === 'DELETE'));
        $this->assertContains('https://'.self::API.'/spaces/keys/AK1', array_values($deleted), 'The setup key does not outlive the setup.');

        $env = $this->sites->env($this->sites->find('web-1.org-1', 'shop'));
        $this->assertStringContainsString("FILESYSTEM_DISK=s3\n", $env);
        $this->assertStringContainsString("AWS_ACCESS_KEY_ID=AK2\n", $env);
        $this->assertStringContainsString("AWS_BUCKET={$bucket}\n", $env);
        $this->assertStringContainsString("AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com\n", $env);
    }

    public function test_an_emulator_endpoint_is_used_in_place_of_digitalocean_and_addressed_by_path(): void
    {
        config(['services.digitalocean.spaces_endpoint' => 'http://localhost:4599']);

        $this->spaces()->attach($this->site());

        $put = array_values(array_filter($this->sent, fn ($r) => $r->method() === 'PUT'))[0];
        $this->assertMatchesRegularExpression('#^http://localhost:4599/cm-shop-[0-9a-f]{6}$#', $put->url());
        $env = $this->sites->env($this->sites->find('web-1.org-1', 'shop'));
        $this->assertStringContainsString("AWS_ENDPOINT=http://localhost:4599\n", $env);
        $this->assertMatchesRegularExpression('#AWS_URL=http://localhost:4599/cm-shop-[0-9a-f]{6}\n#', $env);
        $this->assertStringContainsString("AWS_USE_PATH_STYLE_ENDPOINT=true\n", $env);
    }

    public function test_the_api_url_can_point_at_an_emulator(): void
    {
        config(['services.digitalocean.api_url' => 'http://localhost:4599/v2']);

        $this->spaces()->attach($this->site());

        $urls = array_map(fn ($r) => $r->url(), array_filter($this->sent, fn ($r) => str_contains($r->url(), '/spaces/keys')));
        $this->assertNotEmpty($urls);
        foreach ($urls as $url) {
            $this->assertStringStartsWith('http://localhost:4599/v2/spaces/keys', $url);
        }
    }

    public function test_the_port_is_part_of_what_is_signed(): void
    {
        $with = Spaces::sign('PUT', 'http://localhost:4599', '/bucket', 'AK', 'secret', 1440938160);
        $without = Spaces::sign('PUT', 'http://localhost', '/bucket', 'AK', 'secret', 1440938160);

        $this->assertNotSame($with['Authorization'], $without['Authorization'], 'The Host header carries the port, so the signature must.');
        $this->assertNotSame(
            Spaces::presign('GET', 'http://localhost:4599', 'bucket', 'key', 'AK', 'secret', 300, 1440938160),
            str_replace(':4599', '', Spaces::presign('GET', 'http://localhost:4599', 'bucket', 'key', 'AK', 'secret', 300, 1440938160)),
        );
    }

    public function test_the_setup_key_is_deleted_even_when_the_bucket_is_refused(): void
    {
        $this->refuseBucket = true;

        try {
            $this->spaces()->attach($this->site());
            $this->fail('Should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('TooManyBuckets', $e->getMessage());
        }
        $this->assertNotEmpty(array_filter($this->sent, fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/spaces/keys/AK1')));
    }

    public function test_a_site_with_a_bucket_is_not_given_another(): void
    {
        $site = $this->site();
        $this->spaces()->attach($site);

        $this->expectException(InvalidArgumentException::class);
        $this->spaces()->attach($this->sites->find('web-1.org-1', 'shop'));
    }

    public function test_the_signature_matches_the_aws_reference(): void
    {
        // AWS's published GET-object example, adapted to an empty body: the
        // signing key derivation is fixed by this secret and date.
        $headers = Spaces::sign('PUT', 'https://nyc3.digitaloceanspaces.com', '/bucket', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 1440938160);

        $this->assertSame('20150830T123600Z', $headers['x-amz-date']);
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=', $headers['Authorization']);
    }

    public function test_backups_get_a_bucket_of_their_own_in_another_region_with_the_key_in_the_vault(): void
    {
        $this->spaces()->createForBackups('org-1', 'sfo3');

        $creates = array_values(array_filter($this->sent, fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/spaces/keys')));
        $bucket = $creates[1]['grants'][0]['bucket'];
        $this->assertMatchesRegularExpression('/^cm-backups-[0-9a-f]{8}$/', $bucket);
        $this->assertSame([['bucket' => $bucket, 'permission' => 'readwrite']], $creates[1]['grants']);
        $this->assertNotEmpty(array_filter($this->sent, fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/spaces/keys/AK1')));

        $target = $this->spaces()->backupTarget();
        $this->assertSame([$bucket, 'https://sfo3.digitaloceanspaces.com', 'AK2', 'SK2'], [$target['bucket'], $target['endpoint'], $target['key'], $target['secret']]);

        $this->expectException(InvalidArgumentException::class);
        $this->spaces()->createForBackups('org-1', 'sfo3');
    }

    public function test_each_snapshot_is_copied_off_the_server_and_only_the_newest_are_kept_there(): void
    {
        $this->spaces()->createForBackups('org-1', 'sfo3');
        $this->sent = [];
        $this->listed = ['control-plane-20260101T000000Z.sqlite.gz', 'control-plane-20260102T000000Z.sqlite.gz', 'control-plane-20260103T000000Z.sqlite.gz'];
        $file = sys_get_temp_dir().'/control-plane-20260104T000000Z.sqlite.gz';
        file_put_contents($file, gzencode('snapshot'));

        try {
            $said = $this->spaces()->copyBackup($this->spaces()->backupTarget(), $file, 2);
        } finally {
            unlink($file);
        }

        $this->assertStringContainsString('2 kept there', $said);
        $put = array_values(array_filter($this->sent, fn ($r) => $r->method() === 'PUT'))[0];
        $this->assertMatchesRegularExpression('#^https://sfo3\.digitaloceanspaces\.com/cm-backups-[0-9a-f]{8}/control-plane-\d{8}T\d{6}Z\.sqlite\.gz\?.*X-Amz-Credential=AK2#', $put->url());
        $this->assertSame("\x1f\x8b", substr($put->body(), 0, 2), 'The compressed snapshot is what goes up.');
        $deleted = array_values(array_map(fn ($r) => basename(parse_url($r->url(), PHP_URL_PATH)), array_filter($this->sent, fn ($r) => $r->method() === 'DELETE')));
        $this->assertSame(['control-plane-20260101T000000Z.sqlite.gz'], $deleted, 'The listing as faked holds three; two are kept.');
    }
}
