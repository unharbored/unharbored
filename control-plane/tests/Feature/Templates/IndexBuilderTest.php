<?php

namespace Tests\Feature\Templates;

use App\Lib\Templates\SafeFetcher;
use App\Lib\Templates\Sources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class IndexBuilderTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    private string $root;

    private string $keyFile;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/cm-build-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/templates', 0777, true);
        mkdir($this->root.'/.github');
        $this->keyFile = $this->root.'/secret';
        Artisan::call('templates:keygen', ['secret-file' => $this->keyFile]);
        $this->public = trim(explode("\n", Artisan::output())[0]);
        file_put_contents($this->root.'/.github/CODEOWNERS', "# owners\n/templates/*.yml @acme/maintainers\n/templates/beta.yml @bob\n");
        $this->template('alpha');
        $this->template('beta');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function template(string $id, array $data = [], ?string $file = null): void
    {
        file_put_contents($this->root.'/templates/'.($file ?? $id).'.yml', $this->yaml(array_replace($this->nativeArray(), ['id' => $id], $data)));
    }

    private function build(array $extra = []): int
    {
        return Artisan::call('templates:build-index', ['dir' => $this->root.'/templates', '--key-file' => $this->keyFile] + $extra);
    }

    private function dist(): string
    {
        return $this->root.'/dist';
    }

    /** Serve the built index (or the given bodies) at a fake official URL; returns a fresh Sources. */
    private function sourcesFor(string $keys, ?string $index = null, ?string $sig = null): Sources
    {
        Http::swap(new Factory);
        Http::fake([
            'https://o.example.com/t/index.json' => Http::response($index ?? file_get_contents($this->dist().'/index.json')),
            'https://o.example.com/t/index.json.sig' => Http::response($sig ?? file_get_contents($this->dist().'/index.json.sig')),
            'https://o.example.com/t/alpha.yml' => Http::response(file_get_contents($this->dist().'/alpha.yml')),
            'https://o.example.com/t/beta.yml' => Http::response(file_get_contents($this->dist().'/beta.yml')),
        ]);
        app()->instance(SafeFetcher::class, new SafeFetcher(fn () => ['93.184.216.34']));
        config(['templates.sources' => [['kind' => 'community', 'url' => 'https://o.example.com/t']],
            'templates.community_public_key' => $keys, 'templates.allow_custom' => true]);

        return (new Sources(app(SafeFetcher::class)))->load();
    }

    private function randomPublic(): string
    {
        return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    }

    public function test_the_built_index_is_accepted_by_sources_end_to_end(): void
    {
        $this->assertSame(0, $this->build());
        $s = $this->sourcesFor($this->public);

        $this->assertSame([], $s->failures());
        $this->assertSame(['alpha', 'beta'], array_map(fn ($e) => $e->id, $s->entries()));
        $alpha = $s->find('alpha');
        $this->assertSame('@acme/maintainers', $alpha->publisher);
        $this->assertSame('@bob', $s->find('beta')->publisher);
        $this->assertSame('community', $alpha->tier);
        $this->assertSame('alpha', $s->definition($alpha)->id);
        $this->assertSame(['alpha.yml', 'beta.yml', 'index.json', 'index.json.sig'], array_values(array_diff(scandir($this->dist()), ['.', '..'])));
    }

    public function test_the_signature_verifies_and_tampering_or_a_wrong_key_is_refused(): void
    {
        $this->build();
        $index = file_get_contents($this->dist().'/index.json');
        $sig = base64_decode(trim(file_get_contents($this->dist().'/index.json.sig')));
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig, $index, base64_decode($this->public)));

        $flipped = $index;
        $flipped[5] = $flipped[5] === 'x' ? 'y' : 'x';
        foreach ([$this->sourcesFor($this->public, $flipped), $this->sourcesFor($this->randomPublic())] as $s) {
            $this->assertSame([], $s->entries());
            $this->assertStringContainsString('does not verify', $s->failures()[0]['reason']);
        }
    }

    public function test_a_comma_separated_key_list_accepts_a_signature_from_any_key(): void
    {
        $this->build();
        $old = $this->randomPublic();
        $this->assertCount(2, $this->sourcesFor("$old,{$this->public}")->entries());
        $this->assertCount(2, $this->sourcesFor("{$this->public}, $old")->entries());
        $this->assertSame([], $this->sourcesFor("$old,{$this->randomPublic()}")->entries());
    }

    public function test_an_invalid_template_aborts_and_writes_nothing(): void
    {
        $this->template('gamma', ['runs' => [...$this->nativeArray()['runs'], 'user' => 'root']]);
        $this->assertSame(1, $this->build());
        $this->assertStringContainsString('gamma.yml', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->dist());
    }

    public function test_a_missing_owner_fails(): void
    {
        file_put_contents($this->root.'/.github/CODEOWNERS', "/templates/alpha.yml @acme\n");
        $this->assertSame(1, $this->build());
        $this->assertStringContainsString('beta.yml: no owner', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->dist());
    }

    public function test_an_id_that_does_not_match_the_file_name_fails(): void
    {
        $this->template('delta', [], 'epsilon');
        $this->assertSame(1, $this->build());
        $this->assertStringContainsString('epsilon.yml: the file must be named delta.yml', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->dist());
    }

    public function test_a_missing_key_fails_and_the_key_from_the_environment_is_never_printed(): void
    {
        $this->assertSame(1, Artisan::call('templates:build-index', ['dir' => $this->root.'/templates', '--key-env' => 'CM_NO_SUCH_KEY_VAR']));
        $this->assertDirectoryDoesNotExist($this->dist());

        $secret = trim(file_get_contents($this->keyFile));
        putenv('CM_TEMPLATES_SIGNING_KEY='.$secret);
        $this->assertSame(0, Artisan::call('templates:build-index', ['dir' => $this->root.'/templates']));
        $this->assertStringNotContainsString($secret, Artisan::output());
        putenv('CM_TEMPLATES_SIGNING_KEY');
    }

    public function test_output_is_deterministic_and_sorted_by_id(): void
    {
        $this->build();
        $first = file_get_contents($this->dist().'/index.json');
        $this->build(['--out' => $this->root.'/dist2']);
        $this->assertSame($first, file_get_contents($this->root.'/dist2/index.json'));
        $list = json_decode($first, true);
        $this->assertSame(['alpha', 'beta'], array_column($list, 'id'));
        $this->assertSame(hash('sha256', file_get_contents($this->root.'/templates/alpha.yml')), $list[0]['sha256']);
    }
}
