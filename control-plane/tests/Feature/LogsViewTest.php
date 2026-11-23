<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Records that span lines fold under their first line, and logs can be narrowed by level. */
class LogsViewTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $text): void
    {
        DB::table('log_lines')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'source' => 'laravel:shop', 'text' => $text, 'logged_at' => now()]);
    }

    public function test_a_stack_trace_folds_and_the_level_filter_narrows(): void
    {
        $this->line("production.ERROR: Boom\n#0 /app/Foo.php(1): bar()\n#1 {main}");
        $this->line('production.INFO: all fine');
        $this->line('production.WARNING: careful now');
        $this->actingAs(User::factory()->create());

        $rows = fn (string $url) => collect($this->get($url)->assertOk()->inertiaProps('lines.rows'));

        // A record that spans lines gives its first line and folds the rest.
        $error = $rows('/logs')->firstWhere('head', 'production.ERROR: Boom');
        $this->assertStringContainsString('#0 /app/Foo.php(1): bar()', $error['rest']);
        $this->assertSame('bad', $error['level']);
        $this->assertContains('production.INFO: all fine', $rows('/logs')->pluck('text')->all());

        $this->assertSame(['production.ERROR: Boom'], $rows('/logs?level=bad')->pluck('head')->all());
        $this->assertEqualsCanonicalizing(['production.ERROR: Boom', 'production.WARNING: careful now'], $rows('/logs?level=warn')->pluck('head')->all());
    }

    public function test_a_server_card_links_to_that_servers_logs(): void
    {
        $this->actingAs(User::factory()->create());
        DB::table('nodes')->insert(['node_id' => 'web-9.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/logs?server=web-9.org-1')->assertOk();
    }
}
