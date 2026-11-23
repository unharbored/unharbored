#!/bin/bash
# What scripts/mgmt/setup.sh does on a bare server, for this container: the .env with
# fresh keys on first run, the database, the gateway token and the agent certificate.
# Safe to run again: it never overwrites an existing database or .env.
set -euo pipefail

: "${CP_HOST:?set CP_HOST}" "${AGENT_HOST:?set AGENT_HOST}"
ORG="${ORG:-org-1}"
EXTRA_AGENT_NAMES="${EXTRA_AGENT_NAMES:-}"
APP=/srv/cloud-manager/control-plane
DATA=/var/lib/cloud-manager
ENVF="$DATA/.env"
DB="$DATA/control-plane.sqlite"
export HOME=/tmp

set_env() {
  local value
  value=$(printf '%s' "$2" | sed -e 's/[\\&|]/\\&/g')
  if grep -q "^$1=" "$ENVF"; then sed -i "s|^$1=.*|$1=$value|" "$ENVF"; else echo "$1=$value" >> "$ENVF"; fi
}

mkdir -p "$DATA/backups" "$DATA/certs"
for d in app/public framework/cache/data framework/sessions framework/views logs agent; do mkdir -p "$APP/storage/$d"; done
# The agent binaries this image was built with are what the control plane serves.
cp -f /opt/agent/* "$APP/storage/agent/"

if [ ! -f "$ENVF" ]; then
  install -m 0600 "$APP/.env.example" "$ENVF"
  set_env APP_KEY "base64:$(openssl rand -base64 32)"
  set_env VAULT_MASTER_KEY "$(openssl rand -base64 32)"
  set_env GATEWAY_TOKEN "$(openssl rand -base64 32 | tr -d '/+=')"
  set_env REVERB_APP_ID "$(od -An -N4 -tu4 /dev/urandom | tr -d ' ')"
  set_env REVERB_APP_KEY "$(openssl rand -hex 10)"
  set_env REVERB_APP_SECRET "$(openssl rand -hex 20)"
  echo "new .env with fresh keys: copy VAULT_MASTER_KEY out of the data volume before relying on backups"
fi

# Whatever the .env came from, the names follow this deployment.
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://$CP_HOST"
set_env CONTROL_PLANE_PUBLIC_URL "https://$AGENT_HOST"
set_env CONTROL_PLANE_SERVER_NAME "$AGENT_HOST"
set_env CONTROL_PLANE_SESSION_ADDRESS "$AGENT_HOST:9443"
if [ "${DB_CONNECTION:-sqlite}" = mysql ]; then
  : "${MYSQL_PASSWORD:?set MYSQL_PASSWORD to use MySQL}"
  set_env DB_CONNECTION mysql
  set_env DB_HOST mysql
  set_env DB_PORT 3306
  set_env DB_DATABASE unharbored
  set_env DB_USERNAME unharbored
  set_env DB_PASSWORD "$MYSQL_PASSWORD"
else
  set_env DB_CONNECTION sqlite
  set_env DB_DATABASE "$DB"
  [ -f "$DB" ] || : > "$DB"
fi
set_env DB_QUEUE_RETRY_AFTER 1500
set_env SESSION_LIFETIME 720
set_env GATEWAY_URL http://gateway:9444
[ -n "${AGENT_VERSION:-}" ] && set_env AGENT_VERSION "$AGENT_VERSION"
set_env BROADCAST_CONNECTION reverb
set_env UNSCRIPTED_TRANSPORT reverb
set_env REVERB_HOST reverb
set_env REVERB_PORT 9445
set_env REVERB_SCHEME http
set_env REVERB_SERVER_HOST 0.0.0.0
set_env REVERB_SERVER_PORT 9445
set_env REVERB_APP_MAX_MESSAGE_SIZE 200000
set_env REVERB_MAX_REQUEST_SIZE 200000
set_env UNSCRIPTED_SOCKET_URL "wss://$CP_HOST/app/$(grep '^REVERB_APP_KEY=' "$ENVF" | cut -d= -f2-)?protocol=7&client=unscripted&version=0.1"

cd "$APP"
php artisan migrate --force

grep '^GATEWAY_TOKEN=' "$ENVF" | cut -d= -f2- > "$DATA/certs/gateway.token"
chmod 0600 "$DATA/certs/gateway.token"
names="$AGENT_HOST $EXTRA_AGENT_NAMES"
php artisan tinker --execute="
\$ca = app(App\Lib\Enrollment\EnrollmentService::class)->certificateAuthorityFor('$ORG');
\$names = array_values(array_filter(explode(' ', '$names')));
[\$cert, \$key] = \$ca->issueServerCertificate('$AGENT_HOST', \$names);
file_put_contents('$DATA/certs/server.crt', \$cert);
file_put_contents('$DATA/certs/server.key', \$key);
file_put_contents('$DATA/certs/ca.crt', \$ca->certificatePem());
" >/dev/null
chmod 0600 "$DATA/certs/server.key"
echo "init done: console https://$CP_HOST, agents https://$AGENT_HOST (and $AGENT_HOST:9443 for sessions)"
