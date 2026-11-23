<?php

declare(strict_types=1);

namespace App\Lib\Containers;

/**
 * What preparing a server for containers sends to it, and how the answers of a read are parsed.
 * Pure: no database, no gateway. Docker Engine comes from Docker's own signed apt repository; the
 * signing key is checked against a pinned fingerprint before apt is told to trust it.
 */
final class Docker
{
    /** Docker's apt signing key (https://download.docker.com/linux/ubuntu/gpg), verified with gpg --show-keys on 2026-11-22. */
    public const KEY_FINGERPRINT = '9DC858229FC7DD38854AE2D88D81803C0EBFCD88';

    public const PACKAGES = ['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin'];

    public const DAEMON_JSON = '/etc/docker/daemon.json';

    /** Below this much total memory the daemon's own use (about 100 MB, an estimate) is a real share, and a confirmation is required. */
    public const SMALL_BYTES = 1610612736; // 1.5 GB

    private const APPLIED = '/var/lib/cloud-manager/docker-daemon.applied';

    /**
     * live-restore keeps containers running while the daemon restarts; logs rotate. There is no "hosts" entry (no TCP
     * socket) and "iptables" is left at Docker's default: ports are kept private by binding every one to 127.0.0.1.
     */
    public static function daemonJson(): string
    {
        return json_encode([
            'live-restore' => true,
            'log-driver' => 'json-file',
            'log-opts' => ['max-size' => '10m', 'max-file' => '3'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public static function check(): array
    {
        $script = implode("\n", [
            '. /etc/os-release',
            'case "$ID" in ubuntu|debian) ;; *) echo "Docker is set up on Ubuntu and Debian servers only; this one runs ${PRETTY_NAME:-an unknown system}"; exit 1 ;; esac',
            '[ -n "$VERSION_CODENAME" ] || { echo "this server does not report its release name, so Docker\'s repository cannot be chosen"; exit 1; }',
            'for p in docker.io podman-docker; do dpkg -s "$p" >/dev/null 2>&1 && { echo "$p is installed and conflicts with Docker Engine; remove it first"; exit 1; }; done',
            'exit 0',
        ]);

        return ['name' => 'containers-check', 'steps' => [
            ['id' => 'os', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'the server runs Ubuntu or Debian and has no conflicting Docker package'],
        ]];
    }

    /**
     * The key is downloaded to a temporary file, its fingerprint read with gpg and compared with the pin, and only a match is
     * installed. Every run repeats the check; the source line and the package steps skip what is already done.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function install(): array
    {
        $pin = self::KEY_FINGERPRINT;
        $key = implode("\n", [
            'set -e',
            '. /etc/os-release',
            'tmp=$(mktemp)',
            'trap \'rm -f "$tmp"\' EXIT',
            'curl -fsSL --proto =https --max-time 60 -o "$tmp" "https://download.docker.com/linux/$ID/gpg"',
            // Exactly one key in the file, and it is the pinned one: apt would trust every key in a file it is given.
            '[ "$(gpg --show-keys --with-colons "$tmp" | grep -c \'^pub:\')" = "1" ] || { echo "the file downloaded from download.docker.com does not hold exactly one key; nothing was installed"; exit 1; }',
            'got=$(gpg --show-keys --with-colons "$tmp" | awk -F: \'$1=="fpr" { print $10; exit }\')',
            '[ "$got" = "'.$pin.'" ] || { echo "the key downloaded from download.docker.com has fingerprint ${got:-none}, not the pinned '.$pin.'; nothing was installed"; exit 1; }',
            'install -m 0644 "$tmp" /etc/apt/keyrings/docker.asc',
        ]);
        $source = '. /etc/os-release && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$ID $VERSION_CODENAME stable" > /etc/apt/sources.list.d/docker.list && apt-get update -q';

        $steps = [
            ['id' => 'pkg-ca-certificates', 'type' => 'package', 'name' => 'ca-certificates'],
            ['id' => 'pkg-curl', 'type' => 'package', 'name' => 'curl'],
            ['id' => 'pkg-gnupg', 'type' => 'package', 'name' => 'gnupg'],
            ['id' => 'dir-keyrings', 'type' => 'dir', 'path' => '/etc/apt/keyrings', 'mode' => '0755'],
            ['id' => 'docker-key', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $key], 'description' => 'Docker\'s signing key, fingerprint checked against the pinned '.$pin],
            ['id' => 'docker-source', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $source],
                'unless' => ['sh', '-c', '. /etc/os-release && grep -q "$VERSION_CODENAME stable" /etc/apt/sources.list.d/docker.list'], 'description' => 'Docker\'s apt repository for this release'],
        ];
        foreach (self::PACKAGES as $p) {
            $steps[] = ['id' => "pkg-{$p}", 'type' => 'package', 'name' => $p];
        }

        return ['name' => 'containers-install', 'steps' => $steps];
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public static function configure(): array
    {
        $applied = self::APPLIED;
        $hash = 'sha256sum '.self::DAEMON_JSON.' | cut -d" " -f1';

        return ['name' => 'containers-configure', 'steps' => [
            ['id' => 'dir-docker', 'type' => 'dir', 'path' => '/etc/docker', 'mode' => '0755'],
            ['id' => 'daemon-json', 'type' => 'file', 'path' => self::DAEMON_JSON, 'mode' => '0644', 'content_b64' => base64_encode(self::daemonJson())],
            ['id' => 'dir-state', 'type' => 'dir', 'path' => '/var/lib/cloud-manager', 'mode' => '0755'],
            // Restart only when the file is not the one the daemon was last started with.
            ['id' => 'restart', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "systemctl enable docker && systemctl restart docker && {$hash} > {$applied}"],
                'unless' => ['sh', '-c', "[ \"$({$hash})\" = \"$(cat {$applied} 2>/dev/null)\" ] && systemctl is-active --quiet docker"],
                'description' => 'Docker restarts with the new settings'],
        ]];
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public static function verify(): array
    {
        $script = implode("\n", [
            'systemctl is-active --quiet docker || { echo "Docker is not running"; exit 1; }',
            'docker version --format \'{{.Server.Version}}\' >/dev/null || { echo "docker version fails"; exit 1; }',
            '[ "$(docker info --format \'{{.LiveRestoreEnabled}}\')" = true ] || { echo "live-restore is not on"; exit 1; }',
            '[ "$(docker info --format \'{{.LoggingDriver}}\')" = json-file ] || { echo "the log driver is not json-file"; exit 1; }',
            'if ss -ltn 2>/dev/null | grep -qE ":(2375|2376)[[:space:]]"; then echo "Docker is listening on a TCP port"; exit 1; fi',
            'exit 0',
        ]);

        return ['name' => 'containers-verify', 'steps' => [
            ['id' => 'verify', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', $script], 'description' => 'Docker runs, live-restore is on, logs rotate, no TCP socket'],
        ]];
    }

    /** The reads for the Containers view, one command. */
    public static function readScript(): string
    {
        return implode('; ', [
            'echo "##version"; docker version --format \'{{.Server.Version}}\' 2>&1',
            'echo "##ps"; docker ps -a --filter "name=^cm-svc-" --format \'{{json .}}\' 2>&1',
            'echo "##stats"; ids=$(docker ps -q --filter "name=^cm-svc-"); if [ -n "$ids" ]; then docker stats --no-stream --format \'{{json .}}\' $ids 2>&1; fi',
            'echo "##df"; docker system df --format \'{{json .}}\' 2>&1',
        ]);
    }

    /**
     * @return array{version: ?string, containers: list<array<string, mixed>>, df: list<array<string, string>>}
     */
    public static function parseReport(string $output): array
    {
        $sections = ['version' => [], 'ps' => [], 'stats' => [], 'df' => []];
        $current = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^##(version|ps|stats|df)$/', trim($line), $m)) {
                $current = $m[1];

                continue;
            }
            if ($current !== null && trim($line) !== '') {
                $sections[$current][] = $line;
            }
        }
        $rows = fn (array $lines) => array_values(array_filter(array_map(fn ($l) => json_decode($l, true), $lines), 'is_array'));

        $stats = [];
        foreach ($rows($sections['stats']) as $s) {
            $stats[(string) ($s['Name'] ?? '')] = (string) ($s['MemUsage'] ?? '');
        }
        $containers = [];
        foreach ($rows($sections['ps']) as $p) {
            $name = (string) ($p['Names'] ?? '');
            $containers[] = ['name' => $name, 'state' => (string) ($p['State'] ?? ''), 'status' => (string) ($p['Status'] ?? ''), 'image' => (string) ($p['Image'] ?? ''), 'memory' => $stats[$name] ?? null];
        }
        $df = array_map(fn ($d) => ['type' => (string) ($d['Type'] ?? ''), 'total' => (string) ($d['TotalCount'] ?? ''), 'size' => (string) ($d['Size'] ?? ''), 'reclaimable' => (string) ($d['Reclaimable'] ?? '')], $rows($sections['df']));
        $version = trim($sections['version'][0] ?? '');

        return ['version' => preg_match('/^\d+\.\d+/', $version) ? $version : null, 'containers' => $containers, 'df' => $df];
    }
}
