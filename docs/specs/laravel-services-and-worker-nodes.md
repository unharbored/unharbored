# Laravel services and worker nodes

Status: **A is built** (branch `laravel-service-presets`, not live-tested); **A and B (all three steps) are built** (branches `laravel-service-presets` and `worker-nodes`; not live-tested): roles in the code, adding an existing server as a worker, a Runs on choice per process and for the built-in workers, and making a new server for the application (`site_server_create`: one operation, a provision child then a server_add child). What is left is the live test, and syncing to the public repository. Two pieces, in this order:

- **A. Laravel service presets.** Horizon, Pulse, Nightwatch, Reverb, named queue workers, added from a list instead of typed as commands.
- **B. Worker nodes.** A server that belongs to an application but serves no web traffic, and runs only the processes assigned to it.

## What exists

Application, Settings, **Workers & processes** (`Components/SiteSettings/Processes.vue`, `SiteProcessesController`, `Sites`, `Deployer::programs()`):

- **Queue workers**: `sites.queue_workers`, or `queue_workers_min/max` with `WorkerScaling` (scale on queue depth, to zero). One program, `cm-<site>-worker`, `queue:work --sleep=3 --tries=3 --max-time=3600`, restarted by `queue:restart`, not by supervisor.
- **Reverb**: `sites.reverb`. One program on loopback, the `REVERB_*` variables injected, nginx proxies `/app` and `/apps`.
- **Background processes** (`sites.daemons` JSON: name, command, processes, optional port and public path). Restarted by supervisor onto each new release. `worker` and `reverb` are reserved names.
- **Scheduler**: one cron line, on the primary only.
- **Servers** (`Components/SiteSettings/Servers.vue`, `SiteServersController`, `server_add` operation): an application can run on several servers (`site_nodes`). Every deploy builds on all of them and switches all of them. Every server gets the vhost, the web processes and the same `programs()`.

What is missing:

1. Every server runs every process. There is no way to say "this server runs only workers".
2. Presets: the user has to know each package's command, variables and quirks.
3. Nothing is per server. A Nightwatch agent has to run next to the app on every server that runs it, so that needs a fan-out rule.

Not in scope: containers (`ContainerApps::refuse` already rules out workers and processes), Octane, running the same process on a schedule, and a separate "services" product. Prices and sizes follow the provider catalog already used by "Add a server".

---

## A. Laravel service presets

**As built (differences from the plan below):** Reverb is not in the picker (it keeps its own switch). A daemon gained three optional keys: `stopwait`, `reload` (an artisan command run once after the switch, in place of a supervisor restart, so a deploy never waits on a running job) and `preset`. Nightwatch's agent command, `NIGHTWATCH_TOKEN` and its default `127.0.0.1:2407` address were checked against its documentation. The agent gets the next free loopback port when another application on the server has 2407, and `NIGHTWATCH_INGEST_URI` is set to match. Not built: offering to turn the built-in workers back on when Horizon is removed.

### What the user sees

Workers & processes gets an **Add a Laravel service** button above "+ Add process". A drawer lists:

| Preset | Runs | Needs | Notes |
|---|---|---|---|
| **Queue worker (named queues)** | `queue:work --queue=<list> --sleep=3 --tries=<n> --max-time=3600` | queues, tries, processes | Additional program beside the default worker, e.g. `emails,default` with 3 processes. |
| **Horizon** | `horizon` | Redis queue connection; `laravel/horizon` installed | Replaces the built-in worker (offers to set it to 0). Restarted on deploy, which is how `horizon:terminate` is satisfied. |
| **Pulse** | `pulse:check` | `laravel/pulse` installed | A single process. |
| **Nightwatch** | `nightwatch:agent` | `laravel/nightwatch`; **token** (secret) | Runs on every server that runs the application (see fan-out). Optional sampling rates. |
| **Reverb** | what exists | | The existing toggle, listed here too so the picker is complete; it opens the existing setting instead of a second path. |
| **Scheduler worker** | not offered | | The console already runs the scheduler by cron. Say so in the drawer rather than adding a second way. |

Each preset shows the exact command it will run and the variables it will set, and "Add" is one click. After adding, it appears in the existing Processes list like any other, and can be edited or removed there. A preset is a starting point, not a new kind of object.

If the package is not in the application, the process crash-loops; the existing process list and logs show it. The drawer says which package is needed, and a later improvement can read `composer.lock` on the primary (see open questions).

### How it is built

Smallest change that carries it, in line with "fewest new moving parts":

- `App\Lib\Sites\LaravelServices`: one class with the preset list as data (key, label, help, command template, fields, variables, default processes, `needs` such as `redis` or `package`). No new table.
- `POST .../processes/preset` in `SiteProcessesController`. Validates the preset's fields, then calls the existing `Sites::setDaemon()` and, for variables, the existing `Sites::setEnvValues()`. One audit entry, `site.preset_added`, with the preset key and no secret.
- Secrets (the Nightwatch token) go through `setEnvValues`, which already files values in the vault; they are never stored in `daemons` or the audit log.
- The queue-worker preset needs more than one worker program, so `Deployer::programs()` is unchanged: each named queue is a daemon (`queue-emails`), which gets supervisor restart on deploy. That is correct for `queue:work` too because a restart finishes by `--max-time` and supervisor `stopwaitsecs` (below).
- Reserved names stay reserved. Preset names are suggestions (`horizon`, `pulse`, `nightwatch`, `queue-emails`) and are editable.

Two behaviours need deliberate care:

1. **`stopwaitsecs` for queue-style presets.** Daemons currently get 60. A named queue worker has to be allowed to finish a job, so a daemon gets an optional `stopwait` (default 60; 3600 for queue worker and Horizon). Small change in `setDaemon` and `programs()`.
2. **Horizon versus the built-in worker.** Running both is almost never intended. Adding Horizon asks "Stop the built-in queue worker?" and sets `queue_workers` to 0 if yes. Removing Horizon offers the reverse.

### Tests

- `LaravelServicesTest`: each preset validates its fields; adds the expected daemon and variables; the secret is not in the daemon JSON, the audit record or the page props; reserved names refused; Horizon offers to zero the built-in worker; a container application is refused.
- Extend `SiteProcessesTest`: the page lists the presets; a preset renders in the process list afterwards.
- `Deployer` programs test: `stopwait` flows into the supervisor conf for a queue-style daemon.

### Docs and verification

- A short section in the application settings docs, and the help text in the drawer.
- Live check on a throwaway server with a small Laravel app that has `laravel/horizon` and a Redis service: add Horizon, deploy, `supervisorctl status`, push a job, see it run, deploy again and see Horizon restart and the job complete.
- Nightwatch cannot be fully run live without a Nightwatch account and token. Verify the program starts, listens on loopback, and the variables are present. State that the data path to Nightwatch itself was not exercised.

### Cost

Roughly a day: one class, one controller method, one drawer, `stopwait`, and tests. No migration.

---

## B. Worker nodes

### What the user sees

Application, Settings, **Servers**, which today lists servers and offers "Add a server". It gains:

- **Add worker server** beside it: choose an existing spare server, or make a new one (provider, region, size), the same choices as a new service server. The server is labelled **Worker** in the list; web servers are labelled **Web**.
- **Processes: where each one runs.** In Workers & processes, every process (the built-in queue worker, Reverb, each background process) has a **Runs on** choice: *Web servers*, *Worker servers*, or *All*. Defaults keep today's behaviour: everything on all servers, Reverb on web servers.
- A worker server in the list shows its processes and their state, the release it is on, and **Remove from application**, as other servers do.

For a single-server application nothing changes until a worker server is added.

### Rules (decided)

- A worker server never gets the application's vhost, certificate, or a place in the load balancer. It gets the release, the environment, the build, and the programs assigned to it.
- The **primary stays a web server.** The primary holds migrations, the scheduler and certificate work. Making it worker-only is not allowed, as is removing the last web server.
- **The scheduler stays on the primary.** A "scheduler on worker" option is deferred.
- **Migrations still run once, on the primary**, before any server switches (unchanged).
- **A shared database, cache and storage are required**, for the same reason the autoscaler refuses SQLite or a file cache: a second server cannot see the first one's disk. Adding a worker server is refused with the reason, using the check the autoscaler already has (`ServerScaler`), or one extracted from it.
- **One provider per application** (providers spec): a new worker server uses the application's provider. A Custom (SSH) provider adopts an existing machine; it does not offer "make a new one".
- Autoscaling pools (`autoscale_pool_id`) are web-only in the first version. A worker server can be added to a pooled application but is not scaled.
- Worker count scaling (`WorkerScaling`) applies to the worker program wherever it runs; it already counts per node.

### Data

One migration: `site_nodes.role` string, `'web'` (default) or `'worker'`. The primary is `sites.node_id` and is always web. Processes gain `runs_on` in the daemon JSON (`web`, `worker`, `all`; default `all`), and the built-in worker and Reverb get `sites.workers_on` and Reverb is implied web. Using one JSON key per daemon and one column for the built-in worker avoids a new table.

### Code touch points

The existing code asks "which servers does this site run on?" through `Sites::nodes($site)` in about twenty places. Splitting that question is the real work of B:

- `Sites::nodes()` stays "every server" (builds, releases, logs, backups).
- New `Sites::webNodes()` and `Sites::workerNodes()` (reading `site_nodes.role`).
- **Web only** (switch to `webNodes`): vhost, certificates (`Deployer` ~649, 748, 833, 1271 to check), `LoadBalancers` (lines 109, 228), `SettingsPage` multi-server and domain panels, `Consoles`, the Servers tab "Web" list, balancer and TLS checks in `SiteController`.
- **Every server** (stay `nodes`): build and release unpack, `Deployer::deploy` fan-out, env distribution, health of programs, logs, `SiteBackups`.
- `Deployer::programs($site, $node)` takes the server and returns only the programs whose `runs_on` includes that server's role; `deploy` calls it per node. The scheduler step already takes `$primary`.
- `Deployer`'s per-node recipe skips the nginx steps for a worker, and `supervisor` steps run for every node.
- `server_add` operation: takes `role`, admits the server to the database cluster as today, inserts the `site_nodes` row with the role, deploys, and **skips `LoadBalancers::apply` for a worker**. The existing "a server that cannot be set up must not stay on the site" rollback applies.
- New server for a worker: same one-operation shape as the Service work (`service_create`): one operation makes the server (`queueProvision`), then runs `server_add` on it as a child (`meta['children']`), so a failure leaves a clear record.
- `SiteServersController`: `role` input; refuse making the primary a worker or removing the last web server.
- **Nightwatch fan-out** (A depends on B here): a preset with `runs_on = all` already runs on every server in B, including workers, which is correct because the app on a worker also reports to a local agent. Reverb defaults to web only.
- Console and logs: log shipping already follows `Sites::nodes`, so worker logs appear in the application's Logs with the server name.

### Failure and safety cases to handle

- Worker server unreachable at deploy: today a failed node fails the deploy. For a worker the deploy still fails (the release is inconsistent otherwise), with the server named; this matches web behaviour and is the simple, honest choice. A later option is "continue without this server".
- Removing a worker server: stops its programs, takes the site off it (`removalRecipe`), does not destroy the server (it is the user's); offers "destroy this server too" when the console made it.
- Switching a processes' `runs_on` to `worker` when no worker server exists: allowed, shown as "no server runs this yet". Not an error, and not silent.
- Changing a server's role after the fact: not offered in v1 (remove and re-add).

### Tests

- Unit: `webNodes`/`workerNodes` split; `programs()` per role for each `runs_on` value; scheduler never on a worker.
- Feature: add a worker server (existing and new), deploy skips the vhost and the balancer for it, the primary cannot become a worker, the last web server cannot be removed, a SQLite or file-cache application is refused with the reason, a container application is refused, a Custom provider offers only existing servers.
- Operation tests for `server_add` with `role=worker`, including the rollback on a failed setup, and for the new-server variant (`service_create` pattern).
- Regression: every existing test that passes `Sites::nodes` keeps passing with all-web nodes.

### Verification

Live, on a throwaway provider account (a Hetzner or DigitalOcean server pair, deleted afterwards): a Laravel app with Redis and a managed or self-run database; add a worker server; deploy; check the worker has no nginx vhost, has `cm-<site>-worker` running, the web server has no worker program (with `runs_on = worker`), a queued job runs on the worker, a second deploy moves both, rollback moves both, removing the worker stops its programs. A throwaway test would cost a few cents and needs the user's go-ahead, and none of this has been run.

### Cost

About three to four days: the migration, the node split across roughly twenty call sites, per-node programs, the operation and its UI, and the test pass. The risk is in the call sites, so the first step is a read-through that sorts each `Sites::nodes` use into web, worker or both, with a test per group.

---

## Order and checkpoints

1. **A**, in one change. Ship, deploy, try Horizon on a real app.
2. **B, step 1**: migration, `webNodes`/`workerNodes`, per-node `programs`, with every existing behaviour unchanged (all nodes are `web`). Tests green before anything is visible.
3. **B, step 2**: add and remove a worker server from the console (existing server only), `runs_on` per process.
4. **B, step 3**: "make a new server for it" (provider, region, size), the `service_create`-style child operation.
5. Live test of B on a throwaway account; docs; sync to the public repository.

Each step is independently shippable. Stopping after A, or after B step 2, leaves a working product.

## Open questions

- **Detect installed packages?** Reading `composer.lock` from the primary after a deploy would let the drawer say "Horizon is installed" and warn when it is not. It needs a small agent read or an artisan call, and is nicer rather than necessary. Proposed: not in v1.
- **Nightwatch:** checked against its docs for A (agent command, token variable, default address). Sampling-rate variables are left for the user to add in Variables.
- **Scheduler on a worker.** Some teams want the scheduler off the web server. Deferred; the primary-only rule is the safe one.
- **Pooled (autoscaled) applications and worker servers.** v1 allows adding a worker server but does not scale workers by server; `WorkerScaling` still scales processes within the existing servers.
