<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Agent\AgentRelease;
use SensitiveParameter;

/**
 * Builds the cloud-init user_data that turns a blank Droplet into a node.
 *
 * This is the one moment the platform gets to say anything to a machine
 * before it can talk back, so it has to carry everything the agent needs to
 * reach us and prove who it is:
 *
 *  - the agent binary
 *  - the organisation CA, so the agent can verify the control plane without
 *    trusting the host's root store
 *  - a single-use enrollment token
 *  - where to enroll and where to connect afterwards
 *
 * The token is the sensitive part. It is readable by anyone with DigitalOcean
 * API access to the customer's account, which is a group that already has
 * more power over these servers than the token confers, and it is single-use
 * and short-lived. The agent deletes the file once spent.
 *
 * Nothing here opens an inbound port. The agent dials out; see ADR 0001.
 *
 * Note the heredocs below close at column zero rather than being indented.
 * PHP strips the closing delimiter's indentation from every line, which
 * interacts badly with interpolating pre-indented blocks into a format where
 * indentation is syntax. Explicit is safer than computed here: a
 * mis-indented CA produces a file that parses and writes a broken PEM, and
 * the symptom is a node that boots fine and never enrolls.
 */
final class CloudInit
{
    public function __construct(
        private readonly AgentRelease $release,
        /** Base URL a booting node can reach. Not localhost. */
        private readonly string $baseUrl,
        private readonly string $serverName,
    ) {}

    /**
     * Which build a node gets.
     *
     * DigitalOcean offers both architectures, and a node that downloads the
     * wrong one fails in a way nobody can see: cloud-init reports success,
     * the binary will not execute, and the node never appears.
     */
    private function platformFor(string $architecture): string
    {
        return match ($architecture) {
            'arm64', 'aarch64' => 'linux-arm64',
            default => 'linux-amd64',
        };
    }

    /**
     * The same bootstrap as build(), as a shell script for a server the
     * platform did not create: "bring your own server". It is piped to bash
     * from a one-time URL, so it checks where it is before changing anything,
     * and it never changes the host's name.
     *
     * CM_NO_SERVICE=1 enrolls and stops short of systemd, for testing in a
     * container. The checksum of each architecture's build is embedded and
     * the right one chosen on the host, as uname reports it.
     */
    public function installScript(
        string $nodeId,
        #[SensitiveParameter] string $enrollmentToken,
        string $caCertificatePem,
    ): string {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $enrollmentToken) || ! preg_match('/^[a-z0-9.-]+$/', $nodeId)) {
            throw new \InvalidArgumentException('Refusing to embed an unexpected token or node id in a shell script.');
        }

        $base = rtrim($this->baseUrl, '/');
        $amd = $this->release->sha256('linux-amd64');
        $arm = $this->release->sha256('linux-arm64');
        $unit = $this->systemdUnit("{$base}/api/enroll");
        $ca = trim($caCertificatePem);

        return $this->unix(<<<SH
#!/usr/bin/env bash
# Unharbored agent installer for {$nodeId}. Generated for one use; the
# enrollment token inside is single use and expires within the hour.
set -euo pipefail

say() { printf '\\033[1;32m==>\\033[0m %s\\n' "\$*"; }
die() { printf '\\033[1;31m!!\\033[0m %s\\n' "\$*" >&2; exit 1; }

[ "\$(id -u)" = 0 ] || die "Run this as root: curl ... | sudo bash"
. /etc/os-release 2>/dev/null || die "Cannot tell which OS this is."
[ "\${ID:-}" = ubuntu ] && { [ "\${VERSION_ID:-}" = "22.04" ] || [ "\${VERSION_ID:-}" = "24.04" ] || [ "\${VERSION_ID:-}" = "26.04" ]; } || [ "\${CM_ANY_OS:-0}" = 1 ] \\
  || die "This server runs \${PRETTY_NAME:-an unknown OS}. The stack is built for Ubuntu 22.04, 24.04 and 26.04 (set CM_ANY_OS=1 to try anyway)."
STATE=/var/lib/cloud-manager
BIN=/usr/local/bin/cloud-manager-agent
UNIT=/etc/systemd/system/cloud-manager-agent.service

# Everything this script puts on the server, removed: the service, the program, and the state directory with the
# organisation CA, the single-use token and any half-written identity.
wipe_agent() {
  if command -v systemctl >/dev/null 2>&1 && [ -e "\$UNIT" ]; then
    systemctl disable --now cloud-manager-agent.service >/dev/null 2>&1 || true
  fi
  rm -f "\$UNIT" "\$BIN" "\$BIN.new"
  rm -rf "\${STATE:?}"
  if command -v systemctl >/dev/null 2>&1; then systemctl daemon-reload >/dev/null 2>&1 || true; fi
}

# An earlier run that stopped part way leaves some of that behind, and a service left running keeps trying to enrol with
# a token that is spent. Clear it first so this run starts from nothing. A server that did enrol keeps its identity
# unless it is told to give it up.
if [ -e "\$STATE/node.json" ]; then
  [ "\${CM_REENROLL:-0}" = 1 ] \\
    || die "This server is already enrolled with cloud-manager. To enrol it again, remove it in the console first, then run this with CM_REENROLL=1."
  say "Removing the earlier enrolment (CM_REENROLL=1)"
  wipe_agent
elif [ -e "\$STATE" ] || [ -e "\$UNIT" ] || [ -e "\$BIN" ] || [ -e "\$BIN.new" ]; then
  say "Cleaning up an earlier run that did not finish"
  wipe_agent
fi

# If this run stops before the agent is started, remove what it wrote, so the next run does not meet half of it.
started=0
trap 'rc=\$?; if [ "\$rc" != 0 ] && [ "\$started" != 1 ]; then say "Stopped before it finished: removing what was written, so running it again starts clean"; wipe_agent; fi' EXIT

case "\$(uname -m)" in
  x86_64)  platform=linux-amd64; sha={$amd} ;;
  aarch64) platform=linux-arm64; sha={$arm} ;;
  *) die "Unsupported architecture \$(uname -m)." ;;
esac

say "Installing prerequisites"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null
apt-get install -y -qq curl ca-certificates >/dev/null

say "Writing the organisation CA and enrollment token"
install -d -m 0700 /var/lib/cloud-manager
cat > /var/lib/cloud-manager/ca.crt <<'CA'
{$ca}
CA
chmod 0644 /var/lib/cloud-manager/ca.crt
umask 077
printf '%s' '{$enrollmentToken}' > /var/lib/cloud-manager/enroll.token
umask 022

say "Downloading the agent (\$platform) and checking it"
curl -fsSL --retry 5 --retry-delay 3 --cacert /var/lib/cloud-manager/ca.crt \\
  -o /usr/local/bin/cloud-manager-agent.new "{$base}/api/agent/\$platform"
echo "\$sha  /usr/local/bin/cloud-manager-agent.new" | sha256sum -c - >/dev/null || die "The agent failed its checksum; nothing was installed."
chmod 0755 /usr/local/bin/cloud-manager-agent.new
mv -f /usr/local/bin/cloud-manager-agent.new /usr/local/bin/cloud-manager-agent

if [ "\${CM_NO_SERVICE:-0}" = 1 ]; then
  say "Enrolling without installing the service (CM_NO_SERVICE=1)"
  /usr/local/bin/cloud-manager-agent -state-dir /var/lib/cloud-manager -sites-root /home/deploy \\
    -enroll-url {$base}/api/enroll -server-name {$this->serverName} \\
    -ca-file /var/lib/cloud-manager/ca.crt -token-file /var/lib/cloud-manager/enroll.token -enroll-only
  exit 0
fi

say "Installing the agent service"
cat > /etc/systemd/system/cloud-manager-agent.service <<'UNIT'
{$unit}
UNIT
systemctl daemon-reload
started=1
systemctl enable --now cloud-manager-agent.service >/dev/null

say "Done. {$nodeId} will appear in the console once the agent connects;"
say "the server stack then installs on its own."
SH);
    }

    /**
     * @return string YAML suitable for a Droplet's user_data field.
     */
    public function build(
        string $nodeId,
        #[SensitiveParameter] string $enrollmentToken,
        string $caCertificatePem,
        ?string $hostname,
        string $architecture = 'amd64',
    ): string {
        $platform = $this->platformFor($architecture);
        // A pool's Droplets are named by DigitalOcean, one template for all.
        $host = $hostname === null ? '' : "hostname: {$hostname}\npreserve_hostname: false\n";

        // Reading the checksum from the actual file is the point. A value
        // carried in configuration drifts from the binary being served, and a
        // node that fails its checksum never appears, with nothing to say why.
        $agentUrl = rtrim($this->baseUrl, '/').'/api/agent/'.$platform;
        $agentSha = $this->release->sha256($platform);
        $enrollUrl = rtrim($this->baseUrl, '/').'/api/enroll';

        // Both blocks sit under `content: |` at six spaces of nesting.
        $ca = $this->indent($caCertificatePem, 6);
        $unit = $this->indent($this->systemdUnit($enrollUrl), 6);

        // write_files runs before runcmd, so the token and CA are in place
        // before the agent is asked to use them.
        return $this->unix(<<<YAML
#cloud-config
# Generated by the Unharbored control plane. Do not edit by hand:
# this is rebuilt from CloudInit.php on every provision.

{$host}
package_update: true
package_upgrade: false

packages:
  - ca-certificates
  - curl

write_files:
  # The organisation CA. The agent pins this rather than trusting the host
  # root store, so a coerced public CA cannot impersonate the control plane
  # to this node.
  - path: /var/lib/cloud-manager/ca.crt
    owner: root:root
    permissions: '0644'
    content: |
{$ca}

  # Single use, short lived, and bound to one node identity. The agent
  # deletes this once enrollment succeeds.
  - path: /var/lib/cloud-manager/enroll.token
    owner: root:root
    permissions: '0600'
    content: {$enrollmentToken}

  - path: /etc/systemd/system/cloud-manager-agent.service
    owner: root:root
    permissions: '0644'
    content: |
{$unit}

runcmd:
  - install -d -m 0700 /var/lib/cloud-manager
  # The control plane presents a certificate from the organisation CA, which
  # no host root store trusts, so the download pins that CA as the agent does.
  - curl -fsSL --retry 5 --retry-delay 5 --cacert /var/lib/cloud-manager/ca.crt -o /usr/local/bin/cloud-manager-agent {$agentUrl}
  # Verify before making it executable. An agent fetched over a hijacked
  # connection would otherwise run as root on first boot.
  - echo '{$agentSha}  /usr/local/bin/cloud-manager-agent' | sha256sum -c -
  - chmod 0755 /usr/local/bin/cloud-manager-agent
  - systemctl daemon-reload
  - systemctl enable --now cloud-manager-agent.service

# A node that cannot reach the control plane is unmanaged, not broken, so
# there is nothing to do here but let the agent keep retrying.
final_message: "Unharbored agent bootstrapped for node {$nodeId}"
YAML);
    }

    /**
     * Force LF line endings.
     *
     * Not cosmetic. This file is written on a Windows workstation, and PHP
     * heredocs preserve whatever line endings the source file happens to have,
     * so a CRLF checkout produced cloud-init with CRLF throughout.
     *
     * The result boots and fails invisibly. YAML parsers tolerate CRLF, so the
     * document still parses, but every runcmd entry arrives at the shell with
     * a trailing carriage return: "sha256sum -c -
" is not a command, the
     * checksum step fails, the agent is never installed, and the Droplet comes
     * up looking perfectly healthy with nothing on it.
     *
     * Normalising here rather than relying on .gitattributes or an editor
     * setting means it cannot come back through a stray checkout.
     */
    private function unix(string $yaml): string
    {
        $cr = chr(13);
        $lf = chr(10);

        // chr() rather than escape sequences, so the fix cannot itself be
        // broken by the quoting of whatever edits this file. That is not
        // hypothetical: it happened twice while writing it.
        return str_replace([$cr.$lf, $cr], $lf, $yaml);
    }

    /**
     * The systemd unit. Restart always, because an agent that has stopped is
     * a node that has silently left the fleet.
     */
    private function systemdUnit(string $enrollUrl): string
    {
        return $this->unix(<<<UNIT
[Unit]
Description=Unharbored agent
Documentation=https://github.com/unharbored/unharbored
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/cloud-manager-agent \\
  -state-dir /var/lib/cloud-manager \\
  -sites-root /home/deploy \\
  -enroll-url {$enrollUrl} \\
  -server-name {$this->serverName} \\
  -ca-file /var/lib/cloud-manager/ca.crt \\
  -token-file /var/lib/cloud-manager/enroll.token

Restart=always
RestartSec=5s

# The agent manages nginx, PHP-FPM and systemd units, so it runs as root.
# These limit what a compromised agent can do to the rest of the machine
# without preventing it doing its job. No RestrictSUIDSGID: the agent installs
# packages, and their scripts set setuid and setgid bits (fontconfig-config's
# chmod 2775 on /usr/local/share/fonts failed under it, breaking php-gd).
NoNewPrivileges=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictRealtime=true

[Install]
WantedBy=multi-user.target
UNIT);
    }

    /**
     * Indent a block for embedding under a YAML literal scalar.
     *
     * Blank lines are left empty rather than padded, because trailing
     * whitespace in a PEM block is the kind of thing that survives review and
     * breaks parsing somewhere else entirely.
     */
    private function indent(string $text, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $pad.$line,
            explode("\n", rtrim($text, "\n"))
        ));
    }
}
