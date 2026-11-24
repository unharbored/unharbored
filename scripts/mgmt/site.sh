#!/usr/bin/env bash
#
# Publish site/ (the marketing page) on a management server, at SITE_HOST.
#
#   SITE_HOST=unharbored.com scripts/mgmt/site.sh root@<server>
#
# Point SITE_HOST's DNS at the server first: the certificate is issued by
# Let's Encrypt over HTTP. Safe to run again: it re-copies the files, keeps the
# certificate it has and rewrites the nginx file.
set -euo pipefail

host="${1:?usage: SITE_HOST=example.com scripts/mgmt/site.sh root@<server>}"
: "${SITE_HOST:?set SITE_HOST}"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

rsync -rlpt --delete "$repo/site/" "$host:/srv/unharbored-site/"

ssh "$host" "SITE_HOST=$SITE_HOST bash -s" <<'REMOTE'
set -euo pipefail
chmod -R a+rX /srv/unharbored-site
conf=/etc/nginx/sites-enabled/unharbored-site
cert=/etc/letsencrypt/live/$SITE_HOST

if [ ! -d "$cert" ]; then
  cat > "$conf" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $SITE_HOST;
    location ^~ /.well-known/acme-challenge/ { root /var/www/acme; default_type text/plain; }
    location / { return 301 https://\$host\$request_uri; }
}
NGINX
  nginx -t -q && systemctl reload nginx
  certbot certonly --webroot -w /var/www/acme -d "$SITE_HOST" --non-interactive --agree-tos --register-unsafely-without-email --quiet
fi

cat > "$conf" <<NGINX
# Managed by scripts/mgmt/site.sh.
server {
    listen 80;
    listen [::]:80;
    server_name $SITE_HOST;
    location ^~ /.well-known/acme-challenge/ { root /var/www/acme; default_type text/plain; }
    location / { return 301 https://\$host\$request_uri; }
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name $SITE_HOST;
    ssl_certificate $cert/fullchain.pem;
    ssl_certificate_key $cert/privkey.pem;
    root /srv/unharbored-site;
    index index.html;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000" always;
    location ~* \.(svg|jpg|png)\$ { expires 7d; }
    location / { try_files \$uri \$uri/ =404; }
}
NGINX
nginx -t -q && systemctl reload nginx
echo "site live: https://$SITE_HOST"
REMOTE
