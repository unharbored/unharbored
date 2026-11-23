<?php

namespace Tests\Feature\Templates;

use App\Lib\Templates\Definition;
use App\Lib\Templates\FetchFailed;
use App\Lib\Templates\SafeFetcher;
use App\Lib\Templates\Sources;
use App\Lib\Templates\UpdateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SourcesTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    private string $dir;

    private string $secret;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cm-templates-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
        app()->instance(SafeFetcher::class, new SafeFetcher(fn () => ['93.184.216.34']));
        config(['templates.builtin_path' => $this->dir.'/builtin', 'templates.path' => '', 'templates.allow_custom' => true,
            'templates.official_public_key' => $this->public, 'templates.community_public_key' => $this->public]);
        mkdir($this->dir.'/builtin');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function sources(array $list): Sources
    {
        config(['templates.sources' => $list]);

        return app(Sources::class);
    }

    private function writeTemplate(string $folder, string $id, array $data = []): string
    {
        @mkdir($folder);
        $d = array_replace($this->nativeArray(), ['id' => $id], $data);
        file_put_contents("$folder/$id.yml", $this->yaml($d));

        return "$folder/$id.yml";
    }

    // ---- built-in and local folder --------------------------------------

    public function test_builtin_files_load_with_no_label(): void
    {
        $this->writeTemplate($this->dir.'/builtin', 'demo');
        $s = $this->sources([['kind' => 'builtin']]);
        $this->assertCount(1, $s->entries());
        $e = $s->find('demo');
        $this->assertSame('official', $e->tier);
        $this->assertNull($e->label);
        $this->assertSame([], $s->failures());
        $this->assertSame($e->definition, $s->definition($e));
    }

    public function test_a_file_that_fails_validation_is_not_offered_and_is_listed_with_its_line(): void
    {
        $this->writeTemplate($this->dir.'/builtin', 'good');
        $path = $this->writeTemplate($this->dir.'/builtin', 'bad', ['runs' => [...$this->nativeArray()['runs'], 'user' => 'root']]);
        $s = $this->sources([['kind' => 'builtin']]);
        $this->assertNull($s->find('bad'));
        $this->assertNotNull($s->find('good'));
        $f = $s->failures();
        $this->assertCount(1, $f);
        $this->assertSame('builtin', $f[0]['source']);
        $this->assertSame('bad.yml', $f[0]['file']);
        $this->assertNotNull($f[0]['line']);
        $this->assertStringContainsString('root', $f[0]['reason']);
    }

    public function test_a_file_must_be_named_after_its_id(): void
    {
        $this->writeTemplate($this->dir.'/builtin', 'demo');
        rename($this->dir.'/builtin/demo.yml', $this->dir.'/builtin/other.yml');
        $s = $this->sources([['kind' => 'builtin']]);
        $this->assertSame([], $s->entries());
        $this->assertStringContainsString('named demo.yml', $s->failures()[0]['reason']);
    }

    public function test_local_folder_is_offered_as_custom_and_only_read(): void
    {
        $path = $this->writeTemplate($this->dir.'/local', 'mine');
        $before = [md5_file($path), filemtime($path), scandir($this->dir.'/local')];
        chmod($this->dir.'/local', 0555);
        chmod($path, 0444);
        config(['templates.path' => $this->dir.'/local']);

        $s = $this->sources([['kind' => 'local']]);
        $e = $s->find('mine');
        $this->assertSame('custom', $e->tier);
        $this->assertSame('Custom, not reviewed', $e->label);
        $this->assertSame($before, [md5_file($path), filemtime($path), scandir($this->dir.'/local')]);
        chmod($this->dir.'/local', 0755);
    }

    public function test_local_folder_is_off_when_the_path_is_empty(): void
    {
        $s = $this->sources([['kind' => 'local']]);
        $this->assertSame([], $s->entries());
        $this->assertSame([], $s->failures());
    }

    public function test_a_duplicate_id_in_the_local_folder_is_refused_not_shadowed(): void
    {
        $this->writeTemplate($this->dir.'/builtin', 'demo');
        $this->writeTemplate($this->dir.'/local', 'demo', ['name' => 'Impostor']);
        config(['templates.path' => $this->dir.'/local']);

        // Listed in the lower tier first: order in the config must not matter.
        $s = $this->sources([['kind' => 'local'], ['kind' => 'builtin']]);
        $this->assertSame('Demo', $s->find('demo')->name);
        $this->assertSame('builtin', $s->find('demo')->source);
        $this->assertCount(1, $s->failures());
        $this->assertStringContainsString('refused, not shadowed', $s->failures()[0]['reason']);
        $this->assertSame($this->dir.'/local', $s->failures()[0]['source']);
    }

    // ---- editions --------------------------------------------------------

    public function test_with_custom_sources_off_a_custom_url_and_the_local_folder_are_rejected_at_load_time(): void
    {
        $this->writeTemplate($this->dir.'/local', 'mine');
        config(['templates.path' => $this->dir.'/local', 'templates.allow_custom' => false]);
        Http::fake();

        $s = $this->sources([['kind' => 'builtin'], ['kind' => 'local'], ['kind' => 'custom', 'url' => 'https://c.example.com/t']]);
        $this->assertSame([], $s->entries());
        $reasons = array_column($s->failures(), 'reason', 'source');
        $this->assertStringContainsString('switched off', $reasons[$this->dir.'/local']);
        $this->assertStringContainsString('switched off', $reasons['https://c.example.com/t']);
        Http::assertNothingSent();
    }

    public function test_with_custom_sources_off_official_and_community_still_load(): void
    {
        config(['templates.allow_custom' => false]);
        $this->fakeIndex('https://o.example.com/t', [['demo', 1]]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $this->assertNotNull($s->find('demo'));
    }

    // ---- remote index ----------------------------------------------------

    /** Replace every earlier fake: the first matching stub wins, so a test that changes the answers starts clean. */
    private function http(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    /** @var array<string, mixed> every stub so far, by URL; a later index at the same URL replaces the earlier one */
    private array $fakes = [];

    /** @var array<string, string> file name => content */
    private array $files = [];

    /** Fake an index at $base listing [[id, version], ...]; returns the index body. */
    private function fakeIndex(string $base, array $items, ?string $secret = null, ?string $publisher = 'Acme', bool $sign = true, array $override = []): string
    {
        $list = [];
        foreach ($items as [$id, $version]) {
            $content = $this->yaml(array_replace($this->nativeArray(), ['id' => $id, 'version' => $version]));
            $this->files["$base/$id.yml"] = $content;
            $list[] = ['id' => $id, 'version' => $version, 'name' => 'Demo', 'category' => 'Storage', 'publisher' => $publisher, 'file' => "$id.yml", 'sha256' => hash('sha256', $content)];
        }
        $index = json_encode($list);
        $fakes = ["$base/index.json" => Http::response($index)];
        if ($sign) {
            $fakes["$base/index.json.sig"] = Http::response(base64_encode(sodium_crypto_sign_detached($index, $secret ?? $this->secret)));
        }
        foreach ($this->files as $url => $content) {
            if (str_starts_with($url, $base)) {
                $fakes[$url] = Http::response($content);
            }
        }
        $this->fakes = $override + $fakes + $this->fakes;
        $this->http($this->fakes);

        return $index;
    }

    public function test_a_signed_index_is_verified_and_lists_entries_without_fetching_files(): void
    {
        $this->fakeIndex('https://o.example.com/t', [['demo', 2], ['other', 1]]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t/']]);
        $this->assertCount(2, $s->entries());
        $e = $s->find('demo');
        $this->assertSame('official', $e->tier);
        $this->assertNull($e->label);
        $this->assertSame('Acme', $e->publisher);
        $this->assertNull($e->definition);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'demo.yml'));
        $this->assertSame([], $s->failures());
    }

    public function test_a_community_index_is_labelled(): void
    {
        $this->fakeIndex('https://c.example.com/t', [['demo', 1]]);
        $e = $this->sources([['kind' => 'community', 'url' => 'https://c.example.com/t']])->find('demo');
        $this->assertSame('community', $e->tier);
        $this->assertSame('Community', $e->label);
    }

    public function test_a_tampered_index_is_refused(): void
    {
        $index = $this->fakeIndex('https://o.example.com/t', [['demo', 1]]);
        $sig = base64_encode(sodium_crypto_sign_detached($index, $this->secret));
        $this->fakeIndex('https://o.example.com/t', [['demo', 1]], override: [
            'https://o.example.com/t/index.json' => Http::response(str_replace('"Acme"', '"Mallory"', $index)),
            'https://o.example.com/t/index.json.sig' => Http::response($sig),
        ]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $this->assertSame([], $s->entries());
        $this->assertStringContainsString('signature does not verify', $s->failures()[0]['reason']);
    }

    public function test_an_index_signed_with_the_wrong_key_is_refused(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->fakeIndex('https://o.example.com/t', [['demo', 1]], $other);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $this->assertSame([], $s->entries());
        $this->assertStringContainsString('signature does not verify', $s->failures()[0]['reason']);
    }

    public function test_a_garbage_signature_or_a_missing_key_is_refused(): void
    {
        $this->fakeIndex('https://o.example.com/t', [['demo', 1]], override: ['https://o.example.com/t/index.json.sig' => Http::response('not base64 !!')]);
        $this->assertSame([], $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']])->entries());

        $this->fakeIndex('https://o.example.com/t', [['demo', 1]]);
        config(['templates.official_public_key' => '']);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $this->assertSame([], $s->entries());
        $this->assertStringContainsString('no valid public key', $s->failures()[0]['reason']);
    }

    public function test_a_custom_index_is_unsigned_and_labelled_so(): void
    {
        $this->fakeIndex('https://c.example.com/t', [['demo', 1]], sign: false);
        $e = $this->sources([['kind' => 'custom', 'url' => 'https://c.example.com/t']])->find('demo');
        $this->assertSame('custom', $e->tier);
        $this->assertStringContainsString('unsigned', $e->label);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '.sig'));
    }

    public function test_a_bad_index_entry_is_listed_and_the_rest_are_offered(): void
    {
        $good = ['id' => 'demo', 'version' => 1, 'name' => 'D', 'category' => 'C', 'publisher' => 'P', 'file' => 'demo.yml', 'sha256' => self::SHA];
        $index = json_encode([$good, [...$good, 'id' => 'evil', 'file' => '../../etc/x.yml'], ['id' => 'x']]);
        $this->http([
            'https://o.example.com/t/index.json' => Http::response($index),
            'https://o.example.com/t/index.json.sig' => Http::response(base64_encode(sodium_crypto_sign_detached($index, $this->secret))),
        ]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $this->assertSame(['demo'], array_map(fn ($e) => $e->id, $s->entries()));
        $this->assertCount(2, $s->failures());
    }

    public function test_a_file_is_fetched_when_asked_checked_against_the_index_and_cached(): void
    {
        $this->fakeIndex('https://o.example.com/t', [['demo', 2]]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $def = $s->definition($s->find('demo'));
        $this->assertInstanceOf(Definition::class, $def);
        $this->assertSame(2, $def->version);

        $row = DB::table('template_cache')->first();
        $this->assertSame('https://o.example.com/t', $row->source);
        $this->assertSame('demo', $row->template_id);
        $this->assertSame(2, $row->version);
        $this->assertSame('Acme', $row->publisher);
        $this->assertSame(hash('sha256', $row->content), $row->sha256);

        $this->http([]); // a second ask is served from the cache, no request
        $s->definition($s->find('demo'));
        Http::assertNothingSent();
    }

    public function test_a_file_whose_hash_differs_from_the_index_is_refused_and_not_cached(): void
    {
        $this->fakeIndex('https://o.example.com/t', [['demo', 1]], override: ['https://o.example.com/t/demo.yml' => Http::response($this->yaml([...$this->nativeArray(), 'id' => 'demo', 'name' => 'Swapped']))]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        try {
            $s->definition($s->find('demo'));
            $this->fail('expected a refusal');
        } catch (FetchFailed $e) {
            $this->assertStringContainsString('does not match the sha256', $e->getMessage());
        }
        $this->assertSame(0, DB::table('template_cache')->count());
    }

    public function test_the_cache_serves_the_catalogue_when_the_source_is_down(): void
    {
        $this->fakeIndex('https://o.example.com/t', [['demo', 1], ['other', 3]]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $s->definition($s->find('demo'));
        $s->definition($s->find('other'));

        $this->http(['*' => fn () => throw new ConnectionException('down')]);
        $down = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $ids = array_map(fn ($e) => $e->id, $down->entries());
        sort($ids);
        $this->assertSame(['demo', 'other'], $ids);
        $this->assertSame(3, $down->find('other')->version);
        $this->assertNotNull($down->find('demo')->definition);
        $this->assertSame('Acme', $down->find('demo')->publisher);
        $this->assertStringContainsString('could not be reached', $down->failures()[0]['reason']);
    }

    public function test_a_cached_copy_that_no_longer_matches_its_hash_is_ignored(): void
    {
        $this->fakeIndex('https://o.example.com/t', [['demo', 1]]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $s->definition($s->find('demo'));
        DB::table('template_cache')->update(['content' => 'schema: 1']);

        $this->http(['*' => fn () => throw new ConnectionException('down')]);
        $down = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $this->assertSame([], $down->entries());
    }

    public function test_a_tampered_index_does_not_fall_back_to_the_cache(): void
    {
        $this->fakeIndex('https://o.example.com/t', [['demo', 1]]);
        $s = $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']]);
        $s->definition($s->find('demo'));

        $this->fakeIndex('https://o.example.com/t', [['demo', 1]], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->assertSame([], $this->sources([['kind' => 'official', 'url' => 'https://o.example.com/t']])->entries());
    }

    public function test_a_duplicate_id_from_a_lower_tier_is_refused(): void
    {
        $this->writeTemplate($this->dir.'/builtin', 'demo');
        $this->fakeIndex('https://o.example.com/t', [['demo', 9], ['solo', 1]]);
        $this->fakeIndex('https://c.example.com/t', [['demo', 9], ['solo', 1], ['mine', 1]], sign: false);

        $s = $this->sources([
            ['kind' => 'custom', 'url' => 'https://c.example.com/t'],
            ['kind' => 'community', 'url' => 'https://o.example.com/t'],
            ['kind' => 'builtin'],
        ]);
        $this->assertSame('builtin', $s->find('demo')->source);
        $this->assertSame('https://o.example.com/t', $s->find('solo')->source);
        $this->assertSame('community', $s->find('solo')->tier);
        $this->assertSame('https://c.example.com/t', $s->find('mine')->source);

        $refused = array_filter($s->failures(), fn ($f) => str_contains($f['reason'], 'refused, not shadowed'));
        $this->assertCount(3, $refused); // demo from community and custom, solo from custom
    }

    // ---- updates ---------------------------------------------------------

    private function installed(array $over = []): array
    {
        return [...['version' => 1, 'publisher' => 'Acme', 'download_host' => 'example.com', 'image_repo' => null], ...$over];
    }

    private function def(array $mutate = []): Definition
    {
        $d = $this->nativeArray();
        foreach ($mutate as $k => $v) {
            $d['runs'][$k] = $v;
        }

        return Definition::parse($this->yaml($d));
    }

    public function test_a_new_publisher_for_an_existing_id_is_refused(): void
    {
        $r = UpdateCheck::compare($this->installed(), $this->def(), 'Mallory');
        $this->assertFalse($r['allowed']);
        $this->assertStringContainsString('new publisher', $r['refused']);
    }

    public function test_an_update_that_keeps_the_download_host_needs_no_confirmation(): void
    {
        $r = UpdateCheck::compare($this->installed(), $this->def(), 'Acme');
        $this->assertTrue($r['allowed']);
        $this->assertFalse($r['confirm']);
        $this->assertSame(['version 1 to 2'], $r['changes']);
    }

    public function test_a_changed_download_host_raises_the_confirmation(): void
    {
        $r = UpdateCheck::compare($this->installed(), $this->def(['download' => 'https://downloads.other.net/demo.zip']), 'Acme');
        $this->assertTrue($r['allowed']);
        $this->assertTrue($r['confirm']);
        $this->assertStringContainsString('download host changed from example.com to downloads.other.net', implode(' ', $r['changes']));
    }

    public function test_a_changed_image_repository_raises_the_confirmation(): void
    {
        $c = Definition::parse($this->yaml($this->containerArray()));
        $same = UpdateCheck::compare($this->installed(['version' => 1, 'download_host' => null, 'image_repo' => 'example/demo']), $c, 'Acme');
        $this->assertFalse($same['confirm']);
        $moved = UpdateCheck::compare($this->installed(['download_host' => null, 'image_repo' => 'someone/else']), $c, 'Acme');
        $this->assertTrue($moved['confirm']);
        $this->assertStringContainsString('image repository changed', implode(' ', $moved['changes']));
    }

    public function test_a_version_that_is_not_newer_is_not_an_update(): void
    {
        $r = UpdateCheck::compare($this->installed(['version' => 2]), $this->def(), 'Acme');
        $this->assertFalse($r['allowed']);
    }
}
