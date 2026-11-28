#!/usr/bin/env bash
# Builds one PHP branch from source, for an Ubuntu release that has no packages for it (26.04: the ondrej/php PPA does
# not serve it, and Ubuntu carries 8.5 alone). The result is laid out the way Debian's packages are, so the rest of the
# stack does not know the difference: /usr/bin/php8.4, /usr/sbin/php-fpm8.4, /etc/php/8.4/{cli,fpm}/conf.d, a
# php8.4-fpm service, and sockets under /run/php.
#
#   build-php.sh 8.4
#
# Takes ten to thirty minutes, depending on cores. Safe to run again: it does nothing when the latest patch of the
# branch is already built, and rebuilds in place when there is a newer one (that is how a patch update is applied).
set -euo pipefail

V="${1:?usage: build-php.sh <branch, such as 8.4>}"
[[ "$V" =~ ^8\.[0-9]$ ]] || { echo "not a PHP branch: $V" >&2; exit 2; }
PREFIX="/opt/cloud-manager/php/$V"
SRC=/usr/local/src/cloud-manager-php
export DEBIAN_FRONTEND=noninteractive
say() { printf '==> %s\n' "$*"; }

[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 2; }

say "Finding the latest PHP $V"
release=$(curl -fsSL --retry 5 --retry-delay 3 "https://www.php.net/releases/index.php?json&version=$V")
# No JSON tool is assumed on the server: the fields are plain strings in a small document.
FULL=$(printf '%s' "$release" | grep -o '"version":"[^"]*"' | head -n1 | cut -d'"' -f4)
entry=$(printf '%s' "$release" | grep -o '{[^{}]*\.tar\.xz[^{}]*}' | head -n1)
FILE=$(printf '%s' "$entry" | grep -o '"filename":"[^"]*"' | cut -d'"' -f4)
SHA=$(printf '%s' "$entry" | grep -o '"sha256":"[^"]*"' | cut -d'"' -f4)
[ -n "$FULL" ] && [ -n "$FILE" ] && [[ "$SHA" =~ ^[0-9a-f]{64}$ ]] || { echo "could not read the PHP $V release information" >&2; exit 1; }
[[ "$FULL" =~ ^$V\.[0-9]+$ ]] || { echo "unexpected version $FULL for branch $V" >&2; exit 1; }

if [ -f "$PREFIX/.cloud-manager-build" ] && [ "$(cat "$PREFIX/.cloud-manager-build")" = "$FULL" ] && [ -x "/usr/sbin/php-fpm$V" ]; then
  say "PHP $FULL is already built"
  exit 0
fi

say "Installing build dependencies"
apt-get update -qq
DEPS="build-essential autoconf bison re2c pkg-config curl ca-certificates xz-utils libxml2-dev libssl-dev libcurl4-openssl-dev libzip-dev libonig-dev libsqlite3-dev libpq-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev libicu-dev libreadline-dev libsodium-dev libargon2-dev zlib1g-dev libxslt1-dev libgmp-dev libbz2-dev libsystemd-dev"
# Some servers have a package whose own hook fails (an initramfs tool on a kernel with no modules directory, say) and that
# makes apt exit with an error even though everything asked for is in place. Carry on if every package we need is installed.
if ! apt-get install -y -qq $DEPS >/dev/null; then
  installed=$(dpkg-query -W -f='${db:Status-Status}\n' $DEPS 2>/dev/null | grep -c '^installed$' || true)
  [ "$installed" = "$(printf '%s\n' $DEPS | wc -l)" ] || { echo "could not install the build dependencies" >&2; exit 1; }
  say "A package unrelated to PHP failed to configure; every build dependency is installed, so carrying on"
fi

# The compiler needs about a gigabyte per job; a small server gets a temporary swap file so it is not killed.
swap=""
if [ "$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)" -lt 2500 ] && [ "$(awk '/SwapTotal/ {print $2}' /proc/meminfo)" = 0 ]; then
  say "Adding temporary swap"
  swap=/var/cm-php-build.swap
  fallocate -l 2G "$swap" && chmod 600 "$swap" && mkswap "$swap" >/dev/null && swapon "$swap" || swap=""
fi
cleanup() { [ -z "$swap" ] || { swapoff "$swap" 2>/dev/null; rm -f "$swap"; }; rm -rf "$SRC"; }
trap cleanup EXIT

mkdir -p "$SRC" && cd "$SRC"
say "Downloading PHP $FULL and checking it"
curl -fsSL --retry 5 --retry-delay 3 -o "$FILE" "https://www.php.net/distributions/$FILE"
echo "$SHA  $FILE" | sha256sum -c - >/dev/null || { echo "PHP $FULL failed its checksum; nothing was built" >&2; exit 1; }
tar -xf "$FILE" && cd "php-$FULL"

# One build serves the command line and FPM. The command line reads /etc/php/$V/cli; FPM is pointed at /etc/php/$V/fpm
# by its service (-c, PHP_INI_SCAN_DIR), as Debian lays them out.
say "Configuring"
./configure --prefix="$PREFIX" \
  --with-config-file-path="/etc/php/$V/cli" --with-config-file-scan-dir="/etc/php/$V/cli/conf.d" \
  --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data --with-fpm-systemd \
  --enable-mbstring --with-openssl --with-curl --with-zlib --with-zip --enable-bcmath --enable-intl \
  --enable-mysqlnd --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --with-pgsql --with-pdo-pgsql --with-sqlite3 --with-pdo-sqlite \
  --enable-gd --with-freetype --with-jpeg --with-webp --enable-exif --enable-pcntl --enable-sockets --enable-soap \
  --with-xsl --with-sodium --with-password-argon2 --with-readline --with-gmp --with-bz2 --enable-calendar --enable-ftp \
  --enable-sysvmsg --enable-sysvsem --enable-sysvshm --enable-shmop --enable-opcache \
  >"$SRC/configure.log" 2>&1 || { tail -n 30 "$SRC/configure.log"; exit 1; }

say "Compiling with $(nproc) job(s)"
make -j"$(nproc)" >"$SRC/make.log" 2>&1 || { tail -n 30 "$SRC/make.log"; exit 1; }
make install >"$SRC/install.log" 2>&1 || { tail -n 30 "$SRC/install.log"; exit 1; }

say "Laying it out like the packages"
install -d "/etc/php/$V/cli/conf.d" "/etc/php/$V/fpm/conf.d" "/etc/php/$V/fpm/pool.d" /run/php /var/log
[ -f "/etc/php/$V/cli/php.ini" ] || cp php.ini-production "/etc/php/$V/cli/php.ini"
[ -f "/etc/php/$V/fpm/php.ini" ] || cp php.ini-production "/etc/php/$V/fpm/php.ini"
ext=$("$PREFIX/bin/php-config" --extension-dir)
if [ -f "$ext/opcache.so" ]; then
  echo "zend_extension=opcache" > "/etc/php/$V/mods-opcache.ini"
  for sapi in cli fpm; do cp "/etc/php/$V/mods-opcache.ini" "/etc/php/$V/$sapi/conf.d/10-opcache.ini"; done
  rm -f "/etc/php/$V/mods-opcache.ini"
fi
cat > "/etc/php/$V/fpm/php-fpm.conf" <<CONF
[global]
pid = /run/php/php$V-fpm.pid
error_log = /var/log/php$V-fpm.log
;process_control_timeout = 0
include=/etc/php/$V/fpm/pool.d/*.conf
CONF
ln -sf "$PREFIX/bin/php" "/usr/bin/php$V"
ln -sf "$PREFIX/bin/phpize" "/usr/bin/phpize$V"
ln -sf "$PREFIX/bin/php-config" "/usr/bin/php-config$V"
ln -sf "$PREFIX/sbin/php-fpm" "/usr/sbin/php-fpm$V"

# phpredis is the one extension Laravel apps commonly need that PHP does not ship.
say "Building the redis extension"
redis=$(curl -fsSL --retry 5 "https://pecl.php.net/rest/r/redis/stable.txt")
[[ "$redis" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "unexpected phpredis version: $redis" >&2; exit 1; }
curl -fsSL --retry 5 -o "$SRC/redis.tgz" "https://pecl.php.net/get/redis-$redis.tgz"
mkdir "$SRC/redis" && tar -xzf "$SRC/redis.tgz" -C "$SRC/redis" --strip-components=1 && cd "$SRC/redis"
"$PREFIX/bin/phpize" >/dev/null && ./configure --with-php-config="$PREFIX/bin/php-config" >"$SRC/redis-configure.log" 2>&1 \
  && make -j"$(nproc)" >"$SRC/redis-make.log" 2>&1 && make install >/dev/null 2>&1 \
  || { tail -n 20 "$SRC/redis-make.log" 2>/dev/null; echo "the redis extension did not build" >&2; exit 1; }
for sapi in cli fpm; do echo "extension=redis" > "/etc/php/$V/$sapi/conf.d/20-redis.ini"; done

if [ -d /run/systemd/system ]; then
  echo "d /run/php 0755 root root -" > /etc/tmpfiles.d/cloud-manager-php.conf
  systemd-tmpfiles --create /etc/tmpfiles.d/cloud-manager-php.conf
  cat > "/etc/systemd/system/php$V-fpm.service" <<UNIT
[Unit]
Description=The PHP $V FastCGI Process Manager (built by cloud-manager)
After=network.target

[Service]
Type=notify
Environment=PHP_INI_SCAN_DIR=/etc/php/$V/fpm/conf.d
ExecStart=/usr/sbin/php-fpm$V --nodaemonize --fpm-config /etc/php/$V/fpm/php-fpm.conf -c /etc/php/$V/fpm/php.ini
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
  systemctl enable "php$V-fpm.service" >/dev/null 2>&1 || true
fi

echo "$FULL" > "$PREFIX/.cloud-manager-build"
say "PHP $FULL is built"
