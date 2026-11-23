<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Templates\Definition;
use App\Lib\Templates\InvalidDefinition;
use App\Lib\Templates\Owners;
use App\Lib\Templates\SafeFetcher;
use Illuminate\Console\Command;

/**
 * Builds a signed remote index (docs/specs/templates.md section 3) from a
 * folder of template files: index.json, index.json.sig and a copy of each file.
 * Everything is checked before anything is written; any problem aborts.
 */
final class TemplatesBuildIndex extends Command
{
    protected $signature = 'templates:build-index {dir : folder of template *.yml files}
        {--out= : output folder (default: <dir>/../dist)}
        {--owners= : CODEOWNERS file (default: <dir>/../.github/CODEOWNERS)}
        {--key-file= : file holding the base64 secret key}
        {--key-env=CM_TEMPLATES_SIGNING_KEY : environment variable holding the base64 secret key}';

    protected $description = 'Validate templates and build index.json, index.json.sig and the files for upload';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/');
        $out = rtrim((string) ($this->option('out') ?: $dir.'/../dist'), '/');
        $ownersFile = (string) ($this->option('owners') ?: $dir.'/../.github/CODEOWNERS');
        $errors = [];

        $secret = $this->secretKey($errors);

        $owners = null;
        if (is_file($ownersFile)) {
            $owners = new Owners((string) file_get_contents($ownersFile));
        } else {
            $errors[] = "CODEOWNERS file not found at $ownersFile";
        }

        $files = is_dir($dir) ? (glob($dir.'/*.yml') ?: []) : [];
        sort($files);
        if ($files === []) {
            $errors[] = "no template files found in $dir";
        }

        $entries = [];
        $bodies = [];
        foreach ($files as $path) {
            $file = basename($path);
            $body = (string) file_get_contents($path);
            if (strlen($body) > SafeFetcher::MAX_FILE) {
                $errors[] = "$file: larger than ".SafeFetcher::MAX_FILE.' bytes';

                continue;
            }
            try {
                $def = Definition::parse($body);
            } catch (InvalidDefinition $e) {
                foreach ($e->errors as $err) {
                    $errors[] = "$file: ".InvalidDefinition::format($err);
                }

                continue;
            }
            if ($file !== $def->id.'.yml') {
                $errors[] = "$file: the file must be named {$def->id}.yml after its id";

                continue;
            }
            if (isset($entries[$def->id])) {
                $errors[] = "$file: duplicate id {$def->id}";

                continue;
            }
            $publisher = $owners?->publisher('/templates/'.$file);
            if ($owners !== null && $publisher === null) {
                $errors[] = "$file: no owner in $ownersFile for /templates/$file";

                continue;
            }
            $entries[$def->id] = [
                'id' => $def->id, 'version' => $def->version, 'name' => $def->name, 'category' => $def->category,
                'publisher' => (string) $publisher, 'file' => $file, 'sha256' => hash('sha256', $body),
            ];
            $bodies[$file] = $body;
        }

        $index = '';
        if ($errors === []) {
            $index = $this->encode($entries);
            if (strlen($index) > SafeFetcher::MAX_INDEX) {
                $errors[] = 'index.json would be larger than '.SafeFetcher::MAX_INDEX.' bytes';
            }
        }
        if ($errors !== []) {
            foreach ($errors as $e) {
                $this->error($e);
            }
            $this->error('Nothing was written.');

            return self::FAILURE;
        }

        $signature = base64_encode(sodium_crypto_sign_detached($index, $secret));
        sodium_memzero($secret);

        if (! is_dir($out) && ! mkdir($out, 0755, true)) {
            $this->error("could not create $out");

            return self::FAILURE;
        }
        foreach (glob($out.'/*.yml') ?: [] as $stale) {
            unlink($stale);
        }
        foreach ($bodies as $file => $body) {
            file_put_contents("$out/$file", $body);
        }
        file_put_contents("$out/index.json", $index);
        file_put_contents("$out/index.json.sig", $signature."\n");

        $this->info(count($entries)." templates written to $out (index.json, index.json.sig, files)");

        return self::SUCCESS;
    }

    /** @param list<string> $errors */
    private function secretKey(array &$errors): string
    {
        if ($file = $this->option('key-file')) {
            $raw = is_file($file) ? (string) file_get_contents($file) : '';
            $from = 'the key file';
        } else {
            $raw = (string) getenv((string) $this->option('key-env'));
            $from = 'the environment variable '.$this->option('key-env');
        }
        $key = base64_decode(trim($raw), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $errors[] = "no valid signing key in $from (expected a base64 ed25519 secret key from templates:keygen)";

            return '';
        }

        return $key;
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function encode(array $entries): string
    {
        ksort($entries, SORT_STRING);

        return json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
