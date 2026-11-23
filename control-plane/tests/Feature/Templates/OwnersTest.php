<?php

namespace Tests\Feature\Templates;

use App\Lib\Templates\Owners;
use PHPUnit\Framework\TestCase;

final class OwnersTest extends TestCase
{
    public function test_exact_path_and_org_team_owner(): void
    {
        $o = new Owners("/templates/foo.yml @alice @bob\n/templates/bar.yml @acme/maintainers\n");
        $this->assertSame('@alice', $o->publisher('/templates/foo.yml'));
        $this->assertSame('@acme/maintainers', $o->publisher('/templates/bar.yml'));
    }

    public function test_last_matching_line_wins(): void
    {
        $o = new Owners("* @default\n/templates/*.yml @team\n/templates/foo.yml @alice\n");
        $this->assertSame('@alice', $o->publisher('/templates/foo.yml'));
        $this->assertSame('@team', $o->publisher('/templates/other.yml'));
        $this->assertSame('@default', $o->publisher('/README.md'));
    }

    public function test_comments_and_blank_lines_are_ignored(): void
    {
        $o = new Owners("# header\n\n   \n/templates/foo.yml @alice # trailing comment\n#/templates/bar.yml @bob\n");
        $this->assertSame('@alice', $o->publisher('/templates/foo.yml'));
        $this->assertNull($o->publisher('/templates/bar.yml'));
    }

    public function test_no_match_is_null_and_a_pattern_is_not_a_substring(): void
    {
        $o = new Owners("/templates/foo.yml @alice\n");
        $this->assertNull($o->publisher('/templates/foo.yml.bak'));
        $this->assertNull($o->publisher('/templates/xfoo.yml'));
        $this->assertNull((new Owners(''))->publisher('/templates/foo.yml'));
    }

    public function test_a_matching_line_with_no_owner_clears_ownership(): void
    {
        $o = new Owners("/templates/*.yml @team\n/templates/foo.yml\n");
        $this->assertNull($o->publisher('/templates/foo.yml'));
    }

    public function test_folder_and_bare_name_patterns(): void
    {
        $o = new Owners("/templates/ @team\nCODEOWNERS @root\n");
        $this->assertSame('@team', $o->publisher('/templates/a.yml'));
        $this->assertSame('@root', $o->publisher('/.github/CODEOWNERS'));
    }
}
