#!/usr/bin/env bash
#
# Build a management server on a fresh Ubuntu 24.04 machine, or repair one.
#
#   CP_HOST=cp.example.com AGENT_HOST=agent.example.com \
#     scripts/mgmt/provision.sh root@<ip>
#
# To rebuild from a backup, also pass the snapshot and the .env it belongs
# with (both from the old server, or wherever you keep copies):
#
#   RESTORE_DB=./control-plane-20261106T031700Z.sqlite.gz RESTORE_ENV=./cp.env \
#   CP_HOST=... AGENT_HOST=... scripts/mgmt/provision.sh root@<ip>
#
# Then point both DNS names at the new machine. Nodes follow the name, so
# nothing on them needs to change. See docs/management-server.md.
set -euo pipefail

host="${1:?usage: provision.sh root@host}"
: "${CP_HOST:?set CP_HOST}" "${AGENT_HOST:?set AGENT_HOST}"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
version="$(git -C "$repo" rev-parse --short HEAD)"
build="$(mktemp -d)"
trap 'rm -rf "$build"' EXIT

echo "==> building the agent and gateway at $version"
AGENT_VERSION="$version" bash "$repo/scripts/build-agent.sh" >/dev/null
(cd "$repo" && GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -buildvcs=false -ldflags "-s -w" -o "$build/gateway" ./cmd/gateway)

# The console is Vue, built here: public/build is not in git, and rsync copies it with the rest of the tree.
echo "==> building the console"
(cd "$repo/control-plane" && npm ci --silent && npm run build --silent)

echo "==> copying to $host"
ssh "$host" 'install -d /srv/cloud-manager /root/cm-restore'
rsync -rlptD --delete \
  --exclude .git --exclude 'control-plane/.env' --exclude 'control-plane/vendor/' \
  --exclude 'control-plane/node_modules/' --exclude 'control-plane/storage/' \
  --exclude 'control-plane/bootstrap/cache/' --exclude 'control-plane/public/hot' --exclude 'control-plane/database/*.sqlite' \
  --exclude 'docs/design/control-plane-ui.html' \
  "$repo/" "$host:/srv/cloud-manager/"
scp -q "$build/gateway" "$host:/usr/local/bin/gateway.new"
ssh "$host" 'chmod 0755 /usr/local/bin/gateway.new && mv -f /usr/local/bin/gateway.new /usr/local/bin/gateway
  install -d -o www-data -g www-data /srv/cloud-manager/control-plane/storage/agent'
rsync -rlpt "$repo/control-plane/storage/agent/cloud-manager-agent-linux-"* "$host:/srv/cloud-manager/control-plane/storage/agent/"

remote_env="CP_HOST=$CP_HOST AGENT_HOST=$AGENT_HOST AGENT_VERSION=$version"
[ -n "${ORG:-}" ] && remote_env="$remote_env ORG=$ORG"
[ -n "${EXTRA_AGENT_NAMES:-}" ] && remote_env="$remote_env EXTRA_AGENT_NAMES='$EXTRA_AGENT_NAMES'"
[ -n "${CERTBOT:-}" ] && remote_env="$remote_env CERTBOT=$CERTBOT"
if [ -n "${RESTORE_DB:-}" ]; then
  scp -q "$RESTORE_DB" "$host:/root/cm-restore/db.sqlite.gz"
  remote_env="$remote_env RESTORE_DB=/root/cm-restore/db.sqlite.gz"
fi
if [ -n "${RESTORE_ENV:-}" ]; then
  scp -q "$RESTORE_ENV" "$host:/root/cm-restore/env"
  ssh "$host" 'chmod 0600 /root/cm-restore/env'
  remote_env="$remote_env RESTORE_ENV=/root/cm-restore/env"
fi

echo "==> running setup on $host"
ssh "$host" "$remote_env bash /srv/cloud-manager/scripts/mgmt/setup.sh"
ssh "$host" 'shred -u /root/cm-restore/env 2>/dev/null || true'
