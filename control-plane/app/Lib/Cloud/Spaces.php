<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\DigitalOcean\Client;
use App\Lib\Providers\ProviderScope;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Sites\Sites;
use App\Lib\Vault\Vault;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * A private Spaces bucket for a site's uploads, so every server sees the same
 * files.
 *
 * Each site gets its own bucket and a key that can read and write that bucket
 * and nothing else. Making a bucket needs a key that can make buckets, so one
 * is created for the purpose and deleted straight after: no standing
 * full-access credential is kept anywhere.
 *
 * The site uses it through Laravel's s3 disk, which needs
 * league/flysystem-aws-s3-v3 in the application itself.
 */
final class Spaces
{
    /** Spaces accepts any region in the signature; this is what its docs use. */
    private const SIGNING_REGION = 'us-east-1';

    /** The vault entry holding the backup bucket key's secret. */
    public const BACKUP_SECRET = 'backups.spaces_secret';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ResourceManager $resources,
        private readonly Sites $sites,
        private readonly AuditLog $audit,
        private readonly HttpFactory $http,
        private readonly Vault $vault,
    ) {}

    /**
     * @return list<string> What was done, for the operation log.
     */
    public function attach(stdClass $site): array
    {
        ProviderScope::assertDigitalOcean($site, 'Spaces buckets');
        $region = $this->region($site);
        $resource = $this->resources->declare($site->organization_id, 'bucket', $site->name, [
            'region' => $region,
            // Bucket names are global across Spaces: a random tail keeps two
            // organisations' sites called "shop" apart.
            'bucket' => 'cm-'.substr($site->name, 0, 40).'-'.bin2hex(random_bytes(3)),
        ]);
        if ($resource->state === ResourceState::Active) {
            throw new InvalidArgumentException("{$site->name} already has a bucket, {$resource->attributes['bucket']}.");
        }
        $bucket = (string) $resource->attributes['bucket'];
        $endpoint = self::endpoint($region);
        $actor = Actor::system('control-plane');
        $client = $this->credentials->clientFor($site->organization_id, "Creating storage for {$site->name}", $actor);
        $done = [];
        if ($resource->state !== ResourceState::Provisioning) {
            $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: "Creating storage for {$site->name}", actor: $actor);
        }

        [$key, $made] = $this->bucketWithKey($client, $endpoint, $bucket, "cm-{$site->name}", $resource->attributes['key_id'] ?? null);
        $done = [...$done, ...$made];
        $resource = $this->resources->recordExternalId($resource, $bucket);
        $resource = $this->resources->mergeAttributes($resource, ['key_id' => $key['access_key']]);

        $this->sites->setInjected($site, [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => (string) $key['access_key'],
            'AWS_SECRET_ACCESS_KEY' => (string) $key['secret_key'],
            'AWS_DEFAULT_REGION' => self::SIGNING_REGION,
            'AWS_BUCKET' => $bucket,
            'AWS_ENDPOINT' => $endpoint,
            'AWS_URL' => self::bucketUrl($endpoint, $bucket, $region),
            'AWS_USE_PATH_STYLE_ENDPOINT' => self::overridden() ? 'true' : 'false',
        ], "Spaces bucket {$bucket}");
        $this->resources->transition($resource, ResourceState::Active, reason: 'Bucket and key created', actor: $actor);
        DB::table('sites')->where('id', $site->id)->update(['bucket_id' => $resource->id, 'updated_at' => now()]);
        $done[] = '.env uses the bucket as the default disk; deploy to switch';

        $this->audit->record($site->organization_id, $actor, 'site.bucket_attached', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['bucket' => $bucket, 'region' => $region]);

        return $done;
    }

    /**
     * Deletes the bucket this platform made for the site, everything in it, and its key, and points the
     * site back at its own disk. A bucket the user brought is never touched: it is only let go of.
     * Deleting a bucket needs the same full-access key making one does, made now and deleted straight after.
     *
     * @return list<string> What was done, for the operation log.
     */
    public function remove(stdClass $site): array
    {
        $resource = $site->bucket_id === null ? null : $this->resources->findById((int) $site->bucket_id);
        if ($resource === null) {
            throw new InvalidArgumentException("{$site->name} has no bucket.");
        }
        $actor = Actor::system('control-plane');
        $bucket = (string) ($resource->attributes['bucket'] ?? $resource->external_id);
        $done = [];

        if (($resource->attributes['adopted'] ?? false) !== true) {
            $region = (string) ($resource->attributes['region'] ?? $this->region($site));
            $endpoint = self::endpoint($region);
            $client = $this->credentials->clientFor($site->organization_id, "Deleting the storage of {$site->name}", $actor);
            $temporary = $client->post('spaces/keys', ['name' => "cm-{$site->name}-remove", 'grants' => [['bucket' => '', 'permission' => 'fullaccess']]])['key'] ?? [];
            try {
                $erased = $this->emptyAndDelete($endpoint, $bucket, (string) $temporary['access_key'], (string) $temporary['secret_key']);
                $done[] = "bucket {$bucket} deleted".($erased > 0 ? ", with {$erased} file".($erased === 1 ? '' : 's') : '');
            } finally {
                try {
                    $client->delete('spaces/keys/'.$temporary['access_key']);
                } catch (Throwable) {
                    $done[] = "could not delete the temporary key {$temporary['access_key']}: delete it under API → Spaces Keys";
                }
            }
            if (isset($resource->attributes['key_id'])) {
                try {
                    $client->delete('spaces/keys/'.$resource->attributes['key_id']);
                    $done[] = "the key for {$bucket} deleted";
                } catch (Throwable) {
                    $done[] = "could not delete the key {$resource->attributes['key_id']} for {$bucket}: delete it under API → Spaces Keys";
                }
            }
        } else {
            $done[] = "{$bucket} is yours already: it and its key are left as they are";
        }

        $resource = $this->resources->transition($resource, ResourceState::Deleting, reason: 'Bucket removed', actor: $actor);
        $this->resources->transition($resource, ResourceState::Deleted, reason: 'Bucket removed', actor: $actor);
        DB::table('sites')->where('id', $site->id)->update(['bucket_id' => null, 'updated_at' => now()]);
        $this->sites->setInjected($site, array_fill_keys(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_URL', 'AWS_USE_PATH_STYLE_ENDPOINT'], null)
            + ['FILESYSTEM_DISK' => 'local'], 'Storage removed');
        $done[] = '.env uses the server disk again; deploy to switch';
        $this->audit->record($site->organization_id, $actor, 'site.bucket_removed', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['bucket' => $bucket]);

        return $done;
    }

    /** Deletes every object, then the bucket. Returns how many objects there were. */
    private function emptyAndDelete(string $endpoint, string $bucket, string $accessKey, #[SensitiveParameter] string $secretKey): int
    {
        $count = 0;
        $token = null;
        for ($page = 0; $page < 1000; $page++) {
            $params = ['list-type' => '2'] + ($token === null ? [] : ['continuation-token' => $token]);
            $list = $this->http->get(self::presign('GET', $endpoint, $bucket, '', $accessKey, $secretKey, 300, params: $params));
            if (! $list->successful()) {
                throw new RuntimeException("Could not list {$bucket}: HTTP {$list->status()}");
            }
            preg_match_all('#<Key>([^<]+)</Key>#', $list->body(), $m);
            foreach ($m[1] as $key) {
                $this->http->delete(self::presign('DELETE', $endpoint, $bucket, html_entity_decode($key), $accessKey, $secretKey, 300))->throw();
                $count++;
            }
            if (! preg_match('#<NextContinuationToken>([^<]+)</NextContinuationToken>#', $list->body(), $next)) {
                break;
            }
            $token = html_entity_decode($next[1]);
        }
        $response = $this->http->withHeaders(self::sign('DELETE', $endpoint, "/{$bucket}", $accessKey, $secretKey))->delete("{$endpoint}/{$bucket}");
        if (! $response->successful() && $response->status() !== 404) {
            preg_match('#<Code>([^<]+)</Code>#', $response->body(), $c);
            throw new RuntimeException("Spaces would not delete {$bucket}: ".($c[1] ?? "HTTP {$response->status()}"));
        }

        return $count;
    }

    /**
     * Points the site at a bucket that already exists and that this platform did not make, at any S3-compatible
     * service. The key is tried with one read-only request (HEAD on the bucket); nothing is created, written or
     * deleted there, and the bucket and key are never touched afterwards, by deleting the site or otherwise.
     *
     * @return list<string> What was done, for the operation log.
     */
    public function useExisting(stdClass $site, string $endpoint, string $bucket, string $accessKey, #[SensitiveParameter] string $secretKey, string $region = self::SIGNING_REGION, ?string $url = null): array
    {
        $endpoint = rtrim(trim($endpoint), '/');
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || $host === '' || parse_url($endpoint, PHP_URL_PATH) !== null && parse_url($endpoint, PHP_URL_PATH) !== ''
            || $host === 'localhost' || preg_match('/\.(local|internal|localhost)$/', $host)
            || (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new InvalidArgumentException('The endpoint is the https address of the storage service, for example https://nyc3.digitaloceanspaces.com.');
        }
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new InvalidArgumentException('That is not a bucket name: lowercase letters, digits, dots and dashes.');
        }
        if (! preg_match('/^[a-z0-9-]{1,30}$/', $region)) {
            throw new InvalidArgumentException('The region is a short name such as us-east-1 or nyc3.');
        }
        if ($url !== null && $url !== '' && (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) === null)) {
            throw new InvalidArgumentException('The public address, if you give one, is an https address.');
        }
        if ($accessKey === '' || $secretKey === '') {
            throw new InvalidArgumentException('Both the access key and its secret are needed.');
        }
        if ($site->bucket_id !== null) {
            throw new InvalidArgumentException("{$site->name} already has a bucket.");
        }

        $response = $this->http->withHeaders(self::sign('HEAD', $endpoint, "/{$bucket}", $accessKey, $secretKey, region: $region))->head("{$endpoint}/{$bucket}");
        if (! $response->successful()) {
            throw new InvalidArgumentException(match ($response->status()) {
                404 => "There is no bucket called {$bucket} at {$host}.",
                401, 403 => "{$host} refused that key for {$bucket}. Check the key, its secret and that it may read this bucket.",
                301, 400 => "{$host} did not accept the region {$region} for {$bucket}. Use the region the bucket is in.",
                default => "{$host} answered HTTP {$response->status()} when asked about {$bucket}.",
            });
        }

        $actor = Actor::system('control-plane');
        $resource = $this->resources->declare($site->organization_id, 'bucket', $site->name, ['bucket' => $bucket, 'endpoint' => $endpoint, 'region' => $region, 'adopted' => true]);
        if ($resource->state === ResourceState::Active) {
            throw new InvalidArgumentException("{$site->name} already has a bucket, {$resource->attributes['bucket']}.");
        }
        if ($resource->state !== ResourceState::Provisioning) {
            $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: "Using the existing bucket {$bucket}", actor: $actor);
        }
        $resource = $this->resources->recordExternalId($resource, $bucket);
        $this->sites->setInjected($site, [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => $accessKey,
            'AWS_SECRET_ACCESS_KEY' => $secretKey,
            'AWS_DEFAULT_REGION' => $region,
            'AWS_BUCKET' => $bucket,
            'AWS_ENDPOINT' => $endpoint,
            'AWS_URL' => $url !== null && $url !== '' ? $url : null,
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ], "Existing bucket {$bucket}");
        $this->resources->transition($resource, ResourceState::Active, reason: 'Existing bucket checked and brought in; nothing was changed there', actor: $actor);
        DB::table('sites')->where('id', $site->id)->update(['bucket_id' => $resource->id, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, $actor, 'site.bucket_attached', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['bucket' => $bucket, 'endpoint' => $endpoint, 'existing' => true]);

        return ["{$bucket} at {$host} answered to the key", '.env uses it as the default disk; deploy to switch', 'nothing was created, written or deleted there'];
    }

    /**
     * A private bucket for the control plane's own nightly snapshots, with a
     * key for that bucket only, kept in the vault. Run once; the snapshot
     * command finds it by its resource row.
     *
     * @return list<string>
     */
    public function createForBackups(string $organizationId, string $region): array
    {
        $resource = $this->resources->declare($organizationId, 'backup_bucket', 'control-plane', [
            'region' => $region,
            'bucket' => 'cm-backups-'.bin2hex(random_bytes(4)),
        ]);
        if ($resource->state === ResourceState::Active) {
            throw new InvalidArgumentException("Backups already go to {$resource->attributes['bucket']}.");
        }
        $actor = Actor::system('control-plane');
        if ($resource->state !== ResourceState::Provisioning) {
            $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: 'Creating the backup bucket', actor: $actor);
        }
        $bucket = (string) $resource->attributes['bucket'];
        $region = (string) $resource->attributes['region'];
        $client = $this->credentials->clientFor($organizationId, 'Creating the backup bucket', $actor);
        [$key, $done] = $this->bucketWithKey($client, self::endpoint($region), $bucket, 'cm-backups', $resource->attributes['key_id'] ?? null);
        $this->vault->put($organizationId, self::BACKUP_SECRET, (string) $key['secret_key'], ['bucket' => $bucket]);
        $resource = $this->resources->recordExternalId($resource, $bucket);
        $resource = $this->resources->mergeAttributes($resource, ['key_id' => $key['access_key']]);
        $this->resources->transition($resource, ResourceState::Active, reason: 'Bucket and key created', actor: $actor);
        $this->audit->record($organizationId, $actor, 'backups.bucket_created', resourceType: 'backup_bucket', resourceId: $bucket, metadata: ['region' => $region]);

        return $done;
    }

    /**
     * Where snapshots go, with the key to put them there, or null when no
     * backup bucket has been set up.
     *
     * @return array{organization: string, bucket: string, endpoint: string, key: string, secret: string}|null
     */
    public function backupTarget(): ?array
    {
        $row = DB::table('resources')->where('type', 'backup_bucket')->where('state', 'active')->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }
        $attributes = json_decode((string) $row->attributes, true);
        $secret = $this->vault->get($row->organization_id, self::BACKUP_SECRET)
            ?? throw new RuntimeException('The backup bucket exists but its key is missing from the vault.');

        return [
            'organization' => $row->organization_id,
            'bucket' => (string) $attributes['bucket'],
            'endpoint' => self::endpoint((string) $attributes['region']),
            'key' => (string) $attributes['key_id'],
            'secret' => $secret,
        ];
    }

    /**
     * Uploads one snapshot, then deletes all but the newest $keep in the
     * bucket. Names sort by time, so the newest are the last.
     *
     * @param  array{bucket: string, endpoint: string, key: string, secret: string}  $t  from backupTarget()
     */
    public function copyBackup(array $t, string $file, int $keep): string
    {
        $url = fn (string $method, string $object, array $params = []) => self::presign($method, $t['endpoint'], $t['bucket'], $object, $t['key'], $t['secret'], 300, params: $params);

        $put = $this->http->withBody((string) file_get_contents($file), 'application/gzip')->put($url('PUT', basename($file)));
        if (! $put->successful()) {
            throw new RuntimeException("Spaces refused the snapshot: HTTP {$put->status()}");
        }
        $list = $this->http->get($url('GET', '', ['list-type' => '2', 'prefix' => 'control-plane-']));
        if (! $list->successful()) {
            throw new RuntimeException("Could not list the backup bucket: HTTP {$list->status()}");
        }
        preg_match_all('#<Key>(control-plane-[^<]+\.(?:sqlite|sql)\.gz)</Key>#', $list->body(), $m);
        $copies = $m[1];
        rsort($copies);
        foreach (array_slice($copies, $keep) as $old) {
            $this->http->delete($url('DELETE', $old))->throw();
        }

        return sprintf('Copied to %s; %d kept there.', $t['bucket'], min(count($copies), $keep));
    }

    /** How long a URL handed to a server stays valid: its dump or load may run for up to half an hour before it uses it. */
    public const TRANSFER_TTL = 3600;

    /**
     * A URL for one method on one object in the backup bucket, for a server to upload or download with curl and hold no
     * key. Five minutes for what the control plane itself does at once; TRANSFER_TTL for a URL given to a server that
     * dumps or loads a database first, since the signature is checked when it is used, not when it is made.
     *
     * @param  array<string, string>  $params
     */
    public function backupUrl(string $method, string $object, array $params = [], int $ttl = 300): string
    {
        $t = $this->backupTarget() ?? throw new RuntimeException('No backup bucket: run backup:offsite first.');

        return self::presign($method, $t['endpoint'], $t['bucket'], $object, $t['key'], $t['secret'], $ttl, params: $params);
    }

    /**
     * Objects in the backup bucket under a prefix, with their sizes.
     *
     * @return array<string, int> key => bytes, in name order
     */
    public function backupObjects(string $prefix): array
    {
        $list = $this->http->get($this->backupUrl('GET', '', ['list-type' => '2', 'prefix' => $prefix]));
        if (! $list->successful()) {
            throw new RuntimeException("Could not list the backup bucket: HTTP {$list->status()}");
        }
        preg_match_all('#<Contents>.*?<Key>([^<]+)</Key>.*?<Size>(\d+)</Size>.*?</Contents>#s', $list->body(), $m, PREG_SET_ORDER);
        $objects = [];
        foreach ($m as [, $key, $size]) {
            $objects[html_entity_decode($key)] = (int) $size;
        }
        ksort($objects);

        return $objects;
    }

    public function deleteBackupObject(string $object): void
    {
        $this->http->delete($this->backupUrl('DELETE', $object))->throw();
    }

    /**
     * Makes the bucket with a full-access key that is deleted straight
     * after, then a key for that bucket only, replacing $previousKey.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function bucketWithKey(Client $client, string $endpoint, string $bucket, string $keyName, ?string $previousKey): array
    {
        $done = [];
        $temporary = $client->post('spaces/keys', ['name' => "{$keyName}-setup", 'grants' => [['bucket' => '', 'permission' => 'fullaccess']]])['key'] ?? [];
        try {
            $this->createBucket($endpoint, $bucket, (string) $temporary['access_key'], (string) $temporary['secret_key']);
            $done[] = "bucket {$bucket} in ".(strstr((string) parse_url($endpoint, PHP_URL_HOST), '.', true) ?: parse_url($endpoint, PHP_URL_HOST)).', private';
        } finally {
            try {
                $client->delete('spaces/keys/'.$temporary['access_key']);
            } catch (Throwable) {
                $done[] = "could not delete the setup key {$temporary['access_key']}: delete it under API → Spaces Keys";
            }
        }

        // Re-running replaces the key rather than adding another.
        if ($previousKey !== null) {
            try {
                $client->delete('spaces/keys/'.$previousKey);
            } catch (Throwable) {
            }
        }
        $key = $client->post('spaces/keys', ['name' => $keyName, 'grants' => [['bucket' => $bucket, 'permission' => 'readwrite']]])['key'] ?? [];
        $done[] = "key for {$bucket} only, read and write";

        return [$key, $done];
    }

    /**
     * Spaces creates buckets through its S3 API only. A key can take a few
     * seconds to work after it is made, so a refusal is retried briefly.
     */
    private function createBucket(string $endpoint, string $bucket, string $accessKey, #[SensitiveParameter] string $secretKey): void
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->http->withHeaders(self::sign('PUT', $endpoint, "/{$bucket}", $accessKey, $secretKey))
                ->withBody('', 'application/octet-stream')->put("{$endpoint}/{$bucket}");
            // 409 BucketAlreadyOwnedByYou: an earlier attempt made it.
            if ($response->successful() || ($response->status() === 409 && str_contains($response->body(), 'BucketAlreadyOwnedByYou'))) {
                return;
            }
            if ($attempt >= 6 || ! in_array($response->status(), [401, 403], true)) {
                preg_match('#<Code>([^<]+)</Code>#', $response->body(), $m);
                throw new RuntimeException("Spaces refused the bucket {$bucket}: ".($m[1] ?? "HTTP {$response->status()}"));
            }
            usleep(2_000_000);
        }
    }

    /**
     * AWS Signature Version 4 headers for a request with an empty body.
     *
     * @return array<string, string>
     */
    public static function sign(string $method, string $endpoint, string $path, string $accessKey, #[SensitiveParameter] string $secretKey, ?int $now = null, string $region = self::SIGNING_REGION): array
    {
        $now ??= time();
        $host = self::signedHost($endpoint);
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $date = gmdate('Ymd', $now);
        $payload = hash('sha256', '');
        $signed = 'host;x-amz-content-sha256;x-amz-date';
        $canonical = "{$method}\n{$path}\n\nhost:{$host}\nx-amz-content-sha256:{$payload}\nx-amz-date:{$amzDate}\n\n{$signed}\n{$payload}";
        $scope = "{$date}/".$region.'/s3/aws4_request';
        $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonical);

        $k = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        foreach ([$region, 's3', 'aws4_request'] as $part) {
            $k = hash_hmac('sha256', $part, $k, true);
        }

        return [
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payload,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signed}, Signature=".hash_hmac('sha256', $toSign, $k),
        ];
    }

    /**
     * A URL that allows one method on one object until it expires, so a
     * server can upload or download with curl and hold no key. $params are
     * extra query parameters, signed with the rest (a listing's prefix).
     *
     * @param  array<string, string>  $params
     */
    public static function presign(string $method, string $endpoint, string $bucket, string $object, string $accessKey, #[SensitiveParameter] string $secretKey, int $expires, ?int $now = null, array $params = []): string
    {
        $now ??= time();
        $host = self::signedHost($endpoint);
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $date = gmdate('Ymd', $now);
        $scope = "{$date}/".self::SIGNING_REGION.'/s3/aws4_request';
        $uri = '/'.rawurlencode($bucket).'/'.implode('/', array_map('rawurlencode', explode('/', $object)));
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$accessKey}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ] + $params;
        ksort($query);
        $canonicalQuery = implode('&', array_map(fn ($k, $v) => rawurlencode($k).'='.rawurlencode($v), array_keys($query), $query));
        $canonical = "{$method}\n{$uri}\n{$canonicalQuery}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
        $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonical);
        $k = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        foreach ([self::SIGNING_REGION, 's3', 'aws4_request'] as $part) {
            $k = hash_hmac('sha256', $part, $k, true);
        }

        return "{$endpoint}{$uri}?{$canonicalQuery}&X-Amz-Signature=".hash_hmac('sha256', $toSign, $k);
    }

    /**
     * Where Spaces is for a region: DigitalOcean's, unless services.digitalocean.spaces_endpoint
     * points at an emulator in development.
     */
    private static function endpoint(string $region): string
    {
        return rtrim((string) (config('services.digitalocean.spaces_endpoint') ?: "https://{$region}.digitaloceanspaces.com"), '/');
    }

    private static function overridden(): bool
    {
        return (bool) config('services.digitalocean.spaces_endpoint');
    }

    /** Where a bucket's files are served from. An emulator is addressed by path, not by a bucket subdomain. */
    private static function bucketUrl(string $endpoint, string $bucket, string $region): string
    {
        return self::overridden() ? "{$endpoint}/{$bucket}" : "https://{$bucket}.{$region}.digitaloceanspaces.com";
    }

    /** The host as the client sends it in Host, which is what a signature covers: the port is part of it. */
    private static function signedHost(string $endpoint): string
    {
        $port = parse_url($endpoint, PHP_URL_PORT);

        return (string) parse_url($endpoint, PHP_URL_HOST).($port !== null ? ":{$port}" : '');
    }

    private function region(stdClass $site): string
    {
        $attributes = DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')
            ->where('slug', strstr($site->node_id, '.', true) ?: $site->node_id)->value('attributes');

        return (string) (json_decode((string) $attributes, true)['region'] ?? throw new InvalidArgumentException("{$site->node_id} is not a Droplet the platform created, so there is no region to put the bucket in."));
    }
}
