#!/usr/bin/env bash
#
# Publish the agent at this checkout's commit to the control plane. New
# Droplets and bring-your-own servers get it from then on; running nodes move
# to it with node:upgrade-agent, or the Upgrade agent button on Servers.
#
#   scripts/mgmt/release-agent.sh [root@host]
set -euo pipefail

[ -f "$(dirname "${BASH_SOURCE[0]}")/local.env" ] && . "$(dirname "${BASH_SOURCE[0]}")/local.env"
host="${1:-${CM_MGMT:?set CM_MGMT=root@your-server, or put it in scripts/mgmt/local.env}}"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
version="$(git -C "$repo" rev-parse --short HEAD)"

[ -z "$(git -C "$repo" status --porcelain -- cmd internal)" ] || { echo "agent sources have uncommitted changes; commit first so the version means something" >&2; exit 1; }

AGENT_VERSION="$version" bash "$repo/scripts/build-agent.sh"
rsync -rlpt "$repo/control-plane/storage/agent/cloud-manager-agent-linux-"* "$host:/srv/cloud-manager/control-plane/storage/agent/"
ssh "$host" "set -e; cd /srv/cloud-manager/control-plane
  chown www-data:www-data storage/agent/cloud-manager-agent-linux-*
  sed -i 's|^AGENT_VERSION=.*|AGENT_VERSION=$version|' .env
  runuser -u www-data -- env HOME=/tmp php artisan config:cache --quiet
  # The queue worker read the old version when it started, and upgrades run there.
  systemctl restart cm-queue
  echo \"serving agent $version\""
