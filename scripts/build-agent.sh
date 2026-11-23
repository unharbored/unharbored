#!/usr/bin/env bash
#
# Build the agent for the platforms a Droplet might run, and place the
# binaries where the control plane can serve them.
#
# The control plane publishes the SHA-256 of whatever is in that directory and
# embeds it in the cloud-init it generates, so rebuilding here is what makes a
# new agent reach new nodes. There is no separate publish step and nothing to
# keep in sync.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

out="control-plane/storage/agent"
mkdir -p "$out"

version="${AGENT_VERSION:-$(git rev-parse --short HEAD 2>/dev/null || echo dev)}"

echo "building agent $version"

for platform in linux-amd64 linux-arm64; do
  goos="${platform%%-*}"
  goarch="${platform##*-}"

  # -s -w strips the symbol table and DWARF. The agent ships to customer
  # machines, so smaller is better and nothing debugs it in place.
  GOOS="$goos" GOARCH="$goarch" CGO_ENABLED=0 go build \
    -ldflags "-s -w -X github.com/unharbored/unharbored/internal/agentcore.Version=$version" \
    -o "$out/cloud-manager-agent-$platform" \
    ./cmd/agent

  size="$(wc -c < "$out/cloud-manager-agent-$platform" | tr -d ' ')"
  sum="$(sha256sum "$out/cloud-manager-agent-$platform" | cut -d' ' -f1)"

  printf '  %-14s %8s bytes  %s\n' "$platform" "$size" "$sum"
done

echo
echo "Set AGENT_VERSION=$version in the control plane .env so the version it"
echo "reports matches what it is serving."
