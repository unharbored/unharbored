<?php

declare(strict_types=1);

namespace Tests\Feature\Git;

use App\Lib\Git\AppDetector;
use App\Lib\Git\GitSources;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Detection is a suggestion read from a folder's own files: what it finds, what it declines to call an application, and how a
 * repository with several is searched.
 */
final class AppDetectorTest extends TestCase
{
    /** @param array<string, string> $files */
    private function reader(array $files): callable
    {
        return fn (string $path): ?string => $files[$path] ?? null;
    }

    private function inspect(array $files): ?array
    {
        return AppDetector::inspect($this->reader($files));
    }

    public function test_laravel_is_read_from_composer(): void
    {
        $app = $this->inspect(['composer.json' => '{"require":{"laravel/framework":"^12.0"}}']);

        $this->assertSame(['php', 'Laravel 12.0', 'composer.json'], [$app['runtime'], $app['label'], $app['file']]);
    }

    public function test_a_php_library_and_php_that_is_not_laravel_are_not_run_as_laravel(): void
    {
        $library = $this->inspect(['composer.json' => '{"name":"acme/lib","type":"library","require":{"php":"^8.2"}}']);
        $this->assertNull($library['runtime']);
        $this->assertTrue($library['library']);

        $other = $this->inspect(['composer.json' => '{"type":"project","require":{"symfony/framework-bundle":"^7"}}']);
        $this->assertNull($other['runtime'], 'Laravel is the PHP we know how to build, so it is not offered as Laravel.');
        $this->assertFalse($other['library']);
    }

    public function test_go_is_read_from_go_mod_with_its_version(): void
    {
        $app = $this->inspect(['go.mod' => "module acme/api\n\ngo 1.24\n"]);

        $this->assertSame(['go', 'Go 1.24'], [$app['runtime'], $app['label']]);
        $this->assertSame(['version' => '1.24'], $app['config']);
        $this->assertSame([], $this->inspect(['go.mod' => "module acme/api\n"])['config']);
    }

    public function test_node_with_a_start_script_or_a_server_package_is_node(): void
    {
        $this->assertSame('node', $this->inspect(['package.json' => '{"scripts":{"start":"node server.js"}}'])['runtime']);
        $express = $this->inspect(['package.json' => '{"dependencies":{"express":"^4"}}']);
        $this->assertSame(['node', 'Node · Express'], [$express['runtime'], $express['label']]);
        $this->assertSame('Next.js', $this->inspect(['package.json' => '{"scripts":{"build":"next build","start":"next start"},"dependencies":{"next":"15"}}'])['label']);
    }

    public function test_a_front_end_that_builds_to_a_folder_is_a_static_site_with_the_folder_and_spa_fallback(): void
    {
        $vite = $this->inspect(['package.json' => '{"scripts":{"build":"vite build"},"devDependencies":{"vite":"^6"},"dependencies":{"react":"19"}}']);
        $this->assertSame('static', $vite['runtime']);
        $this->assertSame(['install' => '', 'build' => 'npm run build', 'output' => 'dist', 'spa' => true], $vite['config']);

        $docs = $this->inspect(['package.json' => '{"scripts":{"build":"vitepress build"},"devDependencies":{"vitepress":"1"}}']);
        $this->assertSame('.vitepress/dist', $docs['config']['output']);
        $this->assertFalse($docs['config']['spa']);

        $cra = $this->inspect(['package.json' => '{"scripts":{"build":"react-scripts build"},"dependencies":{"react-scripts":"5","react":"18"}}']);
        $this->assertSame('build', $cra['config']['output']);
    }

    public function test_an_index_html_alone_is_a_static_site_and_a_library_is_not_deployable(): void
    {
        $this->assertSame(['static', '.'], [$this->inspect(['index.html' => '<h1>hi</h1>'])['runtime'], ($this->inspect(['index.html' => 'x'])['config']['output'] ?? '.')]);
        $lib = $this->inspect(['package.json' => '{"name":"ui","main":"dist/index.js","scripts":{"build":"tsc"}}']);
        $this->assertNull($lib['runtime']);
        $this->assertTrue($lib['library']);
        $this->assertNull($this->inspect(['README.md' => 'nothing here']));
    }

    public function test_a_monorepo_lists_each_application_and_leaves_the_choice_to_the_person(): void
    {
        $files = [
            'go.mod' => "module cm\n\ngo 1.25\n",
            'control-plane/composer.json' => '{"require":{"laravel/framework":"^13.0"}}',
            'control-plane/package.json' => '{"scripts":{"build":"vite build"},"devDependencies":{"vite":"6"}}',
            'examples/demo-app/composer.json' => '{"require":{"laravel/framework":"^12.0"}}',
            'packages/ui/package.json' => '{"name":"ui","main":"index.js"}',
            'control-plane/vendor/acme/lib/composer.json' => '{"name":"acme/lib"}',
            'control-plane/node_modules/x/package.json' => '{"name":"x"}',
            'control-plane/public/index.html' => '<p>not a site of its own</p>',
        ];
        $apps = AppDetector::scan(array_keys($files), $this->reader($files));

        $this->assertSame(['', 'control-plane', 'examples/demo-app', 'packages/ui'], array_column($apps, 'dir'));
        $this->assertSame(['go', 'php', 'php', null], array_column($apps, 'runtime'));
        $this->assertSame('Laravel 13.0', $apps[1]['label'], 'A folder with composer.json and package.json is the PHP application; its Vite build is part of it.');
        $this->assertTrue($apps[3]['library']);
        $this->assertNull(AppDetector::suggest($apps), 'Several applications: the person picks, nothing is chosen for them.');
    }

    public function test_the_only_application_is_suggested(): void
    {
        $files = ['apps/web/composer.json' => '{"require":{"laravel/framework":"^12"}}', 'packages/ui/package.json' => '{"name":"ui","main":"i.js"}'];
        $apps = AppDetector::scan(array_keys($files), $this->reader($files));

        $this->assertSame('apps/web', AppDetector::suggest($apps)['dir']);
    }

    public function test_an_index_html_deep_in_a_tree_or_in_a_support_folder_is_not_a_site(): void
    {
        $files = ['index.html' => 'home', 'docs/index.html' => 'x', 'a/b/c/index.html' => 'x', 'tests/index.html' => 'x', 'site/index.html' => 'a site'];
        $apps = AppDetector::scan(array_keys($files), $this->reader($files));

        $this->assertSame(['', 'site'], array_column($apps, 'dir'));
    }

    public function test_a_public_github_repository_is_searched_through_its_file_list(): void
    {
        Http::fake([
            'api.github.com/repos/acme/platform/git/trees/main*' => Http::response(['tree' => [
                ['path' => 'apps/api/go.mod', 'type' => 'blob'], ['path' => 'apps/web/composer.json', 'type' => 'blob'], ['path' => 'apps', 'type' => 'tree'],
            ]]),
            'raw.githubusercontent.com/acme/platform/main/apps/api/go.mod' => Http::response("module api\n\ngo 1.24\n"),
            'raw.githubusercontent.com/acme/platform/main/apps/web/composer.json' => Http::response('{"require":{"laravel/framework":"^12"}}'),
            'raw.githubusercontent.com/*' => Http::response('', 404),
        ]);

        $found = $this->app->make(GitSources::class)->scan('org-1', 'https://github.com/acme/platform', 'https', 'main');

        $this->assertTrue($found['listed']);
        $this->assertSame(['apps/api', 'apps/web'], array_column($found['apps'], 'dir'));
        $this->assertSame('Go 1.24', $found['apps'][0]['label']);
    }

    public function test_where_only_the_top_can_be_read_that_is_all_that_is_looked_at_and_a_named_folder_is_inspected_alone(): void
    {
        Http::fake([
            'gitlab.com/acme/platform/-/raw/main/composer.json' => Http::response('{"require":{"laravel/framework":"^12"}}'),
            'gitlab.com/acme/platform/-/raw/main/services/billing/go.mod' => Http::response("module billing\n\ngo 1.23\n"),
            'gitlab.com/*' => Http::response('', 404),
        ]);
        $sources = $this->app->make(GitSources::class);

        $found = $sources->scan('org-1', 'https://gitlab.com/acme/platform', 'https', 'main');
        $this->assertFalse($found['listed']);
        $this->assertSame([''], array_column($found['apps'], 'dir'));

        $billing = $sources->inspectDir('org-1', 'https://gitlab.com/acme/platform', 'https', 'main', 'services/billing');
        $this->assertSame(['services/billing', 'go'], [$billing['dir'], $billing['runtime']]);
        $this->assertNull($sources->inspectDir('org-1', 'https://gitlab.com/acme/platform', 'https', 'main', 'nothing/here'));
    }
}
