<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Servers\AgentUpgrade;
use Tests\TestCase;

/**
 * Replacing the agent is the one recipe that changes the thing running it.
 * What matters: an unverified binary is never made live, a node already on
 * the build is left alone, and the restart happens after the result is sent.
 */
final class AgentUpgradeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/agent-upgrade-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-amd64", 'amd64 build');
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-arm64", 'arm64 build');
        config(['control_plane.agent_directory' => $this->dir, 'control_plane.agent_version' => 'v2',
            'control_plane.public_url' => 'https://cp.example.com', 'control_plane.gateway_token' => 'test-token']);
        $this->app->forgetInstance(\App\Lib\Gateway\GatewayClient::class);
        $this->app->forgetInstance(\App\Lib\Agent\AgentRelease::class);
        $this->app->forgetInstance(AgentUpgrade::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> */
    private function steps(string $arch = 'amd64'): array
    {
        $spec = $this->app->make(AgentUpgrade::class)->recipe((object) ['arch' => $arch]);

        return array_column($spec['steps'], null, 'id');
    }

    public function test_the_binary_is_verified_before_it_replaces_the_live_one(): void
    {
        $install = $this->steps()['install']['args'][1];
        $sha = hash('sha256', 'amd64 build');

        $this->assertStringContainsString('--cacert /var/lib/cloud-manager/ca.crt', $install);
        $this->assertStringContainsString('https://cp.example.com/api/agent/linux-amd64', $install);
        $this->assertLessThan(strpos($install, 'mv -f'), strpos($install, "echo '{$sha}  /usr/local/bin/cloud-manager-agent.new' | sha256sum -c -"));
        $this->assertStringContainsString(' && ', $install, 'Each stage must stop the chain on failure.');
    }

    public function test_a_node_already_on_the_build_is_left_alone(): void
    {
        $sha = hash('sha256', 'amd64 build');

        $this->assertSame(['sh', '-c', "echo '{$sha}  /usr/local/bin/cloud-manager-agent' | sha256sum -c --status -"], $this->steps()['install']['unless']);
    }

    public function test_arm_nodes_get_the_arm_build(): void
    {
        $install = $this->steps('aarch64')['install']['args'][1];

        $this->assertStringContainsString('/api/agent/linux-arm64', $install);
        $this->assertStringContainsString(hash('sha256', 'arm64 build'), $install);
    }

    public function test_the_restart_is_deferred_so_the_result_can_be_sent(): void
    {
        $restart = $this->steps()['restart-later'];

        $this->assertSame('systemd-run', $restart['name']);
        $this->assertContains('--on-active=5', $restart['args']);
        $this->assertSame(['systemctl', 'restart', 'cloud-manager-agent.service'], array_slice($restart['args'], -3));
    }

    public function test_an_upgrade_takes_restrict_suid_sgid_out_of_an_old_unit_before_the_restart(): void
    {
        $steps = $this->steps();
        $fix = $steps['unit-allow-setgid'];

        $this->assertStringContainsString("sed -i '/^RestrictSUIDSGID=/d' /etc/systemd/system/cloud-manager-agent.service && systemctl daemon-reload", $fix['args'][1]);
        $this->assertSame(['sh', '-c', "! grep -q '^RestrictSUIDSGID=' /etc/systemd/system/cloud-manager-agent.service"], $fix['unless'], 'A unit already without it is left alone.');
        $ids = array_keys($steps);
        $this->assertLessThan(array_search('restart-later', $ids, true), array_search('unit-allow-setgid', $ids, true));
    }

    public function test_readdressing_rewrites_both_places_the_agent_keeps_the_name(): void
    {
        $steps = array_column($this->app->make(AgentUpgrade::class)->readdressRecipe('203-0-113-10.sslip.io', 'agent.example.com')['steps'], null, 'id');
        $rewrite = $steps['rewrite']['args'][1];

        $this->assertStringContainsString("s/203-0-113-10\\.sslip\\.io/agent.example.com/g", $rewrite, 'Dots in the old name are literal, not any character.');
        $this->assertStringContainsString('/var/lib/cloud-manager/node.json', $rewrite);
        $this->assertStringContainsString('/etc/systemd/system/cloud-manager-agent.service', $rewrite);
        $this->assertSame(['sh', '-c', "! grep -qF '203-0-113-10.sslip.io' /var/lib/cloud-manager/node.json /etc/systemd/system/cloud-manager-agent.service"], $steps['rewrite']['unless']);
        $this->assertSame('systemd-run', $steps['restart-later']['name']);
    }

    public function test_readdressing_refuses_anything_but_hostnames(): void
    {
        // The names reach a shell; a quote or a slash must never get there.
        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(AgentUpgrade::class)->readdressRecipe("old.example.com", "x'; rm -rf /; echo '.example.com");
    }
}
