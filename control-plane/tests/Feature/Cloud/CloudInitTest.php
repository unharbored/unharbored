<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Agent\AgentRelease;
use App\Lib\Cloud\CloudInit;
use App\Lib\Pki\CertificateAuthority;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Cloud-init is the one instruction the platform gets to give a machine
 * before it can talk back, and a mistake in it produces a Droplet that boots
 * perfectly and never appears. There is no error to read, because the thing
 * that would have reported the error is the thing that failed to start.
 *
 * So these tests parse the generated YAML rather than matching substrings in
 * it. A mis-indented certificate still looks fine to a regex.
 */
final class CloudInitTest extends TestCase
{
    private string $sha;

    private string $agentDir;

    protected function setUp(): void
    {
        parent::setUp();

        // A stand-in binary, so the checksum under test is of a real file.
        // That is the behaviour that matters: a value read from config drifts
        // from what is actually served, and the symptom is a node that fails
        // its checksum and never appears.
        $this->agentDir = sys_get_temp_dir().'/cloud-manager-agent-test-'.getmypid();
        @mkdir($this->agentDir, 0o755, true);
        file_put_contents($this->agentDir.'/cloud-manager-agent-linux-amd64', 'pretend binary');
        file_put_contents($this->agentDir.'/cloud-manager-agent-linux-arm64', 'pretend arm binary');

        $this->sha = hash('sha256', 'pretend binary');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->agentDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->agentDir);

        parent::tearDown();
    }

    private function builder(): CloudInit
    {
        return new CloudInit(
            release: new AgentRelease($this->agentDir, 'test'),
            baseUrl: 'https://cp.example.test',
            serverName: 'cp.example.test',
        );
    }

    private function build(string $token = 'test-enrollment-token'): string
    {
        return $this->builder()->build(
            nodeId: 'web-1.org-1',
            enrollmentToken: $token,
            caCertificatePem: CertificateAuthority::create('org-1 CA')->certificatePem(),
            hostname: 'web-1',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $yaml): array
    {
        return (array) Yaml::parse($yaml);
    }

    public function test_the_output_is_valid_yaml(): void
    {
        // Yaml::parse throws on malformed input, which is the assertion.
        $parsed = $this->parse($this->build());

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('write_files', $parsed);
        $this->assertArrayHasKey('runcmd', $parsed);
    }

    /**
     * Regression, and a bad one.
     *
     * PHP heredocs preserve the source file line endings, and this is written
     * on a Windows workstation, so a CRLF checkout produced cloud-init with
     * CRLF throughout. The failure is invisible: YAML parsers tolerate CRLF so
     * the document still parses, but every runcmd entry reaches the shell with
     * a trailing carriage return. The checksum step is not a command, the
     * agent is never installed, and the Droplet boots looking perfectly
     * healthy with nothing on it.
     *
     * Every other test here passed while this was broken.
     */
    public function test_the_output_contains_no_carriage_returns(): void
    {
        $yaml = $this->build();

        $this->assertStringNotContainsString(chr(13), $yaml, 'cloud-init carries CRLF line endings.');

        // Including inside the embedded files, which are what actually land
        // on the node.
        foreach ($this->fileMap($this->parse($yaml)) as $path => $file) {
            $this->assertStringNotContainsString(
                chr(13),
                (string) $file['content'],
                $path.' would be written with CRLF.'
            );
        }
    }

    /**
     * Shell commands are where CRLF does the damage, so assert on them
     * directly rather than trusting the whole-document check alone.
     */
    public function test_no_runcmd_entry_has_trailing_whitespace(): void
    {
        foreach ($this->parse($this->build())['runcmd'] as $cmd) {
            $this->assertSame(
                rtrim((string) $cmd),
                (string) $cmd,
                'A runcmd entry has trailing whitespace: '.var_export($cmd, true)
            );
        }
    }

    public function test_it_announces_itself_as_cloud_config(): void
    {
        // cloud-init ignores a file without this first line, silently.
        $this->assertStringStartsWith("#cloud-config\n", $this->build());
    }

    /**
     * The point of parsing rather than grepping: a mis-indented PEM still
     * contains "BEGIN CERTIFICATE" but writes a broken file, and the symptom
     * is a node that never enrolls.
     */
    public function test_the_ca_certificate_survives_as_a_usable_pem(): void
    {
        $ca = CertificateAuthority::create('org-1 CA');
        $expected = $ca->certificatePem();

        $yaml = $this->builder()->build('web-1.org-1', 'tok', $expected, 'web-1');
        $files = $this->fileMap($this->parse($yaml));

        $written = $files['/var/lib/cloud-manager/ca.crt']['content'];

        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $written);
        $this->assertStringContainsString('-----END CERTIFICATE-----', $written);

        // It must parse back into the same certificate, not merely look like
        // one.
        $this->assertSame(
            CertificateAuthority::commonName($expected),
            CertificateAuthority::commonName($written),
        );
    }

    public function test_the_enrollment_token_is_written_privately(): void
    {
        $files = $this->fileMap($this->parse($this->build('a-real-looking-token')));

        $token = $files['/var/lib/cloud-manager/enroll.token'];

        $this->assertSame('a-real-looking-token', trim((string) $token['content']));
        $this->assertSame('0600', $token['permissions'], 'The token must not be world readable.');
        $this->assertSame('root:root', $token['owner']);
    }

    public function test_the_ca_is_readable_but_not_writable_by_others(): void
    {
        $files = $this->fileMap($this->parse($this->build()));

        $this->assertSame('0644', $files['/var/lib/cloud-manager/ca.crt']['permissions']);
    }

    /**
     * The binary is fetched over the network and then run as root. Verifying
     * it before making it executable is the only thing standing between a
     * hijacked download and root on a fresh machine.
     */
    public function test_the_agent_binary_is_verified_before_it_is_made_executable(): void
    {
        $runcmd = $this->parse($this->build())['runcmd'];
        $joined = implode("\n", array_map('strval', $runcmd));

        $shaIndex = $this->indexOfCommandContaining($runcmd, 'sha256sum -c');
        $chmodIndex = $this->indexOfCommandContaining($runcmd, 'chmod 0755');

        $this->assertNotNull($shaIndex, 'No checksum verification in runcmd.');
        $this->assertNotNull($chmodIndex, 'The binary is never made executable.');
        $this->assertLessThan(
            $chmodIndex,
            $shaIndex,
            'The checksum must be verified before the binary becomes executable.'
        );
        $this->assertStringContainsString($this->sha, $joined);
    }

    /**
     * The control plane serves the agent behind a certificate from the
     * organisation CA. Without pinning that CA the download fails on every
     * real Droplet, and the node boots healthy with no agent on it.
     */
    public function test_the_agent_download_trusts_the_organisation_ca(): void
    {
        $runcmd = $this->parse($this->build())['runcmd'];
        $index = $this->indexOfCommandContaining($runcmd, '/api/agent/');

        $this->assertNotNull($index, 'No agent download in runcmd.');
        $this->assertStringContainsString('--cacert /var/lib/cloud-manager/ca.crt', (string) $runcmd[$index]);
    }

    public function test_the_agent_is_installed_and_started(): void
    {
        $runcmd = $this->parse($this->build())['runcmd'];
        $joined = implode("\n", array_map('strval', $runcmd));

        $this->assertStringContainsString('https://cp.example.test/api/agent/linux-amd64', $joined);
        $this->assertStringContainsString('systemctl enable --now cloud-manager-agent.service', $joined);
    }

    public function test_the_systemd_unit_points_at_the_right_control_plane(): void
    {
        $files = $this->fileMap($this->parse($this->build()));
        $unit = $files['/etc/systemd/system/cloud-manager-agent.service']['content'];

        $this->assertStringContainsString('-enroll-url https://cp.example.test/api/enroll', $unit);
        $this->assertStringContainsString('-server-name cp.example.test', $unit);
        $this->assertStringContainsString('-ca-file /var/lib/cloud-manager/ca.crt', $unit);
        $this->assertStringContainsString('-token-file /var/lib/cloud-manager/enroll.token', $unit);

        // An agent that has stopped is a node that has silently left the
        // fleet.
        $this->assertStringContainsString('Restart=always', $unit);
        $this->assertStringContainsString('WantedBy=multi-user.target', $unit);
    }

    public function test_the_agent_may_install_packages_that_set_setuid_or_setgid_bits(): void
    {
        $files = $this->fileMap($this->parse($this->build()));
        $unit = $files['/etc/systemd/system/cloud-manager-agent.service']['content'];

        // Under RestrictSUIDSGID, fontconfig-config's chmod 2775 failed and took php-gd down with it.
        $this->assertDoesNotMatchRegularExpression('/^RestrictSUIDSGID=/m', $unit);
        $this->assertStringContainsString('NoNewPrivileges=true', $unit, 'The rest of the hardening stays.');
    }

    /**
     * Nothing in the bootstrap may open a way in. The entire security
     * argument in ADR 0001 rests on the node listening for nothing.
     */
    public function test_the_bootstrap_opens_no_inbound_access(): void
    {
        $yaml = $this->build();
        $parsed = $this->parse($yaml);

        $this->assertArrayNotHasKey('ssh_authorized_keys', $parsed);
        $this->assertArrayNotHasKey('users', $parsed);

        foreach (['ssh_authorized_keys', 'authorized_keys', 'ufw allow', 'iptables -A INPUT'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $yaml);
        }
    }

    public function test_the_hostname_is_set(): void
    {
        $parsed = $this->parse($this->build());

        $this->assertSame('web-1', $parsed['hostname']);
        $this->assertFalse($parsed['preserve_hostname']);
    }

    /**
     * A full upgrade on first boot can take several minutes and occasionally
     * prompts, which turns a provision into a timeout. Refresh the index,
     * install what is needed, and leave the rest.
     */
    public function test_it_does_not_run_a_full_package_upgrade_on_first_boot(): void
    {
        $parsed = $this->parse($this->build());

        $this->assertTrue($parsed['package_update']);
        $this->assertFalse($parsed['package_upgrade']);
    }

    /**
     * DigitalOcean offers both architectures. A node that downloads the wrong
     * build fails invisibly: cloud-init reports success, the binary will not
     * execute, and the node never appears.
     */
    public function test_the_architecture_selects_the_build(): void
    {
        $armSha = hash('sha256', 'pretend arm binary');

        $yaml = $this->builder()->build(
            'web-1.org-1',
            'tok',
            CertificateAuthority::create('org-1 CA')->certificatePem(),
            'web-1',
            'arm64',
        );

        $runcmd = implode('
', array_map('strval', $this->parse($yaml)['runcmd']));

        $this->assertStringContainsString('/api/agent/linux-arm64', $runcmd);
        $this->assertStringContainsString($armSha, $runcmd);
        $this->assertStringNotContainsString($this->sha, $runcmd);
    }

    public function test_tokens_with_url_safe_characters_survive(): void
    {
        // Enrollment tokens are base64url, so they contain - and _.
        $token = 'T6XxCqfGQ0pFRYxYvBtR0RhXYv0VdKJ3iNhQ8sLpYqM-_x';

        $files = $this->fileMap($this->parse($this->build($token)));

        $this->assertSame($token, trim((string) $files['/var/lib/cloud-manager/enroll.token']['content']));
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, array<string, mixed>>
     */
    private function fileMap(array $parsed): array
    {
        $map = [];
        foreach ($parsed['write_files'] ?? [] as $file) {
            $map[$file['path']] = $file;
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $runcmd
     */
    private function indexOfCommandContaining(array $runcmd, string $needle): ?int
    {
        foreach ($runcmd as $i => $cmd) {
            if (str_contains((string) $cmd, $needle)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Runs the install script's own cleanup block, as sh would, against a sandbox standing in for the server.
     *
     * @param  array<string, string>  $files  paths relative to the sandbox, with contents
     * @return array{output: string, code: int, left: list<string>}
     */
    private function runInstallPrelude(array $files, array $env = [], string $then = ':'): array
    {
        $script = $this->builder()->installScript('web-1.org-1', 'test-enrollment-token', CertificateAuthority::create('org-1 CA')->certificatePem());
        $start = strpos($script, "STATE=/var/lib/cloud-manager\n");
        $end = strpos($script, "trap 'rc=");
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($script, $start, strpos($script, "\n", $end) - $start);

        $root = sys_get_temp_dir().'/cm-install-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($root, 0o755, true);
        foreach ($files as $path => $contents) {
            @mkdir(dirname("$root/$path"), 0o755, true);
            file_put_contents("$root/$path", $contents);
        }
        // The same block, pointed at the sandbox instead of the real server.
        $block = str_replace(
            ['STATE=/var/lib/cloud-manager', 'BIN=/usr/local/bin/cloud-manager-agent', 'UNIT=/etc/systemd/system/cloud-manager-agent.service'],
            ["STATE=$root/var/lib/cloud-manager", "BIN=$root/usr/local/bin/cloud-manager-agent", "UNIT=$root/etc/systemd/system/cloud-manager-agent.service"],
            $block,
        );
        $harness = "set -euo pipefail\nsay() { printf '%s\\n' \"\$*\"; }\ndie() { printf '%s\\n' \"\$*\"; exit 1; }\n$block\n$then\n";
        file_put_contents("$root/harness.sh", $harness);
        $process = new Process(['bash', "$root/harness.sh"], null, $env);
        $process->run();

        $left = [];
        $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($found as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($relative !== 'harness.sh') {
                $left[] = $relative;
            }
        }
        exec('rm -rf '.escapeshellarg($root));
        sort($left);

        return ['output' => $process->getOutput().$process->getErrorOutput(), 'code' => $process->getExitCode(), 'left' => $left];
    }

    public function test_the_install_script_clears_what_an_unfinished_run_left_and_says_so(): void
    {
        $half = [
            'var/lib/cloud-manager/ca.crt' => 'old ca',
            'var/lib/cloud-manager/enroll.token' => 'spent',
            'usr/local/bin/cloud-manager-agent.new' => 'half a download',
            'usr/local/bin/cloud-manager-agent' => 'binary',
            'etc/systemd/system/cloud-manager-agent.service' => 'unit',
        ];

        $run = $this->runInstallPrelude($half);

        $this->assertSame(0, $run['code'], $run['output']);
        $this->assertStringContainsString('Cleaning up an earlier run that did not finish', $run['output']);
        $this->assertSame([], $run['left'], 'Nothing of the earlier run is left.');
    }

    public function test_a_clean_server_is_left_alone_and_nothing_is_announced(): void
    {
        $run = $this->runInstallPrelude([]);

        $this->assertSame(0, $run['code'], $run['output']);
        $this->assertStringNotContainsString('Cleaning up', $run['output']);
    }

    public function test_an_enrolled_server_keeps_its_identity_unless_it_is_told_to_give_it_up(): void
    {
        $enrolled = ['var/lib/cloud-manager/node.json' => '{}', 'var/lib/cloud-manager/ca.crt' => 'ca', 'etc/systemd/system/cloud-manager-agent.service' => 'unit'];

        $kept = $this->runInstallPrelude($enrolled);
        $this->assertSame(1, $kept['code']);
        $this->assertStringContainsString('already enrolled', $kept['output']);
        $this->assertStringContainsString('CM_REENROLL=1', $kept['output']);
        $this->assertCount(3, $kept['left'], 'Nothing is removed from an enrolled server without being asked.');

        $forced = $this->runInstallPrelude($enrolled, ['CM_REENROLL' => '1']);
        $this->assertSame(0, $forced['code'], $forced['output']);
        $this->assertStringContainsString('Removing the earlier enrolment', $forced['output']);
        $this->assertSame([], $forced['left']);
    }

    public function test_a_run_that_stops_before_the_agent_starts_removes_what_it_wrote_and_one_that_got_further_does_not(): void
    {
        $failed = $this->runInstallPrelude([], then: 'mkdir -p "$STATE" && echo token > "$STATE/enroll.token" && : > "$BIN.new"; exit 1');
        $this->assertSame(1, $failed['code']);
        $this->assertStringContainsString('Stopped before it finished', $failed['output']);
        $this->assertSame([], $failed['left'], 'The single-use token does not stay on disk after a failure.');

        $started = $this->runInstallPrelude([], then: 'mkdir -p "$STATE" && echo token > "$STATE/enroll.token"; started=1; exit 1');
        $this->assertSame(['var/lib/cloud-manager/enroll.token'], $started['left'], 'Once the service is started, the agent owns its files.');

        $succeeded = $this->runInstallPrelude([], then: 'mkdir -p "$STATE" && echo ok > "$STATE/ca.crt"');
        $this->assertSame(['var/lib/cloud-manager/ca.crt'], $succeeded['left']);
    }
}
