<?php

declare(strict_types=1);

namespace App\Lib\Git;

/**
 * What kind of application a repository folder holds, read from its own files,
 * and which folders of a repository look like applications.
 *
 * A suggestion, never a decision: the wizard fills in what this finds and the
 * person can change any of it. It reads a few small files (composer.json,
 * package.json, go.mod, index.html) through a reader the caller supplies, so
 * the same code serves a GitHub App repository, a public one and a clone.
 */
final class AppDetector
{
    /** Files that make a folder worth looking at. */
    private const MANIFESTS = ['composer.json', 'package.json', 'go.mod', 'index.html'];

    /** Folders never searched: dependencies and build output. */
    private const SKIP = ['node_modules', 'vendor', '.git', 'dist', 'build', 'public', 'storage', 'bootstrap', 'target'];

    /** An index.html in one of these is part of something else, not a site of its own. */
    private const NOT_A_SITE = ['src', 'test', 'tests', 'fixtures', 'resources', 'static', 'assets', 'templates', 'views', 'examples', 'docs'];

    private const SERVER_DEPS = ['express', 'fastify', 'koa', 'hono', '@nestjs/core', 'next', 'nuxt', '@hapi/hapi'];

    /** Tools that build a folder of files, and where each writes it. */
    private const SITE_BUILDERS = ['react-scripts' => 'build', 'gatsby' => 'public', '@11ty/eleventy' => '_site', '@docusaurus/core' => 'build', 'vitepress' => '.vitepress/dist',
        'vite' => 'dist', '@vue/cli-service' => 'dist', 'astro' => 'dist', 'parcel' => 'dist'];

    private const MAX_DEPTH = 3;

    private const MAX_APPS = 12;

    /**
     * The applications a repository holds, from its file list: the top first, then by depth and name.
     *
     * @param  list<string>  $paths  every file, relative to the repository
     * @param  callable(string): ?string  $read  a file's text from the repository, or null
     * @return list<array{dir: string, runtime: ?string, label: string, file: string, library: bool, config: array<string, mixed>}>
     */
    public static function scan(array $paths, callable $read): array
    {
        $dirs = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (! in_array($name, self::MANIFESTS, true)) {
                continue;
            }
            $dir = trim(dirname($path), './');
            $parts = $dir === '' ? [] : explode('/', $dir);
            if (count($parts) > self::MAX_DEPTH || array_intersect($parts, self::SKIP) !== [] || preg_grep('/^\./', $parts) !== []) {
                continue;
            }
            $dirs[$dir][] = $name;
        }

        $found = [];
        foreach ($dirs as $dir => $files) {
            $dir = (string) $dir;
            // A lone index.html is a site only where it is plainly meant to be one.
            if ($files === ['index.html'] && $dir !== '' && (count(explode('/', $dir)) > 2 || array_intersect(explode('/', $dir), self::NOT_A_SITE) !== [])) {
                continue;
            }
            $app = self::inspect($dir === '' ? $read : fn (string $file): ?string => $read("{$dir}/{$file}"));
            if ($app !== null) {
                $found[] = ['dir' => $dir] + $app;
            }
        }

        usort($found, fn ($a, $b) => [substr_count($a['dir'], '/') + ($a['dir'] === '' ? -1 : 0), $a['dir']] <=> [substr_count($b['dir'], '/') + ($b['dir'] === '' ? -1 : 0), $b['dir']]);

        return array_slice($found, 0, self::MAX_APPS);
    }

    /**
     * What one folder holds, or null when it holds nothing that looks like an application.
     *
     * runtime: php, go, node or static (null for a library or something we do not run);
     * config: suggested runtime settings, such as the folder a static site is served from.
     *
     * @param  callable(string): ?string  $read  a file's text from this folder
     * @return array{runtime: ?string, label: string, file: string, library: bool, config: array<string, mixed>}|null
     */
    public static function inspect(callable $read): ?array
    {
        $composer = self::json($read('composer.json'));
        if ($composer !== []) {
            $require = $composer['require'] ?? [];
            if (isset($require['laravel/framework'])) {
                $version = preg_replace('/^[^0-9]*/', '', (string) $require['laravel/framework']);

                return self::app('php', 'Laravel'.($version !== '' ? ' '.$version : ''), 'composer.json');
            }
            if (($composer['type'] ?? 'library') === 'library') {
                return self::app(null, 'A PHP library', 'composer.json', library: true);
            }

            return self::app(null, 'PHP, not Laravel', 'composer.json');
        }

        $gomod = $read('go.mod');
        if ($gomod !== null && $gomod !== '') {
            // The go line is the version the module needs: suggested, so the build uses it rather than the newest Go.
            $version = preg_match('/^go\s+(\d+(?:\.\d+){1,2})\s*$/m', $gomod, $m) === 1 ? $m[1] : null;

            return self::app('go', 'Go'.($version !== null ? ' '.$version : ''), 'go.mod', config: $version !== null ? ['version' => $version] : []);
        }

        $package = self::json($read('package.json'));
        if ($package !== []) {
            return self::node($package);
        }

        if ($read('index.html') !== null) {
            return self::app('static', 'Static site', 'index.html');
        }

        return null;
    }

    /** Compose files we notice and never run. */
    private const COMPOSE_FILES = ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'];

    /**
     * A Dockerfile in a folder (docs/specs/templates.md, step 6), for the container wizard: its path, the port its first EXPOSE line
     * names (null when it has none or only a variable), every port it exposes, and the Compose file beside it, if any, which is
     * noticed and never run. Null when there is no Dockerfile at that path.
     *
     * @param  callable(string): ?string  $read  a file's text from the folder
     * @return array{file: string, port: ?int, ports: list<int>, compose: ?string}|null
     */
    public static function dockerfile(callable $read, string $file = 'Dockerfile'): ?array
    {
        $text = $read($file);
        if ($text === null || trim($text) === '') {
            return null;
        }
        $ports = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (! preg_match('/^\s*EXPOSE\s+(.+?)\s*(#.*)?$/i', $line, $m)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $token) {
                // 8080, 8080/tcp, 3000-3005 (the first of a range); a variable such as $PORT says nothing we can use.
                if (preg_match('#^(\d{1,5})(?:-\d{1,5})?(?:/(?:tcp|udp))?$#i', $token, $p) && (int) $p[1] >= 1 && (int) $p[1] <= 65535 && ! str_ends_with(strtolower($token), '/udp')) {
                    $ports[] = (int) $p[1];
                }
            }
        }
        $ports = array_values(array_unique($ports));
        $compose = null;
        foreach (self::COMPOSE_FILES as $candidate) {
            if (($read($candidate) ?? '') !== '') {
                $compose = $candidate;
                break;
            }
        }

        return ['file' => $file, 'port' => $ports[0] ?? null, 'ports' => $ports, 'compose' => $compose];
    }

    /** @param array<string, mixed> $package */
    private static function node(array $package): array
    {
        $scripts = (array) ($package['scripts'] ?? []);
        $deps = (array) ($package['dependencies'] ?? []) + (array) ($package['devDependencies'] ?? []);

        $server = array_values(array_intersect(self::SERVER_DEPS, array_keys($deps)));
        if (isset($scripts['start']) || $server !== []) {
            $label = match ($server[0] ?? null) {
                'next' => 'Next.js',
                'nuxt' => 'Nuxt',
                'express' => 'Node · Express',
                'fastify' => 'Node · Fastify',
                '@nestjs/core' => 'Node · NestJS',
                default => 'Node',
            };

            return self::app('node', $label, 'package.json');
        }

        foreach (self::SITE_BUILDERS as $tool => $output) {
            if (isset($deps[$tool]) && isset($scripts['build'])) {
                $spa = in_array($tool, ['vite', 'react-scripts', '@vue/cli-service'], true) && array_intersect(['react', 'vue', 'svelte', 'solid-js', 'preact'], array_keys($deps)) !== [];

                return self::app('static', 'Static site · '.($tool === 'react-scripts' ? 'Create React App' : ucfirst(ltrim($tool, '@'))).' · npm run build', 'package.json', config: ['install' => '', 'build' => 'npm run build', 'output' => $output, 'spa' => $spa]);
            }
        }

        if (isset($package['main']) || isset($package['exports']) || isset($package['module']) || isset($package['types'])) {
            return self::app(null, 'A JavaScript library', 'package.json', library: true);
        }

        return self::app(null, 'package.json with no start or build script', 'package.json', library: ! isset($scripts['build']));
    }

    /**
     * The one to pick for the person: the only application there is. With several, none, so they choose.
     *
     * @param  list<array<string, mixed>>  $apps
     * @return array<string, mixed>|null
     */
    public static function suggest(array $apps): ?array
    {
        $usable = array_values(array_filter($apps, fn ($a) => $a['runtime'] !== null));

        return count($usable) === 1 ? $usable[0] : null;
    }

    /** @return array{runtime: ?string, label: string, file: string, library: bool, config: array<string, mixed>} */
    private static function app(?string $runtime, string $label, string $file, bool $library = false, array $config = []): array
    {
        return ['runtime' => $runtime, 'label' => $label, 'file' => $file, 'library' => $library, 'config' => $config];
    }

    /** @return array<string, mixed> */
    private static function json(?string $text): array
    {
        $data = $text === null ? null : json_decode($text, true);

        return is_array($data) ? $data : [];
    }
}
