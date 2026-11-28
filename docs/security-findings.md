# Security findings

Adversarial reviews of the whole system, checked against the code rather than the docs. Each finding names where it lives and what to do. [specs/security-model.md](specs/security-model.md) is the contract; this page is where it and the code disagree, and what is missing from both.

## Round 1, 2026-11-25: whole architecture

An "old-school ISP operator" review (the `todd-review` skill): attack surface, credentials, blast radius, supply chain, operations. Three code passes covered the control plane, the Go agent and gateway, and the server stack. Nothing was changed. Not verified on a live server unless stated.

**What holds up.** The agent dials out over mutual TLS 1.3 with a pinned CA. It is a static Go binary with zero third-party modules (`go.mod` has no `require`). The platform holds no SSH keys. Enrollment tokens and install secrets are single use, hashed, short lived and throttled. Vault keys are per organisation, with AEAD binding. Every sampled controller scopes queries by organisation, and no IDOR was found. The Docker apt key is fingerprint-pinned. Builds run as `deploy`, not root.

### Open: gets you owned, worst first

1. **Nodes are not default-deny.**
   - The node firewall is off by default (`Firewall.php:15`).
   - Cloud-init leaves sshd at Ubuntu's defaults.
   - Ordinary Droplets are created without `ssh_keys` (`DigitalOceanServers.php:31-34`). Inferred, not verified on a live box: DigitalOcean then emails a root password and password SSH stays on.
   - SSH hardening is an opt-in toggle (`ServerStack.php:352-368`).
   - The security model's "no inbound port on the node" is true of the agent, not the machine.
   - Fix: make ufw default-deny on by default, with IPv6 set explicitly. Disable password auth and root login in cloud-init. Consider a DigitalOcean Cloud Firewall attached at create time.
2. **Console sign-in is password-only.** TOTP is switched off (`LoginController.php:23`). The throttle is 5/min per `email|ip`, so rotating IPs gets around it, and there is no account lockout. The console reaches root on every node, every provider token and every site `.env`. Fix: bring TOTP back, mandatory, before a second user exists.
3. **Gateway events cross the public internet.**
   - The gateway posts to `https://<agent host>/api/gateway/events` (`cm-gateway.service:7`), and `nginx-agent.conf:14` passes every `/api/` request to PHP. The console vhost exposes it too.
   - The controller trusts `organization_id` from the body (`GatewayEventController.php:73`).
   - The shared token is therefore the only barrier to forged node events for any tenant.
   - Fix: post to 127.0.0.1 (same box), and deny `/api/gateway/` from non-loopback in both vhosts.
4. **Unverified downloads installed as root.**
   - The Sury, PGDG and Tailscale apt keys are curled with no fingerprint check (`ServerStack.php:148-150`, `:192-202`; `Tailscale.php:88-90`).
   - `composer.phar` is latest-2.x with no checksum, on nodes (`ServerStack.php:215-222`) and on the management server (`setup.sh:68-71`).
   - `curl https://mise.run | sh` runs as `deploy` (`Runtimes.php:220`).
   - The PECL redis tarball is fetched unchecked (`build-php.sh:105-108`).
   - Fix: follow the Docker pattern (`Docker.php:56-83`) for every key, and pin Composer's checksum or signature.
5. **The agent binary is not signed.**
   - Upgrades and cloud-init check a sha256 that the control plane computes from the file it serves (`AgentRelease.php:86-94`, `AgentUpgrade.php:42`). That catches transit corruption, not a malicious binary.
   - `handlers.go:39-41` claims a signed binary, and no code checks one.
   - BYO installs fetch the script with `curl | sudo bash` over WebPKI.
   - Fix: sign releases with an offline ed25519 key (Go standard library), embed the public key in the agent, and verify before replacing it.
6. **One shared `deploy` user per server.**
   - Every site lives under `/home/deploy/<site>` (`Sites.php:2124-2126`).
   - `npm install` runs when there is no lockfile, without `--ignore-scripts` (`Runtimes.php:43`). `composer install` runs package scripts (`Deployer.php:1096-1100`).
   - One site's dependency can read every other site's `.env` on that server.
   - Fix: a user per site (security-model.md already lists this as a gap), plus `--ignore-scripts` unless the site opts in.

### Open: bites you at 3am

1. **Certificates expire at 30 days and nothing renews them.** Verified in code.
   - Node certificates: `pki.NeedsRenewal` has no callers, and certificates are issued only at enrollment (`EnrollmentService.php:167`, `:247`). At expiry the agent retries forever (`agent.go:222-246`) and never re-enrolls, so the only way back is a reinstall with `CM_REENROLL=1`.
   - The gateway's server certificate is also 30 days (`CertificateAuthority.php:227`). Only `setup.sh:159` issues it, and the agent vhost serves the same certificate. When it lapses, every agent disconnects at once.
   - Fix: build renewal for both. Until then, alert at day 20 and know the date for production.
2. **Nothing watches the management server.**
   - `alerts:check` runs on the box it would report on.
   - `setup.sh` sets up no host firewall; the only firewall is `cm-mgmt-fw`, a cloud firewall made by hand.
   - No SSH hardening, no fail2ban, and root logins for deploys.
   - Fix: an external heartbeat on `/up`, from another machine. Add ufw (or script the cloud firewall) and SSH hardening to `setup.sh`.
3. **One break-in can wipe the backups.**
   - The off-box copy exists only if `backup:offsite` was run once by hand.
   - The bucket key is in the same vault and can delete objects (`Spaces.php:194`, `:251`).
   - Snapshots are gzip, not encrypted as a whole.
   - `management-server.md` says both "Spaces bucket" and "only on the server's own disk".
   - Restore was tested once, in a container where systemd could not start services.
   - Fix: a write-only key or object lock, a restore drill on a real VM, and one consistent doc.
4. **The audit chain is checked only when someone opens the audit page** (`AuditController.php:79`). Fix: verify it in `alerts:check`, and ship the chain head off-box.
5. **The gateway agent listener has no connection caps.** It runs one goroutine per connection, with a 30s handshake window (`server.go:150-168`, `:181-183`), so unauthenticated clients can exhaust file descriptors. There is also no cap on commands in flight per session. Fix: cap connections in total and per IP, and cap concurrent commands.
6. **Security updates are not enforced.** `unattended-upgrades` is never configured or checked, on nodes or on the management server, and packages are unpinned.

### Open: tighten

- **The organisation CA is in the management server's system trust store** (`setup.sh:173`), with no name constraints. Its key could impersonate GitHub or the DigitalOcean API to that box. Give the CA file to the gateway only.
- **SSH adopt accepts loopback and private hosts** (`SshAdoption.php:74-82`). That is SSRF from the management server for anyone with the infrastructure role.
- **Webhooks have no replay protection.** GitHub deliveries are not de-duplicated on `X-GitHub-Delivery`, and the generic git hook accepts the URL secret alone (`GitPush.php:24-33`).
- **The idempotency cache can run a command twice.** It is in memory, holds 256 entries, records success only, and records it only on completion (`handlers.go:90-91`, `:413-449`). A duplicate arriving mid-run executes twice, and the cache does not survive an agent restart. The "outcome unknown" handling relies on it.
- **`SESSION_SECURE_COOKIE` is never set** (`config/session.php:172`). Only HSTS covers for it.
- **Gateway `-api-addr` accepts any address** (`cmd/gateway/main.go:45`). Refuse anything that isn't loopback.
- **Certbot runs `--register-unsafely-without-email`**, so Let's Encrypt has nobody to warn.
- **Stale docs.**
  - `architecture.md` describes Blade, Unscripted and TOTP. The console is Inertia + Vue.
  - security-model.md's "Not yet implemented" list is wrong in both directions. Enrollment rate limiting, revocation (a serial denylist, in memory at the gateway) and agent upgrades exist. Renewal does not.

### Not must-fix: the reviewer's preferences

Recorded so they are not lost, not because they are planned.

- **FreeBSD on the management server.** pf default-block with per-source limits on 9443, and the control plane and gateway in separate jails. Cost: a second OS to support.
- **A jail-like boundary per site** on nodes. On Ubuntu that means a user per site plus systemd sandboxing (see owned #6).
- **Own apt mirror with pinned keys, or keep building PHP from source** instead of trusting Sury.
- **Fewer npm packages.** The console has 165 locked packages, built on the operator's machine.
- **A tiny external watchdog** (cron and a script on another box) for the 3am #2 heartbeat.

### Open questions

- Has any production node stayed connected past 30 days? If so, how?
- When does a second console user arrive?
- Abuse handling: port 25 outbound is open on nodes, and there is no rDNS handling. Who receives complaints, and what is the response?
- Where is the off-box copy of `.env` (`VAULT_MASTER_KEY`) kept?
- What can an attacker reach after a takeover of the operator's own DigitalOcean account?

For a small ISP-run provider: out-of-band access when the control channel is down, rDNS and port 25 policy, a firewall API or console access, connection limits for long-lived TLS listeners, and how binaries pushed to machines the provider doesn't own would be signed.
