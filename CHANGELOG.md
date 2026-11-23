# Release notes

What changed in each release, newest first. Read the **Breaking** section of
every release between yours and the one you are updating to before you run
`git pull` and deploy (see "Updating" in [docs/self-hosting.md](docs/self-hosting.md)).

Each release lists its changes under these headings, leaving out any that are empty:

- **Breaking**: needs something from you when you update: a command to run, a
  setting to change, or code in `control-plane/custom/` that may need a fix.
  Each entry says what changed and what to do.
- **Added**: new features.
- **Changed**: behaviour that is different but needs nothing from you.
- **Fixed**: bugs fixed.

Anything under `app/Lib` and the database tables is internal and can change
in any release. The `cloud` command line, the HTTP API, webhooks, `.env` and
`config/` are stable: a change to them is always listed as **Breaking**.

## Unreleased

### Breaking

Nothing yet. This is the first public release's starting point, so there is
nothing to update from.

### Added

- `control-plane/custom/`: your own providers, routes and migrations, which
  are not in git and so never conflict on update.
- MySQL 8.4 as an alternative to SQLite for the control plane's own database
  (Docker only for now, see "Running in Docker" in the setup guide). Backups
  are `mysqldump` files. The whole test suite passes on both.
- Database clusters on providers other than DigitalOcean: PostgreSQL or MySQL on a
  server Unharbored sets up and runs (Databases → Create a cluster), TLS only and open to
  your application servers alone. One machine, no failover or provider backups.
- Docker: a `Dockerfile` and `docker-compose.yml` that run the whole control
  plane on any host with Docker.
