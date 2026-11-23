#!/usr/bin/env bash
#
# Run the test suite on Linux from a Windows workstation, via WSL.
#
# Why this exists: the agent targets Linux, and several behaviours it depends
# on do not exist on Windows. Symlinks need elevation, POSIX permission bits
# are not enforced, and renaming over an existing symlink behaves differently.
# Running `go test` on Windows silently skips those tests, which hides real
# bugs. The repeated-rollback defect fixed in the deploy package was invisible
# on Windows and failed immediately here.
#
# Go does not need to be installed inside WSL. Test binaries are cross
# compiled on the host and executed in the Linux environment.
#
# Usage:
#   scripts/test-linux.sh            # all packages
#   scripts/test-linux.sh deploy     # one package
#   VERBOSE=1 scripts/test-linux.sh  # per-test output
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if ! command -v wsl.exe >/dev/null 2>&1; then
  echo "wsl.exe not found. This script is for Windows hosts with WSL2." >&2
  echo "On a Linux host just run: go test ./..." >&2
  exit 1
fi

out_dir="build/linux-tests"
rm -rf "$out_dir"
mkdir -p "$out_dir"

# Discover packages that actually contain tests.
if [ $# -gt 0 ]; then
  packages=()
  for name in "$@"; do
    packages+=("./internal/$name")
  done
else
  mapfile -t packages < <(go list ./... )
fi

built=()
for pkg in "${packages[@]}"; do
  name="$(basename "$pkg")"
  # go test -c writes nothing and exits 0 for a package with no test files.
  if GOOS=linux GOARCH=amd64 go test -c -o "$out_dir/$name.test" "$pkg" 2>/dev/null; then
    if [ -f "$out_dir/$name.test" ]; then
      built+=("$name")
    fi
  else
    echo "FAIL: could not build Linux test binary for $pkg" >&2
    exit 1
  fi
done

if [ ${#built[@]} -eq 0 ]; then
  echo "No test binaries were produced." >&2
  exit 1
fi

# Copy into the WSL filesystem before running. Executing from /mnt/c works but
# is slow, and more importantly tests create symlinks under TMPDIR, which must
# be a real Linux filesystem rather than the Windows drive mount.
win_path="$(pwd -W 2>/dev/null || pwd)"
wsl_src="$(wsl.exe -e wslpath -a "$win_path" | tr -d '\r')"

flags="-test.timeout 180s"
if [ "${VERBOSE:-0}" = "1" ]; then
  flags="$flags -test.v"
fi

wsl.exe -e bash -lc "
set -euo pipefail
work=\$(mktemp -d /tmp/cloud-manager-tests.XXXXXX)
trap 'rm -rf \$work' EXIT
cp '$wsl_src/$out_dir'/*.test \$work/
chmod +x \$work/*.test
status=0
for bin in \$work/*.test; do
  name=\$(basename \"\$bin\" .test)
  printf '=== %s\n' \"\$name\"
  if ! (cd \$work && ./\$(basename \"\$bin\") $flags); then
    status=1
  fi
done
exit \$status
"
