#!/usr/bin/env bash
#
# Run the control plane test suite.
#
# Why this exists: development happens on Windows, but PHP lives in WSL and
# the repository lives on the Windows drive mount. Composer and PHPUnit touch
# thousands of small files, and DrvFs makes that pathological: creating the
# Laravel project took 9.5 minutes and two trivial tests took 27 seconds.
#
# So the source is synced into a persistent ext4 working directory and the
# suite runs there. Vendor stays in the working directory between runs, so
# only changed source files are copied. A full run drops from ~30s to ~2s.
#
# Usage:
#   scripts/test-php.sh                  # whole suite
#   scripts/test-php.sh --filter Vault   # pass through to artisan test
#   FRESH=1 scripts/test-php.sh          # reinstall dependencies first
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if [ ! -d control-plane ]; then
  echo "control-plane/ not found. Nothing to test." >&2
  exit 1
fi

if ! command -v wsl.exe >/dev/null 2>&1; then
  # Already on Linux. Run in place.
  cd control-plane
  exec php artisan test "$@"
fi

win_path="$(pwd -W 2>/dev/null || pwd)"
wsl_src="$(wsl.exe -e wslpath -a "$win_path" | tr -d '\r')"

fresh="${FRESH:-0}"
args="$*"

wsl.exe -e bash -lc "
set -euo pipefail
export COMPOSER_ALLOW_SUPERUSER=1
work=\$HOME/.cache/cloud-manager-control-plane
mkdir -p \$work

# Sync source only. Vendor and build output stay in the working directory so
# they survive between runs.
rsync -a --delete \
  --exclude vendor/ \
  --exclude node_modules/ \
  --exclude .git/ \
  --exclude storage/framework/cache/ \
  --exclude storage/framework/sessions/ \
  --exclude storage/framework/views/ \
  --exclude storage/logs/ \
  --exclude database/database.sqlite \
  '$wsl_src/control-plane/' \$work/

cd \$work

if [ '$fresh' = '1' ] || [ ! -d vendor ]; then
  echo '=== installing dependencies ==='
  \$HOME/bin/composer install --no-interaction --prefer-dist --quiet
fi

# A key is needed for the encrypter, which the vault depends on.
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --quiet
fi

mkdir -p database storage/framework/{cache,sessions,views} storage/logs
touch database/database.sqlite

php artisan test $args
"
