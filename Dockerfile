# syntax=docker/dockerfile:1
#
# One image for every part of the control plane: the console (FrankenPHP: Caddy
# and PHP in one binary, so no nginx, php-fpm or certbot), the queue worker,
# Reverb, the cron jobs, the init step, and the Go session gateway. Compose runs
# it once per service with a different command. See docker-compose.yml.

FROM node:22-alpine AS console
WORKDIR /app/control-plane
COPY control-plane/package.json control-plane/package-lock.json ./
RUN npm ci
COPY control-plane/ ./
RUN npm run build

FROM golang:1.25-alpine AS go
ARG TARGETARCH
ARG AGENT_VERSION=docker
WORKDIR /src
COPY go.mod ./
COPY cmd ./cmd
COPY internal ./internal
ENV CGO_ENABLED=0
RUN go build -trimpath -buildvcs=false -ldflags "-s -w" -o /out/gateway ./cmd/gateway \
 && for arch in amd64 arm64; do \
      GOOS=linux GOARCH=$arch go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/unharbored/unharbored/internal/agentcore.Version=$AGENT_VERSION" \
        -o /out/agent/cloud-manager-agent-linux-$arch ./cmd/agent; \
    done

FROM dunglas/frankenphp:1-php8.4 AS app
ARG AGENT_VERSION=docker
ENV AGENT_VERSION=$AGENT_VERSION

RUN install-php-extensions pdo_sqlite pdo_mysql mbstring intl bcmath zip pcntl sodium opcache \
 && apt-get update \
 && apt-get install -y --no-install-recommends cron git openssh-client sqlite3 mariadb-client openssl curl ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && ln -s /usr/local/bin/php /usr/bin/php8.4
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The same path as on a bare server, so the cron file works unchanged.
WORKDIR /srv/cloud-manager/control-plane
COPY control-plane/ ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts \
 && php artisan package:discover --ansi
COPY --from=console /app/control-plane/public/build public/build
COPY --from=go /out/gateway /usr/local/bin/gateway
COPY --from=go /out/agent /opt/agent
COPY scripts/mgmt/files/cron /etc/cron.d/cloud-manager
COPY docker/ /opt/docker/
# The .env lives in the data volume, where init writes it; the app reads it through this link.
RUN chmod 0644 /etc/cron.d/cloud-manager && chmod +x /opt/docker/*.sh \
 && ln -sf /var/lib/cloud-manager/.env .env

# Run as www-data, which is what owns the data on a bare server too. Caddy needs
# to bind 80 and 443 without root. The named volumes take their ownership from
# these directories the first time they are created.
RUN setcap CAP_NET_BIND_SERVICE=+ep /usr/local/bin/frankenphp \
 && mkdir -p /var/lib/cloud-manager /data/caddy /config/caddy \
 && chown -R www-data:www-data /var/lib/cloud-manager /data /config storage bootstrap/cache
USER www-data
CMD ["frankenphp", "run", "--config", "/opt/docker/Caddyfile"]
