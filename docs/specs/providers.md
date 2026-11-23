# Providers: one per server and application, none mixed

Status: phases 0 to 4 built, with an AWS provider (EC2 servers, settable endpoint URL) from phase 5. Phase 6 (the scaler) is built, with DigitalOcean the only provider that can use it. Phase 7 (Hetzner) is built. Phases 8 onward are not started. Replaces the first bullet of ROADMAP Phase 7c.

## What was asked

1. The provider is an **option on each server we build and on each
   application**. An application lives wholly on one provider: its servers,
   database, balancer, bucket and scaling. **No mixing.**
2. **Custom** is a provider too. It has no cloud API, so it gets exactly one
   way to provision: we log in as root over SSH and install the agent, as Forge
   does.
3. Support **AWS**, **Railway** and others where possible, with code that
   does not assume a provider.
4. Providers will have **incomplete feature sets**. The design has to say what
   happens when one lacks something.

## Decided

| Question | Decision |
|---|---|
| Self-run services (we run a database, balancer or object store on a server we manage) | **Design now, build later.** The first release resolves a gap to a single-server app with local services. |
| Object storage the provider does not offer | The user handles it **in the app's environment** (their own S3 credentials in `.env`). cloud-manager does not provision, track, bill or back it up. This is not a provider "mix". We hold no resource for it. |
| Autoscaling | **One provider-independent autoscaler**, driven by the agent's metrics and the server driver. Provider-native pools (DigitalOcean's, AWS's) are documented as a possible later optimisation. |
| A shape the provider cannot support | **Shown but locked, with the reason.** |

Related: [server-runtime.md](server-runtime.md) covers what a server gives its
owner (a login user, SSH keys, databases on the machine, scheduler, nginx and
PHP settings). Its changes to `ServerStack` and `CloudInit` come before this
plan's refactor of them, and its "databases on the server" is this plan's phase 4.

## What I found in the code

| Fact | Consequence |
|---|---|
| `cloud_credentials` already has `provider`, unique per organisation. | One credential per provider per organisation is already the model. |
| `resources` is provider-neutral in shape (`external_id` is a string, `attributes` is JSON) but its type is the literal `droplet`. | Rename the type to `server`; add a `provider` column. |
| The Go agent and gateway do not know about DigitalOcean. Only comments say "Droplet". | The agent needs no change. Everything is in the control plane. |
| 27 PHP files reach DigitalOcean through `CredentialStore::clientFor()`, which returns a DO-specific `Client`. About 3,300 lines in `app/Lib/Cloud`: `DropletProvisioner`, `LoadBalancers`, `DatabaseClusters` (557 lines mixing DO calls with site logic), `Spaces`, `AutoscalePools`, `BaseImages`, `Reconciler`. | The refactor is real work, but it is mechanical. |
| `NewApplications::REGIONS` and `SIZES` are DO constants. The wizard, price estimate and API validate against them. | Regions and sizes must come from the provider. |
| **Multi-server needs four provider services, not two.** `Deployer::ship` and pool members get each build from a bucket; the app needs a balancer and a database every server shares (`NewApplications` already refuses autoscale on SQLite); the database needs firewall access per server. | A provider with no object store cannot do multi-server, even with a balancer. See "Artifact transport" below. |
| Autoscaling today is **DigitalOcean's native Droplet Autoscale Pool** (`AutoscalePools`). | Replaced by the provider-independent scaler, for new applications. |
| `WorkerScaling` already scales queue workers from the app's own metrics. | The pattern (metrics in, a bounded decision out, a cooldown) exists. |
| "Add your own server" already exists: a one-time install command the user runs (`ServerInstall`, 15-minute link), followed by the `adopt` operation that waits for the agent and installs the stack. | Custom reuses all of it. Only the step that *runs* the command changes. |
| ADR 0001 chose an outbound agent over inbound SSH, so we hold no root keys. SSH is "break-glass". | Custom must use SSH **once** and keep nothing. |
| `phpseclib/phpseclib` is already in `composer.json`. | No new dependency for SSH. |
| Spaceship DNS is separate from hosting. It lives in `app/Lib/Dns`, with its key in the vault and no database row. It holds one domain per organisation. | Not a "mix". **DNS providers are a separate kind of integration from cloud providers**, with their own interface, keys and screen. See "DNS providers" below. |
| Today the DigitalOcean account is connected by an artisan command (`ConnectDigitalOcean`). | Generalise it first; a console page comes later. |

## Design

### Rules

1. `provider` is set at creation and **never changes**. Moving means a new
   server or application.
2. A server has one provider. An application has one provider, and every
   resource **we manage** for it (nodes, database cluster, cache cluster,
   balancer, bucket, preview servers) must have that same provider.
3. Nothing checks `if provider === 'digitalocean'`. What a provider can supply
   is data (below), and the wizard, API and estimates read it.
4. A provider has a **method**: `api` (we create the machine) or `ssh` (we
   adopt a machine that exists).

### Who supplies a service

An application needs services: somewhere to run, a database, a cache, file
storage, a balancer. Each can be supplied one of four ways:

| Supplied by | What it is | Needs | Status |
|---|---|---|---|
| **Native** | The provider's own product (DO managed database, AWS ALB) | One adapter per provider | DigitalOcean today; AWS later |
| **Local** | Runs on the app's own server: SQLite, or Postgres, MySQL or Valkey installed there, as Forge does | A server | Built in the committed scope |
| **Yours** | The user's own service, configured in the app's `.env` (for example an S3 bucket for uploads, or an external database), or one the application does not need. We keep no record. Shown in the wizard as **None**. | Nothing from us | Always available for uploads, cache and the database |
| **Self-run** | We run it on a separate server we manage on the same provider (HAProxy, a database server, MinIO) | A server, plus a recipe written once | **Database servers built** (PostgreSQL and MySQL, `DatabaseServers`); the rest designed, not built |

Self-run is the key to incomplete providers: it is written once and works on
every provider that gives us servers, Custom included. Native is written once
per provider. Deferring it means a provider with few native services offers
fewer shapes until we build it. It does not mean the design changes.

Every choice carries a label so no one is surprised about who is responsible:
**provider-managed** (native), **managed by cloud-manager** (local, self-run),
or **managed by you** (yours).

### What each shape needs

Single server needs nothing from the provider.

| Service | Single server | Multi-server | Autoscale |
|---|---|---|---|
| Servers (create or adopt) | yes | yes | yes, **create** (the scaler makes and destroys them) |
| Balancer (the edge) | no | native or self-run | native or self-run |
| Database every server shares | local or SQLite is fine | native, self-run or **yours** (None: an external database the user configures) | native, self-run or **yours** |
| Build artifact store | no | native object storage (see below) | native object storage |
| Firewall access from each server to the database | no | yes | yes |
| Uploads shared across servers | no | the user's job: **yours** or native | same |
| Metrics | agent | agent | agent |

### Provider profiles

What each provider supplies **natively**, and so which shapes it offers
without self-run:

| | DigitalOcean | AWS, servers only | AWS, managed | Custom | A typical small VM provider |
|---|---|---|---|---|---|
| Method | api | api | api | ssh | api |
| Create servers | yes | yes | yes | adopt | yes |
| Balancer | yes | no | ALB | no | often |
| Managed database | pg, mysql, valkey | no | RDS, ElastiCache | no | rarely |
| Object storage | Spaces | no | S3 | no | rarely |
| Private network | VPC | VPC | VPC | no | varies |
| **Single server** | yes | yes | yes | yes | yes |
| **Multi-server** | yes | locked | yes | locked | locked (no object store) |
| **Autoscale** | yes | locked | yes (derived scaler) | locked | locked |

Single server is always open. The locks lift when self-run services exist, or
when the provider's own products do.

### Resolving shapes and showing locks

`Shapes::for($provider)` takes the provider's supply list and returns the three
shapes, each `available` or `locked` with a reason that names the missing
service and the provider:

- "Multi-server needs a load balancer. AWS (servers only) does not offer one yet."
- "Autoscaling needs object storage for builds. Hetzner does not offer it."
- "Custom servers are machines you provide, so cloud-manager cannot create more."

The wizard picks the shape first. A locked shape stays visible, disabled, with
its reason. Within an available shape, each service lists only what the
provider can supply, ordered native, local, yours, with the cheapest working
choice preselected and its label shown. The API returns the same data as
`GET /providers`, with `available` and `reason` on each shape, so the CLI and
MCP can explain a refusal the same way.

### Where the code goes

New area `app/Lib/Providers/`, per the repo's layout rule. Adapters under
`Providers/DigitalOcean`, `Providers/Aws`, `Providers/Custom`.

```php
interface Provider
{
    public function id(): string;              // 'digitalocean' | 'aws' | 'custom'
    public function label(): string;
    public function method(): string;          // 'api' | 'ssh'
    public function supplies(): array;         // ['servers', 'edge', 'database:pg', 'object_storage', ...]
    public function credentialFields(): array; // what the connect form asks; [] for custom
    public function catalog(): Catalog;        // regions, and 4 size tiers with approximate monthly price
    public function verify(array $credential): Account;
    public function servers(): ServerDriver;   // api providers only
}

interface ServerDriver
{
    public function create(ServerSpec $spec): string;          // external id; idempotent on $spec->idempotencyKey
    public function find(string $externalId): ?ProviderServer; // pending | running | failed | gone; public and private IP
    public function destroy(string $externalId): void;         // idempotent
    public function listTagged(string $tag): array;            // for the reconciler's orphan check
}
```

Errors are provider-neutral: `Rejected` (the provider said no; nothing was
created) and `Uncertain` (timeout or 5xx; a server may exist). This is the
distinction `DropletProvisioner` already makes (an uncertain create becomes
`Orphaned`, not `Failed`), moved out of DO-specific code so every adapter
gets it. `DropletProvisioner` becomes `ServerProvisioner`: the state machine,
enrollment token and cloud-init stay; only the API call moves behind
`ServerDriver`. Cloud-init is a standard format that EC2 and most others
accept, so `CloudInit` is reused as is.

**Service behaviour is abstracted when it has a second implementation, not
before.** `supplies()` is the data the wizard needs now. The behaviour
classes (`LoadBalancers`, `DatabaseClusters`, `Spaces`) stay DigitalOcean's
until a second provider needs one. Then each becomes a driver beside the
provider adapters: `EdgeDriver` (add and remove a backend, health check,
certificate sync), `DatabaseDriver`, `ObjectStoreDriver`. The first to be
split is `EdgeDriver`, in the autoscaler phase, because the scaler needs it.

### The autoscaler

One scaler for every provider, replacing `AutoscalePools` for new applications.
It is a reconciler loop in the control plane:

- **Input:** the agent's per-server CPU samples (`node_metrics`), already
  collected, and the application's settings (min, max, CPU target, size).
- **Scale up:** when sustained CPU on the application's pool stays over the
  target for a window, and the cooldown has passed, create one server with
  `ServerProvisioner`. It enrolls, then is joined in a fixed order: stack, the
  application's nginx settings and the primary's PHP settings, its setup script
  (see [server-runtime.md](server-runtime.md); a failing script stops the join),
  the current build from the bucket, and only then the balancer and the database
  firewall.
- **Scale down:** when sustained CPU is well under the target, drain one
  pool-created server from the balancer, wait for connections to finish,
  release its database access, then destroy it.
- **Bounds:** never below min or above max; one scaling action at a time per
  application; a cooldown after each action; the primary is never removed;
  only servers the scaler created are ever destroyed.
- **Safety:** every action is an operation in the log, uses the existing
  resource state machine (a half-created server is `Orphaned`, not lost), and
  the reconciler checks for pool members the provider has but we do not.
- **Needs:** a provider that creates servers, an edge, a shared database and
  an object store. These are the same requirements as the autoscale shape, so
  the lock reasons above already say what is missing.

**Provider-native pools are documented as a future option**, not built. A
native pool (DigitalOcean Droplet Autoscale Pool, AWS Auto Scaling Group)
reacts faster, replaces failed servers itself, and keeps scaling if the
control plane is down. Our scaler does none of those. The cost of ours is that
reaction time follows the metrics interval and scaling stops if the control
plane does. We accept that for one implementation across all providers.
`AutoscalePools` stays for existing pools until they are migrated or retired.

### Artifact transport

`Deployer::ship` and pool joins fetch each build from a bucket. That is why a
provider without object storage is locked out of multi-server, and "yours in
the env" does not fix it: that covers the **app's uploads**, while the build
artifacts belong to cloud-manager. The way to unlock it without an object store
is to let nodes get the build another way, such as pulling it from the primary
over the agent connection, or a short-lived signed URL served by the control
plane. This needs a look at build sizes and what the gateway can carry before
choosing. Until then the lock stands, with its reason. **Open question.**

### Data model

One migration, idempotent, with a backfill:

- `nodes.provider` (string). Backfill: `digitalocean` where a matching
  `resources` row of type `droplet` exists, else `custom`. Today's `web-1`
  ("Not a Droplet this platform created") becomes `custom`.
- `resources.provider` (string, default `digitalocean`); rename
  `type = 'droplet'` to `'server'` in code and data. Clusters, balancers,
  buckets and pools take the provider too.
- `sites.provider` (string), copied from the node at creation and
  immutable. A preview inherits its parent's.
- `cloud_credentials` is unchanged; the unique index already fits.
- Attribute keys stay neutral: `region`, `size`, `ipv4`, `private_ipv4`.
- Where a service is local or "yours", the site records it as such, not as a
  resource row: for example `database_mode = local:pg`, `storage_mode = yours`.

### Keeping providers from mixing

One function, `ProviderScope::assertSame($site, $resource)`, called at every
attach point: adding a node to a site, attaching a database or cache cluster,
creating or attaching a balancer or bucket, scaler joins, creating a preview.
Then three safeguards:

1. The UI never offers a mismatched choice (the wizard filters by provider).
2. The API rejects one with a clear message.
3. The reconciler reports any site whose resources disagree.

Previews: today there is one shared `preview-1`. A preview must be on the
application's provider, so each provider gets its own preview server, created
the first time it is needed. Custom has none: previews run on the
application's own server, or are unavailable.

### Credentials

- API providers: stored in the vault as today, one per provider per
  organisation. **Managed on the Account page under "Provider keys"**, not
  only by artisan command (`cloud:connect`, `dns:connect`); the commands stay
  for first setup on a bare server. See "Provider keys" below.
- **AWS: prefer an IAM role the organisation creates, assumed with an
  external ID, over long-lived access keys.** Access keys are the fallback.
- **Custom: no stored credential at all.** See below.

### Provider keys on the Account page: two lists

**Today.** A DigitalOcean token is connected with an artisan command and kept in
the vault, with a `cloud_credentials` row (provider, kind, account, when it was
verified, when revoked). The Spaceship DNS key and secret are kept in the vault
alone, with no row, for one domain. Neither records an expiry, and neither can be
seen or replaced from the console.

**Design.** The Account page has **two separate panels**, because the two kinds
of provider do different jobs and follow different rules:

| | **Cloud providers** | **DNS providers** |
|---|---|---|
| They do | Create and manage servers, databases, balancers and storage | Add and remove a domain's DNS records |
| Today | DigitalOcean; AWS planned | Spaceship |
| One application uses | Exactly one, for everything it runs on | One per domain, independent of where it runs |
| The "no mixing" rule | Applies | Does not apply |
| A key covers | An account | One or more domains |
| Without one | Nothing can be created | The console shows the records to add at the registrar |
| Code | `app/Lib/Providers` | `app/Lib/Dns` |

One row per connected provider shows:

- the provider and the kind of key (API token, DNS key and secret, IAM role);
- **the key's first characters and last four** (`dop_v1_••••••••c4f2`) and when
  it last verified. A cloud row names the account; a DNS row lists its **domains**;
- **what depends on it** ("3 servers, 1 database cluster, 1 bucket", or
  "records for 4 applications");
- **its expiry, with a countdown**, coloured: green when healthy, amber inside
  14 days, red when expired or rejected, grey when none is set.

The actions are Test connection, **Replace key**, **Change expiry**, and
Disconnect. Disconnect is blocked, with the reason on the button, while any
resource needs the key to be managed.

Decisions:

- **The full key is never shown again.** Only a hint is kept in the clear
  (prefix and last four). A lost key is replaced, not recovered. These keys are
  account-wide, so we do not offer a "reveal", unlike an application's variables.
- **Expiry is entered by the user** (optional). DigitalOcean and Spaceship do not
  report it, so it is "the date you chose when you created the key". A key can
  still die early, so each one is **verified daily**, and a provider rejecting
  it shows as Expired or Rejected at once.
- **Alerts** use the existing Alerts system: 14, 7 and 1 days before the expiry,
  and immediately on a rejection. A banner shows on the Account page.
- **Replacing is safe.** The new key is verified first; the old one is deleted
  from the vault only after that. A key that fails verification is not saved.
- **AWS prefers a role.** The console shows an external ID to copy into the
  role's trust policy. A role has no secret and no expiry, so those fields
  disappear. Access keys, if used, can carry a rotate-by date.
- **Audit.** Connecting, replacing, changing the expiry and disconnecting are
  recorded. The key itself never is.

Data, kept apart as the two lists are:

- **Cloud:** `cloud_credentials` gains `key_hint` (prefix and last four), `label`
  and `expires_at` (nullable). Its unique index (organisation, provider) stays.
- **DNS:** a new `dns_credentials` table with the same columns and a `domains`
  list, and a vault entry per key and secret. The existing Spaceship key is
  backfilled into it, which also lifts today's limit of one domain per
  organisation. It is a separate table, not a `kind` in `cloud_credentials`,
  because a DNS key is not an account that creates resources and must never be
  offered where a cloud provider is chosen.
- The daily check calls `CredentialStore::verify` for cloud keys and the DNS
  client's own check for DNS keys, recording `verified_at` and `last_error`.
  Bucket access keys that cloud-manager creates itself for an application are
  platform-managed and are not listed here.

### DNS providers

A DNS provider is an interface of its own, next to the cloud `Provider`:

```php
interface DnsProvider
{
    public function id(): string;                     // 'spaceship'
    public function verify(array $credential): array; // the domains the key can manage
    public function records(string $domain): array;
    public function upsert(string $domain, array $records): void;
    public function remove(string $domain, array $records): void;
}
```

Spaceship is the only implementation. Cloudflare, Route 53 and DigitalOcean DNS
would each be one more class; a provider with no DNS API is the reason the
"show the records to add yourself" path exists. A domain is mapped to a DNS
credential when it is added to an application, and an application on any cloud
provider can use any connected DNS provider. The new-application wizard and the
domain settings pick the provider from the domain, not from the cloud choice.

## Custom: provisioning over root SSH

### Flow

1. The user enters host, SSH port, user (default `root`), and a private key
   (with passphrase) or a password.
2. The console shows the **control plane's outbound IP** to allow-list, as
   Forge does. SSH is needed only for this step.
3. An `adopt-ssh` operation runs:
   1. **Connect**, record the host key fingerprint in the operation log.
   2. **Preflight**, changing nothing: Ubuntu 24.04 (amd64 or arm64), root or
      passwordless sudo, systemd, at least 1 GB RAM, ports 80 and 443 free,
      outbound 443 to the control plane and 9443 to the gateway, apt works.
      Any failure stops with the specific reason.
   3. **Install the agent**: run the same install script `ServerInstall`
      already serves, with an enrollment token minted at that moment.
   4. Hand over to the **existing `adopt` operation**: wait for the agent to
      enroll, then install the stack.
4. **Discard the credential** and never use SSH again. Repair, deploy,
   upgrade and removal all go through the agent (ADR 0001 holds).

### Credential handling

- Held in the vault under a one-off key with a short expiry, because the work
  runs in a queue worker. Deleted in a `finally` block and again by a
  scheduled sweep of anything older than an hour. Never written to the job
  payload, the audit log (redacted) or the operation log, which shows only the
  fingerprint.
- Prefer a key to a password in the UI; allow both.
- The host key is trusted on first use and shown, with an optional "expected
  fingerprint" field.
- ADR 0001 is amended: SSH is used once to bootstrap, never stored, never the
  control channel. A server also gets a login user for its owner, with the
  owner's public keys (server-runtime.md); that is break-glass access for
  people, not a control channel.

### What Custom gives

Single server, with local services (SQLite, or Postgres, MySQL or Valkey on the
machine) and uploads on disk or in the app's `.env`. No region or size catalog,
no price estimate, no destroy ("Disconnect" uninstalls the agent as now).
**Backups stay on that machine's disk** unless the user points them somewhere,
and the console says so where backups are shown. Multi-server on Custom becomes
possible when self-run services exist: the user's own machines would take the
edge and database roles.

## Providers

### DigitalOcean (refactor, no behaviour change)

Wrap the existing client in a `DigitalOceanProvider` and `ServerDriver`. The
existing tests, which use `Http::fake`, must pass unchanged; that is the proof
nothing moved.

### AWS: two steps

**A. Servers only.** EC2 `RunInstances` with a `ClientToken` (idempotency),
tags, `UserData`, and the Ubuntu 24.04 AMI from Canonical's public SSM
parameter. One security group per organisation and region, opening 80 and 443
and nothing else (no SSH; the agent dials out). `DescribeInstances` for state
and addresses, `TerminateInstances` to destroy. An Elastic IP is optional;
without one the address changes on stop and start, and sslip.io domains
depend on the address, so decide this up front. Check first: EC2's user-data
limit is 16 KB (measure our cloud-init), and accounts without a default VPC
need a subnet chosen. An AWS application in this step is single-server with
local services, the same shape as Custom.

**B. Managed services** (only if wanted): ALB with target groups and ACM
certificates, RDS in a private subnet with security-group rules in place of
DigitalOcean's trusted sources, ElastiCache for Valkey, S3. **Not** Auto
Scaling Groups: our scaler covers that. Large, and its own project.

**Dependency:** `aws/aws-sdk-php` is large; hand-signing SigV4 over Laravel's
HTTP client is small for EC2 but grows with each service. Recommend the SDK.

### Railway: a different kind of provider

Railway runs code as containers it builds and schedules. It does not give you
a machine: no root, no SSH, no cloud-init, nothing to install an agent on. Our
model is a Linux server with releases on disk, FPM pools, supervised workers
and a scheduler, and none of that exists there.

A Railway adapter would be a **platform** target: creating an application means
creating a Railway service from the repository, setting its variables and
domain, and "Deploy" calls Railway's API. Logs and metrics come from Railway,
and the Servers page and PHP versions do not apply. That is a second product
mode, closer to Phase 8 (container tier) than to this work.

**Recommendation: do not build it in this phase.** `method()` can later return
`platform` with its own driver. Decide after AWS.

### Others

Hetzner, Vultr, Linode, Scaleway and Ubicloud (already in 7c) all have an API,
accept cloud-init, and give a Linux machine. Each is a `ServerDriver` plus a
catalog and a `supplies()` list. The first of them is the **proof the
abstraction is right**: if it takes more than that, the interface is wrong and
we fix it before AWS managed.

### Incus on bare metal (planned, phase 7b)

Chosen over Ubicloud (below) for bare metal: Incus only splits a machine into
servers, which is all we need, and takes our cloud-init unchanged. Proven by
hand on a HostHatch test host (`incus-cp`, Ubuntu 26.04, KVM available),
2026-11-21.

**Decided:**

| Question | Decision |
|---|---|
| Where the control plane runs | `cp.example.com`, not on the Incus host. One control plane drives any number of hosts. |
| How a server gets a public address | **IPv6**: each server gets its own address from the host's `/64`. |
| How a server reaches the outside | **IPv4 through the host's NAT.** `cp.example.com` and `github.com` have no AAAA records, so an IPv6-only server could neither enroll nor fetch code. |
| Container or VM | **System containers** by default: lighter, and the network setup below is proven for them. VMs as a later option. |
| How IPv4-only visitors reach a site | **An IPv4 ingress on each Incus host**: nginx `stream` routing by domain name (SNI), managed by Unharbored's own agent. See "IPv4 ingress" below. Chosen over Traefik (fewer new parts, and faster for passthrough) and over Cloudflare (covers customers' domains and sslip.io names, no third party). |

**What was proven on the test host:**

- Incus REST API on `:8443` with mutual TLS. A client certificate restricted to
  one Incus project (`unharbored`) is refused everywhere else.
- Through the API alone: create an `images:ubuntu/24.04/cloud` container with
  `cloud-init.user-data`, start it, wait, read its addresses, stop, delete.
- Two NICs per server: `eth0` on the NAT bridge `incusbr0` (IPv4 out; the
  bridge's own IPv6 turned off), and `eth1` of type `routed` with a public
  IPv6 address. From outside, `curl http://[2605:4840:3:18ea::100]/` reached the
  server, while it still reached `github.com` over IPv4.
- Host needs: `net.ipv6.conf.all.proxy_ndp=1`, `net.ipv6.conf.<parent>.proxy_ndp=1`
  and forwarding, kept in `/etc/sysctl.d/90-incus-routed.conf`.
- Ubuntu 26.04's `incus` package does not pull in what VMs need
  (`qemu-system-modules-spice`, `genisoimage`), and a VM receives user-data
  only through a `disk source=cloud-init:config` device.

**The driver** (`app/Lib/Providers/Incus/{IncusProvider, IncusClient,
IncusServers}.php`):

| Concern | How |
|---|---|
| Credential | Endpoint URL, client certificate and key (vault), the server certificate's fingerprint (pinned, since it is self-signed), project, the host's IPv6 `/64` and its parent interface. |
| Create | `POST /1.0/instances?project=…` with `cloud-init.user-data` = our `CloudInit` unchanged, `limits.cpu`, `limits.memory`, a root disk size, and the routed `eth1`. Names are unique per project, so `GET` first, create if absent, as `VultrServers` does. Creation is an async operation: wait on it. |
| Address | Incus does not hand out routed addresses, so the driver picks the next free one in the `/64` (from a fixed sub-range, such as `::1:0:0:n`), checked against the project's instances, and keeps it in the instance's config. |
| Tags | Real ones: `user.unharbored.tags` in the instance config. `listTagged` filters on it. |
| Find, destroy | `GET …/instances/{name}` and `/state` (status, `eth1` IPv6); `DELETE` after a forced stop. A 404 is gone. |
| Catalog | One "region" per host. Sizes are our own tiers (1 vCPU/1 GB up to 4 vCPU/8 GB) since Incus has no plans. No price: the host is already paid for. |
| `supplies()` | `['servers']`: single-server applications with local services. |
| Bootstrap | `cloud-init`, as every other api provider. No change to `ServerProvisioner`. |

**What Unharbored must learn about IPv6** (small, and every provider benefits):

- `ProviderServer` gains `ipv6`; a node's public address may be IPv6 when
  there is no IPv4.
- `NewApplications::sslipDomain` turns `:` into `-` as well as `.`
  (`app.2605-4840-3-18ea--100.sslip.io`), which sslip.io resolves.
- A domain on an Incus server gets **two records**: `A` to the host's IPv4
  (the ingress) and `AAAA` to the server's own IPv6 (`Services/Catalogue`,
  Spaceship). sslip.io names use the host's IPv4, so they work for everyone.
- The servers page shows the IPv6 address.
- Generated nginx already listens on `[::]:80` and `[::]:443`; nothing to do.

**Host setup** is one script, `scripts/incus-host.sh`, doing what was done by
hand: install Incus (plus the VM packages), preseed the btrfs pool and NAT
bridge, the sysctls, the `unharbored` project, and trust a client certificate
restricted to it. Printed at the end: the endpoint and server fingerprint to
paste into the connect form.

**IPv4 ingress.** About half of visitors have no IPv6. Each Incus host has one
public IPv4 address; the ingress shares it among every server on that host by
routing on the domain name, which every HTTPS connection names in the clear
before encryption starts (SNI) and every HTTP request names in its `Host`
header.

```
IPv4 visitor ──▶ host IPv4 :443 / :80 ──▶ incus-ingress (container) ──▶ server's IPv6
IPv6 visitor ──────────────────────────────────────────────────────▶ server's IPv6
```

- **Who runs it:** one more server on the host, `incus-ingress`, created when
  Incus is connected and enrolled like any server with the node role
  **ingress**. Incus proxy devices in NAT mode give it the host's IPv4 ports
  80 and 443 (NAT mode keeps visitors' addresses, and needs a static bridge
  address, `.250`). It gets no server stack, only nginx with the stream module
  (`libnginx-mod-stream`, installed before the config that needs it); the
  stock `sites-enabled/default` is removed so the ingress owns port 80.
  First designed as the agent on the host itself; the container needs no
  second command on the host and reuses provisioning whole.
- **What it runs:** two files, both regenerated whole from the database on
  every change, so they can never drift:
  - `/etc/nginx/modules-enabled/90-ingress-stream.conf`: a top-level
    `stream {}` block. `listen 443`, `ssl_preread on`, a `map` from
    `$ssl_preread_server_name` to the server's `[IPv6]:10443`, `proxy_pass`
    with `proxy_protocol on`. Nothing is decrypted; certificates stay on the
    servers. Unknown names are closed.
  - `/etc/nginx/conf.d/90-ingress-http.conf`: `listen 80`, a `map` from
    `$host` to the server, `proxy_pass` with `X-Forwarded-For`. This carries
    Let's Encrypt's HTTP-01 checks and the redirect to HTTPS.
- **How it changes:** an `ingress-sync` operation through the existing recipe
  steps (`file` for both, a `command` for `nginx -t`, `service nginx
  reloaded`). Queued whenever a domain is added or removed, a site moves, or a
  server on the host is created or destroyed. It shows in the operations log
  like every other change. Adding a domain to an application stays the one
  thing the user does; the ingress follows.
- **Visitors' real addresses:** a server's nginx gains `listen [::]:10443 ssl
  proxy_protocol` beside its normal 443, plus `set_real_ip_from <ingress's
  IPv6>` with `real_ip_header proxy_protocol` there, and `X-Forwarded-For` on
  80. Only the ingress's IPv6 (worked out from its name) is trusted, so nobody else can forge one by
  connecting to 10443 directly. A separate port is needed because direct
  IPv6 visitors send no PROXY header. `Sites.php` adds these lines only for
  servers behind an ingress.
- **Backends by IPv6:** the ingress connects to each server's public IPv6,
  which we assign and which never changes, not its bridge IPv4, which DHCP
  hands out.
- **Firewall:** a server behind the ingress lets the ingress's IPv6 in on 80
  and 10443 whatever else its firewall closes.
- **Limits:** web traffic only (HTTP, HTTPS, WebSockets). HTTP/3 is not
  carried (UDP); browsers fall back to HTTP/2. If the host is down its sites
  are down, which they would be anyway, since the servers live on it.
- **Proven by hand on incus-cp (2026-11-21)**, nginx 1.28 on the host and two
  containers with public IPv6: over IPv4 from outside, HTTPS and HTTP each
  reached the right container by name, and both containers logged the
  visitor's real IPv4 (PROXY protocol on 10443, `X-Forwarded-For` on 80). An
  unknown name was refused. Direct IPv6 to 443 worked as before. A forged
  PROXY header sent straight to 10443 was ignored: the container logged the
  real sender, since only the host's address (`…::1`, the source it connects
  from) is trusted. Unknown HTTP names get `return 444`.
- **Gotcha:** a process started inside a container through `incus exec` runs
  under a different AppArmor profile, and the container's own systemd cannot
  signal it. Services must be started by the container's init (cloud-init,
  the agent), never through `incus exec`.
- **Fallback** if the agent turns out awkward here: Traefik on the host,
  polling a per-host JSON list from the control plane. Unharbored's side is
  the same list in another format.
- **Not in scope:** routing domains that are not Unharbored applications',
  decrypting at the ingress (caching, web firewall), and one ingress in front
  of many hosts. The last is a possible later option: the same list, served to an
  ingress at a network edge.

**Limits, named:**

- **One host per organisation**, because `cloud_credentials` is unique per
  provider. Incus clustering puts many hosts behind one endpoint, so this
  holds until a customer needs separate hosts.
- **Port 8443 is open to the internet**, guarded only by mutual TLS (Incus's
  normal setup). Restrict it to the control plane's address
  (the management server) with a firewall rule once settled.
- **Containers share the host's kernel.** Unharbored's own stack runs fine in
  a system container. An application that needs kernel modules, or Docker
  inside, needs a VM: the later option above.

**Size: M.** The driver is the size of the Vultr one plus the address
picker; the IPv6 changes touch about six files; the ingress is a node role,
one recipe, one operation and the extra listen lines in `Sites.php`.

**Build order**, each step deployable:

1. IPv6 understood across Unharbored (`ProviderServer`, sslip.io, records,
   servers page). Useful to every provider. **Built.**
2. The host setup script and the Incus driver: servers reachable over IPv6.
   **Built**: `resources/scripts/incus-host.sh` (served at `/incus-host.sh`),
   `app/Lib/Providers/Incus`, the Account panel, and the guide in
   [../incus.md](../incus.md). Connecting spends an Incus trust token from the
   script; Unharbored makes its own key and pins the host's. Proven live on
   incus-cp (`IncusLiveTest`).
3. The ingress: A plus AAAA records, PROXY protocol on servers. Now every
   visitor reaches every site. **Built, as a container rather than on the
   host** (2026-11-21): the ingress is one more server, `incus-ingress`,
   created when Incus is connected, whose NAT proxy devices own the host's IPv4
   80 and 443. It is provisioned like any server and set up with the ingress
   recipe instead of the stack, so the host runs nothing of ours and needs no
   second command. Routes are rebuilt by an `ingress_sync` operation after any
   operation that can change them (`Ingress::AFFECTS`). Proven by hand on
   incus-cp, end to end with the visitor's address kept; the driver's ingress
   container (NAT proxy devices through the restricted key) proven by
   `IncusLiveTest`. **Proven end to end on cp.example.com (2026-11-22)**: an
   app on an Incus server, `cm-test-static`, live at
   `https://cm-test-static.85-155-187-30.sslip.io/` with a Let's Encrypt
   certificate issued through the ingress; over IPv4 the server logs the
   visitor's real address, over IPv6 it is reached directly. The live run
   found four gaps, each fixed: the provision operation dropped the ingress
   role; a destroyed ingress could not be set up again under its fixed name;
   the agent unit's `RestrictSUIDSGID` broke package scripts that set setgid
   bits (fontconfig, so php-gd), on every provider whose image lacked them;
   minimal images have no `openssh-server`; and a new name must route through
   the ingress before Let's Encrypt checks it (`Deployer::syncIngress`, called
   from `secure()` and wherever a domain is set).
   Found on the way: Ubuntu 26.04's AppArmor with Incus 6.0.5 stops systemd in
   a container from signalling services started outside it (nginx's package
   scripts), so nginx cannot be restarted. The setup script adds
   `raw.apparmor: "signal, ptrace (read, trace),"` to the default profile.
4. **Incus host as a managed resource** (below). **Built 2026-11-22, not yet
   proven live.**

#### Incus host as a managed resource

Today a host is connected by running a script on it and pasting what it
prints. Instead, Unharbored should set the host up itself and treat it **like
a database or Redis cluster**: a resource the team creates, sees and destroys
in one place, that applications use but never run on. Nothing runs on the host
directly; applications run only on the servers (containers) inside it.

**Rules:**

- **The host is a node with the role `incus-host`.** It runs the agent and
  nothing else: no server stack, no applications, no services, no templates.
  Every server picker, the new-application wizard and the services installer
  leave it out, as they already leave out `preview` and `ingress` nodes.
- **Created two ways**, both ending in the same install:
  1. **On a provider**: a new machine from the catalogue (Vultr or Hetzner
     bare metal, or any VM with nested virtualisation for containers),
     provisioned with cloud-init as now, with the role `incus-host`.
  2. **Your own machine**: the Custom root-SSH flow installs the agent once,
     with the role `incus-host`. Its preflight wants ports 80 and 443 free and
     nothing else installed; the server stack is never installed.
- **The install is a recipe** (`incus_host_install` operation): the steps of
  `incus-host.sh` as recipe steps (packages, sysctls, preseed, bridge, AppArmor
  line, project, `core.https_address`). Its last step runs
  `incus config trust add` and returns the token in the step's output; the
  control plane spends it with `IncusProvider::connect`, which already creates
  our key, pins the host and queues the ingress. **Nothing to paste.**
- **Delete is refused while any server lives on the host**, with the reason on
  the button ("3 servers still run on this host"), the same guard Disconnect
  has today. The ingress does not count: it is part of the host. Deleting runs
  in order: destroy the ingress, remove our key from the host's trust list,
  delete the connection, then destroy the machine (provider-made) or uninstall
  the agent (your own machine, Incus left installed).
- **Shown on the Servers page**, marked "Incus host", with the servers living
  on it. Not on the Databases page: only DigitalOcean creates database
  clusters today, and a host can be made on any provider.
- **The paste flow stays** as the fallback for a host where you don't want
  Unharbored's agent.
- **Not a template.** Templates run applications on servers; a host is the
  thing servers run on, so it sits with clusters, not in the template catalogue.

**Tradeoffs, named:**

- **The agent runs as root on the host**, where the paste flow trusts only one
  key restricted to a project. Every other server already runs the agent as
  root, and the host stops being invisible (health, disk, upgrades).
- **One host per organisation** still holds (`cloud_credentials` is unique per
  provider). Making hosts managed makes a second host the obvious next ask;
  lifting it means one credential per host node instead of per provider. Not
  in this step.
- **incus-cp is already set up by hand**, and its 80/443 belong to the ingress.
  Adopting it needs the preflight to accept a host that already has Incus and
  our connection, or a reinstall. Simplest: adopt it with a flag that skips
  the install recipe and only links the existing connection to the node.

**Size: M.** The `incus-host` role and its exclusions, the install recipe with
the token handed back, the delete guard and order, the host card, and the
Custom preflight variant. The driver, ingress and servers are unchanged.

**As built** (`app/Lib/Providers/Incus/IncusHost.php`, `IncusHostTest`):

- **Create:** Servers › New server › "What it is for: an Incus host" (any
  connected API provider except Incus), or Connect a server › "Make it an
  Incus host" (SSH). Either way the operation's last stage is `incus` in place
  of `stack`.
- **Install:** the agent writes `incus-host.sh` to
  `/usr/local/sbin/unharbored-incus-host` and runs it; its `✓` lines go to the
  operation log, and the `uh-incus:` line it prints is spent with
  `IncusProvider::connect`, which queues the ingress. The node's role becomes
  `incus-host`.
- **Kept off the host:** new applications, a second server for an
  application, the old site form, services and templates, and the stack,
  firewall, PHP and container operations. The same applies to the ingress.
- **Remove:** destroy (a provider's machine) or disconnect (your own) is
  refused while any server other than the ingress lives on the host. Otherwise
  the ingress is destroyed, our key is removed with `incus config trust
  remove` through the agent, and the connection is deleted, before the
  machine goes. Account › Providers › Incus › Disconnect sends a managed host
  to the Servers page, and for a pasted host now destroys the ingress too
  (before, the ingress itself blocked disconnecting).
- **Not built:** linking incus-cp (set up by hand) to a node; the
  install-command way of adding your own machine as a host.

### Ubicloud (parked)

Parked in favour of Incus above: Ubicloud is a full cloud (accounts,
billing, managed Postgres, balancers) to run and upgrade ourselves, for the
one thing we use, servers. Revisit if its managed Postgres and balancers on
our own metal become wanted. Notes kept for then:

Ubicloud is an open-source cloud you can use hosted (`api.ubicloud.com`) or run
yourself. Checked against its source (`openapi/openapi.yml`, `helpers/vm.rb`,
`prog/vm/`) on a self-hosted instance, 2026-11-20.

**Target: a self-hosted Ubicloud on bare metal.** Today its control plane runs
on a Vultr VM (test-ubi) with no VM hosts. A VPS cannot host Ubicloud VMs (no
KVM), so creating servers waits for a bare-metal host. Ubicloud does not speak
the AWS API (no EC2-compatible endpoint); its AWS "private locations" exist but
are **out of scope**: AWS is our own AWS provider's job.

**Facts that shape the driver:**

| Fact | Consequence |
|---|---|
| Every path is scoped to a project and a location: `/project/{id}/location/{loc}/vm/{name}`. | The credential is **three fields**: token, project id, base URL (default `https://api.ubicloud.com`). `TokenApiProvider::connect` takes one token, so `UbicloudProvider` overrides it with fields, as `AwsProvider` does. |
| A VM is created by `POST …/vm/{name}`; names are unique per project and location (lowercase, digits, dashes, up to 63). | Idempotent create as in `VultrServers`: `GET` the name first, create only if absent. External id is `"{location}/{vm-id}"` so `find` and `destroy` can build the path. |
| VMs have no tags. | `listTagged` lists the project's VMs (`GET /project/{id}/vm`) and filters on a name prefix that carries the organisation. |
| `init_script` is one `runcmd` line, **at most 2,000 characters**, run on bare-metal locations (AWS locations ignore it). | Our cloud-init (CA certificate, agent unit) does not fit, but a one-line install command does. See bootstrap below. |
| `public_key` is **required** on create. | Send the server owner's key (the login user from server-runtime.md), or a throwaway public key whose private half is never kept. Never a key the control plane holds (ADR 0001). |
| `enable_ip4` defaults to **false**: VMs are IPv6 only. | Always send `enable_ip4: true`. The agent dials the control plane and sslip.io domains need IPv4. On self-hosted metal this needs leased IPv4 addresses, or creates fail. |
| Ubicloud has its own load balancer, managed Postgres, firewalls and private subnets. | `supplies()` starts as `['servers']`. Those are candidates for a native `EdgeDriver` and `DatabaseDriver` later, under the rule "abstract on the second implementation". |
| `bin/ubi` CLI and SDKs exist; the HTTP API is plain JSON with a bearer token. | `UbicloudClient extends TokenApiClient`; no dependency. |

**Bootstrap: a one-line install command in `init_script`.** The VM runs
`curl -fsSL {cp}/install/{secret} | sudo bash` on first boot: the same
one-time script `ServerInstall` already serves for "add your own server"
(agent download, checksum, enrollment). About 100 characters, well inside the
limit. Nothing inbound, no SSH, no key held. `ServerProvisioner` gains one
branch keyed on a provider capability (`bootstrap(): 'cloud-init' |
'install-command'`), not on the provider's name: for `install-command` it
issues the install link instead of building cloud-init.

- *Check:* the install link's lifetime (15 minutes today) against how long a
  Ubicloud VM takes to boot; lengthen it for this path if needed.
- *Fallback if `init_script` ever fails us:* SSH once with a throwaway key
  through the existing `SshAdoption` (Custom's flow). Not built unless needed.

**Catalog.** Locations from the project's location list; sizes are Ubicloud's
named sizes (`standard-2`, `standard-4`, …).
Prices are not in the API as far as checked, so the size list carries a static
approximate price, as the fallback catalogs do.

**Files:** `app/Lib/Providers/Ubicloud/{UbicloudProvider, UbicloudClient,
UbicloudServers}.php`, one line in `Providers`, a connect form entry on the
Account page (three fields), `cloud:connect-ubicloud`, and the
`ServerDriverContractTest` against recorded fixtures. Size: **S to M**; the
bootstrap branch is the only change outside the new folder.

**Before it can run end to end:**

1. **A bare-metal host with KVM**, cloudified into the self-hosted install.
   Ubicloud's `cloudify_server` script supports only Hetzner; other bare metal
   (Vultr Bare Metal, for one) means setting up the host by hand, off
   Ubicloud's tested path.
2. **IPv4 for VMs.** Self-hosted VMs get IPv6 from the host's `/64`; IPv4
   needs extra addresses leased from the host provider and added to Ubicloud.
3. **A reachable API.** Our control plane must reach Ubicloud's API over HTTPS
   at a public URL; today it is bound to localhost on test-ubi.

Until then the driver is built and tested against recorded fixtures, and
connecting a key works (it only lists locations). Creating a server has nowhere
to run; check what Ubicloud answers then (an error, or a VM that never leaves
waiting) and surface it, rather than leaving a server pending forever.

## UI, API and CLI

- **Add a server:** a Provider select (connected providers plus Custom). API
  providers show Region and Size from that provider's catalog. Custom shows
  host, port, user and key or password.
- **New application:** provider first, then **shape** with locks and reasons as
  above. Existing servers are filtered to the provider. Each service shows its
  options and labels. The price estimate reads the catalog and is labelled
  approximate.
- **Server card and application header** show the provider, as a chip.
- **API, `cloud` and MCP:** `provider` on create-server and create-application;
  `GET /providers` returns each connected provider with what it supplies, its
  catalog, and each shape's `available` and `reason`. Update `docs/api.md`.
- **Previews and the scaler** follow their application's provider.

## Phases

Each phase ends deployable, with the suite green.

| # | Phase | Delivers | Size | Scope |
|---|---|---|---|---|
| 0 | Decisions | Open questions answered; ADR 0008 (provider model); ADR 0001 amended | S | committed |
| 1 | Seams | `Provider`, `ServerDriver`, error types, `Catalog`, `supplies()`; DigitalOcean adapter; `ServerProvisioner`; provider columns and backfill; `type` rename; `ProviderScope`; reconciler mismatch report. Behaviour unchanged. | L | committed |
| 2 | Shapes and the wizard | `Shapes::for`, locked-with-reason UI, provider select, catalog-driven regions and sizes, API `GET /providers`. Tested with a fake provider, since only DigitalOcean is visible. | M | committed |
| 3 | Custom over SSH | `RemoteShell` (wraps phpseclib), preflight, `adopt-ssh`, credential sweep, form, API | M | committed |
| 4 | Local services | Postgres, MySQL and Valkey on the app's server: install recipe, env wiring, nightly dumps; "yours" for uploads and cache. Designed in [server-runtime.md](server-runtime.md) (item 2); needs its injected-variable layer first. | L | committed |
| 5 | AWS servers | `AwsProvider`, EC2 driver, connect command, security group, catalog | M | committed |
| 6 | Provider-independent autoscaler | Scaler loop, `EdgeDriver` split out of `LoadBalancers`, drain and join order, bounds and cooldown; DigitalOcean first | L | **built** |
| 7 | Second VM provider | Hetzner or Ubicloud: the abstraction test; a second `EdgeDriver` if it has a balancer | S | **built** (Hetzner, servers only) |
| 7b | Incus on bare metal | System containers with a public IPv6 each and IPv4 out through NAT; host setup script; IPv6 addresses understood across Unharbored; an IPv4 ingress per host (nginx stream, agent-managed). See "Incus on bare metal" above | M | planned |
| — | Ubicloud | Servers on a self-hosted Ubicloud; agent installed by a one-line `init_script`. See "Ubicloud (parked)" above | S–M | parked |
| 8 | AWS managed | ALB, RDS, ElastiCache, S3; the scaler then works on AWS | XL | deferred |
| 9 | Self-run services | Edge on HAProxy or nginx, database servers, object store; lifts the locks on small providers | XL | **database servers built 2026-11-23** (PostgreSQL and MySQL on a dedicated server, any provider that makes servers; no Valkey, private network or backups of its own yet); edge and object store deferred |
| 9b | Native databases | A `DatabaseDriver` seam, then Vultr and Linode managed databases, AWS RDS in phase 8; the provider handles backups and failover | M each | planned, after 9 |
| 10 | Artifact transport | Builds without an object store | M | open question |
| 11 | Railway decision | Go or no-go on a platform provider | S | deferred |
| 12 | Native autoscale pools | DigitalOcean and AWS native pools as an optimisation | M | documented only |

Custom (3) comes before AWS (5) on purpose: it is smaller, it is useful today,
and it exercises the "provider lacks services" path that AWS servers will also
hit. Local services (4) come before AWS so both have a real database option
beyond SQLite.

## Testing

- An in-memory **fake provider** with a configurable `supplies()` list, so
  orchestration, the mismatch rules and the wizard are tested once, not once
  per provider.
- **Shape resolution as a table test**: each provider profile in the table
  above produces exactly the expected shapes and lock reasons.
- An abstract **`ServerDriverContractTest`** every adapter must pass: create is
  idempotent, an uncertain failure reports `Uncertain`, `find` reports each
  state, `destroy` is idempotent, `listTagged` finds only ours. Each adapter
  runs it against recorded fixtures through `Http::fake`.
- **Scaler tests** with a fake clock, fake metrics and the fake provider: no
  action inside the cooldown, never beyond min or max, one action at a time,
  an uncertain create becomes `Orphaned`, the primary is never removed.
- `RemoteShell` is an interface with an in-memory fake, so the SSH flow is
  tested without a server. Add one live script in `scripts/`, like the existing
  `live-*.sh`, that adopts a real machine.
- A test for each `ProviderScope::assertSame` call site, and a test that fails
  if a new attach point is added without one.
- A migration test: a database shaped like production (one DigitalOcean
  application, one own server) backfills correctly.

## Risks and tradeoffs, named

- **Abstracting from one example.** The interface is drawn from DigitalOcean
  plus the checklist for a further provider. Mitigation: only the server driver is abstracted
  now, `supplies()` carries the rest as data, Custom (an API-less case) and the
  second VM provider test it early, and service drivers are split only when a
  second implementation exists.
- **Locked shapes are a product choice.** A small provider can only host
  single-server apps until self-run services or its own products arrive. That
  is honest, but some users will find it limiting. The lock reasons say what
  would unlock it.
- **Our scaler is slower and depends on us.** It reacts at the metrics
  interval and stops if the control plane is down; native pools do neither.
  Accepted for one implementation everywhere. Documented as phase 12.
- **Replacing the DigitalOcean pool.** Existing native pools keep working on
  the old code. New applications use the scaler. Retire the old code only
  after migrating or removing existing pools.
- **Local databases are single points of failure.** One machine holds the
  data, and backups are on its disk unless an offsite target exists. The
  console labels this plainly. It is the same trade as Forge's default.
- **"Yours" storage puts uploads on the user.** Multi-server with uploads on
  local disk breaks silently, since each server has its own disk. The wizard
  warns when a multi-server shape is chosen with disk storage.
- **Backfilling production.** The `type` rename and `provider` backfill touch
  live rows. Make the migration idempotent and run it on a copy of the
  production database first.
- **SSH reach.** Custom works only if the control plane can reach port 22. The
  console must say so before the user waits on a failing connection. Show our
  current outbound IP each time, not only in docs.
- **Per-provider quirks** (readiness polling, address assignment, tag formats)
  belong in the driver. The contract test is what keeps them there.
- **Cost surprises.** Nothing here makes the control plane a billing
  authority. Estimates are approximate and say so. The reconciler's orphan
  check covers leaks on every provider that supports `listTagged`.

## Still open

1. **Keep the manual install command in Custom?** You said Custom gets only
   the SSH method. The manual command already works and covers people who will
   not hand over root. Recommend keeping it as a secondary option.
2. **Non-root SSH users with sudo?** Recommend root only to start.
3. **AWS managed services (phase 8):** wanted, or servers only for now?
4. **Railway:** agree to a decision gate after AWS?
5. **AWS auth:** an IAM role with an external ID (recommended) or access keys
   first?
6. **Console page for provider keys.** Resolved: the Account page, as two lists
   (cloud and DNS), with expiry and verification. The artisan commands remain for
   first setup.
7. **Artifact transport (phase 10):** needs a look at build sizes and the
   gateway's capacity before choosing a way to get builds to nodes without a
   bucket.
8. **Existing DigitalOcean autoscale pools:** keep on the old code until
   retired (recommended), or migrate them to the scaler in phase 6?

## Scope check

This is heavy. Phase 1 alone touches about 27 files, and phase 8 matches the
size of the DigitalOcean integration. The committed scope is **0 to 5**: the
seams, shapes and locks, Custom over SSH, local services, and AWS servers.
Phase 6 (the scaler) is the next large piece and is worth it, because it
replaces a per-provider feature with one. Everything marked deferred waits for
a customer who needs it. Leave Railway out; it is a second product.
