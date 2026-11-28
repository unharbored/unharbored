<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Lib\Sites\PhpRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpRequirementTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function constraints(): array
    {
        return [
            'caret fits' => ['^8.2', '8.4.1', true],
            'caret stops at the next major' => ['^8.2', '9.0.0', false],
            'caret too low' => ['^8.2', '8.1.9', false],
            'tilde two parts runs to the next major' => ['~8.1', '8.5.0', true],
            'tilde three parts stops at the next minor' => ['~8.1.2', '8.2.0', false],
            'upper bound' => ['>=8.0 <8.5', '8.5.0', false],
            'upper bound fits' => ['>=8.0 <8.5', '8.4.3', true],
            'or of majors' => ['^7.4|^8.0', '8.3.0', true],
            'double pipe' => ['>=7.2.5 || ^8.0', '8.4.0', true],
            'wildcards' => ['8.2.*|8.3.*', '8.3.7', true],
            'wildcard misses' => ['8.2.*|8.3.*', '8.4.0', false],
            'comma is and' => ['>=8.1, <8.4', '8.4.0', false],
            'range' => ['7.2 - 8.1', '8.1.20', true],
            'range misses' => ['7.2 - 8.1', '8.2.0', false],
            'anything' => ['*', '8.5.0', true],
        ];
    }

    #[DataProvider('constraints')]
    public function test_a_constraint_allows_or_refuses_a_version(string $constraint, string $version, bool $expected): void
    {
        $this->assertSame($expected, PhpRequirement::allows($constraint, $version));
    }

    public function test_a_newer_php_than_a_package_declares_is_fine_and_only_older_ones_are_ruled_out(): void
    {
        $lock = ['packages' => [
            ['name' => 'nette/utils', 'require' => ['php' => '8.0 - 8.4']],
            ['name' => 'laravel/framework', 'require' => ['php' => '^8.2']],
            ['name' => 'no-php-requirement', 'require' => []],
        ]];

        $this->assertSame(['8.5', '8.4', '8.3', '8.2'], PhpRequirement::branches(['require' => ['php' => '^8.2']], $lock));
    }

    public function test_a_minimum_rules_out_older_branches(): void
    {
        $this->assertSame(['8.5', '8.4'], PhpRequirement::branches(['require' => ['php' => '^8.4']], ['packages' => []]));
        $this->assertSame(['8.5', '8.4'], PhpRequirement::branches([], ['packages' => [['require' => ['php' => '>=8.4']]]]));
    }

    public function test_a_platform_override_means_composer_does_not_check_the_running_php(): void
    {
        $composer = ['config' => ['platform' => ['php' => '8.2.0']], 'require' => ['php' => '^8.2']];
        $lock = ['packages' => [['require' => ['php' => '<8.3']]]];

        $this->assertSame(['8.5', '8.4', '8.3', '8.2'], PhpRequirement::branches($composer, $lock));
    }

    public function test_it_keeps_the_current_branch_when_it_fits_then_prefers_one_already_installed(): void
    {
        $this->assertSame(['version' => '8.4', 'installed' => true], PhpRequirement::choose(['8.4', '8.3'], '8.4', []));
        $this->assertSame(['version' => '8.3', 'installed' => true], PhpRequirement::choose(['8.4', '8.3'], '8.5', ['8.5', '8.3']));
        $this->assertSame(['version' => '8.4', 'installed' => false], PhpRequirement::choose(['8.4', '8.3'], '8.5', ['8.5']));
        $this->assertNull(PhpRequirement::choose([], '8.5', ['8.5']));
    }

    public function test_it_describes_the_minimum_for_a_message(): void
    {
        $this->assertSame('PHP 8.2 or newer', PhpRequirement::describe(['8.5', '8.4', '8.3', '8.2']));
        $this->assertSame('PHP 8.5 or newer', PhpRequirement::describe(['8.5']));
    }
}
