# API, CLI and MCP

Everything below uses one kind of credential: a token made on the console's
**API tokens** page. It is shown once; only its SHA-256 is stored. Each is
scoped, each scope including the one before:

| Scope | Can |
|---|---|
| `read` | sites, services, servers, operations and their logs, site logs, backups, `.env` key names |
| `deploy` | read, plus deploy, roll back, back up sites, start, stop and restart services |
| `manage` | deploy, plus `.env` contents and changes, artisan, restores, and backing up and updating services |

Projects group sites; they do not restrict a token. A token reaches every project of its team, and `?project=` is a filter, not a boundary (a site outside it is still found by name). Projects are created, renamed, moved and deleted in the console only.

Everything a token does is in the audit log under the token's name, and
operations it starts show `api` as their trigger.

## The cm command line

Build it once (Go 1.22+), anywhere:

```bash
go build -o ~/bin/cm ./cmd/cloud
cloud login https://cp.example.com      # paste the token
cloud sites
cloud projects                            # slugs and site counts
cloud sites --project client-a
cloud services [--project client-a]       # template services (object storage and the like)
cloud service files                        # one service; never shows credentials
cloud service files restart                # also start, stop; follows the log (--no-wait to return)
cloud deploy demo                          # follows the log; exits 1 if it fails
cloud rollback demo
cloud logs demo -q SQLSTATE --minutes 1440
cloud env demo                             # key names; --reveal for contents (manage)
cloud env:set demo APP_DEBUG=false         # applies on the next deploy
cloud artisan demo migrate:status          # manage
cloud backups demo && cloud restore demo sites/1-demo/…-nightly.pgdump
cloud preview demo feature/new-checkout    # waits, then prints the URL
cloud preview demo fix/typo --database shared   # on demo's own database: no migrations run
cloud previews demo && cloud preview:delete demo-feature-new-checkout
```

`CM_URL` and `CM_TOKEN` override the saved login, for CI.

## MCP, for coding agents

`cloud mcp` is an MCP server on stdio with the same commands as tools:
`list_projects`, `list_sites` (optional `project` slug), `list_services` (same filter), `get_site`, `list_servers`, `deploy_site`, `rollback_site`,
`get_operation`, `search_logs`, `get_env_keys`, `set_env`, `run_artisan`,
`list_backups`, `back_up_site`, `restore_backup`. Deploys, rollbacks, backups
and restores wait for their result and return the end of the log. What an
agent can do is bounded by the token: give it a `deploy` token and it cannot
read secrets or run artisan, whatever it is asked.

In Claude Code:

```bash
claude mcp add cloud-manager -e CM_URL=https://cp.example.com -e CM_TOKEN=cm_… -- cloud mcp
```

## REST

`https://<console>/api/v1`, `Authorization: Bearer cm_…`, JSON both ways.
120 requests a minute per client.

| Method | Path | Scope | |
|---|---|---|---|
| GET | `/whoami` | read | the token's name and scopes |
| GET | `/projects` | read | the team's projects: id, name, slug, `applications` (production sites, not previews) |
| GET | `/sites[?project=<slug>]` | read | each site carries its `project` and its `runtime` (`php`, `node`, `go`, `static`, `dockerfile` or `image`); a `dockerfile` or `image` application also has `container` (`image`, `digest`, `dockerfile`, `container_port`, `memory`, `private_registry`; never a credential). An unknown slug is 404 |
| GET | `/services[?project=<slug>]` | read | template services: `id`, `name`, `template`, `version`, `server`, `project` (slug), `status`, `error`, `runtime` (`native` or `container`), `update` (null, or `version`, `allowed`, `confirm`, `changes` when the template has a newer version), `endpoints` (`domain`, `tls`; for a container also `listen`, always `127.0.0.1:<port>`, and `container_port`), `operation` (the one running, or null). Never credentials. An unknown slug is 404 |
| GET | `/services/{id or name}` | read | the same, plus `recent_operations` |
| POST | `/services/{id or name}/start`, `/stop`, `/restart` | deploy | 202 with the operation, like a deploy; 409 with the reason if another operation is running or the service is not running or stopped |
| GET | `/services/{id or name}/backups` | read | the service's backups, newest first: `id`, `name`, `kind` (`manual`, `scheduled`, `pre_update`, `pre_restore`), `bytes`, `where`, `created_at`. No bucket keys |
| POST | `/services/{id or name}/backup` | manage | a backup of the service's data now (works whatever its Backups setting is, even none); 202 with the operation, 409 with the reason if one is running |
| POST | `/services/{id or name}/update` | manage | updates a running service to its template's newer version: snapshot, fetch and verify, switch, health check, and the old version put back if it fails. Body (all optional): `confirm: true` (required when the download host or image repository changes; the response is 409 saying so otherwise), `inputs` (an object: values for inputs the new version adds), `approve_ports: true`, `acknowledge: true` (when the new version asks for extra firewall ports or an acknowledgement). 202 with the operation; 409 with the reason (nothing newer, a refused publisher, not running, a missing input, an approval not given) |
| GET | `/sites/{site}` | read | servers, pending changes, recent operations |
| POST | `/sites/{site}/deploy` | deploy | `{"commit": "…"}` optional; 202 with the operation |
| POST | `/sites/{site}/rollback` | deploy | for a `dockerfile` or `image` application it restarts the previous image (the one kept) on a new port and switches nginx back |
| GET | `/operations/{id}?after=N` | read | status, stages, error, log from byte N, and `next` |
| GET | `/sites/{site}/logs?q=&minutes=&limit=` | read | redacted lines, oldest first |
| GET | `/sites/{site}/env[?reveal=1]` | read / manage to reveal | reveal is audited |
| PATCH | `/sites/{site}/env` | manage | `{"values": {"KEY": "value", "OLD": null}}` |
| POST | `/sites/{site}/artisan` | manage | `{"args": ["migrate:status"]}`; not commands that never return |
| GET | `/sites/{site}/backups` | read | |
| POST | `/sites/{site}/backups` | deploy | back up now |
| POST | `/sites/{site}/restore` | manage | `{"backup": "sites/…"}` |
| GET | `/servers` | read | connected, provider, agent, latest CPU / memory / disk |
| GET | `/providers` | read | each connected provider: what it supplies, regions, sizes with approximate monthly prices, and each shape (`single`, `multi`, `autoscale`) as `available` with a `reason` when locked |
| GET | `/sites/{site}/previews` | read | the site's previews |
| POST | `/sites/{site}/previews` | deploy | `{"branch": "…", "follow": false, "idle_days": 7, "database": "clone"}`; `idle_days: null` keeps it; `database` is `fresh`, `clone`, `shared` or `sqlite`, the site's setting if left out |
| DELETE | `/sites/{site}` | deploy for a preview, manage for an application | an application's deletion stops it everywhere; `?data=1` also erases its database, uploaded files and stored secrets. Servers and shared clusters are never destroyed by it |
| POST | `/sites` | manage | makes an application from the wizard's answers: `{"name", "repository": "owner/repo", "branch", "runtime", "runtime_config", "server": {"mode": "new", "region", "size"} or {"mode": "existing", "node"}, ...}`. A refused answer is a 422 with `error` and the `field` at fault |
| DELETE | `/servers/{node}` | manage | destroys a server this platform created, once no application is served from it |

A site is its name or id. Starting something returns `202` with
`{"operation", "follow", "url"}`; poll `follow` with `after=next` to stream
the log.
