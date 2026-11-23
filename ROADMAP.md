# Roadmap

Phase definitions come from [PLAN.md](PLAN.md). This file tracks status.

Legend: **done**, **in progress**, **next**, **later**.

---

## Phase 0: Foundations — **done**

Exit criterion: *provision a bare Droplet into a connected DO account, enroll
the agent, and see it report healthy.* Met 2026-11-05.

The control plane provisioned `web-1` on a real DigitalOcean account. It
booted from our cloud-init, downloaded and checksummed the agent, enrolled
over HTTPS, held a session through the gateway over mTLS, and ran a recipe
sent from Laravel. The audit chain verifies and `cloud:reconcile` reports no
drift.

| Item | Status | Notes |
|---|---|---|
| Wire protocol and framing | done | `internal/protocol` |
| Organisation CA, certificate issuance | done | `internal/pki` |
| Enrollment tokens and handshake | done | `internal/enroll` |
| mTLS transport, session, heartbeat, backoff | done | `internal/transport` |
| Agent: enrollment, reconnect loop, handlers | done | `internal/agentcore` |
| Reference control plane | done | `internal/cpd`, development only |
| End-to-end integration suite | done | `internal/integration`, 165 tests green on Linux |
| Control plane skeleton | done | Laravel 13.40, `control-plane/` |
| Enrollment server half | done | `app/Lib/Enrollment`, DB-backed, atomic consumption |
| Organisation PKI | done | `app/Lib/Pki`, one CA per tenant, key held in the vault |
| Credential vault, per-org keys | done | `app/Lib/Vault`, envelope encryption, KMS-ready |
| Audit log | done | `app/Lib/Audit`, append-only, hash chained |
| Resource state machine | done | `app/Lib/Resources`, orphan tracking, optimistic locking |
| DigitalOcean API client, rate limiting | done | `app/Lib/DigitalOcean`, per-tenant token bucket |
| Cross-language conformance fixtures | done | `cmd/conformance`, asserted by the PHP suite |
| KMS-backed master key | **not started** | `LocalKeyManager` is real crypto but config-held |
| Provisioning flow | done | `app/Lib/Cloud/DropletProvisioner` |
| Cloud-init bootstrap template | done | `app/Lib/Cloud/CloudInit`, tests parse the YAML rather than grepping it |
| Reconciliation against the provider | done | `app/Lib/Cloud/Reconciler`, finds untracked, stuck and missing resources |
| Credential storage and attribution | done | `app/Lib/Cloud/CredentialStore`, vault-backed, every retrieval audited |
| Organisations | partial | A table and an id. No users, roles, or signup. |
| DigitalOcean OAuth | **not started** | Personal access tokens only, which is fine for our own account and not for external tenants. ADR 0001. |
| Enrollment over HTTP | done | `app/Http/Controllers/EnrollmentController`, rate limited, uniform rejections |
| **Live agent to control plane** | **done** | `scripts/live-enrollment.sh`. A real Go agent enrolls against the real Laravel control plane over TLS. |
| Session gateway | done | `internal/gateway`, `cmd/gateway`. Tenancy resolved from the issuing CA. |
| Control plane to gateway boundary | done | `app/Lib/Gateway`, command dispatch and event callbacks |
| **Full loop, live** | **done** | `scripts/live-full-loop.sh`. Laravel enrolls an agent, the gateway holds it, Laravel commands it, the agent runs it, the chain verifies. |
| Agent distribution | done | Served by the control plane; checksum read from the real binary |
| Preflight checks | done | `cloud:preflight` reports every blocker at once |
| First-Droplet runbook | done | [docs/first-droplet.md](docs/first-droplet.md) |
| **An actual Droplet** | **done** | 2026-11-05, on a personal account. [docs/first-droplet.md](docs/first-droplet.md). |

### Known gap carried into Phase 1

A resource is marked `active` when DigitalOcean reports the Droplet running,
before the agent enrolls. A node whose agent never arrives still reads as
healthy in the database. Health should follow the agent session.

---

## Phase 1: Single server, end to end — **done**

Exit criterion: *a real internal Laravel application runs in production on the
platform on one Droplet.*

**Met in substance, 2026-11-05:** `unscripted-laravel-demo` runs on `web-1`
over HTTPS with a queue worker and the scheduler, deployed from GitHub in ten
seconds. Since moved to its own domain. It runs
without Reverb's transport (polling) until the app's browser runtime supports
sockets; Reverb itself is running for it.

Target: `examples/demo-app/`. Deliberately small, and built so that each way a
deploy can silently half-succeed shows up on its status page. Harder targets
later.

| Item | Status | Notes |
|---|---|---|
| Server provisioning recipes (nginx, PHP-FPM, supervisor) | **done** | `app/Lib/Servers/ServerStack`, `server:install-stack`. Proven on a real Droplet; a second run changes nothing but the reloads. |
| Git integration (GitHub first) | **done** | GitHub App per organisation, created through the manifest flow. Clones use an hour-long installation token scoped to one repository, read-only; nothing long-lived on the node. Deploy keys remain as a fallback. |
| Bring your own server | **done** | One-time install command for any Ubuntu 24.04 machine; an operation follows it to the stack. |
| Remove a server | **done** | Certificate revoked at the gateway at once; Droplets are not destroyed. |
| Management server rebuild | **done** | `scripts/mgmt/setup.sh`, nightly snapshots, 14 kept on the server and 14 in a Spaces bucket in sfo3, [docs/management-server.md](docs/management-server.md). |
| Console UI | **done** | Password sign-in (2FA off for now); Sites, Site, Deploy, Settings, Servers, Audit at `cp.example.com`. Built with Unscripted to [docs/design](docs/design/). Every long action is an operation with stages and a log. |
| Push-to-deploy | **done** | Signed webhook queues a deploy; deploys of one site are serialised. Push to live in about 11 seconds. |
| Build and release pipeline | **done** | `site:create`, `site:deploy`, `site:rollback`, `site:env`. Clone, composer, migrate, optimize, activate. Proven live: deploy in 9s, shared storage survives, rollback serves the old release at once. Old releases pruned to the newest five after each deploy. |
| Release prepare / activate / rollback | **done** | `internal/deploy`, driven over the wire |
| Recipe engine and wire format | **done** | `internal/recipe` |
| Environment variable management | **done** | Vault stores them; the file step writes them; every change is a version, the last twenty can be put back, history shows changed key names only |
| ACME certificates | **done** | `site:https`: certbot over HTTP-01 for a domain pointed at the node; renewal by certbot's timer, nginx reloaded by its hook. `site:domain` moves a site to a new name, reissues, and retires the old certificate. DNS-01 and wildcards wait for preview environments. |
| Queue workers under supervisor | **done** | Configured after the swap, restarted onto the live release on deploy and rollback. |
| Scheduler | **done** | cron per site, as the deploy user. Lock election is a Phase 3 concern: one server needs none. |
| Deploy log streaming | **done** | Progress messages stream per step |
| Daemons | **done** | `site:daemon`. Any long-running command under supervisor, from the live release, restarted onto it after every switch. |
| Agent upgrades | **done** | `node:upgrade-agent`. Checksummed swap, deferred restart, success is the node reconnecting on the new version. |
| Laravel Reverb | **done** | `site:reverb`. Per-site loopback port, nginx proxies `/app` and `/apps` with upgrade headers, so browsers get wss on the site's certificate. |

---

## Phase 2: Managed resources — **done**

Managed MySQL, PostgreSQL and Valkey provisioning with trusted-source
firewalling. Spaces buckets with scoped keys. Backup and restore surfaces.

Exit criterion: *that same application uses a managed database and cache, with
no manual DigitalOcean console work.*

| Item | Status | Notes |
|---|---|---|
| Managed PostgreSQL and MySQL | **done** | Databases page: create, follow to online, destroy. Each site gets its own database and user, granted only its own database; the cluster firewall gains its Droplet and keeps what it had. Proven live: `demo` and `unscripted` both run on cluster `main` (PostgreSQL, nyc3). |
| Managed Valkey (cache, sessions, queues) | **done** | Attach moves cache, sessions and queue under a per-site prefix. One password per cluster: sites sharing one are separated by prefix only. Both sites run on cluster `cache`. |
| Spaces buckets with scoped keys | **done** | One private bucket and one bucket-scoped key per site, set as Laravel's default disk. The setup key that makes the bucket is deleted straight after. Proven live on `demo` across two servers. |
| Site database backup and restore | **done** | Each site's database is dumped on its primary to the backup bucket: nightly, before every deploy's migrations, before a restore, and by hand; 14 days kept. Restore runs with every server in maintenance mode, after a backup of what is there. DigitalOcean's daily cluster backups are listed as a fallback and restored through a temporary cluster. Proven live on `demo` (PostgreSQL 18). |
| Bring-your-own servers on managed databases | **done** | Admitted by the public IPv4 address they enrolled from; Droplets stay named by id, which survives an address change. A server whose address changes must be admitted again. Spaces still needs a Droplet to choose its region. |

---

## Phase 3: Multi-server and load balancing — **done**

**Met 2026-11-06:** `demo` serves traffic on `web-1`, `web-2` and `web-3`
behind a DigitalOcean Load Balancer. Measured from inside DigitalOcean
during a deploy: 90 of 90 requests answered 200, and no request got the old
release once any server had switched. Done: coordinated release swap across a site's servers, migrations and the
scheduler on the primary only, adding and removing servers from the console,
DigitalOcean Load Balancer provisioning, TLS at the balancer with the site's
own Let's Encrypt certificate, uploads on Spaces, one build on the primary
shipped to the others, missed push webhooks resent.

Known gap: bring-your-own servers cannot join a balanced site.

Server roles, builder node, artifact distribution through Spaces, coordinated
release swap, migration election, DO Load Balancer provisioning, TLS at the
LB, state externalisation checks.

Exit criterion: *an application serves production traffic across three nodes
behind a load balancer with zero-downtime deploys.* This is the Forge-parity
milestone.

Note: the protocol already separates `deploy` (prepare) from
`activate_release` specifically so this phase does not require a protocol
change. Prepare everywhere, then swap everywhere.

---

## Phase 4: Autoscaling — **done**

Done: Droplet Autoscale Pool per site, unattended enrollment by Droplet
address, boot-time pull of the live release's build from Spaces, health
gating (a member enters the balancer only once it serves the live
release), scheduled minimums, removal of members DigitalOcean deletes.
A maintained base image (rebuilt monthly) brings a new member to serving
in under two minutes, most of it DigitalOcean's boot. Proven live on
`demo`. Queue workers scale on queue depth, down to zero, on every server
of a site (proven live on `demo`: woken from zero by 18 waiting jobs).

Droplet Autoscale Pool integration, base image maintenance, boot-time artifact
pull, health gating, scheduled scaling, queue-depth-driven worker scaling
including scale to zero for workers.

---

## Phase 5: Observability and alerts — **done**

Metrics, logs and alerts from the agent, with no service outside the
platform.

| Item | Status | Notes |
|---|---|---|
| Server metrics | **done** | CPU, memory, disk, load and uptime once a minute from the agent; two weeks kept. Servers charts CPU and memory over an hour, a day or a week. |
| Log shipping with redaction | **done** | Each site's Laravel and worker logs and nginx's error log, tailed by the agent through rotation and truncation. Secrets are removed on the server before a line is sent. A week kept, dropped gaps recorded. Proven live: a planted password and token arrived as `[REDACTED]`. |
| Log search | **done** | Logs page: text, server, source and age. |
| Alerts | **done** | Offline, disk, sustained CPU and failed operations, thresholds set per organisation (0 is off); sent on opening and clearing to a webhook and by email. |
| More log sources | **built, not deployed** | Node and Go output (`app.log`), PHP-FPM errors, supervisor events, kernel and out-of-memory lines from syslog, and a per-site nginx error log (the vhost now names a per-site access and error log). A Laravel exception and its stack trace ship as one record. Needs the new agent, and each site's vhost rewritten (the next deploy does it). Access logs are not shipped: they are for the live reader. |
| Formatted log viewer | **built, not deployed** | A record that spans lines (a Laravel exception and its stack trace) shows its first line and folds the rest under it; a **Level** filter narrows to warnings and errors or errors only. Each server card has a **Logs** link to that server's lines. |
| Live log reader | partly | The Logs page already refreshes every 5 seconds, so new lines appear without a reload. Not built: a true streaming tail, a pause button, and shipping nginx access logs. |
| DigitalOcean Monitoring API ingestion | not built | The agent's own samples cover Droplets. Load balancer and managed database metrics would come from it; not needed yet. |

---

## Phase 6: API, CLI, and MCP — **done**

A REST API with scoped tokens, the `cloud` CLI, and an MCP server over the same
surface. See [docs/api.md](docs/api.md).

Exit criterion met: `demo` deployed and rolled back entirely from `cloud`, and
again (deploy, roll back, deploy) through an MCP session, with a `deploy`
token whose attempt to run artisan was refused.

---

## Phase 7: Preview environments — **mostly done**

A branch of a site at its own URL on a shared preview server
(`preview-1`), at `<branch>.<site>.preview.example.com`, over HTTPS.

Done, and proven live on `demo`:
- On demand from any branch: console, API, `cloud preview`, the
  `create_preview` MCP tool.
- Pull request previews: a site names a base branch; a PR into it gets a
  preview that follows its pushes and is deleted when it closes. Forks are
  never previewed. Read-only GitHub access.
- Database per preview: fresh (empty, of the parent's kind), clone (the
  parent's data), shared (the parent's own, never migrated) or SQLite.
- Per-site `.env` overrides with `{url}`, `{domain}`, `{branch}`
  placeholders, kept in the vault; production-only keys left out.
- `noindex`, idle deletion, nothing left behind on delete.

Still to do:
- A Droplet per pull request, with its DNS record made through Spaceship's
  API (needs a DNS-only key and an OK to create Droplets automatically).
- A wildcard redirect for previews in the OAuth server the apps share.
- Optional password (HTTP basic auth) on previews.

---

## Phase 7b: Console redesign — **next**

Done so far: the environment page's Environment, Deployments, Logs and
Settings tabs, the redesigned look, an Account page, button tooltips. Phase 7f's
scheduler and runtime settings land here as Settings sections.

The console works but is hard to find your way around: a site's settings
are one long page of fifteen panels. Keep the look (dark, mono, green
cursor; light mode), and reorganise around how Laravel Cloud is laid out:

- **Applications**, each with **environments**: production and every
  preview side by side, not previews buried in settings.
- An environment page with tabs: Overview (a diagram of domain, balancer,
  servers, database, cache and bucket, with status), Deployments, Logs,
  Metrics, Commands, Settings (General, Variables, Domains, Workers,
  Database and backups, Previews).
- Shared infrastructure on its own: Servers, Databases, Storage.
- Activity in one place: Operations, Alerts, Audit.
- New application wizard: source, then single server or autoscaling, chosen
  once. The choice is fixed after creation (size, min and max still change);
  switching means a new app. The console stops offering "add a server" or
  "add a pool" to an existing single-server app.

---

## Phase 7c: More providers, and databases we run ourselves — **in progress**

Provider work so far: **seams** (`app/Lib/Providers`: `Provider`, `ServerDriver`, `Providers` registry, `ServerProvisioner` replacing `DropletProvisioner`, `provider` on nodes, resources and sites, resource type `droplet` is now `server`) and an **AWS provider** with a settable endpoint URL, so LocalStack or any AWS-compatible service works (`php artisan cloud:connect-aws`). Hand-signed SigV4 rather than the AWS SDK: EC2, STS and SSM are a handful of posts, and the SDK is large. Reconciler's orphan check still reads DigitalOcean directly. **Shapes and the wizard**: `Shapes::for` locks a shape with a reason naming the missing service and provider; the new-application wizard and Add a server pick the provider and read regions, sizes and prices from its catalog; `GET /api/v1/providers`; `ProviderScope` refuses to attach a DigitalOcean balancer, bucket, pool or cluster to an application on another provider (a Custom server may still use them, as web-1 does today). **Custom over SSH**: Servers → Connect over SSH is a plain form (host, port, user, key or password, optional expected host key fingerprint); an `adopt_ssh` operation connects, runs a read-only preflight (Ubuntu 24.04, arch, root or passwordless sudo, systemd, 1 GB, ports 80/443 free, apt, outbound 443 and 9443), runs the install script, discards the login, then follows the agent and installs the stack as `adopt` does. A sweep (`ssh-adopt:sweep`, every 15 minutes) deletes any login older than an hour. ADR 0001 amended. **Local services**: PostgreSQL, MySQL and Valkey on an application's own server (wizard options, "Services" on each server card), bound to 127.0.0.1, one database and user per site with a random password held only in the injected variables, the grant run as root over the local socket by a one-off program that is removed; **None** (nothing installed or injected, "managed by you"); nightly dumps to the server's own disk (14 days) when there is no backup bucket. Valkey installs `valkey-server` where Ubuntu has it, else `redis-server`. **AWS keys** are on the Account page with an endpoint URL. **Variables, honestly layered**: an application's own variables start empty; its key, name, address and logging are injected ("Made with the application"), and an application from before has them moved there by a migration, only where they are what would have been made, so the server is given exactly what it was. Changing a domain or HTTPS no longer writes the merged environment into the user's layer (it did, and the copies overrode later changes: a cluster attached afterwards never took effect), and the Variables tab offers to remove copies an earlier fault left. **Background processes** (the app's Daemons, renamed): a command supervisor keeps running from the live release, optionally with a port (passed as `PORT` and `{port}`, loopback only, unique on the server) and a public path that nginx passes, WebSockets included, from the app's own domain over its certificate (`wss://<domain>/<path>`). **Clusters for a new application**: the wizard's database and cache choices are an existing cluster (shared; each application gets its own database and user on it) or a new one made beside the server, named for the application, sized from a list, priced into the estimate and attached once it is online; a cluster outlives the application and other applications can use it. **Code from any Git host** (`app/Lib/Git`): a repository can be a GitHub App repository, any public repository (cloned with no login; its branches come from git's own smart-HTTP answer, and a repository you do not own is polled every five minutes by `sites:poll-git`), or a private one on any host (GitLab, Bitbucket, Gitea, Forgejo, self-hosted) cloned over SSH with a per-application read-only deploy key. The host's SSH key is read when the application is made, shown as a fingerprint and pinned, and a changed one is refused. Push-to-deploy for those hosts is a webhook address with a secret (`/api/git/hook/{site}/{secret}`; GitHub, GitLab, Gitea/Forgejo and Bitbucket payloads understood, signatures checked where the host signs). The control plane resolves every address it is given and refuses private, loopback and link-local ones. The wizard takes "Another repository"; a private one waits at an `access` step until the host accepts the key. Pull-request previews and a repository picker stay GitHub-App-only. **Hetzner** is the second VM provider (`php artisan cloud:connect-hetzner`, or the Account page): servers only, so single-server applications with local services. Adding it changed nothing in `Provider`, `ServerDriver` or `ServerProvisioner`, which is the check the spec asked for; server types are named as of writing and should be compared with Hetzner's list. **Autoscaler** (`app/Lib/Scaling`): one scaler for every provider, replacing DigitalOcean's native pool for new autoscaling. Each minute (`sites:scale-servers`) it reads the agents' CPU and adds or removes one server through `ServerProvisioner`: up when CPU stays over the target for 5 minutes, down when it stays under half of it for 10, never outside the minimum (raised by the schedule) and maximum, one action at a time with a 5 minute cooldown, a removed server drained for 60 seconds off the balancer first, only servers it made ever destroyed and never the primary, and a server that never joined destroyed rather than left billing. The scaler refuses an application whose variables still point at SQLite or a file cache (a second server could never run it, and names the variables that override the injected ones), stops after 3 servers in a row fail to join and raises a critical alert rather than making servers again and again, and Resume on the Servers & scaling tab carries on once it is put right; the new-application wizard warns when pasted variables override what it sets up, and refuses them for autoscaling. `EdgeDriver` is the balancer seam, with DigitalOcean's balancer as the one implementation. Existing native pools keep running on their old code (`ServerScaler::manages` tells them apart); limits, schedule and stop use the same console controls.

Detailed plan: [docs/specs/providers.md](docs/specs/providers.md): one provider per server and
application, never mixed; Custom provisions over root SSH; what a provider
lacks is shown as a locked option with the reason; one autoscaler for every
provider.

- A provider layer in front of servers, databases, balancers and images,
  so DigitalOcean is one provider among several. Ubicloud first: VMs,
  private subnets, firewalls, machine images, PostgreSQL with restore to
  any moment. Storage stays S3-compatible (Spaces, R2, B2); DNS through
  Spaceship.
- Turso (hosted libSQL, SQLite-compatible) as a database option, including
  for autoscaling: every server reaches the same database over the network,
  where plain SQLite on each server would give each its own.
- Self-run database servers for providers without managed ones: Valkey
  first (cache only), then MySQL and PostgreSQL on one server each, with
  nightly dumps and continuous change-log shipping, then automatic restore
  tests. Replicas and failover later.

---

## Phase 7d: Other frameworks and languages — **started: static, Node, Go; detection and root folder**

Done: the runtime core (`app/Lib/Sites/Runtimes.php`, `sites.runtime` and
`runtime_config`) with presets for a static site, Node and Go. Toolchains are
installed per deploy user with mise; builds run on the server in the release's
folder; the app runs as one supervisor program with `PORT` set and the
application's variables sourced; nginx serves a folder or proxies to the port
(WebSockets included); the deploy restarts the process and waits for a health
check. Chosen in the new-application wizard, changed under Type & build. A new
runtime is a new entry in `Runtimes::PRESETS`. Not yet: detection from the
repository, a zero-downtime start (the process restarts, so there is a gap of
a few seconds), a builder node for heavy builds, and the runtimes below.

Laravel first. Then, each as a runtime: what the server stack installs, how
a release builds, how the app starts and stays up, and its health check.

1. **Other PHP:** Symfony, WordPress, Statamic, plain PHP. The same stack
   with different build and deploy commands: cheapest to add.
2. **Node:** Next.js, Nuxt, Express, Hono. Node on the stack, `npm ci` and
   `npm run build`, the app run under supervisor behind nginx as a reverse
   proxy, zero-downtime by starting the new release before switching.
3. **Python:** Django, FastAPI, Flask. `uv` or pip, gunicorn or uvicorn
   behind nginx, `collectstatic`, migrations.
4. **Ruby:** Rails. `bundle install`, `assets:precompile`, Puma,
   `db:prepare`.
5. **Go and Rust:** build a binary, run it under supervisor.
6. **Anything with a Dockerfile:** build the image on the server and run
   it; overlaps with the container tier in Phase 8. Needs the Docker support
   planned in Phase 7e. **Built as step 6 of Phase 7e (not run on a real
   server):** "From a Dockerfile" builds on the server, starts the new
   container beside the old one, switches nginx after the health check and keeps
   two previous images for rollback; "From a Docker image" runs a digest-pinned
   image. See the step 6 note under Phase 7e and
   docs/specs/templates.md ("Step 6 as built").

**How a site picks its runtime:**

1. **Detect.** Read the repository (`composer.json`, `package.json`,
   `pyproject.toml`, `Gemfile`, `go.mod`, `Cargo.toml`, `Dockerfile`) and
   propose a project type with the reason, such as "composer.json requires
   symfony/framework-bundle".
2. **Offer presets.** The console lists the supported types, with the
   detected one first and filled in: install commands, build commands, start
   command, health check. Each preset is a versioned definition in the
   repository, like the Phase 7e templates.
3. **Allow custom.** The customer can always choose "custom" and enter their
   own build, start and health commands, and the server packages they need.
   Detection never blocks a site, and a wrong guess is fixed by editing a
   field, not by filing a request.

Previews, variables, logs, metrics, backups and the API work unchanged for
every type; only the build and start differ.

**Done so far:** detection of the application's type from its own files
(`app/Lib/Git/AppDetector`: Laravel, Go, Node, static, and libraries it will
not deploy), a list of the applications in a repository with a picker, and a
**root folder** for a monorepo (`sites.root_dir`). The wizard fills in the type
and folder and the person can change either; with several applications, or none
recognised, it asks. Under the root folder, builds, nginx, workers, the
scheduler and the shared `.env` and `storage` links all work from that folder;
the agent's `SharedPath` gained a `Link` field for this, so a root-folder
deploy needs the new agent (the deploy says so and stops if a server has an
older one). The file list comes from the GitHub App, or from GitHub's tree API
for a public GitHub repository; elsewhere only the top is read and a folder can
be named. Not yet: detection for deploy-key repositories (the key exists only
after the application does; see the SSH-first plan), the templates tab, and
deploying only when the folder changed.

**Wish list (not scheduled):**

- **Git behind WireGuard.** A Git host reachable only over a WireGuard
  tunnel. Deploys already clone on the application's server, so they work if
  that server is on the tunnel (set up by the customer). What fails is the
  control plane's create-time checks (access, branches, detection), which run
  from its own network and refuse private addresses. Preferred fix: run those
  checks from a chosen server through the agent (a "git check" operation, so
  the address guard stays), at the cost of picking the server before the
  repository. Joining the control plane to the tunnel is rejected.
- **Cross-engine database cloning.** Cloning copies within one engine today
  (SQLite to SQLite, MySQL to MySQL, PostgreSQL to PostgreSQL), as a dump and
  load. A copy into another engine, for example MySQL or PostgreSQL into SQLite
  to pull production data into a local development database, is not possible
  that way: dumps are not portable. It would mean reading tables and rows and
  rebuilding the schema for the target, and the risk is in column types,
  indexes, constraints and engine-specific features, so it would need a
  report of what could not be carried over. Wanted for development copies;
  not scheduled.

---

## Phase 7e: Templates — **later**

A catalogue of preconfigured setups (the Coolify model) that a customer
picks and deploys in a few clicks: a site with its database, cache and
workers already wired, or a self-hosted service such as a monitoring tool,
an analytics tool or a CMS, running on a server we manage.

- **Scope, as decided:** a template is a ready-made *self-hosted service*
  (MinIO, a GitLab or Gitea server, a GitHub Actions runner, Uptime Kuma,
  analytics, n8n), never a starter for the customer's own code. Choosing a
  framework for their own repository belongs in the repository step of the new
  application wizard (detection, with a list to override and a custom option;
  see Phase 7d). So a "Laravel template" is not planned.
- Most services run in a container, so the first piece is the container
  runtime below and a per-server "prepare for containers" step. Ports stay on
  127.0.0.1 behind nginx (Docker publishes ports around ufw), images are pinned
  by digest, volumes are managed and backed up. Single-binary services do not
  need one (see the first services below).
- Mockups: the New resource page (Applications, Databases, Templates), the
  catalogue, a service form, review, start, the running service, updates, and
  the definition file are in the Template Mockups artifact.
- **Spec:** [docs/specs/templates.md](docs/specs/templates.md): the definition
  format, the native and container runners, security, and six build steps.
- **Built (steps 1 to 3, not run on a real server yet):** the definition format,
  validator, signed remote indexes and four built-in definitions; the native
  runner (`services` tables, install, start/stop/restart and remove operations,
  `service:*` commands); and the console: Services list, Templates catalogue,
  generated form, review screen, service page (credentials revealed with an
  audit record, delete with keep-or-delete data), the API, `cloud services`,
  `cloud service` and the MCP `list_services`. **Only RustFS is offered**
  (`config('templates.offered')`). The GitHub runner and Gitea definitions are
  built but unverified and not offered; Uptime Kuma waits for the container
  runner (step 4). Not yet built: Logs panel, opening approved extra ports in
  the firewall, updates and backups. Nothing here has been run on a real
  server: the live test (docs/specs/templates-live-test.md) comes first.
- **Built (step 4, container runner; not run on a real server):** `server_containers`
  (Docker Engine from Docker's signed apt repository with the key fingerprint
  pinned in the recipe, daemon settings, `containers_ready_at` and
  `docker_version` on the node, a confirmation on servers under about 1.5 GB),
  the container branch of the same install/start/stop/restart/remove
  operations (digest pull and check, hardened `docker run`, host ports
  allocated from 23000-23999 and bound to 127.0.0.1, named volumes kept
  unless delete-data is chosen), Uptime Kuma offered, a server's Containers
  view (`/server/containers`) with cached reads and `container_cleanup`
  (dangling images and build cache only), `server:containers` and
  `server:containers-cleanup`, and approved extra ports in the firewall.
  Nothing has run on a real server; the live test is in
  docs/specs/templates-live-test.md section 7 onwards. Docker updates and
  layer cleanup on a schedule belong to Phase 7i (Disk and housekeeping,
  Full OS upgrades); only the manual cleanup button exists.
- **Built (step 5, updates and backups; not run on a real server):** a service
  shows "Update available" (what changes, a changed download host or image
  repository needing a ticked confirmation, new inputs asked for, new extra
  ports or acknowledgement re-approved) and the Services list a badge;
  `template_service_update` takes a backup first, fetches the new release or
  image beside the old one and verifies it, switches, checks health and puts the
  old one back by itself if it fails; `template_service_backup` and
  `template_service_restore` archive and restore a data folder or Docker volumes,
  on the server and (when the service's Backups choice is Spaces) in the backup
  bucket, 14 days kept, nightly through `services:backup`; API
  (`/services/{id}/backups`, `/backup`, `/update`) and the `service:backup`,
  `service:restore`, `service:update` commands. Container host ports 23000-23999
  are now refused for applications too. Nothing has run on a real server; the
  live test is docs/specs/templates-live-test.md section 8.
- **Built (step 6, applications from a Dockerfile or a Docker image; not run on
  a real server):** two new application sources, as `sites` rows with the
  runtime `dockerfile` or `image` on a server prepared for containers. A
  Dockerfile application is detected from the repository ("We found a
  Dockerfile", the port of its EXPOSE line, a Compose file noticed and never
  run), checked out as usual and `docker build` on the server (tag
  `cm-app-{name}:{commit12}`, BuildKit cache kept); the new container starts on
  a free port from 23000-23999 beside the old one, and only after its health
  check passes does nginx switch and the old container go. The live image and
  two previous ones are kept, and the existing Roll back restarts the previous
  image on a new port and switches nginx back. An image application is pinned by
  digest on its first pull, registry credentials live in the vault and reach
  `docker login` through a per-application config folder and a 0600 token file
  (never an argument), and it changes only when Update is pressed (the page shows
  the old and the new digest and asks for confirmation). Same hardening as
  services (one shared flag list), domain, HTTPS, password protection and the
  Variables page work unchanged. Not supported in this version, refused with a
  plain message: scheduler, queue workers, background processes, Reverb,
  previews, load balancer, autoscaling, a second server. Also: Uptime Kuma and
  RustFS now stop around their nightly backup. Nothing has run on a real server;
  the live test is docs/specs/templates-live-test.md section 9.
- **Container support (Docker): built in step 4 (see above), unverified. The text below is the original plan.** There is no container
  support today (no Docker install, no image or Dockerfile deploys). The
  decision: Docker Engine on Droplets first, ahead of the Phase 8 container
  tier, as a per-server "Prepare for containers" operation.
  - Installed from Docker's signed apt repository (Docker Engine is Apache 2.0;
    only Docker Desktop has a paid-use limit, and servers never run it).
  - Every published port binds 127.0.0.1 behind nginx, never `0.0.0.0`, because
    Docker's rules sit ahead of ufw. The firewall editor must say so.
  - Images pinned by digest. Docker Hub rate-limits anonymous pulls per IP, so
    prefer GitHub or Quay registries, and mirror the few pinned images to our
    own registry if pulls start failing.
  - Memory: the daemon is roughly 100 MB, fixed per server and not per
    container. On a 1 GB server that is a real share, so the requirements check
    warns there. (Estimate, not measured.)
  - Also unlocks "an application from a Dockerfile or image" in the New
    resource page (Phase 7d, item 6).
  - **Alternative considered: Podman** (same images, no root daemon, no
    iptables changes when rootless, Quadlet units fit our recipes). Not chosen
    because Docker is what customers and image documentation assume; revisit if
    the firewall bypass or the daemon's size becomes a problem.
- **First services.** Tested on a 1 GB Droplet (single node, one disk, 200 x 1
  MB uploads then 4 x 200 MB multipart uploads and downloads at once):
  | | MinIO | RustFS 1.0.5 |
  |---|---|---|
  | Idle / peak memory | 118 / 350 MB | 100 / 568 MB |
  | Whole system peak | 798 MB of 961 | 804 MB of 961 |
  | Time (small, big up, big down) | 7.9, 16.6, 6.9 s | 9.5, 20.5, 10.7 s |
  | OOM kills | 0 | 0 |
  - Both run on 1 GB for light use. Add swap and recommend 2 GB.
  - **MinIO is no longer distributed:** `dl.min.io` returns 410 Gone for the
    official binary, so we would build from source (reports version
    "DEVELOPMENT"), host it and track upstream ourselves, under AGPL. Not the
    first S3 service.
  - **RustFS is the first S3 service:** Apache 2.0, publishes release zips,
    deb and rpm with checksums and an SBOM, 1.0.5 is a 1.0 release. Its binary
    is about 290 MB unpacked and its peak memory is higher but it releases it.
    Only raw S3 upload and download were tested; policies, versioning and the
    console were not.
  - Other first services: GitHub Actions runner (a native binary, no
    container), Gitea (a single binary), Uptime Kuma (an image).
  - Check each service's licence and where its image or binary is published
    before it enters the catalogue.
- Each template is a versioned definition in the repository, reviewed like
  code. A customer can change its values, but not the steps it runs.
- **Definition files (decided 2026-11-15):** one YAML file per service, versioned,
  and the catalogue card, form, review screen and operation are generated from
  it, so a new service is a copy and an edit. A schema check refuses unknown
  fields, a download without a checksum, and privileged containers, the Docker
  socket and host-folder mounts. Three kinds of source are in scope:
  1. **Built-in** (`templates/` in the repository): reviewed; with the signed
     official index, the only ones we vouch for.
  2. **A local folder for self-hosters** (a configured path on the control
     plane): the same format and the same checks, shown as "custom, not
     reviewed".
  3. **Remote indexes** (decided 2026-11-15): an HTTPS or S3/CDN index, signed
     (official and community) or unsigned (custom), with checksums, a cache, a
     guarded fetcher and trust tiers shown on every card. Custom URLs and the
     local folder are **off in the SaaS edition**. The community index is a
     public Git repository (a submission is a pull request, CI runs the
     validator), published as a signed static index; a browse portal comes
     later only if used.
  Not in scope for now: editing or pasting definitions in the console, and
  importing a Docker Compose file. Both
  would need the schema's limits applied to team-supplied files, a readable
  error list and a preview of exactly what will run.

**Weight:** this is a catalogue plus a second deploy path. Keep the first
version to two or three templates before building the picker.

---

## Phase 7f: Server access and tuning — **planned**

What a real application needs from its server that the console does not yet
offer. Full design: [docs/specs/server-runtime.md](docs/specs/server-runtime.md).

| Item | Status | What |
|---|---|---|
| Login user with sudo | **built** | A user for people (default `cloudmgr`, one constant, recorded per server), with a sudo password the owner reveals in the console (audited) and can rotate. Confined to key-only SSH. `deploy` stays the app user. **Lock down SSH** is an opt-in switch on each server card: no root login, no passwords, keys only, written as a drop-in that sorts before cloud-init's, checked by `sshd -t` and removed again if sshd refuses it. It is refused, with the reason on the button, until the login user exists and has an installed key, and checked again when the operation runs. It is off by default, including on servers cloud-manager creates (open question 3 of the spec is still open). A host firewall (`ufw` or the provider's own) is not built: that is open question 4. |
| SSH keys | **built** | The organisation keeps a key library on the Account page; each server has its own installed set, from its card. Public keys only. Removing a key removes it from every server, and an offline server keeps it until it reconnects. When a server is created the form lists the keys, with the "new servers" ones switched on; what is switched on is what the login user gets, and switching all off means none, not the defaults. BYO servers added by install command still take the defaults. |
| Firewall and access allow-lists | **built, run live (Tailscale still not)** | **Firewall** on each server card: `ufw` off by default; on, it denies incoming except SSH and the web ports, each limited to a list of addresses or networks (empty means anyone, so turning it on with no lists changes nothing visitors see). The private network (10.0.0.0/8) stays open to the web ports for a load balancer and a pool's other servers. Rules are rebuilt from the lists each time, as an operation; the agent dials out and the browser console still works if SSH is closed to you. **Password protection** per application (Settings, Web server): one username and password in front of the whole application through nginx basic auth, applied without a deploy, never in front of certificate renewal, only a bcrypt hash kept; not offered behind a load balancer. Not built: the Tailscale "private" mode that follows, and ufw on servers cloud-manager creates by default. **Run live 2026-11-15** on a throwaway server: the open-list firewall left SSH, web and the agent working; with SSH limited to the management server and web to an unrelated address, this Mac was refused on both (timeouts) and operations still ran through the agent; the private 10.0.0.0/8 web rule was present. Password protection: 401 without or with a wrong password, 200-range with the right one, port 80 challenge path stays open and a forced renewal succeeded while protection was on. Finding: the password file (a bcrypt hash) is mode 0644, readable by every local user on the server including the `deploy` user the application runs as; 0640 root:www-data would do, needs group support in the file step. Tailscale was not run (no tailnet key saved for the organisation). |
| Private servers with Tailscale | **built, not run live** | Account → Providers saves a tagged auth key (vault, audited). Each server card has **Join tailnet** / **Leave tailnet** (installs Tailscale from its signed repository, joins with the key through a root-only file that is removed even on failure, records the tailnet address and name). The firewall editor gains **SSH from the tailnet only** and **Web ports from the tailnet only**: the port opens on `tailscale0` alone. Safeguards: needs the server on the tailnet, the firewall on, a confirmation that you can already reach the tailnet address (SSH), and the server verified online just before any rule closes a port; web is refused where an application has a certificate; leaving switches tailnet only off first. Who may reach a server stays in Tailscale. [docs/specs/tailscale.md](docs/specs/tailscale.md). |
| Certificate control | **steps 1-4 built and deployed, run live (one bug fixed, not yet deployed)** | Before this, a certificate was issued once by certbot (HTTP-01) and renewed on certbot's timer with nothing to see or change. Added, as Forge offers: **upload your own certificate** (key and chain, with expiry shown and an alert before it lapses), **renew now** from the console, a **renewal choice** (automatic, manual only), **DNS-validated** certificates for wildcards and for servers that are not reachable from the internet (which also unblocks tailnet-only web ports), and showing issuer and expiry on every application. Spec: docs/specs/certificate-control.md. **Built (step 1):** `sites.cert_source` and `cert_expires_at`, read from the server with `openssl x509 -enddate` (operation `certificate_check`, also after every issue), shown on the application page (amber under 21 days, red under 7) with a Refresh button, and a `certificate_days` alert rule (default 14, 0 off) under Alerts. **Built (step 2):** **Renew now** on the application page (Developers and above, like Refresh, with a confirm because Let's Encrypt allows 5 forced renewals a week per domain): operation `certificate_renew` runs `certbot renew --force-renewal` with the nginx reload hook, re-reads the expiry, uploads to the balancer if there is one, audits `site.certificate_renewed`; refused for uploaded certificates and while another certificate operation runs; certbot's failure text is the operation's error, never retried. **Built (step 3):** **Upload certificate** (Manager and Owner): chain and key are checked on the control plane and again inside the `certificate_upload` operation, held encrypted in the vault only until applied (deleted on success or failure, never in the operation row, logs or audit), written to `/etc/cloud-manager/certs/{domain}` on the primary server only (key `sensitive`, 0600; only its vhost has an `ssl_certificate`), vhost switched, `nginx -t`, reload, and only then `cert_source='custom'`; a failure, the database write included, puts files and vhost back. certbot's lineage for the name is deleted so its timer stops renewing it. The vhost, balancer sync and expiry read follow `cert_source`; a domain change drops back to Let's Encrypt. **Use Let's Encrypt instead** issues fresh (a still-valid lineage from an earlier attempt is reused, not issued again), flips the vhost and removes the uploaded files. Audited `site.certificate_uploaded` / `site.certificate_reverted`. **Built (step 4):** **Auto-renew on/off** for Let's Encrypt certificates (Developers and above, like Renew now): operation `certificate_autorenew` sets `autorenew = False` or `True` in `/etc/letsencrypt/renewal/{domain}.conf` on the primary server, and `sites.cert_auto_renew` flips only after that succeeded; refused for uploaded certificates and sites without HTTPS; audited `site.certificate_autorenew`; the page shows "auto-renew off" beside the date and the expiry alert is unchanged; Renew now still works (certbot checks `--force-renewal` before the setting); a new issue, upload, switch back or domain change resets it to on. **Daily expiry read:** `certificates:check` (cron, 06:17) queues one `certificate_check` per Let's Encrypt HTTPS site, 5 seconds apart, skipping sites with a certificate operation running, so a certificate certbot renewed on its own clears the alert; not audited. Reviewed (code review and security review, nine findings fixed). Not built: DNS validation; upload needs HTTPS already on. The `certificates:check` cron line is installed on the host, and `deploy.sh` now installs `scripts/mgmt/files/cron` on every deploy so future scheduled jobs ship with the code. **Run live 2026-11-15** (real Let's Encrypt certificate on an sslip.io name): expiry read, auto-renew off written to the renewal file, upload (served certificate was the uploaded one, files 0750/0600, certbot lineage removed, vault copy gone), revert to Let's Encrypt (fresh certificate served, uploaded files removed), and `--force-renewal` does ignore `autorenew = False`. **Bug found and fixed (f4667f7):** Renew now timed out, because certbot with no terminal sleeps a random time of up to about 8 minutes before renewing, past the 3 minute limit; the command now passes `--no-random-sleep-on-renew` (tested by hand on the server: renews in seconds). Not yet deployed. Not run: the backup and restore of a failed upload on a real failure, and the daily check against a certbot-renewed certificate. |
| Upload and timeout settings | **built** | nginx upload limit and request timeout per application, a custom snippet in its server block, and `php.ini` per server and PHP version. Fixes the 413 on any upload over about 1 MB (Settings, Web server; `php.ini` per server on the server card). Every change is checked (`nginx -t`) and rolled back on failure. |
| Scheduler interface | **built, but server-level jobs** | Built: the Laravel scheduler switch and task list, custom jobs (name, command with `{php}`, frequency, primary or every server, pause, resume, run now, output, next runs, `flock -n`), and heartbeat monitoring of the scheduler and of each job (a ping after each successful run; an alert when one is more than five minutes late). Still to build: server-level jobs. |
| Provider keys on the Account page | **built, but three things** | Built: two lists, **cloud providers** (the DigitalOcean token) and **DNS providers** (Spaceship). Connect, replace (the new key is checked first, the old one stays until then), test, change expiry and disconnect (blocked, with the reason, while resources need the key). Each row shows the key's first characters and last four, what depends on it and its expiry with a countdown; a daily `provider-keys:verify` checks every key, and Alerts report one that is rejected, expired or inside 14 days. Full keys are never shown again. Not built: a Spaceship key covering **several domains** (still one per organisation), separate **7 and 1 day** reminders (one alert opens inside 14 days), and AWS roles. |
| Server setup script | **built** | A per-application script in Settings, every version kept and audited. It runs as root through the agent the first time a server joins the application, right after the server is set up and before it fetches or builds a release, on a deploy and on an autoscale join. A failing script (15 minute limit) fails the join and records nothing. "Save and run on all servers now" runs it on every server as an operation. Autoscale itself is still Phase 7c; this is ready for it. |

**Weight:** five features, each useful alone. The first slice is the upload and
timeout settings, which is a bug fix. Start the free-text editors after the
structured fields.

---

## Phase 7g: Databases on your server — **planned**

Design: [docs/specs/server-runtime.md](docs/specs/server-runtime.md).

| Item | Status | What |
|---|---|---|
| Injected and custom variables | **built** | The platform's injected values (`DB_*`, `REDIS_*`, what is made with an application) are a separate layer from the user's own variables, and a custom value wins and is never rewritten. Variables page shows both, with overrides flagged and removable; existing applications were split once; their own variables start empty. Fixed the overwrite of a user's edit on re-attach or rotation, and the domain-change and HTTPS copies. |
| Reference system values in variables | idea, to workshop | Insert a reference to something already in the system (another application's URL, a token, a database or cache value) instead of pasting it. Rough UX: typing `_.` in a value opens a searchable dropdown. Open questions: store a reference that resolves at deploy time or copy the value in, how tokens stay masked in the editor, logs and audit trail, and how references show in the audit. Settle this before building the injected and custom variable layers. |
| PostgreSQL, MySQL or Valkey on the app's server | planned | Forge's behaviour for single-server applications: chosen in the wizard or installed later from the server card. Loopback only, one database and user per site, dumps included in the existing backups. Needs the provider shapes in Phase 7c. |
| Copy a connection string | **built** | Database & backups: **Copy connection string** puts the site's database on the clipboard as one URL (`postgresql://` or `mysql://`, TLS on) for psql, TablePlus and the like; each copy has **Copy string** too. It carries the password, so it needs the right to see secrets (Developer and above) and is audited (`site.database_connection_read`). Not for SQLite. |
| Clone a database | **built, not run live (conversion untested; a database on the app's own server cannot be cloned at all, only SQLite and managed clusters)** | On Database & backups: a new named database from a fresh dump, as an operation, schema only or with data. On a managed cluster it is a new database and user beside the site's own; with SQLite it is a file in `shared/`. The connection is revealed as `.env` lines (audited) and the copy can be deleted. Optional conversion queries (set every password, scrub emails; `{{bcrypt:word}}` and `{{now}}` placeholders, saved per application) run on the copy only, as its own database user, and a failing statement drops the copy. A failed copy removes what it made. The dump and load program is checked as valid PHP in tests but has not yet run against a real PostgreSQL or MySQL cluster. Not built: injecting the copy into another application, cross-cluster copies, and local PostgreSQL or MySQL (they wait for local databases). |

**Weight:** the variable layers are small. The local services are large:
they make cloud-manager responsible for data on a single machine.

---

## Phase 7h: Organisations, roles and access — **next**

Design: [docs/specs/organisations-and-roles.md](docs/specs/organisations-and-roles.md).
Build in this order; each step ships alone.

| Item | Status | What |
|---|---|---|
| Current-organisation helper | **built** | `App\Lib\Auth\Org::id()` is the organisation chosen in the switcher, checked against the user's memberships on every call. It replaced the hard-coded `org-1`. Node ids such as `web-1.org-1` and the artisan command examples are unchanged. |
| Memberships and a gate per permission | **built, enforced** | `memberships` table, `users.is_platform_admin`, and `App\Lib\Auth\Roles` holding the role table with one gate per permission (`view`, `tokens.read`, `view.secrets`, `deploy`, `edit`, `console`, `tokens.create`, `audit.read`, `create`, `infrastructure`, `connections`, `members`, `billing`, `organization.delete`, `ownership`). Every console server method and endpoint checks one (see below). |
| Switcher and invitations | **built** | Invitations carry a role (Owner, Manager, Developer, Viewer; default Developer); a Manager cannot invite an Owner. `Org::id()` is the organisation chosen in the switcher, checked against memberships on every call. The switcher in the top bar appears only for someone in more than one organisation. The People list shows roles; removing a member removes the membership, and the account only if they belong nowhere else; a Manager cannot remove an Owner and the last Owner cannot be removed. Inviting, withdrawing and removing now need the `members` permission. Account page → New organisation creates one with its own opaque id (`org-` and ten hex characters), makes the creator its Owner and switches to it. **Do not let a second organisation connect a server until the gateway fixes below land.** People can change one another's roles from the People list (an Owner can change anyone but themselves; a Manager only people below Owner, and cannot make an Owner; the last Owner stays; audited as `user.role_changed`). The Account page is split into sections with a left index (Organisation, People, SSH keys, Providers, GitHub, Your login); new organisation sits behind a **+** and rename behind a button. **Data is now separated by organisation in the console:** every list and every lookup by id in the console (servers, applications, deployments, logs, databases, operations, alerts, audit) carries the current organisation, so another organisation's things neither show nor open by a guessed id; creating an application checks the server is the organisation's own. A test with two organisations covers it. **Roles are now enforced:** every console server method names the permission it needs (`#[Server(can: ...)]`, a test fails if one is missing), and so do the Account, provider, GitHub, SSH key, server-connect and console endpoints; a person who belongs to no organisation sees nothing. A Viewer reads only (no variable values, secrets or consoles); a Developer deploys and edits but cannot create or delete applications, servers or databases; a Manager does everything but billing and ownership. The Account page shows only what the role can use: Viewers and Developers do not see the invite form, role and remove controls or consoles; SSH keys, Providers and GitHub sections are listed only to those who may change them. |
| Gateway audit fixes | **built, deployed** | Finding 8 of the 2026-11-08 audit. The gateway keys sessions by (organisation, node) and every node call (`commands`, `terminal`, status) must carry an `X-Organization-ID` header or is refused with 400; the control plane takes it from its own `nodes` record and treats an unknown node as not connected. The node-connected callback updates only the node of that organisation. A cross-CA, same-node-name regression test is in. Audit findings 1 to 8 are all addressed (finding 6 partly: the explicit confirmation before a pool is deleted is not built). See `docs/security-audit-2026-11-08.md`. |
| Projects | **steps 1-4 built** | Steps 1-2 of docs/specs/projects.md are built: `projects` table, `sites.project_id` (NOT NULL), a Default project per organisation, the wizard Project select, move in application General settings, `project.*` and `site.moved` audit, a minimal Projects tab on Account, `project` in the API site summary. Step 4 built: `GET /api/v1/projects`, `?project=<slug>` on `/sites`, `cloud projects`, `cloud sites --project`, MCP `list_projects` and `list_sites` project argument. Step 3 built: a top-bar project switcher (only with 2+ projects, says projects do not restrict access), `/sites?project=<slug>`, the Applications list and its activity filtered, a polished Projects tab, organisation-wide notes on Servers and Databases, and one door (`Projects::visibleSites()`) for application names on shared cards. v1 is a filter, not a boundary: lookups by id are unchanged. Organization → Project → Application → Environment. Project-level access is later. Databases and Redis clusters can be shared across projects; servers are not assumed to be. |
| One GitHub App for the platform | planned | Installations map to organisations; members' own GitHub logins are later. |
| GitLab and Bitbucket repositories | planned | Seen in Deploynix (GitHub, GitLab, Bitbucket under its git provider settings). Today only GitHub has an integration (the per-organisation GitHub App); anything else works only as a plain git URL with a deploy key. Each provider needs its own connection (OAuth app or token), repository picker, a read-only clone credential and a signed push webhook. Do GitLab first (it also covers self-hosted GitLab), Bitbucket second. Put them behind one small git-provider seam so the GitHub code is the first implementation, not a special case. |
| Scheduled deployments | planned | From the Deploynix changelog: queue a deploy for a chosen time and cancel it before it runs. A delayed `deploy` operation fits the operations queue. |
| Resumable provisioning and failure emails | planned | From the Deploynix changelog: a failed server provisioning resumes from the last completed step instead of starting over, and the owner is emailed with the error. Check what our provisioning operation already retries before building. |
| DigitalOcean OAuth | planned | Wanted before public signup; refresh tokens are single-use, so refresh needs a lock. |
| Platform administrators and support access | planned | A flag set only from the command line, two-factor required; changes need a time-limited, reasoned, audited session. |
| Product name | planned | "cloud-manager" is a description. Candidates Fieldhand, then Acre; trademark and domain checks first. |

---

## Phase 7i: Maintenance and updates — **planned**

Keeping the servers we manage healthy after the day they are built. Today a
server is installed once and then changed only when someone asks: cloud-manager
does not configure, see or report on operating system updates, and nothing says
which servers are behind. What exists to build on: the agent reports its
version to the control plane (`nodes.agent_version`), PHP patch versions are
recorded per server (`nodes.php_patches`), the server stack is a set of
re-appliable recipes, operations give every change a log and an audit entry,
alerts already notify by webhook and email, and certificates have their own
renewal and expiry tracking (Phase 7f).

The principle: **a server's state is something we can read, compare to what it
should be, and bring back in line as an operation**, never something we hope is
fine. Updates run when the owner says, on a schedule the owner sets, and always
leave a way back.

| Item | Status | What |
|---|---|---|
| Fleet health view | planned | One page listing every server with what is out of date: operating system packages waiting (security versus other), a reboot required, runtime patch versions (PHP, Node, Go) against the latest, the agent version against the current one, the Ubuntu release against its end-of-life date. Read-only first; it needs no new power over servers, only reading `apt`, `/var/run/reboot-required` and the versions already known. The first slice. |
| Security updates | planned | Turn on and report **unattended security updates** per server, as a stack recipe step with a switch on the server card (default on for servers we create). Show the last run and any held packages. Security only by default; full upgrades are the next row. |
| Full OS upgrades | planned | `apt upgrade` as an operation on one server or a group, with a log of what changed. For a server behind a balancer or in a pool: **drain, update, health check, return**, one server at a time, and stop if the check fails. A snapshot first where the provider supports it (DigitalOcean does), so there is a way back. |
| Reboots | planned | Detect "reboot required" and offer a reboot as an operation, with the same drain-and-return for balanced servers, a wait for the agent to reconnect, and an alert if it does not come back. Never an automatic reboot of a single-server application unless its owner chose a window. |
| Maintenance windows | planned | A weekly window per server or per organisation (day, hour, time zone) in which scheduled updates and reboots may run, outside it nothing changes without a click. Applications show "maintenance in 2 hours". Visitors see nothing for a balanced or pooled application; a single server is told plainly it has a short outage. |
| Runtime updates | planned | PHP, Node, Go and the other toolchains mise installs: update to the latest patch, or move an application to a new minor version, as an operation with a rollback to the previous release. Warn when a version is within six months of its end of life, and block creating new applications on one that is already past it. PHP has per-version settings (`php.ini`, FPM pools) that must survive an update; test that. |
| Agent updates | planned | Update the Go agent on a server from the console: download the signed build, verify it, swap and restart, confirm it reconnects, and roll back to the previous binary if it does not. A fleet "update agents" action for the owner's servers. Some features already require a newer agent (the deploy stops and says so); this makes that fix one click. |
| Stack drift and repair | planned | Compare a server with the recipes it should have (packages, nginx, supervisor programs, firewall, SSH keys, certificate files) and show the differences. **Repair** re-applies the recipe as an operation. Catches the hand edit someone made over SSH. Read the difference before applying; never repair silently. |
| Disk and housekeeping | planned | The things that fill a disk: old releases, build caches, log files, the apt cache, Docker layers (the manual "Clean up unused layers" button exists from Phase 7e step 4; scheduling it and keeping Docker Engine itself up to date belong here). A per-server report of what takes the space, safe cleanups as operations (keep the last N releases, rotate logs), and the existing disk alert pointing at the cleanup. |
| Backup verification | planned | A backup nobody has restored is a hope. A scheduled test that restores the latest database backup into a throwaway database, runs a row count against the source, deletes it, and records the result per application; an alert when it fails or the backup is older than its schedule. Builds on database cloning. Phase 7c already plans automatic restore tests for databases we run ourselves; this is the same idea for all of them. |
| Security advisories | planned | Tell an application's owner when something it runs has a known vulnerability: `composer audit` and `npm audit` on deploy (shown on the deployment, never blocking), and an advisory notice for the operating system and runtimes on the fleet view. Information first; enforcement is a policy the owner opts into. |
| Pool and image refresh | planned | Autoscaled servers join from a fresh base. Keep that base current so a scale-up does not create a server that is weeks behind, and replace old pool members gradually after a security update instead of patching them in place. |
| The control plane itself | planned | The management server is a server too. Give it the same fleet view, and write down its own routine: operating system updates, gateway and queue restarts, database backups taken and verified (the deploy takes none today; the backups made before migrations have been by hand), a restore drill, and disk and certificate alerts on itself. |
| Maintenance history | planned | Every maintenance action is an operation with an audit entry; add a per-server Maintenance tab listing what ran, when, by whom or by schedule, and whether it worked. The Activity page gains a filter for it. |

**Roles:** viewing the fleet is any member; running updates, reboots and
cleanups needs Manager and above (they affect running applications, like the
other infrastructure actions); changing a maintenance window needs Manager.

**Weight:** large, but it slices well. Start with the fleet health view, which
is read-only and tells us how big the problem is, then security updates (one
recipe step and a switch), then OS upgrades with drain and return, which is the
first piece with real risk and needs the throwaway-server pass before testers
use it. Reboots, windows and runtime updates build on those; backup
verification and the control-plane routine are independent and can run in
parallel. Drain and return depends on balancers and pools behaving as designed,
and none of that has been exercised live.

---

## Phase 7j: Application addresses and your own subdomain zone — **planned, not needed yet**

Every application needs a URL before it has a domain, and a production team
wants random names under its own domain without anyone adding DNS by hand.

Built:
- **A free address.** The wizard gives a new application
  `<name>.<server ip>.sslip.io` once its server is ready. An application that
  missed it (its creation failed before that step) takes it from Settings,
  Domain & HTTPS, **Use a free address**. One certificate per name over HTTP.
- **By hand:** a wildcard A record such as `*.app.example.com` pointed at a
  server, then Move to domain with any name under it.

Planned, with **DigitalOcean DNS** as the provider (the credential is already
connected):
- **A delegated zone.** The team sets a base domain once (`app.example.com`).
  The control plane creates the zone through the DigitalOcean API and shows the
  three NS records to add at the registrar. The key then reaches that zone
  only, never the parent domain.
- **Random subdomains.** A **Use a random subdomain** button (and a wizard
  option) makes `app-<random>.app.example.com`, writes its A record through the
  API, waits for it to resolve, moves the application and offers HTTPS. Nobody
  edits DNS per application. The record goes with the application.
- **One wildcard certificate, only if needed.** Let's Encrypt allows 50 new
  certificates per registered domain a week, which a handful of applications
  never reach. When one does, the control plane gets a single
  `*.app.example.com` certificate with the DNS challenge (the zone is ours to
  write to) and pushes it to the servers through the upload-your-own-certificate
  path, so no server holds a DNS token. This is how Forge's free
  `on-forge.com` sites avoid the limit: one shared wildcard served at the
  edge, not a certificate each.

Decisions: DigitalOcean DNS, not Cloudflare (no proxy in front, and the
servers see real client addresses). Not Spaceship: its connector holds one
domain per team and is not a delegated zone. Servers on other providers use the
same zone; the A record points at whichever server or balancer serves the
application. One domain per application stays as it is. Serving a random name
and a real domain together is separate, larger work (nginx, certificates and
`APP_URL` per extra domain).

**Weight:** small for the zone and the button, medium for the wildcard
certificate. Build the zone and the button first and leave the certificate
until a limit is actually hit.

---

## Phase 8: DOKS container tier — later

Cluster provisioning, container build pipeline, Kubernetes deployment model,
horizontal pod autoscaling, scale-to-zero with a request-holding proxy,
namespace-per-PR previews.

---

## Self-hosted edition — **built**

- **Teams:** the container of members, provider keys and audit log is called a
  team in the console. One install can hold several, so an agency can keep
  clients apart.
- **Instance administrator:** `users.is_platform_admin`, set only from the
  command line (`instance:admin <email> [--remove]`; the first `user:create` on an
  install is made one). It sets the system up and decides who may create teams
  (`instance:team-creation [anyone|admin]`). It opens no team: no servers,
  secrets or audit log of a team it is not a member of.
- **Licence:** the Functional Source License, variant FSL-1.1-ALv2 (Apache 2.0
  two years after each release), copyright Matt Coleman. See `LICENSE`.

---

## Before Postgres or public signup: database hardening — **planned**

Found by an independent review of the schema. None of it blocks today's single
SQLite box; all of it should land before a second tenant you do not know, or a
move to Postgres.

| Item | Status | What |
|---|---|---|
| Audit table protection | built | SQLite triggers refuse UPDATE and DELETE on `audit_records`. On Postgres, `REVOKE UPDATE, DELETE` from the app role instead. |
| Retention | built | `history:prune` daily: finished operations, resolved alerts and closed console sessions older than 90 days, in chunks. The audit log is never pruned. |
| Foreign keys | planned | On the main relationships (`organization_id`, `project_id`, `site_id`, `node_id`, resource ids). `nodes.node_id` becomes the target, RESTRICT on tenant roots, `foreign_key_constraints` stays on. Heavy: needs a data audit for orphans first. |
| Tamper-evident audit chain | planned | Keyed hash with the key held outside the database, and the chain head exported off the box. Without it, someone who can write the database can recompute the whole chain. |
| Tenant isolation in the schema | planned | `organisation_id` on per-site and per-node tables, or composite keys, so a wrong join cannot cross tenants. Postgres row-level security later. |
| Case-insensitive uniqueness | planned | User emails, project names and site names: `lower()` indexes or normalise on write. Small. |
| SQLite-only SQL | planned | `strftime` in `servers.blade.php`, `VACUUM INTO` in `BackupDatabase`, `orderByRaw` boolean sorts in Projects and Previews. |
| `operations.log` growth | planned | Cap the text, move it to a child table or file, redact secrets in it. |
| Index tidy | planned | Operations status indexes, `site_nodes.node_id`, drop redundant single-column organisation indexes. |
| JSON columns | planned | JSON held as text becomes `json` / `jsonb` on Postgres. |
| `sites` table width | planned | One very wide table; split the rarely read columns out. Heavy, do it only if it hurts. |

---

## Potential: console performance

Found while asking why the console feels slower since the move to Vue, Tailwind and Inertia. Nothing here is built; measure first, then do them in this order.

| Item | Status | Notes |
|---|---|---|
| Cache the gateway health check | **potential** | The shared `shell` prop calls `GatewayClient::health()` on every full page visit: a synchronous HTTP call with a 10 second timeout, so a slow or unreachable gateway delays every page. Cache the result for 10 to 30 seconds. The header's gateway indicator can then be that stale. Probably most of the felt slowness. |
| Cache the rest of the shell data | **potential** | The same shared prop rebuilds the header data (team, application and environment switchers, counts) on each visit. Cache it per user and team for a short time; it must be cleared when a rename or a new application changes it, which is the part that can go wrong. |
| Cut the per-site queries | **potential** | Pages that list applications run several queries for each one (releases, servers, status). Load them in a few batched queries. The largest change of the three and the one that needs the most tests. |

---

## Deliberately out of scope

| Item | Why |
|---|---|
| First-party VPS resale | Contradicts the customer-owned-account model. See FEATURE-COMPARISON §1. |
| Sub-second web scale-to-zero on Droplets | Not achievable on a cold VM. Worker scale-to-zero in Phase 4, container tier in Phase 8. |
| A 20-framework matrix | Popular runtimes only (Phase 7d), each added when there is an app to run on it. The long tail is a Dockerfile. |
| A single global build strategy | Build strategy follows the compute model instead. Fixed servers build their own code; autoscale nodes fetch a prebuilt artifact. [ADR 0006](docs/adr/0006-build-strategy-follows-compute-model.md). |
| Terraform for provisioning | State custody per tenant and a poor partial-failure story. PLAN.md §1.3. |
| Ansible for configuration | Needs a Python runtime on every node and gives no structured progress. PLAN.md §2.2. |

- **Console** (Commands tab): a shell on a server from the browser, through the agent (pty, no SSH). Opt-in per organisation, audited, nothing typed is stored. Needs the new agent on each server to work; existing servers upgrade with the agent.
