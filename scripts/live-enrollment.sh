#!/usr/bin/env bash
#
# A real Go agent enrolling against the real Laravel control plane, over TLS,
# on Linux.
#
# Why this exists: until now the two codebases were only known to *agree*,
# via the fixtures in cmd/conformance. Agreement is not interoperation. This
# is the first time an actual agent binary talks to an actual control plane,
# and it exercises things no unit test can: the TLS handshake against a
# certificate the control plane minted, Go's JSON encoding meeting PHP's
# decoding, and a certificate issued by phpseclib being parsed by crypto/x509.
#
# It is still not a Droplet. Nothing here boots, runs cloud-init, or starts
# systemd. What it proves is that the enrollment handshake works end to end
# across the language boundary.
#
# Usage: scripts/live-enrollment.sh
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

if ! command -v wsl.exe >/dev/null 2>&1; then
  echo "This script drives WSL. On Linux, run the inner script directly." >&2
  exit 1
fi

win_path="$(pwd -W 2>/dev/null || pwd)"
wsl_src="$(wsl.exe -e wslpath -a "$win_path" | tr -d '\r')"

wsl.exe -e bash -lc "SRC='$wsl_src' bash '$wsl_src/scripts/live-enrollment-inner.sh'"
