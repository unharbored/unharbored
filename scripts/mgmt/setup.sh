#!/usr/bin/env bash
#
# Sets up the management server: the control plane, the session gateway, the
# queue worker, nginx for both names, nightly backups. Runs ON the server, as
# root; scripts/mgmt/provision.sh copies everything up and calls it.
#
# Safe to re-run. It converges: packages present, files as templated,
# services running. It never overwrites an existing database or .env.
#
# Required:
#   CP_HOST       public name for the console, e.g. cp.example.com
#   AGENT_HOST    name agents use, e.g. agent.example.com
# Optional:
#   ORG                  organisation whose CA signs the agent name (org-1)
#   EXTRA_AGENT_NAMES    more names the agent certificate and vhost accept,
#                        for nodes not yet moved off an old name
#   RESTORE_DB           a backup:database snapshot (.sqlite.gz) to restore
#   RESTORE_ENV          the .env that goes with it: VAULT_MASTER_KEY in
#                        particular, without which the snapshot's secrets
#                        cannot be read
#   AGENT_VERSION        version of the agent builds in storage/agent
#   CERTBOT=skip         use a self-signed certificate for CP_HOST instead of
#                        Let's Encrypt (testing only)
set -euo pipefail

: "${CP_HOST:?set CP_HOST}" "${AGENT_HOST:?set AGENT_HOST}"
ORG="${ORG:-org-1}"
EXTRA_AGENT_NAMES="${EXTRA_AGENT_NAMES:-}"
APP=/srv/cloud-manager/control-plane
FILES=/srv/cloud-manager/scripts/mgmt/files
DATA=/var/lib/cloud-manager
DB="$DATA/control-plane.sqlite"
PHP=/usr/bin/php

say() { printf '\n==> %s\n' "$*"; }
artisan() { (cd "$APP" && runuser -u www-data -- env HOME=/tmp XDG_CONFIG_HOME=/tmp "$PHP" artisan "$@"); }
set_env() {
  local value
  # The replacement is a sed expression: escape the characters it treats
  # specially so a URL's & cannot corrupt the line.
  value=$(printf '%s' "$2" | sed -e 's/[\\&|]/\\&/g')
  if grep -q "^$1=" "$APP/.env"; then sed -i "s|^$1=.*|$1=$value|" "$APP/.env"; else echo "$1=$value" >> "$APP/.env"; fi
}
render() { sed -e "s|__CP_HOST__|$CP_HOST|g" -e "s|__AGENT_HOST__|$AGENT_HOST|g" -e "s|__EXTRA_AGENT_NAMES__|$EXTRA_AGENT_NAMES|g" "$FILES/$1"; }

[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
[ -d "$APP" ] || { echo "$APP is missing: copy the repository up first (provision.sh does)" >&2; exit 1; }

say "Packages"
export DEBIAN_FRONTEND=noninteractive
# A Droplet a few minutes old is still running cloud-init and its own
# unattended upgrades, which hold apt's lock: wait for them rather than fail.
if command -v cloud-init >/dev/null; then cloud-init status --wait >/dev/null 2>&1 || true; fi
echo 'DPkg::Lock::Timeout "600";' > /etc/apt/apt.conf.d/90cloud-manager-lock-timeout
apt-get update -qq
apt-get install -y -qq software-properties-common curl ca-certificates unzip sqlite3 cron nginx certbot openssl >/dev/null
# Command substitution rather than "| grep -q": under pipefail, grep -q
# exiting early can kill the writer with SIGPIPE and fail the whole test.
if [ -z "$(ls /etc/apt/sources.list.d/ | grep ondrej-ubuntu-php || true)" ]; then
  LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php >/dev/null
fi
# PHP 8.4 where it can be had (the PPA carries it for LTS releases up to 24.04).
# Where it cannot (26.04 ships 8.5 and the PPA has no 8.4 for it) the distribution's own PHP is
# used: composer.json allows ^8.4. The unversioned php, php-fpm.sock and php*-fpm names
# below follow whichever is installed.
if apt-cache policy php8.4-cli | grep -q 'Candidate: [0-9]'; then V=8.4; else V=; fi
apt-get install -y -qq "php$V-cli" "php$V-fpm" "php$V-sqlite3" "php$V-mbstring" "php$V-xml" "php$V-curl" "php$V-zip" "php$V-bcmath" "php$V-intl" >/dev/null
if [ ! -x /usr/local/bin/composer ]; then
  curl -fsSL https://getcomposer.org/download/latest-2.x/composer.phar -o /usr/local/bin/composer.tmp
  chmod 0755 /usr/local/bin/composer.tmp && mv /usr/local/bin/composer.tmp /usr/local/bin/composer
fi

# PHP 8.3 from before the move to 8.4: nothing uses it.
if [ -n "$(dpkg -l | grep -E '^ii +php8\.3-' || true)" ]; then
  apt-get purge -y -qq 'php8.3-*' >/dev/null && apt-get autoremove -y -qq >/dev/null
fi

say "Directories"
install -d -m 0755 /etc/cloud-manager /var/www/acme
install -d -o www-data -g www-data -m 0750 "$DATA" "$DATA/backups"
install -d -o www-data -g www-data -m 0775 "$APP/storage" "$APP/bootstrap/cache"
for d in app/public framework/cache/data framework/sessions framework/views logs agent; do
  install -d -o www-data -g www-data -m 0775 "$APP/storage/$d"
done
chown -R www-data:www-data "$APP/storage" "$APP/bootstrap/cache"

say "Dependencies"
# No scripts as root: they boot the app, which would run www-data-writable cache
# files as root (audit 2026-11-08, #3). Discovery runs as the web user instead.
(cd "$APP" && HOME=/root COMPOSER_ALLOW_SUPERUSER=1 /usr/local/bin/composer install --no-dev --no-interaction --optimize-autoloader --no-scripts --quiet)
(cd "$APP" && sudo -u www-data HOME=/tmp php artisan package:discover --quiet)

say "Configuration"
if [ -n "${RESTORE_ENV:-}" ] && [ ! -f "$APP/.env" ]; then
  install -m 0640 -o root -g www-data "$RESTORE_ENV" "$APP/.env"
  echo "restored .env"
elif [ ! -f "$APP/.env" ]; then
  install -m 0640 -o root -g www-data "$APP/.env.example" "$APP/.env"
  set_env APP_KEY "base64:$(openssl rand -base64 32)"
  set_env VAULT_MASTER_KEY "$(openssl rand -base64 32)"
  set_env GATEWAY_TOKEN "$(openssl rand -base64 32 | tr -d '/+=')"
  echo "new .env with fresh keys: copy VAULT_MASTER_KEY off this server before relying on backups"
fi
# Whatever the .env came from, the names and paths follow this host.
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://$CP_HOST"
set_env CONTROL_PLANE_PUBLIC_URL "https://$AGENT_HOST"
set_env CONTROL_PLANE_SERVER_NAME "$AGENT_HOST"
# The gateway has one server certificate, signed by this team's CA (below); every team's agents pin that CA.
set_env CONTROL_PLANE_SERVER_CA_ORG "$ORG"
set_env CONTROL_PLANE_SESSION_ADDRESS "$AGENT_HOST:9443"
set_env DB_CONNECTION sqlite
set_env DB_DATABASE "$DB"
set_env DB_QUEUE_RETRY_AFTER 1500
set_env SESSION_LIFETIME 720
[ -n "${AGENT_VERSION:-}" ] && set_env AGENT_VERSION "$AGENT_VERSION"

# The console's websocket transport. Reverb listens on loopback; nginx
# proxies /app to it on the console name. Keys are generated once and kept.
set_env BROADCAST_CONNECTION reverb
set_env UNSCRIPTED_TRANSPORT reverb
# A blank value counts as missing: the .env copied from .env.example has these lines, empty.
if ! grep -q '^REVERB_APP_ID=.' "$APP/.env" || ! grep -q '^REVERB_APP_KEY=.' "$APP/.env" || ! grep -q '^REVERB_APP_SECRET=.' "$APP/.env"; then
  set_env REVERB_APP_ID "$(od -An -N4 -tu4 /dev/urandom | tr -d ' ')"
  set_env REVERB_APP_KEY "$(openssl rand -hex 10)"
  set_env REVERB_APP_SECRET "$(openssl rand -hex 20)"
fi
set_env REVERB_HOST 127.0.0.1
set_env REVERB_PORT 9445
set_env REVERB_SCHEME http
set_env REVERB_SERVER_HOST 127.0.0.1
set_env REVERB_SERVER_PORT 9445
set_env REVERB_APP_MAX_MESSAGE_SIZE 200000
set_env REVERB_MAX_REQUEST_SIZE 200000
set_env UNSCRIPTED_SOCKET_URL "wss://$CP_HOST/app/$(grep '^REVERB_APP_KEY=' "$APP/.env" | cut -d= -f2-)?protocol=7&client=unscripted&version=0.1"
# The browser terminal reads this one; without it the console falls back to ws://127.0.0.1, the visitor's own machine.
set_env CONSOLE_SOCKET_URL "wss://$CP_HOST/app/$(grep '^REVERB_APP_KEY=' "$APP/.env" | cut -d= -f2-)?protocol=7&client=console&version=0.1"

chown root:www-data "$APP/.env"; chmod 0640 "$APP/.env"

say "Database"
if [ -f "$DB" ]; then
  echo "keeping the existing database"
elif [ -n "${RESTORE_DB:-}" ]; then
  gunzip -c "$RESTORE_DB" > "$DB"
  echo "restored $(basename "$RESTORE_DB")"
else
  : > "$DB"
fi
chown www-data:www-data "$DB"; chmod 0640 "$DB"
artisan migrate --force
artisan config:cache --quiet

say "Gateway token and agent certificate"
grep '^GATEWAY_TOKEN=' "$APP/.env" | cut -d= -f2- > /etc/cloud-manager/gateway.token
chmod 0600 /etc/cloud-manager/gateway.token
names="$AGENT_HOST $EXTRA_AGENT_NAMES"
artisan tinker --execute="
\$ca = app(App\Lib\Enrollment\EnrollmentService::class)->certificateAuthorityFor('$ORG');
\$names = array_values(array_filter(explode(' ', '$names')));
[\$cert, \$key] = \$ca->issueServerCertificate('$AGENT_HOST', \$names);
file_put_contents('/tmp/cm-server.crt', \$cert);
file_put_contents('/tmp/cm-server.key', \$key);
file_put_contents('/tmp/cm-ca.crt', \$ca->certificatePem());
" >/dev/null
install -m 0644 /tmp/cm-server.crt /etc/cloud-manager/server.crt
install -m 0600 /tmp/cm-server.key /etc/cloud-manager/server.key
install -m 0644 /tmp/cm-ca.crt /etc/cloud-manager/ca.crt
rm -f /tmp/cm-server.crt /tmp/cm-ca.crt; shred -u /tmp/cm-server.key 2>/dev/null || rm -f /tmp/cm-server.key
# The gateway posts events to the agent name, which serves the organisation
# CA's certificate; this host has to trust it.
install -m 0644 /etc/cloud-manager/ca.crt "/usr/local/share/ca-certificates/cloud-manager-$ORG.crt"
update-ca-certificates >/dev/null

say "nginx"
# The distribution's default site, and the names this server's vhosts had
# when they were written by hand, before this script.
rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-enabled/cloud-manager /etc/nginx/sites-enabled/cp-public
render nginx-agent.conf > /etc/nginx/sites-enabled/cloud-manager-agent
if [ ! -f "/etc/letsencrypt/live/$CP_HOST/fullchain.pem" ]; then
  # Port 80 alone first, so the HTTP-01 challenge can be answered.
  render nginx-cp-http.conf > /etc/nginx/sites-enabled/cloud-manager-cp
  nginx -t -q && { systemctl reload nginx 2>/dev/null || systemctl start nginx; }
  if [ "${CERTBOT:-}" = skip ]; then
    install -d "/etc/letsencrypt/live/$CP_HOST"
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$CP_HOST" \
      -keyout "/etc/letsencrypt/live/$CP_HOST/privkey.pem" -out "/etc/letsencrypt/live/$CP_HOST/fullchain.pem" 2>/dev/null
    echo "CERTBOT=skip: self-signed certificate for $CP_HOST"
  else
    certbot certonly --webroot -w /var/www/acme -d "$CP_HOST" --non-interactive --agree-tos \
      --register-unsafely-without-email --deploy-hook "systemctl reload nginx"
  fi
fi
{ render nginx-cp-http.conf; echo; render nginx-cp-https.conf; } > /etc/nginx/sites-enabled/cloud-manager-cp
nginx -t -q

say "Services"
render cm-gateway.service > /etc/systemd/system/cm-gateway.service
render cm-queue.service > /etc/systemd/system/cm-queue.service
install -m 0644 "$FILES/cm-reverb.service" /etc/systemd/system/cm-reverb.service
install -m 0644 "$FILES/cron" /etc/cron.d/cloud-manager
systemctl daemon-reload
FPM=$(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '{print $1}')
systemctl enable --now $FPM nginx cron >/dev/null 2>&1
systemctl reload nginx
systemctl enable cm-gateway cm-queue cm-reverb >/dev/null 2>&1
systemctl restart cm-gateway cm-queue cm-reverb

say "Done"
echo "services: $(systemctl is-active $FPM nginx cm-gateway cm-queue cm-reverb cron | tr '\n' ' ')"
echo "console:  https://$CP_HOST"
echo "agents:   https://$AGENT_HOST (and $AGENT_HOST:9443 for sessions)"
[ -s /usr/local/bin/gateway ] || echo "WARNING: /usr/local/bin/gateway is missing; provision.sh uploads it"
