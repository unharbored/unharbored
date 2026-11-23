<?php

declare(strict_types=1);

namespace App\Lib\Templates;

use InvalidArgumentException;

/**
 * One validated template (schema 1, docs/specs/templates.md section 2).
 * Immutable. Build it with parse() or fromFile(); both throw InvalidDefinition
 * carrying every error. render() fills the placeholders for one install and
 * checks each value against its input type.
 */
final class Definition
{
    public const INPUT_TYPES = ['slug', 'domain', 'size', 'choice', 'text', 'number', 'secret'];

    public const GENERATORS = ['word', 'password'];

    public const SLUG = '/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/D';

    private const PLACEHOLDER = '/\{\{\s*(.*?)\s*\}\}/';

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @param  list<array{key: string, generate: string}>  $secrets
     * @param  array<string, mixed>  $runs
     * @param  array<string, mixed>  $proxy
     * @param  list<string>  $requires
     * @param  list<array{port: int, proto: string, label: string}>  $extraPorts
     */
    private function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $name,
        public readonly string $category,
        public readonly string $description,
        public readonly string $memory,
        public readonly string $runtime,
        public readonly array $inputs,
        public readonly array $secrets,
        public readonly array $runs,
        public readonly array $proxy,
        public readonly array $requires,
        public readonly array $extraPorts,
        public readonly ?string $licence,
        public readonly ?string $source,
        public readonly ?string $acknowledge,
        /** @var array{stop: bool} stop the service around a data backup (default false: the copy is taken while it runs) */
        public readonly array $backup = ['stop' => false],
    ) {}

    /** @throws InvalidDefinition */
    public static function parse(string $yaml): self
    {
        $checker = new DefinitionChecker($yaml);
        $d = $checker->run();
        if ($d === null) {
            throw new InvalidDefinition($checker->errors());
        }

        return new self($d['id'], $d['version'], $d['name'], $d['category'], $d['description'], $d['memory'], $d['runtime'], $d['inputs'], $d['secrets'], $d['runs'], $d['proxy'], $d['requires'], $d['extra_ports'], $d['licence'], $d['source'], $d['acknowledge'], $d['backup']);
    }

    /** @throws InvalidDefinition */
    public static function fromFile(string $path): self
    {
        $yaml = @file_get_contents($path);
        if ($yaml === false) {
            throw new InvalidDefinition([['path' => '', 'line' => null, 'message' => 'the file could not be read']]);
        }

        return self::parse($yaml);
    }

    public function memoryBytes(): int
    {
        return (int) self::parseSize($this->memory);
    }

    /** Native: the host the program is downloaded from (compared when an update changes it). */
    public function downloadHost(): ?string
    {
        return isset($this->runs['download']) ? strtolower((string) parse_url($this->runs['download'], PHP_URL_HOST)) : null;
    }

    /** Container: the image without its tag or digest, e.g. louislam/uptime-kuma. */
    public function imageRepository(): ?string
    {
        if (! isset($this->runs['image'])) {
            return null;
        }
        $repo = explode('@', $this->runs['image'])[0];
        $slash = strrpos($repo, '/');
        $colon = strrpos($repo, ':');

        return $colon !== false && ($slash === false || $colon > $slash) ? substr($repo, 0, $colon) : $repo;
    }

    /**
     * What a person has to supply and what we generate, as referenced by runs:
     * the input keys and secret keys that appear in a placeholder.
     *
     * @return array{inputs: list<string>, secrets: list<string>}
     */
    public function needs(): array
    {
        $in = [];
        $sec = [];
        $runs = $this->runs;
        array_walk_recursive($runs, function ($v) use (&$in, &$sec) {
            if (! is_string($v)) {
                return;
            }
            preg_match_all(self::PLACEHOLDER, $v, $m);
            foreach ($m[1] as $ref) {
                if (str_starts_with($ref, 'inputs.')) {
                    $in[] = substr($ref, 7);
                } elseif (str_starts_with($ref, 'secrets.')) {
                    $sec[] = substr($ref, 8);
                }
            }
        });

        return ['inputs' => array_values(array_unique($in)), 'secrets' => array_values(array_unique($sec))];
    }

    /**
     * Check supplied input values (defaults fill the gaps) and return the
     * problems by input key. An empty array means the values fit.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function checkInputs(array $values): array
    {
        $errors = [];
        $known = array_column($this->inputs, null, 'key');
        foreach ($values as $k => $_) {
            if (! isset($known[$k])) {
                $errors[(string) $k] = 'is not an input of this template';
            }
        }
        foreach ($known as $key => $input) {
            if (! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') {
                if (! array_key_exists('default', $input) && ! isset($input['generate'])) {
                    $errors[$key] = 'is required';
                }

                continue;
            }
            try {
                self::coerce($input, $values[$key]);
            } catch (InvalidArgumentException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Fill the placeholders of runs for one install. $inputs are the person's
     * values, $secrets the generated ones (by key). $binaryPath is what
     * {{ binary }} becomes (the unpacked file's full path on the server; the
     * relative name from the definition when not given).
     *
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string>  $secrets
     * @return array<string, mixed> runs with every string filled; ports as port => domain
     *
     * @throws InvalidArgumentException when a value does not fit its input
     */
    public function render(array $inputs, array $secrets = [], ?string $binaryPath = null): array
    {
        $errors = $this->checkInputs($inputs);
        foreach ($this->secrets as $s) {
            if (! isset($secrets[$s['key']]) || ! is_string($secrets[$s['key']]) || $secrets[$s['key']] === '') {
                $errors['secrets.'.$s['key']] = 'is missing';
            }
        }
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', array_map(fn ($k, $m) => "$k $m", array_keys($errors), $errors)));
        }

        $values = [];
        foreach ($this->inputs as $input) {
            $v = $inputs[$input['key']] ?? null;
            $values['inputs.'.$input['key']] = self::coerce($input, $v === null || $v === '' ? $input['default'] : $v);
        }
        foreach ($this->secrets as $s) {
            $values['secrets.'.$s['key']] = $secrets[$s['key']];
        }
        $values['name'] = $values['inputs.name'];

        $fill = function (string $s) use (&$values): string {
            return preg_replace_callback(self::PLACEHOLDER, fn ($m) => $values[$m[1]] ?? throw new InvalidArgumentException("no value for {{ {$m[1]} }}"), $s);
        };

        $runs = $this->runs;
        if ($this->runtime === 'native') {
            $values['data'] = $fill($runs['data']);
            $values['binary'] = $binaryPath ?? $runs['binary'];
            $runs['data'] = $values['data'];
            $runs['command'] = $fill($runs['command']);
        } else {
            $runs['volumes'] = array_map($fill, $runs['volumes']);
        }
        $runs['env'] = array_map($fill, $runs['env']);
        $runs['ports'] = array_map($fill, $runs['ports']);

        return $runs;
    }

    /**
     * Check one value against an input's type and return it in the form it is
     * rendered in. Throws with a message a person can read.
     *
     * @param  array<string, mixed>  $input
     */
    public static function coerce(array $input, mixed $value): string
    {
        $type = $input['type'];
        if (! is_scalar($value) || is_bool($value)) {
            throw new InvalidArgumentException('must be a single value');
        }
        $v = trim((string) $value);

        switch ($type) {
            case 'slug':
                if (! preg_match(self::SLUG, $v)) {
                    throw new InvalidArgumentException('must be a slug: lower case letters, digits and -, up to 40 characters');
                }

                return $v;
            case 'domain':
                $v = strtolower($v);
                if (! self::isHostname($v)) {
                    throw new InvalidArgumentException('must be a domain name such as files.example.com');
                }

                return $v;
            case 'size':
                if (self::parseSize($v) === null) {
                    throw new InvalidArgumentException('must be a size such as 512MB or 50GB');
                }

                return strtoupper(str_replace(' ', '', $v));
            case 'choice':
                if (! in_array($v, $input['options'] ?? [], true)) {
                    throw new InvalidArgumentException('must be one of '.implode(', ', $input['options'] ?? []));
                }

                return $v;
            case 'number':
                if (! is_numeric($v) || abs((float) $v) > 1e12) {
                    throw new InvalidArgumentException('must be a number');
                }

                return $v;
            case 'text':
            case 'secret':
                if ($v === '' || strlen($v) > 500 || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                    throw new InvalidArgumentException('must be 1 to 500 characters on one line');
                }

                return $v;
        }
        throw new InvalidArgumentException("unknown input type $type");
    }

    /** "50GB", "512 MB", "1.5GB" to bytes (1024 based), or null when it is not a size. */
    public static function parseSize(string $s): ?int
    {
        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*(MB|GB|TB)\s*$/i', $s, $m) || (float) $m[1] <= 0) {
            return null;
        }

        return (int) round((float) $m[1] * (1024 ** ['MB' => 2, 'GB' => 3, 'TB' => 4][strtoupper($m[2])]));
    }

    public static function isHostname(string $h): bool
    {
        if (strlen($h) > 253 || ! str_contains($h, '.') || filter_var($h, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (explode('.', $h) as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) {
                return false;
            }
        }

        return ! preg_match('/^\d+$/D', substr($h, (int) strrpos($h, '.') + 1));
    }
}
