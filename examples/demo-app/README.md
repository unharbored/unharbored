# demo-app

A deliberately small Laravel application used as the Phase 1 deploy target.

Its job is not to do anything useful. Its job is to **make a half-broken
deploy visible**. Every part of the deploy pipeline that can silently fail has
something here that notices.

## What it exercises

| Endpoint | Answers |
|---|---|
| `GET /` | Full status. Which release is live, and whether each part of the deploy worked. |
| `GET /health` | Is PHP answering. Touches nothing else. |
| `GET /ready` | Should this node take traffic. May touch dependencies. |

`/health` deliberately does not touch the database. A health check that does
will fail during a database restart and pull an otherwise healthy node out of
the load balancer for a reason that has nothing to do with the node.

## The checks, and what their failure means

| Check | Fails when | What it tells you |
|---|---|---|
| `release` | Shows `not-deployed` or a stale id | The symlink was never repointed, so the deploy did not take effect |
| `database` | `ok: false` | Migrations did not run |
| `queue.worker_running_stale_release` | `true` | Workers were not restarted and are running the previous release |
| `queue.last_processed_at` | Stops advancing | Supervisor is misconfigured, or no worker is running |
| `storage.survived_a_deploy` | `false` after a second deploy | **Shared storage is not shared, and user uploads are being lost on every release** |
| `config.deploy_marker` | `null` | The shared `.env` is not linked or not readable |
| `scheduler.seconds_ago` | Large or missing | The cron entry was never installed, or is pinned to a node that no longer exists |

The storage one is worth singling out. Getting shared paths wrong is the most
expensive configuration mistake in the whole pipeline, because nothing errors:
the site works perfectly and quietly discards everything users upload. A
deploy marker that resets on every release is the cheapest way to catch it.

## The queue check is the subtle one

Every request dispatches a `RecordVisit` job that writes down **which release
the worker was running**, not which release served the request. If those
disagree, `queue:restart` did not happen and workers are executing old code
against a new database schema. That combination is how a deploy appears
successful and corrupts data anyway.

## Running it locally

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan test

php artisan serve
curl -s localhost:8000 | jq
```

To see the queue and scheduler checks go green, run the workers too:

```bash
php artisan queue:work      # in another terminal
php artisan schedule:work   # in another terminal
```

## Deploying it

This is the application the platform targets in Phase 1. The settings that
matter:

- **Shared paths:** `.env` and `storage`. Both are in
  `deploy.DefaultLaravelShared`, so the defaults are correct.
- **Migrations:** must run on deploy, exactly once, before the symlink swap.
- **Queue workers:** must be restarted after the swap, not before.
- **Scheduler:** every minute. On more than one node it must elect a single
  executor; the heartbeat task uses `withoutOverlapping` for that.
- **`DEPLOY_MARKER`:** set it in the shared `.env` to anything. Its presence
  on the status page proves the environment file is linked.

## What it deliberately is not

No authentication, no frontend build, no external services. Each of those adds
a failure mode that belongs to the application rather than to the deploy, and
the point of this app is to isolate the deploy.

Harder targets come later.
