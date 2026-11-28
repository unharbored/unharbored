# Scope findings

Matt reviews (the `matt-review` skill) of plans and specs: weight and scope first, then cost, Forge and the new user. Checked against the code. The companions are [security-findings.md](security-findings.md) and [usability-findings.md](usability-findings.md).

## Round 1, 2026-11-25: providers ([specs/providers.md](specs/providers.md))

**Verdict: trim it.** The model is right: one provider per app, `supplies()` as data, locked shapes with reasons, and SSH used once for Custom. But the plan has grown past the ask. Six providers are built with no customer yet, two scalers run side by side, and the spec is 930 lines and turning into a build log.

### Ripple effects

- **More providers make the firewall fix harder.** security-findings suggests a DigitalOcean Cloud Firewall at create time. Across six providers that is six firewall APIs. The provider-neutral fix is ufw default-deny on every node (`Firewall.php:15`). Only AWS gets a native rule, its security group.
- **The scaler inherits the day-30 certificate expiry.** It scales from agent metrics, and those stop when certificates lapse. Nothing tells it "no data", so it freezes or acts on stale numbers. It also stops when the management server dies, and nothing watches that server.
- **Each provider adds daily calls and a contract to keep up.** `providers:refresh-catalogs` and `provider-keys:verify` call every connected provider daily. One API change means an alert and a driver fix, multiplied by every provider.
- **A preview server per provider** ("Keeping providers from mixing") is created on first use and never removed. It is billed on every provider ever used.
- **Self-hosters see every provider.** Each needs connect docs and key guidance in the public edition.
- **Custom's SSH step is the SSRF** in security-findings (`SshAdoption.php:74-82`). The preflight checks the target, but never that the target is not the management server.
- **Incus port 8443 is open "until settled"** (Incus limits): a firewall made by hand, waiting to happen. Close it to the management server's IP in the host recipe from the start.

### Cut or defer

1. **Freeze providers.** `Providers/Vultr` and `Providers/Linode` were built even though the scope check says deferred work "waits for a customer", and the Status line omits them.
   - **For cutting:** each is a catalog, a contract test and daily calls to maintain, with no one asking.
   - **Against:** they exist (about 210 lines each) and prove the abstraction.
   - **Call:** keep them. Add a rule to the spec: no new provider without a customer asking.
2. **Delete the old DigitalOcean autoscale pools.** That is `AutoscalePools` and `BaseImages` (601 lines), the per-minute `sites:reconcile-pools`, the monthly `images:refresh`, and the pool path in `EnrollmentService` (a second way node certificates get issued).
   - **For cutting:** two scalers is pure duplication, the snapshots are a hidden monthly cost per region, and the new scaler was already chosen.
   - **Against:** any live pools must be migrated first.
   - **Call:** delete now if no production app uses a native pool (Still open #8).
3. **Phase 9b, native databases ("M each").**
   - **For cutting:** self-run database servers already cover every provider that makes servers.
   - **Against:** managed databases bring backups and failover.
   - **Call:** defer until a customer asks.
4. **Phases 8, 11 and 12** (AWS managed, Railway decision, native pools).
   - **For cutting:** they are deferred but sit in the phase table looking like plans.
   - **Against:** they record past thinking.
   - **Call:** move them to a "Not doing" list, one line each.
5. **The Ubicloud section** (about 70 lines, parked).
   - **For cutting:** the notes go stale while parked.
   - **Against:** it is real research.
   - **Call:** move it to a short note in `docs/`, and keep one line in the spec.

### Smaller version

Freeze providers, delete the old pools, then do **phase 10, artifact transport**. It is the one remaining phase that unlocks multi-server for every small provider at once.

- **Pull builds from the primary over the private network, with a short-lived token.**
  - For: no bucket, and it works on Incus and Custom.
  - Against: the primary becomes a dependency for joins.
- **Have the control plane serve builds.**
  - Against: bandwidth and disk on a server that is already a single point of failure.
- **Lean:** pull from the primary.

Then cut the spec to rules, data model and the phase table, and move the "as built" detail to [incus.md](incus.md) and the code.

### Breaks a decision

- **"Abstract on the third use."** The spec states the rule (line 173), then plans `DatabaseDriver` in 9b before a second native database exists.
- **The Unharbored rename.** The spec says "managed by cloud-manager" (line 83), and users still see it in `Web.vue:40`. `Shapes.php` already says Unharbored.

### Costs

| Item | Who pays | Roughly | Cheaper option |
|---|---|---|---|
| A preview server per provider | Customer | $5–6/month each, forever | Previews on the app's own server, as Custom does, by default |
| Base image snapshots | Customer | Per GB per region, monthly, rebuilt monthly | Gone with cut #2 |
| AWS public IPv4 | Customer | About $3.60/month per server, missing from the estimate | Add it to the catalog price |
| Incus | — | $0 extra on a host already paid for | Worth saying on the site |

### Versus Forge

- **Level:** Forge covers DigitalOcean, AWS, Hetzner, Vultr, Linode and custom VPS. Adding more providers wins no one over.
- **Better:** the outbound agent with no root keys held. Incus: many cheap servers on one bare-metal box, each with real IPv6 and an SNI ingress. One autoscaler across providers.
- **Worse:** the wizard has more concepts (shapes, native / local / yours / self-run, three responsibility labels). Forge asks for a provider, size and region.

### A new user trips here

| Now | Better |
|---|---|
| "Shape" | "How many servers?" One / Several behind a balancer / Grows with traffic |
| "Native", "Self-run", "Yours" | Only the responsibility label: "Run by DigitalOcean" / "Run by Unharbored on your server" / "You set it up (in .env)" |
| "Autoscaling needs object storage for builds. Hetzner does not offer it." | "…Pick DigitalOcean or AWS to scale, or start with one server and add more later." |
| "Custom" in the provider select | "Your own server" (as `Shapes.php:31` already says) |

### Not proven yet

- Incus host as a managed resource: built 2026-11-22, not proven live.
- Self-run database servers: built 2026-11-23, with no backups of their own.
- The Status line and phase table lag the code: Vultr, Linode and 7b are built but not marked.
- Whether any production app uses a native DigitalOcean pool. That decides cut #2.

### Done from this round

Nothing yet.
