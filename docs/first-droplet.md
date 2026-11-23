# Provisioning the first real Droplet

> Historical: how the first Droplet was brought up by hand, 2026-11-05. The
> management server is now built by `scripts/mgmt/setup.sh`; see
> [management-server.md](management-server.md). Agents use
> `agent.example.com`, not an IP-derived name.

This was first done on 2026-11-05 and worked on the first provision, after
the fixes below were found while setting up. The notes in each step are what
that run turned up.

## The constraint that shapes everything

**The gateway cannot go behind an HTTP tunnel.**

ngrok, cloudflared and similar terminate TLS and forward HTTP. That is fine
for enrollment, which is an ordinary HTTPS request. It does not work for the
gateway, which needs a raw TCP connection carrying mutual TLS: the node
presents a client certificate, and a tunnel that terminates TLS strips it.

So the control plane and gateway need a real, routable address. The simplest
way to get one is to run them on a Droplet.

## The shape

Two Droplets:

```
  management Droplet              managed Droplet
  ------------------              ---------------
  Laravel control plane     <---  enrols over HTTPS
  Go gateway                <---  connects over mTLS
  nginx (TLS termination)
```

The managed Droplet is the thing under test. The management Droplet exists
only to be reachable, and can be the smallest size DigitalOcean sells.

Cost is a few pence for an afternoon. Destroy both when done.

## What you need to do

### 1. A DigitalOcean API token

Create one at **API → Tokens → Generate New Token**, with **write** scope.

**Do not paste it into a chat.** The `cloud:connect` command prompts for it
with hidden input so it never passes through anything else:

```bash
php artisan cloud:connect org-1 --name "Personal"
```

It verifies the token against the DigitalOcean API immediately and tells you
which account it belongs to.

### 2. A management Droplet

Smallest size, Ubuntu 24.04, in a region near you. Note its public IPv4.

**It needs a hostname, not a bare IP.** Server certificates carry their names
as DNS names, and Go will not match an IP address against one. Without a
domain to spare, use sslip.io: `203-0-113-10.sslip.io` resolves to
203.0.113.10 and costs nothing. That name goes in every setting below.

Install PHP 8.3, Composer, nginx and sqlite3. Go is not needed on the server:
build the agent and the gateway on your workstation and copy them up.

```bash
bash scripts/build-agent.sh
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -ldflags "-s -w" -o gateway ./cmd/gateway
```

If you copy the repository up rather than cloning it, delete
`control-plane/bootstrap/cache/*.php` on the server afterwards. A cache
generated on a development machine lists dev-only packages, and a
`composer install --no-dev` then fails on a missing service provider.

### 3. Open the right ports, and only those

| Port | Faces | Must be reachable |
|---|---|---|
| 443 | the managed Droplet | yes, enrollment and the agent download |
| 9443 | the managed Droplet | yes, the gateway session listener |
| 9444 | localhost only | **no**, this is the gateway control API |
| 22 | you | your address only |

Port 9444 is the one to be careful about. Anything that can reach it and
present the shared secret can run commands as root on every connected node.
Bind it to loopback and leave it there.

### 4. Configure and check

```bash
cd control-plane
cp .env.example .env
php artisan key:generate
php artisan vault:generate-key        # put the result in VAULT_MASTER_KEY

# Set in .env:
#   CONTROL_PLANE_PUBLIC_URL=https://<hostname>
#   CONTROL_PLANE_SERVER_NAME=<the same hostname>
#   CONTROL_PLANE_SESSION_ADDRESS=<hostname>:9443
#   GATEWAY_TOKEN=<openssl rand -base64 32>

php artisan migrate
../scripts/build-agent.sh             # builds both architectures
php artisan cloud:connect org-1 --name "Personal"
```

`cloud:connect` needs a real terminal for its hidden prompt. Run it over
`ssh -t` from your own terminal, not through a tool that pipes stdin.

Run artisan as `www-data` so the SQLite database and storage stay writable by
PHP-FPM. Tinker also wants a writable home for its history, so prefix it:
`sudo -u www-data HOME=/tmp php artisan tinker ...`.

Mint the certificates the gateway and nginx present. Both must come from the
organisation CA, because that is what the agent pins:

```bash
php artisan tinker --execute='
$ca = app(App\Enrollment\EnrollmentService::class)->certificateAuthorityFor("org-1");
[$cert, $key] = $ca->issueServerCertificate(config("control_plane.server_name"), [config("control_plane.server_name")]);
file_put_contents("/etc/cloud-manager/server.crt", $cert);
file_put_contents("/etc/cloud-manager/server.key", $key);
file_put_contents("/etc/cloud-manager/ca.crt", $ca->certificatePem());
'
```

Point nginx at that certificate and proxy to the Laravel application, then
start the gateway:

```bash
gateway -agent-addr 0.0.0.0:9443 -api-addr 127.0.0.1:9444 \
        -cert-file /etc/cloud-manager/server.crt \
        -key-file /etc/cloud-manager/server.key \
        -token-file /etc/cloud-manager/gateway.token \
        -events-url https://<hostname>/api/gateway/events

php artisan gateway:sync-organisations
```

The gateway posts events to Laravel over HTTPS using the system trust store,
and Laravel is serving the organisation CA's certificate. Trust that CA on the
management Droplet, or every event fails TLS:

```bash
cp /etc/cloud-manager/ca.crt /usr/local/share/ca-certificates/cloud-manager-org-1.crt
update-ca-certificates
```

**Testing from an office network.** If your workstation sits behind a TLS
inspecting firewall, checks from it will fail with an unknown issuer even on
port 9443. Look at the issuer `openssl s_client` reports before debugging the
certificate. Droplet to Droplet traffic is unaffected.

Then, before spending anything:

```bash
php artisan cloud:preflight org-1
```

It checks the database, the credential, the vault, the CA, the agent builds,
the public URL, the gateway, and whether the gateway knows about the
organisation. It reports every problem at once, and each one says what would
break. Do not provision until it is clean.

### 5. Provision

```bash
php artisan tinker --execute='
$r = app(App\Cloud\DropletProvisioner::class)->provision(
  organizationId: "org-1",
  slug: "web-1",
  spec: ["region" => "lon1", "size" => "s-1vcpu-1gb", "image" => "ubuntu-24-04-x64"],
);
echo $r->state->value, " ", $r->externalId, PHP_EOL;
'
```

Then watch:

```bash
php artisan gateway:status org-1
```

## When it does not work

It probably will not, first time. In rough order of likelihood:

**The Droplet never appears.** Almost always cloud-init. SSH in and read
`/var/log/cloud-init-output.log`. The checksum step and the download are the
two that fail.

**The agent downloads but will not start.** `journalctl -u cloud-manager-agent`.
Usually the certificate name does not match, or the gateway address is wrong.

**It enrolls and then does not connect.** Enrollment reaches port 443 and the
session needs 9443. Check the firewall, and check that the gateway certificate
is issued by the organisation CA rather than a public one.

**It connects and then drops.** Look at the gateway log. The heartbeat read
deadline is three intervals, so a flaky link shows up here first.

**Nothing is tracked afterwards.** `php artisan cloud:reconcile org-1`. If a
Droplet exists under our tag with no record, that is exactly the condition
reconciliation was built to surface, and it means something went wrong between
creating it and recording the id.

## Clean up

```bash
php artisan cloud:reconcile org-1     # confirm nothing is left untracked
```

Then destroy both Droplets. The reconcile matters: destroying the management
Droplet first would leave you unable to see what the platform created.

## What this proves, and what it does not

Success here closes the last Phase 0 gap: a Droplet created by the platform,
bootstrapped by our cloud-init, running our agent, enrolled against our
control plane and holding a session through our gateway.

It does not prove anything about Phase 1. No application is deployed, nginx
and PHP-FPM are not installed on the node, and nothing has been released.
That is the next phase, and it is all buildable locally once this works.
