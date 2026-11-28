<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lib\Sites\Runtimes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BuildScriptEnvironmentTest extends TestCase
{
    /** Runs a build script the way the deploy does, in a temporary folder, and returns what it printed. */
    private function printed(string $script): string
    {
        $dir = sys_get_temp_dir().'/bs-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $steps = Runtimes::buildScriptSteps((object) ['name' => 'shop'], $dir, $script, '', '/usr/bin/php8.4', null);
            $shell = end($steps)['args'];
            $process = new Process(['sh', '-c', end($shell)]);
            $process->mustRun();

            return $process->getOutput();
        } finally {
            rmdir($dir);
        }
    }

    public function test_the_script_can_use_composer_without_composer_seeing_a_variable_of_that_name(): void
    {
        $this->assertSame("/usr/bin/php8.4 /usr/local/bin/composer\n", $this->printed('echo "$COMPOSER"'));
        // Composer reads an exported COMPOSER as the name of the file to use instead of composer.json.
        $this->assertSame("none\n", $this->printed('env | grep -q "^COMPOSER=" && echo exported || echo none'));
    }

    public function test_php_is_exported_for_the_commands_the_script_runs(): void
    {
        $this->assertSame("/usr/bin/php8.4\n", $this->printed('sh -c \'echo "$PHP"\''));
    }

    public function test_composer_is_told_to_ignore_the_upper_php_limit_of_packages(): void
    {
        $this->assertSame("php+\n", $this->printed('sh -c \'echo "$COMPOSER_IGNORE_PLATFORM_REQS"\''));
    }
}
