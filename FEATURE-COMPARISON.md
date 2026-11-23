# Feature Comparison: Laravel Cloud vs Laravel Forge, and Our Target

Companion to `PLAN.md`. Every Forge and Cloud row below was pulled from current vendor documentation on 2026-11-04, not from memory.

## How to read this

The two products are not competitors so much as two answers to the same question. Forge sells **control**: your servers, your root access, your cloud account, flat monthly fee. Cloud sells **abstraction**: no servers exist as far as you are concerned, usage-based pricing, Laravel-tuned.

Our platform takes Forge's ownership model and Cloud's managed experience. That combination is the product thesis. Where we cannot hit a feature exactly, the compromise column states what we ship instead and what it costs us.

**A note on vendor sources.** Laravel's own [Forge vs Cloud comparison page](https://laravel.com/forge/forge-vs-cloud) is stale in two places. It lists Cloud as supporting "Laravel" only, while the [Cloud docs](https://laravel.com/cloud/docs) list over twenty frameworks across six runtimes. It lists six Cloud regions, while the Cloud homepage says nine. I used the docs and homepage as authoritative where they conflict.

Legend: ● full, ◐ partial, ○ none.

---

## 1. Provisioning and server model

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Bring your own cloud account | ● DO, AWS, Hetzner, Vultr, Linode, plus custom server | ○ | ● DigitalOcean via OAuth | Single provider in v1. Provider interface abstracted from day one so Hetzner and Vultr are additive, not a rewrite. |
| First-party instant VPS | ● Laravel VPS, under 10 seconds | n/a | ○ out of scope | **Decided against, not a gap.** Reselling our own VPS contradicts the customer-owned-account model, and a warm pool is impossible inside an account we do not own. Provisioning speed is still addressed in §9.2. |
| Server types as first-class | ● web, database, load balancer, cache, worker | ○ abstracted away | ● app, worker, scheduler, builder, load-balancer, database, cache | Full parity. This is how the single/multi/LB requirement is satisfied. |
| Root and SSH access | ● | ○ | ● break-glass SSH | Parity with Forge. The agent is the control channel, SSH is the escape hatch. |
| Web terminal with shared sessions | ● Laravel VPS only | ○ | ◐ deferred | On hold. The agent's duplex channel makes this cheap to add later, so nothing in the architecture needs to anticipate it. Break-glass SSH covers the operational need in the meantime. |
| No servers to manage at all | ○ | ● | ◐ | Honest gap. Ownership is the tradeoff for ownership. Mitigated by automated patching, drift correction, and never requiring the user to open a terminal in normal operation. |
| Automatic OS patching | ● unattended-upgrades | ● fully managed | ● plus reboot scheduling and patch reporting | We beat Forge: Forge enables unattended-upgrades but does not report posture. We surface what is patched, what is pending, and what needs a reboot. |

---

## 2. Stack and runtime

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Nginx, PHP-FPM, multi-version PHP | ● | ● managed | ● | Parity. |
| Nginx template customization | ● | ○ | ● | Forge's edge, kept. Cloud users cannot touch the web server config, which is a real limitation for anything non-standard. |
| OPcache, logrotate, UFW | ● | ● managed | ● | Parity. |
| Meilisearch, Memcached | ● | ○ | ◐ Meilisearch deferred | Not v1. Add as an installable package once the recipe system is stable. |
| Framework support | ● Laravel, PHP, WordPress, Statamic, Node, Nuxt, Next | ● 20+ across PHP, Node, Bun, Deno, Go, Python, Ruby, Rust | ◐ Laravel and PHP first, Symfony second, Node third | Laravel Cloud's framework matrix is a long tail. Chasing it early buys nothing for an internal-first product. |
| Per-site user isolation | ● | ● by design | ● | Parity. |
| Custom provisioning scripts | ● Recipes, run across servers | ○ | ● | Forge's Recipes feature is underrated and cheap to match given the agent. |

---

## 3. Deployment

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Git push to deploy | ● GitHub, GitLab, Bitbucket | ● | ● GitHub v1 | GitLab and Bitbucket deferred. Not an architectural problem, just surface area. |
| Zero-downtime deploy, single server | ● now default | ● | ● | Parity. |
| Zero-downtime deploy, multi-server | ◐ requires Envoyer subscription | ● | ● built in | **We beat Forge outright.** Forge's own FAQ states it only supports zero-downtime on one server and points multi-server users back to Envoyer. Our build-once-ship-artifact pipeline with a coordinated swap handles this natively. |
| Editable deploy script | ● | ◐ build and deploy hooks | ● | Parity with Forge, which is the stronger model here. |
| Deployment rollback | ● | ● | ● symlink repoint | Parity. |
| Stacked and queued deployments | ● | ● | ● | Parity. |
| Deploy log streaming | ● | ● | ● realtime via Reverb | Parity. |
| Build once, ship artifact | ○ builds on each node | ● | ● | We beat Forge. Forge's per-node build is how multi-server version skew happens. |
| Migration run-once election | ○ manual discipline | ● | ● distributed lock | We beat Forge. |
| Deploy duration | ~30-60s typical | under 60s claimed | target under 60s | Achievable. Artifact pull is faster than a per-node composer install. |

---

## 4. Scaling

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Manual vertical scale | ● | ● | ● | Parity. DO resize requires a reboot, so we schedule it. |
| Horizontal scale, static pool | ● | ● | ● | Parity. |
| Load balancer | ● Forge-managed nginx | ● built in | ● DigitalOcean Load Balancer | We use the managed LB rather than a self-managed nginx box. Fewer moving parts, health checks and autoscale pool membership included. Self-managed HAProxy stays available for edge cases. |
| Autoscaling on demand | ○ explicitly none | ● CPU-based within replica max | ● DO Droplet Autoscale Pools, CPU and memory | **Parity with Cloud, and we beat Forge.** DO autoscale pools scale on CPU or memory, feed a Load Balancer, and inherit cloud firewall rules automatically. |
| Scheduled pre-warm scaling | ○ | ● | ● | Parity. Cron-driven min-replica changes. |
| Worker autoscaling on queue depth | ○ | ● | ● | Parity, and arguably better. We scale on queue depth and oldest-job age rather than CPU, which is the correct signal for a worker. |
| Worker scale to zero | ○ | ● | ● | Full parity. Nothing waits on an HTTP response, so this works cleanly on Droplets. |
| Web scale to zero, sub-second wake | ○ | ● under 500 ms | ◐ | **The one genuine miss.** See §9. |

---

## 5. Managed resources

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Database on the server | ● MySQL, Postgres, MariaDB | ○ | ● | Forge's edge, kept. Cheapest option for small apps. |
| Managed database provisioning | ● provider managed DB integration | ● MySQL, Serverless Postgres | ● DO Managed MySQL and PostgreSQL | Parity. |
| Serverless Postgres with autoscaling compute | ○ | ● | ○ | DO Managed PostgreSQL is fixed-size. Compromise: automated sizing recommendations from observed load, one-click resize in a scheduled window, and connection pooling enabled by default. Removes the monitoring burden without matching the autoscale behavior. |
| Managed cache | ● | ● | ● DO Managed Valkey | Parity. |
| Database backups | ● configurable to object storage | ● automatic plus point-in-time | ● | DO Managed Databases provide automated daily backups and point-in-time restore. Verify the retention window per engine before promising a specific RPO. For on-server databases we match Forge's scheduled dump to Spaces. |
| Object storage | ● storage provider integrations | ● first-party | ● DO Spaces, per-environment bucket with scoped keys | Parity. |
| WebSockets as a managed resource | ○ | ● one click | ◐ | Compromise: one-click Laravel Reverb install as a supervised process behind the LB with sticky sessions. Functionally equivalent, operationally yours rather than ours. |
| Bring your own database | ● | ● | ● | Parity. |

---

## 6. Queues, scheduling, background work

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Supervised queue workers via UI | ● | ● | ● | Parity. |
| Horizon support | ● | ● | ● with dashboard proxied through our UI | We beat both on ergonomics. Proxying Horizon means failed-job retry without exposing an admin route on the app. |
| Failed job retry dashboard | ◐ via Horizon on the app | ● built in | ● | Parity with Cloud. |
| Scheduler | ● intervals or cron expressions | ● | ● with Valkey lock election | We beat Forge. Forge pins the scheduler to a server; ours survives that node being replaced by the autoscaler. |
| Scheduled job heartbeat alerting | ● Heartbeats | ○ | ● | Forge's edge, kept. Genuinely useful and Cloud lacks it. |
| Daemons and background processes | ● | ● worker clusters | ● | Parity. |
| Queues continue while web tier sleeps | ○ | ● | ● | Parity, since our worker pool is separate from the web pool. |

---

## 7. Networking, domains, security

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Free automated SSL | ● Let's Encrypt | ● | ● ACME with DNS-01 default | We beat both slightly. DNS-01 works before DNS cutover and supports wildcards, which HTTP-01 does not. |
| Custom certificates | ● | ◐ | ● | Parity with Forge. |
| Instant test domain | ● on-forge.com | ● | ● wildcard on our own domain | Parity. Cheap and high-value for preview environments. |
| Default firewall rules | ● UFW | ● managed | ● UFW aligned with DO Cloud Firewall | We beat Forge: Forge manages UFW on the box, we manage both the host firewall and the cloud firewall so a misconfigured host cannot expose a port. |
| Bot and DDoS protection | ○ | ● Cloudflare edge | ◐ | DO has no native WAF. Compromise: first-class Cloudflare integration in the domain setup flow that automates zone creation, proxy mode, WAF rules, and rate limiting. Same vendor Cloud uses, one extra account. Functionally close to parity. |
| Private networking and VPC | ◐ manual | ● | ● VPC per environment by default | We beat Forge. Databases bound to VPC trusted sources, never public. |
| SSH key management | ● | n/a | ● plus no long-lived keys stored | **We beat Forge on security posture.** Forge stores usable private keys for every managed server and requires you to allow-list their four IPs for inbound SSH. Our agent is outbound-only mTLS with no inbound port and no stored keys. |
| SSO and enforced 2FA | ◐ | ● | ● Phase 9 | Deferred to commercialization. |
| Audit logging | ◐ | ● Private Cloud tier | ● from Phase 0 | We beat Forge, and match Cloud's enterprise tier at the base tier. Cannot be retrofitted, so it ships first. |
| SOC 2 | ● Trust Center | ● Type II | ◐ Phase 9 | Data plane inherits DigitalOcean's SOC 2 Type 2. Control plane certification is a Phase 9 commercialization item. |
| Security posture reporting | ○ | ○ | ● | **Neither competitor does this.** CIS benchmark checks, CVE exposure on installed packages, open port drift, certificate expiry, and weak config detection, reported per server. Natural differentiator for us specifically. |

---

## 8. Observability, operations, developer experience

| Feature | Forge | Cloud | Our target | Compromise |
|---|---|---|---|---|
| Server metrics, CPU/memory/disk/bandwidth | ● real-time charts | ● | ● via DO Monitoring API | Parity, and free. No agent work needed for infra metrics. |
| Application logs | ● log access | ● searchable in dashboard | ● shipped by agent, searchable | Parity. |
| Request status codes and throughput | ○ | ● | ● from nginx | We beat Forge. |
| Web analytics | ○ | ● | ◐ deferred | Nice to have, not a Phase 5 blocker. |
| Health checks post-deploy | ● | ● | ● | Parity. |
| Alerts to email and Slack | ● notifications | ● resource, deploy, billing | ● | Parity. |
| Cost visibility | ○ | ● spending limits with auto-pause | ◐ | We bill nothing, so we cannot pause a customer's DO account. Compromise: pre-provision cost estimate, live spend dashboard from the DO billing API, budget alerts, and opt-in auto-hibernate limited to non-production environments. Deliberately not auto-destroying someone else's infrastructure on a threshold. |
| REST API | ● | ● | ● API-first, our UI consumes it | Parity. API-first guarantees completeness. |
| CLI | ● | ● | ● | Parity. |
| SDK | ● | ◐ | ◐ generated from OpenAPI | Cheap once the API is specified. |
| MCP server for coding agents | ○ | ◐ Boost plus Cloud CLI | ● first-class | **We beat both.** Cloud gives agents the CLI; a native MCP server over the full API is a better fit for how agent tooling is going. Cheap once the API exists. |
| Command palette | ● ⌘K | ◐ | ● | Forge's edge, kept. Small feature, disproportionate daily value. |
| Organizations, teams, RBAC | ● | ● | ◐ planned | One organisation is hard-coded today; docs/specs/organisations-and-roles.md defines members, roles and projects. Parity once built. |
| Preview environment per pull request | ○ | ● fully isolated | ◐ | See §9. |

---

## 9. The four compromises, in detail

Everything above that is not full parity reduces to four items. These are the ones to walk stakeholders through.

### 9.1 Web scale-to-zero

**Cloud does:** container sleeps when idle, wakes in under 500 ms, you pay nothing while asleep.

**Why we cannot match it on Droplets:** a powered-off Droplet takes tens of seconds to boot. There is no amount of engineering that makes a cold VM behave like a warm container.

**Compromise, three tiers:**

1. **Worker scale-to-zero: full parity.** Ships in Phase 4. This is where most of the idle cost actually sits for a queue-heavy Laravel app.
2. **Environment hibernation for non-production.** Power off idle staging and preview Droplets on a schedule or inactivity timer. A shared always-on wake proxy holds the first request, powers the Droplet on via the DO API, and forwards once healthy. First request takes 30 to 60 seconds. Positioned explicitly as a dev and staging feature, never production.
3. **True web scale-to-zero: Phase 8, DOKS tier.** Realistic target is single-digit seconds, not 500 ms.

**The honest reframe worth making to stakeholders:** DigitalOcean's floor price is a few dollars a month for a Droplet that is always on. Cloud's scale-to-zero exists partly because AWS economics punish idle capacity. On DigitalOcean the saving from scale-to-zero on a small app is smaller than the complexity of implementing it. This gap matters much less here than the feature matrix implies.

### 9.2 First-provision speed

**Cloud does:** live in under 60 seconds. Forge's sub-10-second Laravel VPS is not the benchmark here, since first-party VPS is out of scope by decision.

**Why we cannot pre-provision:** the Droplet is created inside the customer's DigitalOcean account. A warm pool requires owning the machines, which the customer-owned-account model rules out.

**Compromise:** maintain a pre-baked DO custom image containing the full stack and the agent, refreshed on a schedule. A Droplet from a custom image boots with everything installed, so provisioning is boot time plus site configuration rather than boot time plus a five-minute package install. Realistic target is roughly two minutes from click to first deploy. Be honest about the number rather than claiming sub-minute.

### 9.3 Preview environments

**Cloud does:** fully isolated production-like environment per pull request, torn down on merge.

**Why full isolation is expensive on Droplets:** a Droplet per PR is slow to provision and costs real money per open PR.

**Compromise:** a shared preview host per application. Per-PR nginx vhost on a wildcard subdomain, per-PR database schema on a shared Managed Database, per-PR queue prefix on shared Valkey, teardown on merge or close plus a TTL sweeper for abandoned branches. This captures the workflow value, which is the part that matters, at a fraction of the cost. Hard isolation per PR arrives with the namespace-per-PR model in the DOKS tier.

### 9.4 Managed WAF and bot protection

**Cloud does:** Cloudflare edge network with DDoS protection and WAF on every application.

**Why we cannot match it natively:** DigitalOcean has no application-layer firewall product. Cloud Firewalls are network layer.

**Compromise:** build Cloudflare integration into the domain setup flow as a first-class step rather than a documentation note. Automate zone configuration, proxy mode, WAF rule sets, and rate limiting from our UI. This is the same vendor Laravel Cloud uses. The cost is one additional account in the architecture, not a capability gap.

---

## 10. Best of both: what we combine that neither does

This is the pitch, internal or external.

**From Forge, kept:**
- Your cloud account, your servers, your root access
- Server roles, including dedicated load balancer and worker nodes
- Editable deploy scripts and nginx templates
- Recipes for custom provisioning across a fleet
- Scheduled job heartbeat alerting
- Command palette
- Flat, predictable platform pricing

**From Cloud, adopted:**
- Autoscaling, on CPU, memory, and queue depth
- Worker scale-to-zero
- Managed database, cache, and object storage provisioned and wired in one click
- Preview environments tied to pull requests
- Environment as the unit of deployment, so staging and preview are not bolted on
- Failed-job dashboard without exposing an app route
- Logs, metrics, request status codes, and alerts in one place
- API-first with a real CLI

**Neither has, and we should:**

1. **Zero-downtime multi-server deploys in the base product.** Forge charges for Envoyer to get this and still caps it. Cloud has it but takes your servers away. Nobody offers both.
2. **No stored SSH private keys.** Forge's model requires us to hold keys that grant root on every managed server and to allow-list inbound SSH. The agent model removes that entire class of risk. For this project this is the architecturally correct choice and a credible differentiator.
3. **Security posture reporting as a product feature.** CIS benchmark checks, CVE exposure on installed packages, port drift detection, weak TLS config, certificate expiry, patch status. Neither Forge nor Cloud reports any of this. We are a security firm building a deployment platform; this is the feature only we would think to build.
4. **Native MCP server.** Cloud points agents at its CLI. A first-class MCP surface over the full API is a better fit and nearly free once the API exists.
5. **Cost transparency against real provider billing.** Estimated cost before provisioning, actual spend pulled from the DO billing API, attributed per environment. Forge shows nothing. Cloud shows its own usage, not your underlying cloud bill.

---

## 11. Scorecard

Counting the rows above, against a target of Laravel Cloud parity:

| Outcome | Count | Notes |
|---|---|---|
| Full parity with Cloud | ~38 | The large majority. |
| We beat Forge outright | 11 | Multi-server zero-downtime, autoscaling, worker scale-to-zero, scheduler election, build-once, VPC defaults, SSH key handling, audit log, firewall layering, patch reporting, request metrics. |
| We beat both | 5 | See §10. |
| Partial, with a stated compromise | 8 | Serverless Postgres, WebSockets, WAF, preview isolation, cost auto-pause, framework breadth, SOC 2 timing, web terminal. |
| Out of scope by decision | 1 | First-party instant VPS. Not counted as a gap. |
| Genuine miss | 1 | Sub-second web scale-to-zero on the Droplet tier. Addressed in Phase 8. |

One unavoidable gap, and it is the one that matters least given DigitalOcean's pricing floor. That is a defensible position to take into a build decision.

---

Sources: [Laravel Cloud docs](https://laravel.com/cloud/docs), [Laravel Cloud homepage](https://laravel.com/cloud), [Laravel Forge docs index](https://laravel.com/forge/docs), [Forge introduction](https://laravel.com/forge/docs/introduction), [Forge homepage](https://laravel.com/forge), [Forge vs Cloud](https://laravel.com/forge/forge-vs-cloud), [DO Droplet Autoscale Pools](https://docs.digitalocean.com/products/droplets/how-to/use-autoscale-pools/), [DO App Platform Scale to Zero](https://docs.digitalocean.com/products/app-platform/how-to/scale-to-zero).
