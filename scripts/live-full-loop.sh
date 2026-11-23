#!/usr/bin/env bash
#
# Runs the whole stack live: Laravel control plane, Go gateway, Go agent.
#
# See scripts/live-full-loop-inner.sh for what it actually does and why. This
# wrapper just drives it inside WSL from a Windows host.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if ! command -v wsl.exe >/dev/null 2>&1; then
  echo "This script drives WSL. On Linux, run scripts/live-full-loop-inner.sh directly." >&2
  exit 1
fi

win_path="$(pwd -W 2>/dev/null || pwd)"
wsl_src="$(wsl.exe -e wslpath -a "$win_path" | tr -d '\r')"

wsl.exe -e bash -lc "SRC='$wsl_src' bash '$wsl_src/scripts/live-full-loop-inner.sh'"
