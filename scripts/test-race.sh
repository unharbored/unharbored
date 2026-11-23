#!/usr/bin/env bash
#
# Run the Go suite under the race detector, on Windows and on Linux.
#
# Why both: the race detector only reports races that actually occur during a
# run. It does not prove their absence, it samples. Different schedulers
# produce different interleavings, so a run on one platform is weaker evidence
# than runs on two, and a single pass is much weaker than several.
#
# That is also why the stress mode varies -cpu and repeats: most of the
# concurrency here is in internal/transport, where a session multiplexes
# heartbeats, progress streaming and command replies over one connection.
#
# Requirements:
#   Windows host  gcc on PATH (choco install mingw)
#   Linux in WSL  gcc and Go installed inside WSL; this script will say so if
#                 they are missing
#
# Usage:
#   scripts/test-race.sh              # one pass on both platforms
#   scripts/test-race.sh --stress     # repeated runs, varied parallelism
#   scripts/test-race.sh --windows    # Windows only
#   scripts/test-race.sh --linux      # Linux only
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

stress=0
run_windows=1
run_linux=1

for arg in "$@"; do
  case "$arg" in
    --stress) stress=1 ;;
    --windows) run_linux=0 ;;
    --linux) run_windows=0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

# Known mingw location from choco. Harmless if absent.
export PATH="/c/ProgramData/mingw64/mingw64/bin:$PATH"

status=0

if [ "$run_windows" = "1" ]; then
  echo "=== windows ==="
  if ! command -v gcc >/dev/null 2>&1; then
    echo "gcc not found on PATH. The race detector needs cgo." >&2
    echo "Install with: choco install mingw   (then open a new shell)" >&2
    status=1
  else
    gcc --version | head -1
    if [ "$stress" = "1" ]; then
      CGO_ENABLED=1 go test -race -count=10 -cpu=1,4,8 -timeout 900s ./internal/transport/ || status=1
      CGO_ENABLED=1 go test -race -count=5 -cpu=1,4,8 -timeout 900s ./internal/integration/ || status=1
      CGO_ENABLED=1 go test -race -count=3 -timeout 900s ./... || status=1
    else
      CGO_ENABLED=1 go test -race -count=1 -timeout 600s ./... || status=1
    fi
  fi
fi

if [ "$run_linux" = "1" ] && command -v wsl.exe >/dev/null 2>&1; then
  echo "=== linux ==="

  win_path="$(pwd -W 2>/dev/null || pwd)"
  wsl_src="$(wsl.exe -e wslpath -a "$win_path" | tr -d '\r')"

  # Build in ext4 rather than over the Windows mount, which is slow enough to
  # dominate the run.
  linux_cmd="
set -euo pipefail
export PATH=/usr/local/go/bin:\$PATH

if ! command -v gcc >/dev/null 2>&1; then
  echo 'gcc not installed in WSL. Install with: apt-get install -y gcc libc6-dev' >&2
  exit 1
fi
if ! command -v go >/dev/null 2>&1; then
  echo 'Go not installed in WSL. See scripts/test-race.sh for the tarball install.' >&2
  exit 1
fi

work=\$HOME/.cache/cloud-manager-go
mkdir -p \$work
rsync -a --delete --exclude build/ --exclude control-plane/ --exclude .git/ '$wsl_src/' \$work/
cd \$work
go version
"

  if [ "$stress" = "1" ]; then
    linux_cmd="$linux_cmd
CGO_ENABLED=1 go test -race -count=10 -cpu=1,4,8 -timeout 900s ./internal/transport/
CGO_ENABLED=1 go test -race -count=5 -cpu=1,4,8 -timeout 900s ./internal/integration/
CGO_ENABLED=1 go test -race -count=3 -timeout 900s ./...
"
  else
    linux_cmd="$linux_cmd
CGO_ENABLED=1 go test -race -count=1 -timeout 600s ./...
"
  fi

  wsl.exe -e bash -lc "$linux_cmd" || status=1
fi

if [ "$status" = "0" ]; then
  echo
  echo "race detector clean"
fi

exit $status
