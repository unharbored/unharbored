<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Templates\Definition;
use App\Lib\Templates\InvalidDefinition;
use Illuminate\Console\Command;

/** Validates one template file, or every *.yml in a folder, and prints every error. For authors and CI. */
final class ValidateTemplates extends Command
{
    protected $signature = 'templates:validate {path? : a file or a folder (default: templates/)}';

    protected $description = 'Validate template definition files against schema 1';

    public function handle(): int
    {
        $path = $this->argument('path') ?? base_path('templates');
        $files = is_dir($path) ? (glob(rtrim($path, '/').'/*.yml') ?: []) : [$path];
        sort($files);
        if ($files === [] || (! is_dir($path) && ! is_file($path))) {
            $this->error("no template files found at $path");

            return self::FAILURE;
        }

        $failed = false;
        foreach ($files as $file) {
            try {
                $def = Definition::fromFile($file);
                $this->line("ok     $file ({$def->id} version {$def->version})");
            } catch (InvalidDefinition $e) {
                $failed = true;
                foreach ($e->errors as $err) {
                    $this->error("$file: ".InvalidDefinition::format($err));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
