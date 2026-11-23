# Architecture

How the pieces fit together as built today. [PLAN.md](../PLAN.md) is the
design and roadmap; the ADRs in [adr/](adr/) record why each major choice was
made; [specs/protocol.md](specs/protocol.md) and
[specs/security-model.md](specs/security-model.md) are the contracts. This
page is the map between them.

## The pieces

```
  Browser console / artisan            GitHub webhooks
            │                                │
            ▼                                ▼
┌────────────────────────────────────────────────────────┐
│  Control plane  (Laravel, control-plane/)              │
│  tenancy · vault · desired state · operations · audit  │
│  DigitalOcean client · organisation CAs · enrollment   │
└──────┬──────────────────────────────────▲──────────────┘
       │ HTTP, shared secret               │ POST /api/gateway/events
       │ 127.0.0.1:9444  /v1/nodes/...     │ connect · disconnect · progress
       ▼                                   │
┌────────────────────────────────────────────────────────┐
│  Session gateway  (Go, cmd/gateway)                    │
│  holds every agent connection, relays, nothing else    │
└──────▲─────────────────────────────────────────────────┘
       │ outbound mTLS (TLS 1.3) on :9443, held open
┌──────┴───────┐  ┌──────────────┐  ┌──────────────┐
│ agent        │  │ agent        │  │ agent        │   customer's DigitalOcean
│ (Droplet)    │  │ (pool member)│  │ (own server) │   account, plus LBs, managed
└──────────────┘  └──────────────┘  └──────────────┘   DBs/Valkey, Spaces, firewalls
```

**The main design choice: nodes connect out, and nothing connects in.** No
port listens on a node, and the platform holds no SSH key for any server.
See [ADR 0001](adr/0001-agent-not-ssh.md).

### Control plane: `control-plane/`

The control plane is the only component that decides anything. It holds
tenancy, encrypted credentials, the desired state of every resource, operation
history and the audit log. It is never in the request path of a customer
application: if it goes down, every site keeps serving
([management-server.md](management-server.md)).

| Module (`app/Lib/`) | Responsibility |
|---|---|
| `Vault/` | Envelope encryption for customer secrets (DigitalOcean tokens, GitHub App key, site `.env`s, CA keys). One master key, `VAULT_MASTER_KEY`. |
| `Audit/` | Append-only, hash-chained audit log. Every privileged action, including every command sent to a node, is recorded. |
| `Resources/` | A persisted state machine for every paid resource in a customer account, so a failed provision is resumable, not orphaned. |
| `DigitalOcean/` | API client with per-tenant rate limiting. |
| `Cloud/` | Droplets, cloud-init, load balancers, database clusters, Spaces, autoscale pools, base images, and the `Reconciler` that compares what DigitalOcean has with what we believe. There is no Terraform ([PLAN.md §1.3](../PLAN.md)). |
| `Pki/` | One certificate authority per organisation. |
| `Enrollment/` | Server half of the enrollment handshake: single-use token in, node certificate out. |
| `Gateway/` | `GatewayClient`, the control plane's only way to reach a node, and the organisation sync that tells the gateway which CAs exist. |
| `Operations/` | Long-running actions (deploy, rollback, install, agent upgrade, base image build) recorded as operations with stages and run on the queue. |
| `Sites/` | Sites and the `Deployer`. |
| `Servers/` | The server stack recipe, bring-your-own-server installs, agent upgrades and node removal. |
| `GitHub/` | The per-organisation GitHub App: push-to-deploy webhooks and clone tokens. |
| `Agent/` | The agent binaries served to booting nodes. |
| `Auth/` | Console sign-in with TOTP. |

The console is Blade views (`resources/views/console/`) backed by server
components (`resources/components/`) through the unscripted runtime
(`config/unscripted.php`). Every component call requires the signed-in
session. Artisan commands in `app/Console/Commands/` cover the same actions
from the CLI.

### Session gateway: `cmd/gateway`, `internal/gateway`

PHP-FPM handles one request and exits, so it cannot hold thousands of
long-lived connections. The gateway does that and only that
([ADR 0007](adr/0007-session-gateway.md)). It has two sides:

- **Agent side** (`:9443`). A mutual-TLS listener. A node belongs to the
  organisation whose CA signed its certificate. Nothing the node says about
  itself counts toward identity. The control plane pushes the set of
  organisation CAs with `PUT /v1/organisations`, and the gateway re-syncs
  them on every start.
- **Control plane side** (`127.0.0.1:9444`). A private HTTP API
  authenticated by a shared secret: `POST /v1/nodes/{id}/commands`,
  `GET /v1/nodes`, `GET /v1/nodes/{id}`. Anyone who can reach this API can
  run root commands on every node, so it must never be publicly reachable.
  The secret is a second line of defence, not the first.

The gateway posts node events back to the control plane at
`/api/gateway/events`: connects, disconnects, and command output lines. That
is the only way the control plane learns which nodes are alive.

### Agent: `cmd/agent`, `internal/agentcore`

The agent is a single static Go binary with no third-party dependencies
([ADR 0003](adr/0003-zero-dependency-agent.md)). It enrolls once, then holds
one outbound session with heartbeats and reconnects with backoff. It carries
out three kinds of work:

- **Recipes** (`internal/recipe`). An idempotent engine of file and exec
  steps that converges a server to a described state. It replaces Ansible
  ([ADR 0005](adr/0005-recipe-engine-not-ansible.md)).
- **Deploys** (`internal/deploy`). Atomic release directories, activation
  by switching a symlink, rollback, and pruning of old releases.
- **Commands** (`internal/commands`). Argument and result payloads shared by
  both ends.

### Shared Go packages

| Package | Purpose |
|---|---|
| `internal/protocol` | Wire format: a 4-byte length prefix plus a JSON envelope, 8 MiB maximum. Either side can originate messages. Specified in [specs/protocol.md](specs/protocol.md). |
| `internal/transport` | mTLS, session multiplexing, heartbeat, reconnect backoff. |
| `internal/pki` | Organisation CA, plus node and server certificate issuance. Uses ECDSA P-256 ([ADR 0004](adr/0004-ecdsa-p256.md)). |
| `internal/enroll` | Enrollment tokens and the client half of the handshake. |
| `internal/integration` | End-to-end tests over real TLS sockets. |

`cmd/conformance` writes `control-plane/tests/fixtures/conformance.json`,
which the PHP suite asserts against. This keeps the Go and PHP halves of
enrollment and certificate handling in agreement.

## Key flows

### Bringing a server up

1. The control plane creates a Droplet. Its `user_data` cloud-init carries a
   single-use enrollment token and the expected sha256 of the agent binary
   (`Cloud/CloudInit.php`). For a server you already own,
   `/install/{secret}` serves an equivalent installer.
2. The node downloads the agent from `/api/agent/{platform}` without
   authenticating. It has nothing to authenticate with yet. It checks the
   checksum before making the file executable.
3. The agent generates a key and calls `POST /api/enroll` with the token and
   a CSR. The organisation's CA signs it, and the token is spent.
4. The agent connects to the gateway over mTLS. From then on, every command
   reaches it through that session.
5. The server stack recipe (`Servers/ServerStack.php`) installs PHP, nginx,
   Composer, Supervisor and the `deploy` user.

### Running anything long: operations

A deploy, rollback, install or upgrade is an **operation**: a row in
`operations` with named stages, a trigger, an actor, and a log. The console
and webhooks call `OperationRunner::queue*()`, and the `cm-queue` worker runs
it. Artisan commands call the same `run()` inline, so the CLI and the console
leave identical history.

### Deploying a site

`Sites/Deployer.php` runs the stages
`setup → fetch → prepare → build → activate → workers → prune`. Each one is a
recipe or command sent to the node through `GatewayClient`, and progress
streams back as gateway events into the operation.

`GatewayClient` separates two outcomes that look alike:

- **Failed**: the command did not happen, so it can be retried.
- **Outcome unknown** (`CommandOutcomeUnknown`): a timeout or a lost
  connection, so the work may already have run.

Retrying an activation or a migration that actually ran is how a deploy
corrupts data while looking like it recovered. Callers have to handle the
unknown case deliberately.

### Building: who builds the code

The compute model decides who builds
([ADR 0006](adr/0006-build-strategy-follows-compute-model.md)):

- **Fixed servers, or a fixed pool behind a load balancer**: each server
  builds its own release.
- **Autoscale pools**: members are created by DigitalOcean with nobody
  watching, so they never build. The site's fixed primary builds the release,
  runs migrations and runs the scheduler. The release is stored as an
  artifact in the site's Spaces bucket.

### Autoscaling

`Cloud/AutoscalePools.php` manages a DigitalOcean Droplet Autoscale Pool that
adds web capacity to a site. A new member works like this:

1. It boots from the region's **base image** (`Cloud/BaseImages.php`), a
   snapshot with the server stack already installed and no identity, so it
   skips minutes of package installs. The image is rebuilt once it is a month
   old. If no image exists, the member boots stock Ubuntu.
2. It enrolls with the pool's token. `EnrollmentService` explains why one
   token is safe here.
3. It is joined to the site. The stack recipe runs (and finds everything in
   place), and the current release is fetched from the bucket. Only after
   that is the member added to the load balancer and the database firewalls.

DigitalOcean removes members without notice. `sites:reconcile-pools` runs
every minute and removes departed members from their sites.

### Scheduled jobs on the management server

These are defined in `scripts/mgmt/files/cron`:

| Schedule | Command | Why |
|---|---|---|
| every minute | `sites:reconcile-pools` | Detach pool members DigitalOcean removed |
| every 5 min | `github:redeliver-failed` | GitHub never retries a failed webhook |
| daily 03:17 | `backup:database` | Snapshot of the SQLite database, 14 kept |
| daily 04:41 | `sites:sync-balancer-certificates` | Copy renewed certificates onto load balancers |
| daily 05:23 | `images:refresh` | Rebuild base images older than 30 days |

## Deployment of the platform itself

One Droplet runs the control plane (nginx + PHP-FPM, SQLite), the gateway
(`cm-gateway.service`) and the queue worker (`cm-queue.service`). Everything
on it is written by `scripts/mgmt/setup.sh`. See
[management-server.md](management-server.md) for deploying, backups and
rebuilding.
