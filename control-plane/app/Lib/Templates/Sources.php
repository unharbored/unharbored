<?php

declare(strict_types=1);

namespace App\Lib\Templates;

use Illuminate\Support\Facades\DB;

/**
 * Reads config('templates.sources') in order and builds the catalogue
 * (docs/specs/templates.md section 3). Every file goes through the same
 * validator. A file or index that fails validation or its signature is not
 * offered; it is listed in failures() for the catalogue page.
 *
 * Trust: built-in beats official beats community beats custom. A duplicate id
 * from an equal or lower tier is refused, never shadowed. Remote files are
 * fetched only when definition() is asked for one, checked against the hash in
 * the index, and cached in template_cache.
 *
 * Signatures are raw ed25519 (libsodium): the public key and the detached
 * signature in index.json.sig are base64. This is NOT minisign format.
 */
final class Sources
{
    /** kind => [tier, rank (lower wins)] */
    private const KINDS = [
        'builtin' => ['official', 0],
        'official' => ['official', 1],
        'community' => ['community', 2],
        'custom' => ['custom', 3],
        'local' => ['custom', 3],
    ];

    /** @var array<string, Entry> */
    private array $entries = [];

    /** @var list<array{source: string, file: string, line: ?int, reason: string}> */
    private array $failures = [];

    private bool $loaded = false;

    public function __construct(private readonly SafeFetcher $fetcher) {}

    /** @return list<Entry> */
    public function entries(): array
    {
        $this->load();

        return array_values($this->entries);
    }

    /** @return list<array{source: string, file: string, line: ?int, reason: string}> "Templates that could not be loaded" */
    public function failures(): array
    {
        $this->load();

        return $this->failures;
    }

    public function find(string $id): ?Entry
    {
        $this->load();

        return $this->entries[$id] ?? null;
    }

    public function load(): self
    {
        if ($this->loaded) {
            return $this;
        }
        $this->loaded = true;
        $this->entries = $this->failures = [];

        $sources = array_values(config('templates.sources', []));
        usort($sources, fn ($a, $b) => (self::KINDS[$a['kind'] ?? '']['1'] ?? 9) <=> (self::KINDS[$b['kind'] ?? '']['1'] ?? 9));

        foreach ($sources as $s) {
            $kind = $s['kind'] ?? '';
            if (! isset(self::KINDS[$kind])) {
                $this->fail((string) json_encode($s), '', null, "unknown source kind \"$kind\"");

                continue;
            }
            [$tier] = self::KINDS[$kind];
            $name = $kind === 'local' ? (string) config('templates.path') : ($s['url'] ?? $kind);

            if ($tier === 'custom' && ! config('templates.allow_custom')) {
                $this->fail($name, '', null, 'custom sources (a custom URL or a local folder) are switched off on this install');

                continue;
            }

            match ($kind) {
                'builtin' => $this->folder((string) config('templates.builtin_path'), 'builtin', $tier, 'Cloud Manager', null),
                'local' => config('templates.path') === '' ? null : $this->folder((string) config('templates.path'), (string) config('templates.path'), $tier, 'Local folder', 'Custom, not reviewed'),
                default => $this->index((string) ($s['url'] ?? ''), $kind, $tier),
            };
        }

        return $this;
    }

    /**
     * The full definition of an entry. Fetches and verifies the file for a
     * remote entry (cache first), never for a built-in or local one.
     *
     * @throws FetchFailed when the file cannot be fetched or does not match the index
     * @throws InvalidDefinition
     */
    public function definition(Entry $entry): Definition
    {
        if ($entry->definition !== null) {
            return $entry->definition;
        }

        $cached = DB::table('template_cache')->where(['source' => $entry->source, 'template_id' => $entry->id, 'version' => $entry->version])->first();
        if ($cached && $cached->sha256 === $entry->sha256 && hash('sha256', $cached->content) === $cached->sha256) {
            return Definition::parse($cached->content);
        }

        $body = $this->fetcher->get((string) $entry->file, SafeFetcher::MAX_FILE);
        if (! hash_equals((string) $entry->sha256, hash('sha256', $body))) {
            throw new FetchFailed($entry->file.' does not match the sha256 in the index');
        }
        $def = Definition::parse($body);
        if ($def->id !== $entry->id || $def->version !== $entry->version) {
            throw new FetchFailed($entry->file.' is not the id and version the index lists');
        }

        DB::table('template_cache')->updateOrInsert(
            ['source' => $entry->source, 'template_id' => $entry->id, 'version' => $entry->version],
            ['sha256' => $entry->sha256, 'publisher' => $entry->publisher, 'content' => $body, 'fetched_at' => now()],
        );

        return $def;
    }

    /**
     * The definition if it is on hand without a request: a built-in or local one, or a remote one already in the cache.
     * Null otherwise. "Is an update available" uses this, so a page load never fetches.
     */
    public function cachedDefinition(Entry $entry): ?Definition
    {
        if ($entry->definition !== null) {
            return $entry->definition;
        }
        $cached = DB::table('template_cache')->where(['source' => $entry->source, 'template_id' => $entry->id, 'version' => $entry->version])->first();
        if ($cached && $cached->sha256 === $entry->sha256 && hash('sha256', $cached->content) === $cached->sha256) {
            return Definition::parse($cached->content);
        }

        return null;
    }

    // ---- sources ---------------------------------------------------------

    private function folder(string $dir, string $source, string $tier, string $publisher, ?string $label): void
    {
        if (! is_dir($dir)) {
            $this->fail($source, '', null, "the folder $dir does not exist");

            return;
        }
        $files = glob(rtrim($dir, '/').'/*.yml') ?: [];
        sort($files);
        foreach ($files as $path) {
            $file = basename($path);
            if (! is_file($path) || filesize($path) > SafeFetcher::MAX_FILE) {
                $this->fail($source, $file, null, 'not a regular file, or larger than '.SafeFetcher::MAX_FILE.' bytes');

                continue;
            }
            try {
                $def = Definition::fromFile($path);
            } catch (InvalidDefinition $e) {
                foreach ($e->errors as $err) {
                    $this->fail($source, $file, $err['line'], ($err['path'] === '' ? '' : $err['path'].': ').$err['message']);
                }

                continue;
            }
            if ($file !== $def->id.'.yml') {
                $this->fail($source, $file, null, "the file must be named {$def->id}.yml after its id");

                continue;
            }
            $this->add(new Entry($def->id, $def->version, $def->name, $def->category, $publisher, $source, $tier, $label, $def));
        }
    }

    private function index(string $url, string $kind, string $tier): void
    {
        $base = rtrim($url, '/');
        $label = match ($tier) {
            'community' => 'Community',
            'custom' => 'Custom, not reviewed, unsigned',
            default => null,
        };

        try {
            $raw = $this->fetcher->get("$base/index.json", SafeFetcher::MAX_INDEX);
            $sig = $tier === 'custom' ? null : $this->fetcher->get("$base/index.json.sig", 4096);
        } catch (FetchFailed $e) {
            $this->fail($base, 'index.json', null, $e->getMessage().'; showing what was fetched before');
            $this->fromCache($base, $tier, $label);

            return;
        }

        if ($sig !== null && ($why = $this->unverified($raw, $sig, (string) config("templates.{$kind}_public_key"))) !== null) {
            $this->fail($base, 'index.json', null, $why);

            return;
        }

        $list = json_decode($raw, true);
        if (! is_array($list) || ! array_is_list($list)) {
            $this->fail($base, 'index.json', null, 'the index is not a JSON list');

            return;
        }
        foreach ($list as $i => $e) {
            $problem = self::indexEntryProblem($e);
            if ($problem !== null) {
                $this->fail($base, "index.json[$i]", null, $problem);

                continue;
            }
            $this->add(new Entry($e['id'], $e['version'], $e['name'], $e['category'], $e['publisher'], $base, $tier, $label, null, "$base/{$e['file']}", $e['sha256']));
        }
    }

    /** The source is down: offer the files we fetched earlier, each re-checked against the hash stored with it. */
    private function fromCache(string $base, string $tier, ?string $label): void
    {
        $seen = [];
        foreach (DB::table('template_cache')->where('source', $base)->orderByDesc('version')->get() as $row) {
            if (isset($seen[$row->template_id])) {
                continue;
            }
            $seen[$row->template_id] = true;
            if (hash('sha256', $row->content) !== $row->sha256) {
                $this->fail($base, $row->template_id, null, 'the cached copy does not match its hash and was ignored');

                continue;
            }
            try {
                $def = Definition::parse($row->content);
            } catch (InvalidDefinition) {
                $this->fail($base, $row->template_id, null, 'the cached copy no longer validates and was ignored');

                continue;
            }
            $this->add(new Entry($def->id, $def->version, $def->name, $def->category, $row->publisher, $base, $tier, $label, $def));
        }
    }

    // ---- helpers ---------------------------------------------------------

    private function add(Entry $e): void
    {
        if (isset($this->entries[$e->id])) {
            $this->fail($e->source, $e->id, null, "the id \"{$e->id}\" is already provided by {$this->entries[$e->id]->source} (same or higher trust); this one is refused, not shadowed");

            return;
        }
        $this->entries[$e->id] = $e;
    }

    /** $keys is one base64 public key, or several separated by commas (during a key rotation); a signature from any one is accepted. */
    private function unverified(string $raw, string $sig, string $keys): ?string
    {
        $pks = [];
        foreach (array_filter(array_map('trim', explode(',', $keys))) as $key) {
            $pk = base64_decode($key, true);
            if ($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $pks[] = $pk;
            }
        }
        if ($pks === []) {
            return 'no valid public key is configured for this source, so its index cannot be verified';
        }
        $signature = base64_decode(trim($sig), true);
        if ($signature !== false && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES) {
            foreach ($pks as $pk) {
                if (sodium_crypto_sign_verify_detached($signature, $raw, $pk)) {
                    return null;
                }
            }
        }

        return 'the index signature does not verify; the index was refused';
    }

    private static function indexEntryProblem(mixed $e): ?string
    {
        $keys = ['id', 'version', 'name', 'category', 'publisher', 'file', 'sha256'];
        if (! is_array($e) || array_diff($keys, array_keys($e)) !== [] || array_diff(array_keys($e), $keys) !== []) {
            return 'an index entry must be exactly { '.implode(', ', $keys).' }';
        }
        if (! is_string($e['id']) || ! preg_match(Definition::SLUG, $e['id']) || ! is_int($e['version']) || $e['version'] < 1) {
            return 'bad id or version';
        }
        foreach (['name', 'category', 'publisher'] as $k) {
            if (! is_string($e[$k]) || trim($e[$k]) === '') {
                return "bad $k";
            }
        }
        if (! is_string($e['file']) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.yml$/', $e['file'])) {
            return 'file must be a plain file name beside the index';
        }
        if (! is_string($e['sha256']) || ! preg_match('/^[a-f0-9]{64}$/', $e['sha256'])) {
            return 'sha256 must be 64 hex characters';
        }

        return null;
    }

    private function fail(string $source, string $file, ?int $line, string $reason): void
    {
        $this->failures[] = ['source' => $source, 'file' => $file, 'line' => $line, 'reason' => $reason];
    }
}
