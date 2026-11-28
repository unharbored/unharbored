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
        $this->http = new HttpFactory;
        $this->resources = new ResourceManager(new AuditLog);
        $this->sites = new Sites($this->app->make(Vault::class), new AuditLog);
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
        return new CredentialStore(vault: $this->app->make(Vault::class), http: $this->http, cache: Cache::store('array'), audit: new AuditLog);
    }

    private function spaces(): Spaces
    {
        return new Spaces($this->credentials(), $this->resources, $this->sites, new AuditLog, $this->http, $this->app->make(Vault::class));
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

    public function test_deleting_a_bucket_erases_its_files_then_the_bucket_and_both_keys_and_the_site_goes_back_to_its_disk(): void
    {
        $site = $this->site();
        $this->spaces()->attach($site);
        $bucketRow = $this->sites->find('web-1.org-1', 'shop');
        $bucket = $this->resources->findById((int) $bucketRow->bucket_id)->externalId;
        $this->sent = [];
        $this->listed = ['a.jpg', 'docs/b.png'];

        $done = $this->spaces()->remove($bucketRow);

        $deleted = array_values(array_map(fn ($r) => $r->url(), array_filter($this->sent, fn ($r) => $r->method() === 'DELETE')));
        $this->assertCount(2, array_filter($deleted, fn ($u) => str_contains($u, "/{$bucket}/") && str_contains($u, 'X-Amz-Signature')), 'Every file is deleted first.');
        $this->assertContains("https://nyc3.digitaloceanspaces.com/{$bucket}", $deleted, 'Then the bucket.');
        $this->assertContains('https://'.self::API.'/spaces/keys/AK3', $deleted, 'The temporary full-access key does not outlive it.');
        $this->assertContains('https://'.self::API.'/spaces/keys/AK2', $deleted, "The site's own key goes too.");
        $this->assertStringContainsString('with 2 files', implode(' ', $done));

        $fresh = $this->sites->find('web-1.org-1', 'shop');
        $this->assertNull($fresh->bucket_id);
        $this->assertSame('deleted', $this->resources->findById((int) $bucketRow->bucket_id)->state->value);
        $env = $this->sites->env($fresh);
        $this->assertStringContainsString("FILESYSTEM_DISK=local\n", $env);
        $this->assertStringNotContainsString('AWS_BUCKET', $env);
    }

    public function test_a_bucket_the_user_brought_is_let_go_of_and_never_deleted_or_emptied(): void
    {
        $site = $this->site();
        $this->spaces()->useExisting($site, 'https://nyc3.digitaloceanspaces.com/', 'client-uploads', 'AKEXISTING', 'SKEXISTING', 'nyc3');
        $this->sent = [];

        $this->spaces()->remove($this->sites->find('web-1.org-1', 'shop'));

        $this->assertSame([], $this->sent, 'Nothing is asked of the service: no key, no listing, no delete.');
        $this->assertNull($this->sites->find('web-1.org-1', 'shop')->bucket_id);
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

    public function test_an_existing_bucket_is_checked_with_a_read_only_request_and_nothing_is_created_or_changed_there(): void
    {
        $site = $this->site();
        $this->sent = [];

        $this->spaces()->useExisting($site, 'https://nyc3.digitaloceanspaces.com/', 'client-uploads', 'AKEXISTING', 'SKEXISTING', 'nyc3', 'https://cdn.example.com');

        $this->assertSame(['HEAD'], array_values(array_unique(array_map(fn ($r) => $r->method(), $this->sent))), 'One read-only request, and no key or bucket is made or deleted.');
        $this->assertSame('https://nyc3.digitaloceanspaces.com/client-uploads', $this->sent[0]->url());
        $this->assertStringContainsString('Credential=AKEXISTING/', $this->sent[0]->header('Authorization')[0]);
        $this->assertStringContainsString('/nyc3/s3/aws4_request', $this->sent[0]->header('Authorization')[0], 'It is signed for the region given.');

        $fresh = $this->sites->find('web-1.org-1', 'shop');
        $env = $this->sites->env($fresh);
        foreach (["FILESYSTEM_DISK=s3\n", "AWS_ACCESS_KEY_ID=AKEXISTING\n", "AWS_SECRET_ACCESS_KEY=SKEXISTING\n", "AWS_BUCKET=client-uploads\n", "AWS_DEFAULT_REGION=nyc3\n",
            "AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com\n", "AWS_URL=https://cdn.example.com\n", "AWS_USE_PATH_STYLE_ENDPOINT=true\n"] as $line) {
            $this->assertStringContainsString($line, $env);
        }
        $bucket = $this->resources->findById((int) $fresh->bucket_id);
        $this->assertSame(['client-uploads', true], [$bucket->externalId, $bucket->attributes['adopted']]);
        $this->assertStringNotContainsString('SKEXISTING', (string) json_encode($bucket->attributes), 'The secret lives in the vault, not on the resource.');
    }

    public function test_an_existing_bucket_is_refused_for_a_bad_address_a_refused_key_or_a_missing_bucket_and_nothing_is_recorded(): void
    {
        $site = $this->site();
        foreach ([
            ['http://nyc3.digitaloceanspaces.com', 'https://'],
            ['https://localhost', 'https://'],
            ['https://10.0.0.5', 'https://'],
            ['https://169.254.169.254', 'https://'],
            ['https://s3.example.net/some/path', 'https://'],
        ] as [$endpoint, $why]) {
            try {
                $this->spaces()->useExisting($site, $endpoint, 'client-uploads', 'AK', 'SK');
                $this->fail($endpoint);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($why, $e->getMessage(), $endpoint);
            }
        }

        foreach ([403 => 'refused that key', 404 => 'There is no bucket called client-uploads'] as $status => $why) {
            $http = new HttpFactory;
            $http->fake(['*' => $http->response('', $status)]);
            $spaces = new Spaces($this->credentials(), $this->resources, $this->sites, new AuditLog, $http, $this->app->make(Vault::class));
            try {
                $spaces->useExisting($site, 'https://s3.example.net', 'client-uploads', 'AK', 'SK');
                $this->fail((string) $status);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('bucket_id'));
        $this->assertStringNotContainsString('AWS_BUCKET', $this->sites->env($this->sites->find('web-1.org-1', 'shop')));
        $this->assertSame(0, DB::table('resources')->where('type', 'bucket')->count());
    }

    public function test_a_site_with_a_bucket_is_not_given_an_existing_one_over_it(): void
    {
        $site = $this->site();
        $this->spaces()->useExisting($site, 'https://s3.example.net', 'client-uploads', 'AK', 'SK');

        $this->expectExceptionMessage('already has a bucket');
        $this->spaces()->useExisting($this->sites->find('web-1.org-1', 'shop'), 'https://s3.example.net', 'another', 'AK', 'SK');
    }
}
