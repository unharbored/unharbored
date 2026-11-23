<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Servers\Firewall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class FirewallTest extends TestCase
{
    use RefreshDatabase;

    public function test_addresses_and_networks_are_read_one_per_line_and_checked(): void
    {
        $this->assertSame(['203.0.113.7', '198.51.100.0/24', '2001:db8::/32'], Firewall::parse("203.0.113.7\n# office\n198.51.100.0/24, 2001:DB8::/32\n203.0.113.7", 'SSH'));
        $this->assertSame([], Firewall::parse("  \n", 'SSH'));

        foreach (['not an ip', '203.0.113.7/33', '2001:db8::/129', '300.1.1.1', '10.0.0.0/abc'] as $bad) {
            try {
                Firewall::parse($bad, 'Web');
                $this->fail("{$bad} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Web', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Firewall::parse(implode(' ', array_map(fn ($i) => "10.0.{$i}.1", range(1, 51))), 'SSH');
    }

    public function test_off_does_nothing_but_turn_ufw_off_and_never_installs_it(): void
    {
        $steps = Firewall::recipe(['enabled' => false, 'ssh' => [], 'web' => []])['steps'];

        $this->assertCount(1, $steps);
        $this->assertStringContainsString('ufw --force disable', $steps[0]['args'][1]);
        $this->assertSame([], array_filter($steps, fn ($s) => ($s['type'] ?? '') === 'package'));
    }

    public function test_on_with_no_lists_denies_by_default_but_leaves_ssh_and_web_open_to_everyone(): void
    {
        $steps = Firewall::recipe(['enabled' => true, 'ssh' => [], 'web' => []])['steps'];
        $commands = array_map(fn ($s) => $s['type'] === 'package' ? 'package '.$s['name'] : implode(' ', $s['args']), $steps);

        $this->assertSame(['package ufw', '--force reset', 'default deny incoming', 'default allow outgoing', 'allow 22/tcp', 'allow 80/tcp', 'allow 443/tcp', '--force enable'], $commands);
    }

    public function test_lists_limit_who_reaches_each_port_and_the_private_network_stays_open_to_the_web_ports(): void
    {
        $steps = Firewall::recipe(['enabled' => true, 'ssh' => ['203.0.113.7'], 'web' => ['198.51.100.0/24']])['steps'];
        $commands = array_map(fn ($s) => $s['type'] === 'package' ? 'package '.$s['name'] : implode(' ', $s['args']), $steps);

        $this->assertContains('allow from 203.0.113.7 to any port 22 proto tcp', $commands);
        $this->assertContains('allow from 198.51.100.0/24 to any port 443 proto tcp', $commands);
        $this->assertContains('allow from 10.0.0.0/8 to any port 80 proto tcp', $commands);
        $this->assertNotContains('allow 22/tcp', $commands, 'A list means not anyone.');
        $this->assertSame('--force enable', end($commands), 'Rules first, then on.');
    }

    public function test_a_developer_cannot_change_a_firewall_and_a_manager_can(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $call = fn () => $this->postJson('/servers/web-1.org-1/firewall', ['enabled' => true, 'ssh' => 'not an ip', 'web' => '']);

        $dev = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $dev->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($dev);
        $this->assertSame(403, $call()->status());

        $manager = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $manager->id, 'organization_id' => 'org-1', 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($manager);
        // Past the permission, a bad address is a validation message and nothing is saved.
        $this->assertSame(422, $call()->status());
        $this->assertNull(DB::table('nodes')->where('node_id', 'web-1.org-1')->value('firewall'));
    }
}
