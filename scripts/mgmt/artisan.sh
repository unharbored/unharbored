#!/usr/bin/env bash
#
# Run an artisan command on the management server as the web user.
#
#   scripts/mgmt/artisan.sh operation:show 49 --wait --tail=10
#   scripts/mgmt/artisan.sh tinker --execute='echo DB::table("sites")->count();'
set -euo pipefail
[ -f "$(dirname "${BASH_SOURCE[0]}")/local.env" ] && . "$(dirname "${BASH_SOURCE[0]}")/local.env"
host="${CM_MGMT:?set CM_MGMT=root@your-server, or put it in scripts/mgmt/local.env}"
ssh "$host" "cd /srv/cloud-manager/control-plane && sudo -u www-data HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan $(printf '%q ' "$@")"
