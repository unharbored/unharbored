<?php

namespace Tests\Feature\Templates;

use App\Lib\Templates\Definition;
use App\Lib\Templates\InvalidDefinition;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The CI test: every file in templates/ validates, and records its licence and source. */
final class BuiltInTemplatesTest extends TestCase
{
    use Fixtures;

    /** @return list<string> */
    private function files(): array
    {
        return glob(base_path('templates/*.yml')) ?: [];
    }

    public function test_the_four_first_definitions_exist(): void
    {
        $ids = array_map(fn ($f) => basename($f, '.yml'), $this->files());
        sort($ids);
        $this->assertSame(['gitea', 'github-runner', 'rustfs', 'uptime-kuma'], $ids);
    }

    public function test_every_built_in_template_validates_and_names_its_licence_and_source(): void
    {
        $this->assertNotEmpty($this->files());
        foreach ($this->files() as $file) {
            try {
                $d = Definition::fromFile($file);
            } catch (InvalidDefinition $e) {
                $this->fail(basename($file).":\n".$e->getMessage());
            }
            $this->assertSame(basename($file, '.yml'), $d->id, basename($file).' must be named after its id');
            $this->assertNotEmpty($d->licence, "$d->id needs a licence");
            $this->assertMatchesRegularExpression('#^https://#', (string) $d->source, "$d->id needs a source URL");
            $this->assertNotNull($d->memoryBytes());
        }
    }

    public function test_the_validate_command_passes_on_the_built_ins_and_fails_with_every_error_on_a_bad_file(): void
    {
        $this->artisan('templates:validate')->assertExitCode(0);

        $dir = sys_get_temp_dir().'/cm-validate-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $bad = $this->nativeArray();
        $bad['runs']['user'] = 'root';
        unset($bad['runs']['sha256']);
        file_put_contents("$dir/bad.yml", $this->yaml($bad));
        file_put_contents("$dir/good.yml", $this->yaml($this->nativeArray()));

        $this->artisan('templates:validate', ['path' => $dir])
            ->expectsOutputToContain('bad.yml: runs.user')
            ->expectsOutputToContain('bad.yml: runs.sha256')
            ->assertExitCode(1);
        $this->artisan('templates:validate', ['path' => "$dir/good.yml"])->assertExitCode(0);
        $this->artisan('templates:validate', ['path' => "$dir/missing.yml"])->assertExitCode(1);
        File::deleteDirectory($dir);
    }
}
