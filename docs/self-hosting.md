# Running your own Unharbored

How to stand up your own Unharbored control plane from this repository: one management
server that runs the console, the session gateway and the queue worker, and
your own DigitalOcean account for everything it creates. About 30 minutes,
most of it waiting for DNS and the first server.

The management server itself, what runs where, backups and rebuilding it are
covered in [management-server.md](management-server.md). This page is the
path from nothing to a first deployed application.

## What you need

| | Why |
|---|---|
| A DigitalOcean account | Servers, managed databases, load balancers and Spaces are created in it. Everything is yours; Unharbored holds an API token. |
| A DigitalOcean personal access token, full access | Read once from a prompt, kept encrypted in the vault, verified before it is stored. |
| A domain whose DNS you control | Two names point at the management server: one for the console, one for agents. A third, a wildcard, is optional for previews. |
| A GitHub account | Unharbored creates its own GitHub App in it (read-only code access) to clone repositories and hear pushes and pull requests. |
| On your machine: Go 1.23+, Node 20+ with npm, PHP 8.4 with Composer, `rsync`, `ssh` | `provision.sh` builds the agent, the gateway and the console's JavaScript locally and copies everything up. |

Costs to you on DigitalOcean: the management server (the smallest Droplet,
about $6/mo, works), plus whatever you create through the console.

## 1. Pick names and create the management server

Choose two names, for example:

- `cp.example.com`: the console, GitHub webhooks and installers (HTTPS, Let's
  Encrypt)
- `agent.example.com`: where agents enroll and hold their sessions (ports
  443 and 9443, signed by your own certificate authority)

Create an Ubuntu 24.04 (or 26.04) Droplet with your SSH key. Give it a cloud firewall
allowing 22 from your own address, and 80, 443 and 9443 from anywhere.

Point both names at its IPv4 address with A records, and wait until they
resolve:

```bash
dig +short cp.example.com agent.example.com
```

DNS must be in place first: the console's certificate is issued during
setup.

## 2. Build and install it

From a clone of this repository:

```bash
git clone https://github.com/unharbored/unharbored.git && cd unharbored
CP_HOST=cp.example.com AGENT_HOST=agent.example.com \
  scripts/mgmt/provision.sh root@<droplet ip>
```

This builds the agent, gateway and console, copies the code up, and runs
`scripts/mgmt/setup.sh` there, which installs PHP 8.4, nginx and the rest,
writes a fresh `.env` with new keys, creates the database, issues the agent
certificate from a new certificate authority, obtains the console's HTTPS
certificate, and starts the gateway, the queue worker and the nightly jobs.
It is safe to run again; it never overwrites an existing database or `.env`.

It ends with:

```
services: active active active active active
console:  https://cp.example.com
agents:   https://agent.example.com (and agent.example.com:9443 for sessions)
```

**Save the `.env` somewhere private now**, above all `VAULT_MASTER_KEY`.
Without it, nothing in any backup can be decrypted:

```bash
ssh root@<droplet ip> cat /srv/cloud-manager/control-plane/.env
```

The other scripts in `scripts/mgmt/` default to the original author's
server. Point them at yours for the rest of this guide:

```bash
export CM_MGMT=root@<droplet ip>
```

## 3. Your login

```bash
scripts/mgmt/artisan.sh user:create you@example.com --name="Your Name"
```

It asks for a password (twelve characters or more) twice. Sign in at
`https://cp.example.com`.

## 4. Connect DigitalOcean

```bash
ssh -t $CM_MGMT 'cd /srv/cloud-manager/control-plane && sudo -u www-data php artisan cloud:connect org-1 --name="Your Org"'
scripts/mgmt/artisan.sh gateway:sync-organisations
```

The first command creates the team, asks for the token (it is not
echoed), checks it against the DigitalOcean API and stores it encrypted. The
second tells the gateway about the team, so its agents can connect.
(`-t` gives the prompt a terminal; `artisan.sh` has none.)

The first team is `org-1`; use that id here. Once it exists you can also
add or replace cloud provider keys in the console under Account → Providers.

## 5. Connect GitHub

```bash
scripts/mgmt/artisan.sh github:setup org-1
```

It prints a link that works once, for 30 minutes. Open it signed in to
GitHub: it creates a private GitHub App in your account with read-only
access to code and pull requests, and subscribes it to pushes and pull
requests. Then install the app on the repositories you want to deploy
(GitHub offers this straight after; later, from the app's settings page).

## 6. Your first server and application

In the console:

1. **Servers → Add a server → + Create Droplet**: a region and size. It takes about four
   minutes: the Droplet boots, its agent enrolls, then nginx, PHP 8.4,
   Composer and the rest are installed. You can skip this and let the wizard
   create one in step 2.
2. **Sites → + New application**: pick a repository, single server or
   autoscaling, the database, cache and uploads, any variables, then Create.
   One operation does the rest and shows each step as it runs; a failed step
   stops there and says why.

The application gets an address like `app.203-0-113-7.sslip.io` with HTTPS
straight away. Add your own domain from its settings once DNS points at it.

Pushes to its branch deploy it from then on.

## Optional

**Managed databases.** Databases → create a PostgreSQL, MySQL or Valkey
cluster (about $15/mo each). New applications can then use it; one cluster
serves many applications, each with its own database and user.

**Off-site backups of the control plane.** The database is snapshotted
nightly on the server. To keep copies in another region too:

```bash
scripts/mgmt/artisan.sh backup:offsite org-1 --region=sfo3
```

This creates a Spaces bucket (about $5/mo) and a key limited to it. Site
databases are backed up nightly to it as well, 14 days kept.

**Previews.** Create a server for previews, then:

```bash
scripts/mgmt/artisan.sh node:role <server>.org-1 preview
```

Set `PREVIEW_DOMAIN=preview.example.com` in the control plane's `.env` (there is
no default; previews refuse to start without it), run
`scripts/mgmt/artisan.sh config:cache`, and add a DNS record
`*.preview.example.com` pointing at the preview server. Previews then get
`https://<branch>.<app>.preview.example.com`.

**Alert email.** Alerts → set an address. Email needs a mailer in the
control plane's `.env` (`MAIL_MAILER`, `MAIL_HOST` and so on, as for any
Laravel app); without one, alerts still show in the console and go to a
webhook if set.

**The `cloud` command line and MCP server.** API tokens → create one, then
`go build -o cloud ./cmd/cloud && ./cloud login https://cp.example.com`. See
[api.md](api.md).

## Running in Docker

Instead of steps 1 and 2, the control plane runs from the `Dockerfile` and
`docker-compose.yml` in this repository, on any host with Docker. The two
names from step 1 must point at the host first, with 80, 443 and 9443 open.

```bash
git clone https://github.com/unharbored/unharbored.git && cd unharbored
CP_HOST=cp.example.com AGENT_HOST=agent.example.com docker compose up -d --build
docker compose exec web cat /var/lib/cloud-manager/.env    # save VAULT_MASTER_KEY now
docker compose exec web php artisan user:create you@example.com --name="Your Name"
```

Put the two names in a `.env` file next to `docker-compose.yml` to keep them
for later commands. Steps 4 and 5 are the same, with
`docker compose exec web php artisan …` in place of `scripts/mgmt/artisan.sh …`.

One image runs every part (console, queue worker, Reverb, cron jobs and the
gateway) as separate services. Caddy replaces nginx and certbot: it gets the
console's certificate itself. Your data is in the `data` volume (database,
`.env`, certificates, backups) and `storage` volume; back both up. To update:
`git pull && docker compose up -d --build`; migrations run on start. The
gateway's API port (9444) is never published. If 80 or 443 is taken on the
host, set `HTTP_PORT` and `HTTPS_PORT`, but Let's Encrypt needs the real ports
reachable from outside.

**MySQL instead of SQLite.** SQLite in the data volume is the default and is
enough for one host. To use MySQL 8.4, choose it before the first start (a later
switch does not move your data):

```bash
DB_CONNECTION=mysql MYSQL_PASSWORD=<a long password> COMPOSE_PROFILES=mysql \
  CP_HOST=cp.example.com AGENT_HOST=agent.example.com docker compose up -d --build
```

Put those in the `.env` next to `docker-compose.yml` to keep them. This starts
a `mysql` service (its data is the `mysql` volume; back it up too). Backups
become `mysqldump` files, `control-plane-<stamp>.sql.gz`. To restore one over
the running database:

```bash
docker compose exec -T web sh -c 'gunzip -c /var/lib/cloud-manager/backups/<file>.sql.gz' \
  | docker compose exec -T mysql sh -c 'mysql -u unharbored -p"$MYSQL_PASSWORD" unharbored'
```

Only the bundled MySQL service is supported: the control plane does not
connect to a database you run elsewhere, managed or not.

Not covered here: the scripts in `scripts/mgmt/` (`deploy.sh`, `artisan.sh`
and the rest) are for the bare-server install and do not apply.

## Customising

If you need something this repository does not do, do not edit its files:
every file you change is a possible conflict the next time you `git pull`.
Put your own code in `control-plane/custom/` instead. It is not in git, so an
update never touches it, and the deploy script copies it up with the rest.

```
control-plane/custom/
  Providers/MyProvider.php    service providers, registered automatically
  routes/web.php              web routes (web middleware)
  routes/api.php              API routes (under /api, api middleware)
  migrations/                 your own tables
  Anything.php                other classes, namespace Custom\
```

A provider is where listeners, bindings and commands go. For example,
`custom/Providers/MyProvider.php`:

```php
<?php

namespace Custom\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MyProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(\Illuminate\Auth\Events\Login::class, fn ($e) => logger("{$e->user->email} signed in"));
        $this->commands([\Custom\Commands\NightlyReport::class]);
    }
}
```

After adding a class under a new folder, run `composer dump-autoload` on the
server (the deploy script already runs composer).

**What stays stable across updates, and what does not.**

- Stable, so build on these first: the `cloud` command line and the HTTP API
  ([api.md](api.md)), webhooks, configuration in `.env` and `config/`, and
  the application templates.
- Not stable: the classes under `app/Lib` and the database tables. They are
  internal and can change in any release. Code in `custom/` that calls them
  may need a fix after an update, so read the "Breaking" section of the
  release notes ([CHANGELOG.md](../CHANGELOG.md)) before you deploy one.

If you keep finding you need to change core files, that is worth an issue:
the thing you need is probably a missing extension point.

## Updating

```bash
git pull
scripts/mgmt/deploy.sh          # uses $CM_MGMT
```

Deploys copy the code, migrate and restart the worker; they never touch the
database or `.env`. New agent versions: `scripts/mgmt/release-agent.sh`.

## What has been tested

This run predates the Vue console and the team and provider changes; the
commands were rechecked against the code since, but a fresh install has not
been repeated end to end.

2026-11-08, on a fresh s-1vcpu-1gb Droplet in nyc3, following this page with
sslip.io names (`cp.<ip>.sslip.io`, `agent.<ip>.sslip.io`, so no DNS records
were needed): steps 1 to 3 worked as written. Every service came up, the
console took a Let's Encrypt certificate and accepted the new login, the
agent name served a certificate from the new organisation CA, port 9443
answered, and the server used about 400 MB of its 1 GB. Steps 4 to 6 need a
DigitalOcean token and a GitHub account of the person installing, and were
not repeated there; they are the same commands the original server was set
up with.

Found on the way and fixed: a Droplet a few minutes old is still running
its own package updates, and setup stopped on apt's lock. It now waits.

## Limits worth knowing

- **Teams.** `org-1` is the first team. More can be created in the console,
  and an instance administrator decides who may create them
  (`instance:team-creation`).
- **Providers.** Servers can be created at DigitalOcean, Vultr, Linode,
  Hetzner and AWS. Load balancers and autoscaling are DigitalOcean's, and so
  are managed databases (a provider's own product, with backups and failover).
  On any other provider a database cluster is PostgreSQL or MySQL on a server
  Unharbored sets up and runs for you (Databases → Create a cluster): TLS
  only, open to your application servers alone, a database and user per site.
  It is one machine, with no failover and no provider backups: the nightly site
  dumps are the backup. A server from anywhere else can be added with its install
  command (Servers → Add your own server → Get install command). An
  application and everything it runs on stays on one provider.
- **Laravel first.** Other frameworks are on the roadmap, not built.
- **One management server.** If it is down, sites keep serving, but nothing
  can be deployed or changed until it is back:
  [management-server.md](management-server.md) has the rebuild.
