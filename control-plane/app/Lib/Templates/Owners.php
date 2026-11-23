<?php

declare(strict_types=1);

namespace App\Lib\Templates;

/**
 * A CODEOWNERS file, read for one question: who owns this path. The last
 * matching line wins; the first owner on it is the publisher. Supports the
 * simple patterns the community repository uses: /templates/foo.yml,
 * /templates/*.yml, a bare name (matches in any folder), and a trailing slash
 * for a folder. Comments (#) and blank lines are ignored.
 */
final class Owners
{
    /** @var list<array{regex: string, owners: list<string>}> */
    private array $rules = [];

    public function __construct(string $contents)
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim(preg_replace('/(^|\s)#.*$/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', $line) ?: [];
            $pattern = (string) array_shift($tokens);
            $this->rules[] = ['regex' => self::regex($pattern), 'owners' => array_values($tokens)];
        }
    }

    /** The first owner on the last line matching $path (like "/templates/foo.yml"), or null. */
    public function publisher(string $path): ?string
    {
        $path = '/'.ltrim($path, '/');
        $owner = null;
        foreach ($this->rules as $rule) {
            if (preg_match($rule['regex'], $path)) {
                $owner = $rule['owners'][0] ?? null;   // a matching line with no owners clears ownership, as on GitHub
            }
        }

        return $owner;
    }

    private static function regex(string $pattern): string
    {
        $folder = str_ends_with($pattern, '/');
        $body = trim($pattern, '/');
        $prefix = (! str_starts_with($pattern, '/') && ! str_contains($body, '/')) ? '(?:.*/)?' : '';
        $re = preg_quote($body, '#');
        $re = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '[^/]'], $re);

        return '#^/'.$prefix.$re.($folder ? '/.*' : '(?:/.*)?').'$#';
    }
}
