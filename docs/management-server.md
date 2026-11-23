# The management server

One Droplet runs the platform: the control plane (Laravel), the session
gateway (Go), the queue worker that runs operations, and nginx for two names.
If it is lost, every site keeps serving, but nothing can be deployed or
changed until it is back. This page is how to get it back.

## What runs there

| Piece | Where | Notes |
|---|---|---|
| Control plane | `/srv/cloud-manager/control-plane` | Code owned by root, not writable by PHP |
| Database | `/var/lib/cloud-manager/control-plane.sqlite` | Outside the code, so deploys never touch it |
| Configuration | `control-plane/.env` | `VAULT_MASTER_KEY`, `APP_KEY`, `GATEWAY_TOKEN`, names |
| Gateway | `/usr/local/bin/gateway`, `cm-gateway.service` | Re-syncs organisations on every start |
| Queue worker | `cm-queue.service` | Runs deploys and every other operation |
| Agent name | `agent.example.com`, nginx `cloud-manager-agent` | Organisation CA certificate; only `/api/*` and `/up` |
| Console name | `cp.example.com`, nginx `cloud-manager-cp` | Let's Encrypt; the console, webhooks, installers |
| Backups | `/var/lib/cloud-manager/backups`, `/etc/cron.d/cloud-manager`, Spaces bucket `cm-backups-c33afc3b` (sfo3) | Nightly at 03:17 UTC, 14 kept in each place |

Everything above is written by `scripts/mgmt/setup.sh`. Nothing on the
server is configured by hand any more; if it differs from the script, the
script is right.

## Deploying

```bash
scripts/mgmt/deploy.sh            # set CM_MGMT=root@host (or scripts/mgmt/local.env), or pass a host
```

Copies the code, installs dependencies, migrates, clears caches, reloads
PHP-FPM, restarts the queue worker. It never touches the database, `.env`,
`storage/` or `bootstrap/cache/`.

## What to keep somewhere else

Two things, kept **apart** from each other:

1. **The database snapshots.** Encrypted secrets, the organisation CA's key
   (wrapped), the audit chain, every record. Useless without item 2.
2. **The `.env`**, above all `VAULT_MASTER_KEY`. Without it, every secret in
   every snapshot is unreadable: DigitalOcean token, GitHub App key, site
   `.env`s, the CA key. With it and a snapshot, everything comes back.

Today the snapshots exist only on the server's own disk. That covers a bad
migration or a corrupted database, not losing the Droplet. To cover that,
copy them off (see Open decisions), and keep the `.env` in a password
manager:

```bash
ssh root@203.0.113.10 cat /srv/cloud-manager/control-plane/.env   # save it, privately
```

## Rebuilding

If the server is gone, fetch the newest snapshot from the Spaces bucket
`cm-backups-c33afc3b` (sfo3): in the DigitalOcean console under Spaces, or
with any S3 client and a Spaces key you make for the purpose. Its key in the
vault went with the server. You also need `VAULT_MASTER_KEY` and the `.env`.

On a fresh Ubuntu 24.04 Droplet (any size; the smallest works), with your
SSH key and the cloud firewall `cm-mgmt-fw` attached (22 from you, 80 and
443 and 9443 from anywhere):

```bash
RESTORE_DB=./control-plane-<stamp>.sqlite.gz RESTORE_ENV=./cp.env \
CP_HOST=cp.example.com AGENT_HOST=agent.example.com \
  scripts/mgmt/provision.sh root@<new ip>
```

Then point `cp.example.com` and `agent.example.com` at the new IP.
Agents follow the name and pin the organisation CA, which the restore brings
back unchanged, so no node needs touching: they reconnect when DNS updates.
The console certificate is issued by Let's Encrypt during setup, so point
`cp.example.com` at the new IP **before** running it, or rerun the script
after.

Without `RESTORE_*`, the same command builds a new, empty control plane with
fresh keys.

### What was tested

`setup.sh` was run in a fresh Ubuntu 24.04 container twice: once from
nothing, and once restoring a snapshot and `.env` into a second container
under different names. After the restore, a vault secret decrypted, records
were intact, the audit chain verified, the organisation CA's fingerprint was
unchanged, and a new agent certificate for the new name was signed by it.
systemd inside that container could not start services, so nginx, PHP-FPM,
the gateway and the queue worker were started by hand there; their
configuration was otherwise exercised as written.

## Open decisions

- **Gateway and control plane on one machine.** A single point of failure,
  accepted for now; splitting them is Phase 3 territory.
