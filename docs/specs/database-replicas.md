# Database replicas: a warm standby you can promote by hand

Status: proposed. Nothing here is built. Builds on the self-run database servers of providers.md phase 9 (`DatabaseServers`), which have not yet been run live on a provider other than DigitalOcean. **Do not start this until they have run live on one non-DO provider (Hetzner).**

## What was asked

A database cluster on a provider that has no managed databases (anything but DigitalOcean), from **new servers that exist only to run the database**. Today a cluster is one such server. This adds a second (and third) one that follows it, so there is a warm copy if the first dies.

## Decided

| Question | Decision |
|---|---|
| Shape | One primary, any number of replicas. Apps use the primary only. |
| Engine | **PostgreSQL only.** MySQL waits until Postgres has run live. |
| Promote | **By hand, from a runbook on the page.** No button. |
| Network | Public address, TLS the replica verifies, firewall allow-list, and a `pg_hba` line for that one address. |
| Backups | Unchanged. A replica is **not** a backup: a bad `DROP` replicates. The page says so beside the replica. |
| Cost | Each replica is another paid server. The Add button shows the monthly price. |

## What exists

`DatabaseServers` makes a `database_cluster` resource (mode `self_run`) and one `server` resource. `prepare()` installs only the engine, turns TLS on and closes the firewall to the `admitted` addresses. The cluster's attributes hold `host`, `port`, `ca_pem` and `admitted`.

## The change

### Records

One new cluster attribute, no new table:

```
replicas: [{ slug, host, state }]   // state: building | streaming | failed
```

Each replica is its own `server` resource, `<cluster>-r1`, `<cluster>-r2`, made by the same provisioner with `attributes.database_cluster = <cluster>`. It is the same size as the primary: a smaller one cannot keep up with writes, or carry the load once promoted, and saves little.

### Add a replica

An operation in stages, as the existing create:

1. **Provision** a server on the cluster's provider, same region by default.
2. **Prepare** it as `prepare()` does (engine, TLS certificate made for its own address).
3. **Primary side:**
   - Create a role `replicator_<slug>` (hyphens in the slug become underscores) for this replica only (`REPLICATION LOGIN`, a generated password kept in the vault) and a physical replication slot for it. One role per replica, so rebuilding one never breaks another and one leaked credential covers only one replica.
   - Set `max_slot_wal_keep_size` on the primary, once, to 20% of its data disk (never above 50%), so a vanished replica can never fill its disk. Past it, Postgres drops the slot and the replica is marked `failed`; the primary stays up.
   - Add a `hostssl replication replicator_<slug> <replica-ip>/32 scram-sha-256` line. The primary's `pg_hba` is already rewritten from the admitted addresses on every firewall update (`DatabaseServers::hbaStep`), so this line goes into that same generated list, or the next update would delete it. No IPv6 line for replication.
   - Admit the replica's address on the engine port in the firewall (the primary's `admitted` list gains it).
4. **Replica side:**
   - Put the primary's certificate (`ca_pem`) on the replica first. The replica connects with `sslmode=verify-full`; `require` is not accepted, because every server here is its own CA.
   - Keep the replicator password out of the connection string and `postgresql.auto.conf` (a 0600 `.pgpass` owned by postgres).
   - Stop the engine, empty the data directory, run `pg_basebackup -R -S <slot>` over TLS from the primary, start the engine.
5. **Check:** `pg_stat_replication` on the primary shows the replica streaming; record `state: streaming`.

Re-running a failed add is safe, because step 4 starts from an empty data directory. Re-running reuses the replica's own role and password.

### Show it

The cluster's page lists each replica with its address, its state, and how many seconds behind it is (from `pg_stat_replication`). No alert in this version. If the replica stops streaming or falls too far behind, the row says so in plain words and nothing else happens. A `failed` replica keeps its row and a **Rebuild** action, which re-runs the add; without it, recovery would be destroy and re-add.

The connection details show the replica's address and port, and the same CA, so the user can wire reads themselves. Unharbored does not add `DB_READ_HOST`.

### Destroy

A replica can be destroyed at any time. That also removes its `pg_hba` line, its firewall rule, its role and its replication slot from the primary. Destroying a cluster destroys the primary and every replica, and keeps today's rule: refused while any site is attached.

### Promote, by hand

The page has a short runbook, in plain text, next to the replicas:

1. If the old primary is still up, close its firewall or power it off **first**, so nothing can write to two servers.
2. On the replica: `sudo -u postgres psql -c "select pg_promote()"`.
3. Admit each app server's address on the replica's firewall (the page lists them; the replica's own list is empty, so until this step no app can reach it).
4. In each app's environment, set `DB_HOST` to the replica's address and point the SSL settings at the replica's certificate. The page shows the exact lines for an app Unharbored deployed (Postgres: `DB_SSLMODE` and the certificate file).
5. Deploy each app.

Unharbored does not know the promotion happened and keeps showing the old layout until someone destroys the old cluster record. That is the price of leaving the button out; it is why the button is the first thing in "Later".

## What it costs

- Every replica is another paid server, about the price of the primary at the same size. The Add button shows it before anything is created.
- Replication runs over the public internet with TLS. Same region by default.
- A new replica copies the whole database. On 27 GB over the internet that is hours, and the cluster has no standby until it finishes. The page says "copying" while it does.

## Later (each only after someone needs it)

- **A Promote button** that runs the runbook: needs manager rights, an audit entry, a list of the apps it will repoint, and the old primary's firewall closed. It rewrites many apps at once, which is why it waits.
- **`DB_READ_HOST`** on attach, once apps use it.
- **Lag and stopped-replica alerts**, if the existing alerting can carry them.
- **MySQL replication.**
- **A WireGuard or Tailscale tunnel** between the primary and replicas, so replication is never on a public port. Probably the best fix for the public-network weakness.
- Automatic failover; making an old primary a replica of the new one; Valkey; restore of a self-run cluster.

## Open questions

1. `Firewall.php` allows `10.0.0.0/8` to database ports. On a provider whose private network is shared with other customers, that includes their servers. Check it per provider. It is a bug today, separate from this.
2. What private network does each provider offer customer servers, and how isolated is it? (A question for the provider doc.)

## Build order

1. Add a replica, with its test against the fake gateway as `DatabaseServersTest` does.
1. Show replicas on the page, with the runbook and the "not a backup" line.
2. Destroy a replica.

Steps 1 to 3 are about a day or two, and ship together.
