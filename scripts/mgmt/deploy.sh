#!/usr/bin/env bash
#
# Deploy the control plane from this checkout to the management server.
#
#   scripts/mgmt/deploy.sh [root@host]
#
# Code is copied owned by root and is not writable by PHP. What PHP writes
# lives outside the copied tree or is never copied over: the database in
# /var/lib/cloud-manager, and storage/ and bootstrap/cache/, which rsync
# leaves alone. That is what keeps a deploy from briefly breaking requests:
# earlier hand-run syncs reset the ownership of the database's directory and
# requests failed with "attempt to write a readonly database" until a chown.
set -euo pipefail

[ -f "$(dirname "${BASH_SOURCE[0]}")/local.env" ] && . "$(dirname "${BASH_SOURCE[0]}")/local.env"
host="${1:-${CM_MGMT:?set CM_MGMT=root@your-server, or put it in scripts/mgmt/local.env}}"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo "deploying $(git -C "$repo" rev-parse --short HEAD) to $host"

# The gateway holds every agent's session, so it is replaced only when the
# build actually changed: an unneeded restart drops every node for a moment.
build="$(mktemp -d)"
trap 'rm -rf "$build"' EXIT
(cd "$repo" && GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -buildvcs=false -ldflags "-s -w" -o "$build/gateway" ./cmd/gateway)
local_sum="$(shasum -a 256 "$build/gateway" | cut -d' ' -f1)"
remote_sum="$(ssh "$host" 'sha256sum /usr/local/bin/gateway 2>/dev/null | cut -d" " -f1')"
if [ "$local_sum" != "$remote_sum" ]; then
  echo "gateway changed: replacing it"
  scp -q "$build/gateway" "$host:/usr/local/bin/gateway.new"
  restart_gateway=1
else
  restart_gateway=0
fi

# The console is Vue, built here: public/build is not in git but rsync copies it with the rest of the tree.
(cd "$repo/control-plane" && npm ci --silent && npm run build --silent)

rsync -rlptD --delete \
  --exclude .git \
  --exclude 'control-plane/.env' \
  --exclude 'control-plane/vendor/' \
  --exclude 'control-plane/node_modules/' \
  --exclude 'control-plane/storage/' \
  --exclude 'control-plane/bootstrap/cache/' \
  --exclude 'control-plane/public/hot' \
  --exclude 'control-plane/database/*.sqlite' \
  --exclude 'docs/design/control-plane-ui.html' \
  "$repo/" "$host:/srv/cloud-manager/"

# A dev server running here leaves public/hot, which sends pages to localhost for their assets; never leave one on the server.
ssh "$host" "rm -f /srv/cloud-manager/control-plane/public/hot"

# What is live, for anyone checking later: cat /srv/cloud-manager/REVISION.
ssh "$host" "echo $(git -C "$repo" rev-parse HEAD) > /srv/cloud-manager/REVISION"

ssh "$host" "RESTART_GATEWAY=$restart_gateway bash -s" <<'REMOTE'
set -euo pipefail
cd /srv/cloud-manager/control-plane
export HOME=/root COMPOSER_ALLOW_SUPERUSER=1
# Composer runs as root, so it must never boot the application: its scripts
# (package:discover) would execute whatever sits in the www-data-writable
# bootstrap/cache, including an event cache, as root (audit 2026-11-08, #3).
# Install without scripts, then run discovery as the web user.
composer install --no-dev --no-interaction --optimize-autoloader --no-scripts --quiet
as_www() { sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan "$@"; }
as_www package:discover --quiet
as_www migrate --force
as_www optimize:clear --quiet
as_www config:cache --quiet
FPM=$(systemctl list-units 'php*-fpm.service' --no-legend | awk '{print $1}')
systemctl reload $FPM
# Scheduled jobs ship with the code: without this, a new cron line would only exist after a full setup.sh run.
install -m 0644 /srv/cloud-manager/scripts/mgmt/files/cron /etc/cron.d/cloud-manager
systemctl restart cm-queue
# Reverb runs the app for the console's websocket transport; restart it so
# code and config changes take effect. Absent on servers before it existed.
systemctl try-restart cm-reverb >/dev/null 2>&1 || true
if [ "$RESTART_GATEWAY" = 1 ]; then
  chmod 0755 /usr/local/bin/gateway.new && mv -f /usr/local/bin/gateway.new /usr/local/bin/gateway
  systemctl restart cm-gateway   # re-syncs organisations on start
fi
echo "deployed: $(systemctl is-active $FPM nginx cm-queue cm-gateway | tr '\n' ' ')"
REMOTE
