# Server access, databases and tuning

Status: proposed. Adds ROADMAP Phases 7f and 7g. Written 2026-11-10 from a
review of what a real application needs on a server and what the platform
does today.

## What was asked

Seven questions about the current build, each answered from the code, and a
decision on each:

| # | Question | Today | Decision |
|---|---|---|---|
| 1 | Is a user other than root created? | `deploy` runs the apps. Nobody can log in as it. The agent runs as root. | Create a **login user with sudo**, give the user its **sudo password**. The name is meaningful to the app and can change later. |
| 2 | Do we install a database on a single machine? | No. Single-server apps get SQLite. Only client tools are installed. | **Copy Forge**: a single-server app can install **Valkey or Redis, PostgreSQL or MySQL on the same VM**. |
| 3 | Do we inject database variables? | Yes, into the one environment blob. A later change by the platform overwrites the user's edit. | **Keep injecting, but let the user override** any value **without the platform forgetting it**, stored securely. |
| 4 | Can the user add an SSH key? | No. | The user can **install a key per server**, and the **organisation remembers its keys**. |
| 5 | Can we clone a database? | Only inside previews. | **Scope it** and put it on the roadmap. |
| 6 | How is the scheduler handled? | A fixed cron entry on the primary. No interface. | The user gets an **interface like Forge's or Laravel Cloud's**. |
| 7 | Can the user edit nginx or `php.ini`? | No. No upload size or timeout can be set. Defaults apply, so a file over about 1 MB gets a 413. | Editable **per application for nginx**, **per server for `php.ini`**. Plus an **area for a script that runs on servers when they are deployed**, mostly for autoscale. |

## Rules that apply to all of it

1. **Ours and theirs stay apart.** Everything the platform generates (the
   nginx vhost, the FPM pool, injected variables) is regenerated and
   overwritten. Everything the user writes lives in a separate slot the
   platform never rewrites: a snippet, an override, a script.
2. **Every change goes through the agent as a recipe** and shows as an
   operation with a log. No inbound control channel is added (ADR 0001).
3. **A config change is checked before it takes effect.** nginx runs
   `nginx -t` and PHP its own check; on failure the previous file is restored
   and the console shows the tool's error. A typo never takes a site down.
4. **Secrets live in the vault.** Revealing one is audited, as revealing the
   environment is today. A secret never appears in a recipe's arguments, an
   operation log or the audit log.
5. **A new server inherits.** A server that joins an application (autoscale)
   gets the application's nginx settings and a copy of the primary's PHP
   settings, then its setup script, before it takes traffic.
6. **Nothing here needs the provider.** It all works on Custom servers, so it
   fits the provider plan ([providers.md](providers.md)).

---

## 1. A login user, with a sudo password

**Today.** The stack creates `deploy`. It owns every site and runs PHP-FPM,
builds, git, supervisor and the scheduler cron. No key is installed for it and
no one is told a password. nginx runs as `www-data`. The agent runs as root.

**Design.**

- Two users with two jobs. **`deploy` stays the app user**: it runs the code
  and is not for people. A new **admin user is for people**: SSH key login,
  `sudo` with a password.
- The default name is `cloudmgr`, one constant (`ServerStack::ADMIN_USER`).
  Each server records the name it was built with (`nodes.admin_user`), so
  changing the default later never strands an existing server. A rename
  becomes an operation that creates the new user and moves its keys. That
  operation is deferred.
- **Sudo needs a password.** There is no `NOPASSWD`. The password is generated
  per server (24 random characters) and held in the vault.
- The server is given the **hash** of the password, not the password: the
  recipe sets it from a crypt hash, so the plaintext is never in a command, a
  log or an audit line.
- **The user gets it from the console.** The server card has "Reveal sudo
  password" (audited, like revealing the environment) and "Rotate". We do not
  show it once and lose it, because people close tabs.
- **Existing servers** get the user, the password and their keys the next time
  they run **Repair stack**, which is already safe to re-run. No separate
  migration.
- **Built (the first slice):** the user, its password and its keys, on every
  server, new or existing. Two safeguards come with it and need no opt-in,
  because they change nobody else's login: the user is confined to key-only
  SSH by a `Match User` rule in its own file (so the password can never be
  guessed over the network), and that file is checked with `sshd -t` and
  removed if sshd refuses it.
- **Not built yet: the server-wide hardening below.** It can lock an owner out,
  so it is its own slice with its own opt-in.
- **Hardening applies to servers we create, not to ones we adopt.**
  - New servers get `PasswordAuthentication no` and `PermitRootLogin no`, but
    only once at least one key is installed for the admin user.
  - They also get a host firewall: deny incoming except 22, 80 and 443. The
    agent dials out, so it needs nothing inbound.
  - Adopted and Custom servers keep their root login and firewall as the owner
    set them. Disabling root on a machine we did not build could lock the owner
    out, so it is an explicit opt-in per server.
- This is break-glass access for people. The control plane still never uses
  SSH to command a node. ADR 0001 gets a line saying so.

**Where it goes in the code.** `ServerStack` (the user, sudo, sshd and firewall
steps) and `CloudInit` (the user and keys at first boot). The `adopt` flow
installs them after the agent enrolls, so Custom servers get them too.

---

## 4. SSH keys: remembered by the organisation, installed per server

**Design.**

- **The organisation keeps a key library** (`ssh_keys`: name, public key,
  fingerprint, who added it, and a flag "install on new servers"). It lives on
  the Account page, beside the people list.
- **Adding a key validates it.** Accept `ssh-ed25519`, `ecdsa-sha2-*` and RSA of
  at least 3072 bits. Reject anything that looks like a private key, with a
  message saying so, so nobody pastes one by mistake.
- **Each server has its own installed set** (`server_ssh_keys`: server, key,
  state `installed`, `pending`, `removing` or `failed`). The server card shows
  the keys with a switch per key, the admin user name, and a ready-to-copy
  `ssh cloudmgr@<ip>`.
- **New servers** (any provider) offer the library with the flagged keys
  ticked. They are installed at first boot.
- **Installing writes the admin user's `authorized_keys`** between marker
  lines, one per key. A key the user added by hand is left alone, and the
  write is idempotent.
- **Deleting a key from the organisation removes it from every server.** A
  server that is offline stays "pending removal" and the console says so until
  it reconnects.
- **Public keys only.** We never hold a private key (ADR 0001).

---

## 3. Environment variables: injected and overridden

**Today.** One encrypted blob per site in the vault, with a version history.
When the platform attaches a database or cache it writes `DB_*`, `REDIS_*` and
the cache and session settings **into that same blob**. Edit `DB_HOST` by
hand, and a later re-attach or rotation overwrites it.

**Design: two layers, like Laravel Cloud's injected and custom variables.**

| Layer | Who writes it | Contents |
|---|---|---|
| **Injected** | The platform | Values derived from resources: `DB_*`, `REDIS_*`, cache, session and queue drivers, `REVERB_*`. Rewritten when the resource changes. |
| **Custom** | The user | Free-form `KEY=value`. Wins over injected. **The platform never writes it.** |

- The **effective environment** at deploy is injected overlaid by custom. A
  preview's own overrides (which exist today, with `{url}` and `{branch}`
  placeholders) sit above both.
- **The Variables page shows both**: the custom editor as now (Reveal and
  edit), and a read-only injected list saying where each value came from
  ("from database cluster `cm-main`") with a marker on any key a custom value
  overrides, and a count: "You are overriding 2 injected variables."
- **Overrides survive.** Detaching, re-attaching or rotating the resource
  rewrites the injected layer and leaves custom untouched. If an override's
  injected key no longer exists, the override stays and is flagged "overrides
  nothing now". If the platform's value changed while an override is in place,
  the page says "the platform value changed, your override is older". There is
  a "Remove override" action.
- **Their variables start empty.** `APP_KEY`, the name, address and logging made with an application are injected ("Made with the application"), never written into the user's own layer. `APP_KEY` is generated once and never rewritten; it goes in
  custom, since rotating it breaks sessions.
- **Storage is unchanged in kind.** Both layers are encrypted in the vault,
  both are versioned (the history records which layer changed), revealing is
  audited, and neither is written to an operation log.
- **Existing sites are split once.** A key the platform injects, whose value
  equals what it would write, becomes injected. A key it injects with a
  different value is the user's edit and becomes custom. Everything else is
  custom. The version history makes it reversible.

---

## 2. A database or cache on the app's own server

Forge's behaviour: on a single server, choose to install the services on the
same machine.

**Design.**

- **Single-server applications only.** Multi-server applications need a
  database every server shares, which means a managed cluster now and a
  self-run database server later (the providers plan).
- **At creation, the wizard offers:**
  - *Database:* SQLite, **PostgreSQL on this server**, **MySQL on this
    server**, a managed cluster, or **None**.
  - *Cache, sessions and queue:* the database, **Valkey on this server**, a
    managed Valkey, or **None**.
- **None means "not needed, or I'll handle it".** It is the "managed by you"
  way of supplying a service, and it is open on every provider and every shape.
  - cloud-manager installs nothing, injects no `DB_*` or `REDIS_*` variables,
    and takes no backups. If the application needs a service, the user sets
    its variables in their custom layer (item 3) and runs it themselves: an
    external database, a database they installed by hand, or none at all.
  - **It follows the rest of the choices.** A cache that *uses the database* is
    locked while the database is None ("Needs a database"). On a multi-server
    shape None is allowed, but the wizard warns that sessions and cache kept on
    each server are not shared, as it already does for uploads on disk.
  - The application's overview and Variables page show "Database: none, managed
    by you", so it is clear nothing is watching it.
- **On an existing server**, a "Services" section on its card has Install
  PostgreSQL, Install MySQL and Install Valkey, like Install PHP today.
- **Packaging.** PostgreSQL comes from the PGDG repository the stack already
  configures. Check before building: MySQL (Ubuntu 24.04 ships 8.0; decide
  whether to offer 8.4 LTS) and Valkey (confirm it is packaged for 24.04, or use
  Redis from Ubuntu).
- **Nothing is exposed.** Each service binds 127.0.0.1 and a socket. No port is
  opened in any firewall.
- **One database and one user per site**, with a random password. The
  administrative credentials stay in the vault.
- **The variables are injected** (layer above) as `DB_HOST=127.0.0.1` and so
  on, so a user's overrides work the same.
- **Backups.** The nightly and pre-migration dumps (`SiteBackups`) extend to
  the local engines. Offsite copies need object storage; without it the dump is
  on the same disk, and the console says so wherever backups appear.
- **Upgrades.** Patch updates through apt, as "Update PHP" does. Major version
  upgrades are manual and out of scope.
- **Memory.** On 1 GB servers the wizard warns before adding a database and
  Valkey next to PHP.
- **Removal is deliberate.** "Remove PostgreSQL from this server" is blocked
  while a site uses it, and asks the user to type the database names, because
  it deletes the data.
- **Data model.** `server_services` (server, service, version, state) and
  `sites.database_mode` (`sqlite`, `local:pgsql`, `local:mysql`, `cluster`),
  with a matching `cache_mode`.
- **Not in scope.** Replication, failover, and tuning files beyond a later
  `postgresql.conf` or `my.cnf` editor.

This is phase 4 of the providers plan (local services): it is the "Local" way
a service can be supplied, labelled "managed by cloud-manager".

---

## 5. Cloning a database

**Today.** A preview set to "clone" restores the parent's newest backup into its
own database. That is the only clone, and it needs a managed cluster.

**Scope.** A "Clone database" action on Database & backups.

- **Target:** a new, named database on the same cluster or server, with its own
  user, for staging, testing a migration, or a one-off. The result is shown as
  connection variables to paste, or injected into an application the user
  picks.
- **Source data:** a **fresh dump** (`pg_dump` or `mysqldump
  --single-transaction`, or SQLite's `.backup`), not the newest backup, which
  can be a day old. Schema only, or schema and data.
- **Guards:** the name is free; the cluster or disk has room for the copy
  (checked from the source's size); it is audited and runs as an operation with
  progress; nothing is written to the source.
- **SQLite on a server** is allowed here: the copy goes under the site's
  `shared/` folder. Previews still refuse SQLite.
- **v1 limits:** same cluster or server only. Cross-cluster copies and
  anonymising data come later.

It works on managed clusters now and on local databases once item 2 exists.

---

## 6. The scheduler

**Today.** A cron file, `/etc/cron.d/cm-<site>`, runs `artisan schedule:run`
every minute as `deploy` on the **primary server only**, and is removed from
every other server so jobs do not run once per server. The overview shows
"Scheduler: every minute". There is no way to turn it off, see what it runs,
or add to it.

**What the two products do** (from the screenshots):

- **Forge** gives manual control. A **Scheduled jobs** list on the server page,
  one row per job: name, `user · command`, frequency, a heartbeat indicator, a
  status badge (*Installed*), and a ⋯ menu: **Show output, Show next runs,
  Pause, Run, Edit, Copy ID, Delete**. A **+** opens a modal: Name, Command,
  User, Frequency, and **Monitor with heartbeats** ("generate a URL to ping
  after the job has run"). `schedule:run` is just the first row. Server
  maintenance jobs (`composer self-update`, `apt-get autoremove`) sit in the
  same list, as `root`.
- **Laravel Cloud** gives a **Scheduler switch** in the app's compute settings
  ("when enabled, the Laravel scheduler wakes your application to run
  scheduled tasks") and, switched on, **lists the scheduled tasks** the
  application defines. There is nothing to configure: the schedule lives in
  the app's code.

**Design: the Cloud switch on top, the Forge list underneath.** Most apps need
only the first. The second is for what the code does not schedule.

```
Scheduler                                        (Settings, after Workers)

┌ Laravel scheduler ────────────────────────────────────── [ on ] ┐
│ Runs `php artisan schedule:run` every minute on web-1.           │
│ Monitor with heartbeats                                  [ off ] │
│                                                                  │
│ Tasks in your code · from schedule:list · 2 min ago  [ Refresh ] │
│   inspire                    Hourly         next 14:00 UTC       │
│   reports:daily              Daily at 03:00 next 03:00 UTC       │
│ Change these in your application's code.                         │
└──────────────────────────────────────────────────────────────────┘

Custom jobs                                              [ + New job ]
┌──────────────────────────────────────────────────────────────────┐
│ Prune exports   deploy · php artisan exports:prune               │
│                 Daily at 02:30 · ♡ monitored · [Installed]    ⋯  │
│ Rebuild sitemap deploy · php artisan sitemap:build               │
│                 Weekly · [Paused]                              ⋯  │
└──────────────────────────────────────────────────────────────────┘
```

**The Laravel scheduler.**

- A switch, on by default. Off removes the cron line and the row says
  "Off: scheduled tasks will not run".
- It always runs on the **primary** only. Pool servers never run it.
- **"Tasks in your code"** is read-only: the output of
  `php artisan schedule:list` (`--json` where the Laravel version supports it,
  the plain text otherwise), run on the primary through the agent, cached, with
  its age and a Refresh. It shows when each task is next due. It answers "is my
  schedule actually registered?" without logging in.
- The overview's "Scheduler: every minute" line links here and shows Paused or
  Off when that is the case.

**Custom jobs** follow Forge's row and modal.

- **Fields:** Name; Command; User; Frequency; Where; Monitor with heartbeats.
  - The command runs from the application's current release, so `php artisan
    exports:prune` works as written. `{php}` stands for the application's PHP
    binary (`php8.4`), so a PHP version change does not break the job.
  - **User** is `deploy` in v1. Forge lets you choose `root`; here that waits
    for server-level jobs, below.
  - **Frequency** is a list: every minute, five minutes, fifteen, hourly,
    daily at a chosen time, weekly, monthly, or **Custom**, which shows a cron
    expression field and the **next five run times** as you type.
  - **Where** (shown only for multi-server applications): the primary only
    (default) or every server.
- **The ⋯ menu:** Show output, Show next runs, Pause or Resume, Run now, Edit,
  Delete. Not Forge's "Copy ID": nothing needs it yet.
  - *Show output* opens Forge's modal: the command, and the tail of the job's
    output, read from its log through the agent.
  - *Run now* runs the command once as an operation and shows the result.
  - *Pause* keeps the job and removes it from cron. The badge says Paused.
- **Badges:** Installed, Pending (being applied), Paused, Failed (with the
  reason on hover, as the server buttons do).
- **Heartbeats**, answering the earlier open question. The job gets a URL
  (`/heartbeat/<token>`), and its cron line pings it after a successful run. If
  no ping arrives within the frequency plus a grace period, the existing
  Alerts raise "job did not run". The token is the credential: stored as a
  hash, single purpose, throttled. The Laravel scheduler can be monitored the
  same way, **off by default**, since it costs one request a minute.
- **No overlap.** Each job is wrapped in `flock -n` so a run that outlasts its
  interval is not started again on top of itself.

**How it is applied.**

- One table, `scheduled_jobs` (name, command, user, frequency or cron,
  where, paused, heartbeat token and last ping, state, last error), with a
  `kind` of `laravel` or `custom`. The Laravel scheduler is a row of the same
  table, so pause, output and heartbeat share one code path.
- The table can belong to a site or, later, a server: a nullable `site_id` and
  `node_id`, so server-level jobs do not need a migration.
- **One file per site, `/etc/cron.d/cm-<site>`, rebuilt whole from the table**
  whenever a job changes, through the agent, with no deploy. Rebuilding whole
  keeps it idempotent: a repeat changes nothing. The file is validated
  (expressions parsed, no newlines in a command) before it is written, and
  deleting the application removes it.
- **Output** is appended to `shared/storage/logs/cron-<job>.log` (rotated) and
  appears on the Logs tab as `cron:<site>`.
- **Next-run times** are computed in PHP from the cron expression, using the
  expression library Laravel's own scheduler uses. Times are **UTC**, the
  servers' time zone, and the page says so.

**Later:** **server-level jobs** on the server card, using the same table and
rows, for what the screenshot shows Forge users doing: `composer self-update`,
`apt-get autoremove`, run as `root`. Better still, the stack could run those
itself as managed maintenance and not ask the user to schedule them. Run
history is also later; heartbeats and output cover the need until then.

**Slices.** 1: the switch, the task list and heartbeat for the Laravel
scheduler. 2: custom jobs. 3: server-level jobs.

---

## 7. Runtime configuration

### nginx, per application

- **Structured settings:** upload limit (`client_max_body_size`), request
  timeout (`fastcgi_read_timeout`). They fix the 1 MB upload problem.
- **A custom snippet:** free text placed inside the application's `server {}`
  block, through the `{$extra}` slot the vhost template already has. It is
  stored in the database (not secret) and **survives regeneration**, which
  happens on deploy, domain and HTTPS changes.
- **A read-only view** of the full generated config, so the user can see what
  the snippet sits in.
- **Applying:** write to a temporary file, run `nginx -t`, swap and reload; on
  failure restore the old file and show nginx's own error.
- **Pool servers** inherit the application's nginx settings.
- **Not offered:** replacing the whole file. The platform changes the vhost
  for certificates and domains, and a full override would fight it. Revisit if
  a real need appears.

### `php.ini`, per server

- **Per server and per PHP version**, since each version has its own FPM pool
  and its own settings on a server.
- **Structured fields:** `upload_max_filesize`, `post_max_size`,
  `memory_limit`, `max_execution_time`, plus FPM `pm.max_children` and the
  related pool values (today fixed at 10).
- **Free text:** extra directives, written to `99-cloud-manager.ini` for both
  FPM and CLI, so a PHP package update never overwrites it.
- **Applying:** write, check (`php-fpm<ver> -t`), reload gracefully so requests
  finish; on failure restore the old file.
- **Cross-check:** the page warns when an application's nginx upload limit is
  smaller than the server's `post_max_size`, or the reverse.
- **Pool servers** copy the primary's settings when they join.

### A script for new servers

For autoscale, and for anything a server needs that the stack does not install
(`ffmpeg`, `imagemagick`, a font, a system package).

- **Per application, a "Server setup script"** in Settings. It runs as root,
  through the agent, on each server that joins the application, **before the
  server takes traffic**.
- **A failing script stops the join.** A non-zero exit fails the operation, the
  new server is not added to the balancer or given database access, and the
  scaler removes it. A broken script cannot put a half-built server in
  rotation.
- **Actions:** "Run on all servers now" for the existing ones, and a choice of
  running on new servers only or on all.
- **Limits:** a timeout (15 minutes), output in the operation log, every
  version kept in a history, every change and run audited.
- **The page says it plainly:** it runs as root, should be safe to run twice,
  and secrets should not be written into it.
- **Not the same as deploy build commands.** Under ADR 0006, fixed servers
  build their own code and pool servers fetch a prebuilt artifact. This is
  about the machine, not the build.

**Join order on a new pool server:** stack, then the application's nginx
settings and the primary's PHP settings, then the setup script, then the build
artifact, and only then the balancer and the database firewall.

---

## How it fits the roadmap

New phases in [ROADMAP.md](../../ROADMAP.md), both **planned**:

- **Phase 7f: Server access and tuning**: items 1, 4, 6 and 7.
- **Phase 7g: Databases on your server**: items 3, 2 and 5.

Console work (the tabs and settings sections of Phase 7b) is where these appear:
the scheduler and runtime settings are Settings sections, SSH keys are on the
Account page and server cards.

### Order

This order fixes bugs first, and puts changes to `ServerStack` and `CloudInit`
before the provider refactor that also touches them, so the two do not collide.

| Step | What | Roadmap | Providers plan | Size |
|---|---|---|---|---|
| A | Injected and custom variables (3) | 7g | none | S–M |
| B | Upload and timeout settings, `php.ini` per server (7: nginx, php.ini) | 7f | none | M |
| C | Login user, sudo password, SSH keys, hardening (1, 4) | 7f | adds to cloud-init and `adopt` | M |
| D | Scheduler interface (6) | 7f | none | M |
| E | Provider seams, shapes, Custom over SSH | 7c | phases 0 to 3 | L |
| F | Databases and Valkey on the server (2) | 7g | phase 4 | L |
| G | Clone a database (5) | 7g | none | S–M |
| H | Server setup script (7) | 7f | with phase 6, the scaler | M |

A is the correctness fix: today a platform write can overwrite a user's edit.
B fixes the 413 on uploads. F needs E, because local services are one of the
ways the provider plan supplies a service, and the shape rules decide where
they are offered.

## Open questions

1. **The admin user's name.** `cloudmgr` is the proposal. It is one constant,
   so it can change later.
2. **Sudo password delivery.** Reveal on demand, audited (recommended), or show
   once?
3. **Hardening on servers we create.** Disable root SSH and password login, and
   enable the firewall, by default? Adopted servers stay as they are.
4. **Host firewall.** `ufw` deny-incoming with 22, 80, 443 open, or leave the
   firewall to the provider's own?
5. **Versions.** MySQL 8.0 (Ubuntu) or 8.4 LTS, and how Valkey is packaged on
   24.04. Check before building.
6. **Whole-file nginx override.** Structured settings plus a snippet is the
   plan. Is a full-file editor needed?
7. **Scheduler slices.** Resolved by the screenshots: the switch, task list and
   heartbeat first, then custom jobs, then server-level jobs.
8. **Clone data source.** A fresh dump (recommended) or the newest backup?
9. **Setup script.** Runs as root, and a failure blocks the join. Agreed?
10. **Scheduler time zone.** Cron on the servers runs in UTC, and the page
    shows UTC. Should a job be able to choose a zone, or is the Laravel app's
    own zone, which the scheduler already honours, enough?
11. **Overlap protection.** Custom jobs are wrapped in `flock -n` by default.
    Is there a job where two runs at once is wanted?

## Scope check

Seven features is heavy, and the order above is the way to carry it: each step
is useful alone and can be shipped before the next starts. If it needs
trimming, defer in this order: renaming the admin user, the free-text `php.ini`
and nginx snippet (start with the structured fields), custom scheduler jobs
(start with the toggle), cross-cluster clone, and MySQL (start with PostgreSQL
and Valkey). The part not to defer is A and B, since one is a data-safety fix
and the other a bug.
