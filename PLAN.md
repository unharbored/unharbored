# Project Plan: A Laravel Cloud-class PaaS on DigitalOcean

Working title: **Deployment** (rename later)

## Decisions locked

| Decision | Choice |
|---|---|
| Resource ownership | Customer's own DigitalOcean account, connected via OAuth. Forge model. We never resell DO capacity in v1. |
| Compute substrate | Droplets plus agent now. DOKS container tier added later. |
| Audience | Own use first. Control plane built multi-tenant-ready from day one so commercialization is a go-to-market problem, not a rewrite. |
| Scope target | Full Laravel Cloud feature parity, phased. |

## Honest sizing up front

Full parity is a multi-quarter program, not a sprint. Laravel Cloud is the output of a funded team shipping for roughly two years. A realistic read:

- Phases 0 through 3 (single server, managed resources, multi-server with load balancer) produce something genuinely useful internally. That is the Forge-equivalent milestone.
- Phases 4 through 7 are where the Laravel Cloud differentiation lives and where effort compounds.
- Phase 8 (DOKS tier) is effectively a second product sharing a control plane.

Two items will not reach parity on the Droplet tier at any effort level, and that is a property of the substrate rather than the implementation:

1. **Scale-to-zero with sub-second wake.** Droplet cold boot is tens of seconds. Laravel Cloud wakes a sleeping container in under 500 ms. Only the DOKS tier can get close, and even there seconds is the realistic target.
2. **Managed WAF.** DigitalOcean has no native application-layer firewall. Closing this means fronting with Cloudflare, which is what Laravel Cloud does anyway, but it is a second vendor in the architecture.

Both are worth stating to stakeholders early rather than discovering in Phase 4.

---

## 1. Architecture

### 1.1 Three planes

**Control plane (ours).** A Laravel application we host. Owns tenancy, credentials, desired state, orchestration, deploy history, metrics, logs, API, and UI. Never in the request path of a customer application.

**Data plane (customer's DO account).** Droplets, Load Balancers, Managed Databases, Valkey, Spaces, VPCs, Cloud Firewalls, Reserved IPs, DNS records. Everything the customer pays DigitalOcean for.

**Agent (on every Droplet).** A single static Go binary. Opens an outbound mTLS connection to the control plane and holds it. Executes config recipes, runs deploys, ships logs and metrics, reports health.

### 1.2 Why an agent instead of SSH

Forge drives servers over inbound SSH from the control plane. That is simpler to build and worse to operate:

- Requires storing usable private keys for every customer server in our database. For a security firm this is an unattractive blast radius.
- Breaks behind restrictive firewalls and NAT.
- Gives no push channel for metrics, logs, or health.
- Deploy latency is dominated by connection setup.

The agent model inverts the direction. Outbound-only from the Droplet, mTLS with a per-node certificate, no inbound port opened, no long-lived SSH keys at rest. SSH remains available as a break-glass path and for the initial bootstrap verification, but it is not the control channel.

**Bootstrap sequence:**
1. Control plane creates the Droplet with `user_data` cloud-init containing a single-use enrollment token.
2. Cloud-init installs the agent and calls home with the token.
3. Control plane validates the token, issues a node certificate, marks the node enrolled.
4. Enrollment token is burned. All subsequent traffic is mTLS.

### 1.3 Desired state and reconciliation

Every resource (droplet, load balancer, database, firewall rule, site, certificate, worker) has a **desired state** record in the control plane and an **observed state** refreshed by poll or agent report. A reconciler walks the gap.

Do not shell out to Terraform. It sounds appealing and becomes a liability: state file custody per tenant, no good partial-failure story, poor fit for continuous drift correction, and an opaque failure surface. Build a narrow reconciler over the DO API instead. The resource surface we actually need is small and well-documented.

Each resource gets an explicit state machine with persisted transitions, so a failed provision is resumable rather than orphaned. Orphaned paid resources in a customer's account is the worst class of bug this product can have.

### 1.4 Tenancy model

```
Organization
  └─ Cloud Credential (DO OAuth grant, encrypted, per-org key)
  └─ Project
       └─ Application
            └─ Environment  (production, staging, preview/pr-123)
                 ├─ Compute Target  (single droplet | droplet pool + LB | autoscale pool | DOKS namespace)
                 ├─ Resource Attachments (database, cache, bucket, queue)
                 ├─ Environment Variables (versioned, encrypted)
                 └─ Deployments (immutable history)
```

Environment is the unit of deployment and the unit of isolation. This matches Laravel Cloud and keeps preview environments from being a special case bolted on later.

---

## 2. Subsystem design

### 2.1 Provisioning engine

Wraps the DO API for: Droplets, VPCs, Cloud Firewalls, Load Balancers, Managed Databases, Spaces, Reserved IPs, DNS, Droplet Autoscale Pools, Snapshots, DOKS.

Requirements:
- Per-tenant token bucket respecting DO rate limits, with exponential backoff and jitter. Provisioning fan-out will hit limits during multi-server operations.
- Idempotency keys on every create so a retry after a timeout does not double-provision.
- Tagging convention on every created resource so we can always answer "what did we create for this environment" and clean up reliably.
- A reconciliation sweep that detects resources we created but no longer track, and surfaces them rather than silently deleting.

### 2.2 Server configuration

Idempotent recipes executed by the agent, expressed as declarative desired state rather than imperative scripts. Scope for the PHP stack:

- nginx with per-site vhosts
- PHP-FPM, multiple versions side by side, per-site version selection
- Composer, Node, npm/yarn/pnpm/bun
- supervisor for workers
- certbot or an embedded ACME client
- fail2ban, unattended-upgrades, UFW aligned to the DO Cloud Firewall
- a dedicated unprivileged deploy user per site
- logrotate and disk pressure guards

Do not reach for Ansible. It means a Python runtime on every node, SSH-shaped execution, and poor real-time feedback into the deploy log UI. Recipes in the agent give us typed results, structured progress events, and a single binary to ship.

Server types as first-class concepts, matching Forge: `app`, `worker`, `scheduler`, `database`, `cache`, `load-balancer`, `builder`. A single-server environment is simply all roles on one node.

### 2.3 Deploy engine

Atomic release directories, the proven pattern:

```
/home/deploy/<site>/
  releases/
    2026-11-04-143022-a1b2c3d/
  shared/
    .env
    storage/
  current -> releases/2026-11-04-143022-a1b2c3d
```

Pipeline: fetch source, `composer install --no-dev --optimize-autoloader`, asset build, link shared paths, `config:cache` / `route:cache` / `view:cache` / `event:cache`, run migrations under a distributed lock, atomic symlink swap, `php-fpm` reload, opcache reset, `queue:restart`, post-deploy hooks.

Rollback is a symlink repoint plus cache rebuild. Keep N releases configurable.

Hooks before and after each stage, user-editable, matching Forge's deploy script editability. This is a feature users genuinely rely on and its absence is felt immediately.

### 2.4 Multi-server deploys

Build once, ship the artifact. Not build-on-every-node.

1. A designated builder (a role, can be the app node in small setups) produces a release tarball including vendor and compiled assets.
2. Artifact is uploaded to the environment's Spaces bucket, content-addressed by commit SHA.
3. Each app node pulls the artifact, unpacks to a release dir, and holds.
4. Control plane issues a coordinated symlink swap across all nodes.
5. Migrations run exactly once, on one elected node, under a lock, before the swap.

Build-on-every-node is simpler and produces version skew when a `composer install` resolves differently on node 3. Avoid it.

**Mandatory state externalization for multi-node.** Sessions and cache must move to Valkey, file storage to Spaces, database to Managed Database. The control plane should detect single-node-only config (`SESSION_DRIVER=file`, `FILESYSTEM_DISK=local`) and block or warn before converting an environment to multi-node. This is the single most common way a Forge-style multi-server migration breaks in production.

### 2.5 Load balancing

Use DigitalOcean Load Balancers rather than self-managed nginx or HAProxy. They handle health checks, TLS termination, and sticky sessions, and they integrate with Droplet Autoscale Pools for automatic membership.

TLS placement decision:
- If customer DNS is on DigitalOcean, use DO-managed Let's Encrypt certificates on the LB. Zero cert work for us.
- Otherwise, we issue the certificate via ACME DNS-01 in the control plane and upload it into the DO certificate store, rotating on renewal.
- Offer passthrough to node-terminated TLS for customers who require it.

Self-managed HAProxy stays on the roadmap only for customers who need features DO LBs lack.

### 2.6 Autoscaling

DigitalOcean provides Droplet Autoscale Pools that scale on CPU or memory and register members with a Load Balancer.

The hard part is not scaling, it is that a newly booted node must already contain the current release. Two approaches:

- **Snapshot per release.** Bake a DO custom image on every deploy. Fast boot, correct state, but snapshot creation adds minutes to every deploy and accumulates storage cost.
- **Boot-time artifact pull.** Node boots from a base image containing the agent and the configured stack, then pulls the current release pointer from Spaces and unpacks before reporting healthy to the LB.

Recommend boot-time artifact pull. Deploy stays fast, and the LB health check naturally gates traffic until the node is ready. Maintain a periodically refreshed base image so package installation is not on the boot path.

**Worker autoscaling** is where we approximate Laravel Cloud's managed queues. The control plane polls queue depth and oldest-job age from the environment's Valkey or database, then resizes the worker pool against a configured min/max. Scaling workers to zero is viable because nothing is waiting on an HTTP response, which makes this the one place the Droplet tier can match Laravel Cloud economics.

### 2.7 Queues and scheduler

Workers run under supervisor. Support plain `queue:work` and Horizon, with Horizon's dashboard proxied through our UI so failed-job retry works without exposing the app's admin route.

The scheduler needs exactly-once semantics across a pool. Run `schedule:run` on every app node, and rely on a shared cache lock in Valkey to elect a single executor each minute. Pinning the scheduler to one named node is simpler and breaks the moment that node is replaced by the autoscaler.

### 2.8 Managed resources

Provision and attach DO Managed MySQL, PostgreSQL, and Valkey. On attach:
- inject connection credentials into the environment's variable set
- add the environment's VPC or Droplet IPs to the database trusted sources, never leave it open
- surface backup and restore controls, read replica creation, and connection pool settings
- support bring-your-own-database by credential entry

Spaces buckets provisioned per environment, with scoped access keys, CORS configuration, and CDN toggle.

### 2.9 Environment variables and secrets

Encrypted at rest with a per-organization data key, wrapped by a master key held outside the application database. Versioned with diff and rollback. Written to the node as `shared/.env` with `0600` ownership by the deploy user. Never written to deploy logs, never returned in full by the API once set, redacted in all log shipping.

### 2.10 Domains and TLS

ACME with DNS-01 as the default. DNS-01 works before DNS cutover, supports wildcards (needed for preview environments), and does not require the site to be reachable. HTTP-01 as a fallback for customers who will not delegate DNS.

Automatic renewal with alerting well before expiry. DigitalOcean DNS integration for customers hosting DNS there, so apex and subdomain records are created automatically.

### 2.11 Preview environments

On the Droplet tier, a droplet per pull request is too slow and too expensive. Use a **shared preview host per application**:

- One Droplet (or small LB pool) dedicated to previews
- Per-PR nginx vhost on a wildcard subdomain, covered by a wildcard certificate
- Per-PR database created as a separate schema or database on a shared Managed Database instance
- Per-PR queue prefix on shared Valkey
- Teardown on merge or close, plus a TTL sweeper for abandoned branches

This gets most of the value. True isolation per PR waits for the DOKS tier, where a namespace per PR is the natural unit.

### 2.12 Observability

Split by source:
- **Infrastructure metrics** (CPU, memory, disk, bandwidth) come from the DigitalOcean Monitoring API. Free, already collected, no agent work.
- **Application signals** (PHP-FPM pool status, nginx request rates and status codes, Laravel logs, deploy logs, queue depth) come from the agent.

Logs stream to the control plane over the existing agent channel, land in object storage, and are indexed for search with a retention window per plan. Metrics go to a time-series store. Avoid building a general-purpose observability product; scope to what a developer needs to answer "is my app healthy and what broke."

### 2.13 Alerts

Threshold alerts on CPU, memory, disk, queue depth, failed jobs, deploy failure, certificate expiry, and health check failure. Delivery by email and Slack webhook. Per-application and per-organization routing.

### 2.14 API, CLI, and agent interface

The API is not an afterthought. Design the control plane API first and have our own UI consume it, which guarantees the public API is complete.

- REST API with scoped tokens
- A CLI mirroring the Laravel Cloud CLI surface: deploy, logs tail, artisan command execution, resource provisioning, env management
- An MCP server exposing the same operations to coding agents

The MCP server is a genuine differentiator and cheap once the API exists. Given where tooling is heading, shipping it early is worth more than another UI screen.

### 2.15 Framework support

Start with Laravel and generic PHP only. Add Symfony second, since it shares the PHP runtime and differs mainly in console binary and cache commands. Node-based frameworks are a separate runtime and build pipeline, and should not be attempted until the PHP path is stable.

Laravel Cloud's twenty-plus framework matrix is a long tail that looks impressive and delivers little early value for an internal-first product.

---

## 3. Security design

This matters more than usual given who is building it.

**Credential custody is the dominant risk.** A DigitalOcean personal access token is account-wide. Holding full-access tokens for customer accounts in our database makes us a high-value target with catastrophic downside. Requirements:

- Use DigitalOcean OAuth with the narrowest scopes the API supports, never pasted PATs, for any external tenant.
- Per-organization data encryption keys, wrapped by a master key in a dedicated KMS, not in application config.
- Token use is audited: every DO API call attributed to an actor, an organization, and a reason.
- Support customer-initiated revocation and show them exactly what we can access.

Other controls:
- Agent mTLS with per-node certificates and short rotation. Enrollment tokens single-use and short-lived.
- Default-deny Cloud Firewall on every provisioned Droplet. SSH restricted to the control plane egress range or disabled entirely once the agent is enrolled.
- Managed Databases bound to VPC trusted sources only, never public.
- Per-site unprivileged deploy users. The agent runs privileged operations through a narrow allow-listed command surface, not arbitrary shell from the control plane.
- Full audit log of every control plane action, immutable, exportable.
- Secret redaction in log shipping as a pipeline stage, not a best effort.
- Tenant isolation tested adversarially, not assumed. Scope this as its own work item.

Because the product is internal first, SOC 2 scoping can wait, but the audit log, credential custody, and tenant isolation design cannot be retrofitted cheaply. Build them in Phase 0.

---

## 4. Technology choices

| Component | Choice | Reasoning |
|---|---|---|
| Control plane | Laravel 12 | Team fluency, and the product serves Laravel developers. Dogfooding is a feature. |
| Control plane UI | Inertia with React | Realtime deploy log streaming and resource dashboards need client-side state. |
| Control plane database | PostgreSQL | JSONB for desired-state documents, strong constraint support for the state machines. |
| Orchestration | Laravel queues plus explicit per-resource state machines | Long-running provisioning is a jobs problem. The state machine is what makes failures resumable. |
| Realtime | Laravel Reverb | Deploy log streaming and live resource status. |
| Agent | Go, single static binary | No runtime dependency on the node, cross-compiles, small, and appropriate for long-lived connections. |
| Agent transport | mTLS over a persistent WebSocket, protobuf framing | Outbound only, push-capable, no inbound ports. |
| Metrics store | TimescaleDB on the same Postgres | Avoids a second datastore until volume justifies ClickHouse. |
| Log store | Spaces with an index in Postgres | Cheap retention, adequate search for the internal phase. |

Control plane hosting: do not dogfood initially. Run it on stable infrastructure until the platform can be trusted to host itself, then migrate as a milestone.

---

## 5. Phased roadmap

Each phase is independently useful. Do not start a phase before the previous one is in real use.

### Phase 0: Foundations
Control plane skeleton, organization and project model, DigitalOcean OAuth flow, encrypted credential vault with per-org keys, audit log, agent protocol and enrollment handshake, resource state machine framework, DO API client with rate limiting.
**Exit criterion:** we can provision a bare Droplet into a connected DO account, enroll the agent, and see it report healthy.

### Phase 1: Single server, end to end
Server provisioning recipes, site creation, Git integration (GitHub first), deploy engine with atomic releases, deploy script editing, environment variable management, ACME certificates, supervisor workers, scheduler, deploy log streaming, rollback.
**Exit criterion:** a real internal Laravel application runs in production on the platform on one Droplet.

### Phase 2: Managed resources
Managed MySQL, PostgreSQL, and Valkey provisioning and attachment with trusted-source firewalling. Spaces bucket provisioning with scoped keys. Bring-your-own-database. Backup and restore surfaces.
**Exit criterion:** that same application uses a managed database and cache, with no manual DO console work.

### Phase 3: Multi-server and load balancing
Server roles, builder node, artifact build and distribution through Spaces, coordinated multi-node release swap, migration election under lock, DO Load Balancer provisioning, TLS at the LB, state externalization checks, single-to-multi node conversion flow.
**Exit criterion:** an application serves production traffic across three nodes behind a load balancer with zero-downtime deploys. This is the Forge-parity milestone.

### Phase 4: Autoscaling
Droplet Autoscale Pool integration, base image maintenance, boot-time artifact pull, health gating, scheduled scaling for predictable load, queue-depth-driven worker pool scaling including scale to zero for workers.
**Exit criterion:** an application absorbs a synthetic traffic spike by scaling out and scales back in without intervention.

### Phase 5: Observability and alerts
DO Monitoring API ingestion, agent log shipping with secret redaction, log search, application and infrastructure dashboards, request status code and throughput views, alert rules, email and Slack delivery.
**Exit criterion:** we diagnose a real production incident using only the platform UI.

### Phase 6: API, CLI, and MCP
Public REST API with scoped tokens, CLI covering deploy, logs, artisan, env, and resource management, MCP server over the same surface.
**Exit criterion:** a full deploy and a rollback performed entirely from the CLI, and from a coding agent through MCP.

### Phase 7: Preview environments
Shared preview host model, wildcard DNS and certificates, per-PR vhost and schema provisioning, GitHub pull request lifecycle integration, TTL sweeper, preview URL commenting on the PR.
**Exit criterion:** opening a pull request produces a working URL within a few minutes and closing it reclaims every resource.

### Phase 8: DOKS container tier
Cluster provisioning, container build pipeline and registry, Kubernetes deployment model, horizontal pod autoscaling, scale-to-zero with a request-holding proxy, namespace-per-PR preview environments, shared control plane with the Droplet tier behind a common Compute Target abstraction.
**Exit criterion:** an application runs on the container tier with scale-to-zero and wakes in single-digit seconds.

### Phase 9: Commercialization
Public signup, plans and billing, team RBAC, SSO and enforced 2FA, support tooling, abuse controls, status page, SOC 2 readiness work.

---

## 6. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| DO credential custody breach | Catastrophic. Full access to customer infrastructure. | OAuth with minimal scopes, per-org keys in a dedicated KMS, audited token use, customer-visible access disclosure, revocation path. Phase 0 work, not deferrable. |
| Orphaned paid resources after failed provisioning | Customer billed for resources they cannot see or delete. Erodes trust fast. | Resumable state machines, consistent resource tagging, reconciliation sweep that surfaces untracked resources. |
| DO API rate limits during fan-out | Provisioning and multi-node deploys fail under load. | Per-tenant token bucket, backoff with jitter, batch operations where the API supports it. |
| Config management scope creep | The classic underestimate. Absorbs a quarter silently. | Narrow the supported stack hard. One nginx layout, one supervisor layout, a fixed set of PHP versions. Refuse customization requests in v1. |
| Multi-node conversion breaks apps using local sessions or local disk | Silent data loss, confusing failures. | Pre-flight configuration checks that block conversion, with explicit remediation guidance. |
| Scale-to-zero parity cannot be met on Droplets | Expectation gap against Laravel Cloud. | State it publicly up front. Position worker scale-to-zero as the Droplet-tier answer and route the use case to the DOKS tier in Phase 8. |
| No native WAF on DigitalOcean | Security gap relative to Laravel Cloud's Cloudflare edge. | Offer first-class Cloudflare integration as part of the domain setup flow. |
| Deploy engine correctness under partial failure | A half-swapped multi-node release is a production outage. | Two-phase deploy with explicit prepare and commit stages, automatic rollback on partial commit failure, and a deliberate chaos test suite. |
| Building a second product in Phase 8 | DOKS tier doubles maintenance surface. | Abstract Compute Target cleanly in Phase 3 so the tier is a strategy implementation, not a fork. |

---

## 7. What to build first

Start Phase 0 with three things in parallel, because they are the long poles and everything else depends on them:

1. **The agent protocol and enrollment handshake.** Get a Droplet booting, enrolling, and holding an mTLS channel. Everything downstream rides on this.
2. **The credential vault and audit log.** Cannot be retrofitted. Design it before any DO token is stored.
3. **The resource state machine framework.** The difference between a demo and a product is what happens when provisioning fails halfway.

The first real milestone to aim at is the Phase 1 exit criterion: one internal Laravel application running in production, on one Droplet, deployed entirely through the platform. Everything after that is additive.

---

## 8. Open questions

- Which internal application is the Phase 1 guinea pig? It should be real enough to expose problems and tolerant enough to survive them.
- GitHub only in v1, or GitLab and Bitbucket as well? GitHub only is the recommendation.
- Do we need Windows or ARM Droplet support? Assume no, x86 Ubuntu LTS only, until someone asks.
- What is the acceptable deploy duration target? This drives the build-once-and-ship decision more than any other factor.
- Does anything in scope require handling client engagement data? If so, the credential and log handling design needs a review against the data handling requirements of whoever supplies that data before Phase 2.
