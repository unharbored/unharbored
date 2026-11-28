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

    public function test_the_list_is_capped_newest_first_and_the_limit_grows_to_a_ceiling_with_filters_kept(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1100; $i++) {
            $rows[] = ['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'source' => 'laravel:shop', 'text' => ($i % 2 ? 'odd ' : 'even ').$i, 'logged_at' => now()->subSeconds(1101 - $i)];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('log_lines')->insert($chunk);
        }
        $this->actingAs(User::factory()->create());
        $lines = fn (string $url) => $this->get($url)->assertOk()->inertiaProps('lines');

        $default = $lines('/logs');
        $this->assertCount(200, $default['rows']);
        $this->assertSame(1100, $default['total']);
        $this->assertSame('even 1100', $default['rows'][0]['text'], 'Newest first.');

        $this->assertCount(400, $lines('/logs?limit=400')['rows']);
        $this->assertCount(1000, $lines('/logs?limit=999999')['rows'], 'The ceiling is 1000.');
        $this->assertCount(50, $lines('/logs?limit=1')['rows'], 'The floor is 50.');

        $odd = $lines('/logs?q=odd&limit=100');
        $this->assertCount(100, $odd['rows']);
        $this->assertSame(550, $odd['total']);
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
