#!/usr/bin/env bash
# Unharbored · Incus host setup
#
# Turns a fresh Ubuntu 24.04 or 26.04 machine into an Incus host Unharbored can create servers on, then prints a
# one-time connection string to paste into Account › Providers › Incus. Every step checks first, so running it again
# is safe and only prints a new connection string.
#
#   curl -fsSL https://<control plane>/incus-host.sh | sudo bash
#   curl -fsSL https://<control plane>/incus-host.sh | sudo bash -s -- --project team-b
#
# What it changes on the host:
#   - installs Incus (and QEMU when the CPU can run VMs)
#   - a storage pool "default" (btrfs, a file on the root disk) and the NAT bridge "incusbr0" for IPv4 out
#   - sysctls for proxy NDP, so each server can answer on its own address from the host's IPv6 /64
#   - the Incus API on port 8443, which only accepts client certificates
#   - an AppArmor rule on the default profile so services inside containers can be stopped and reloaded
#   - it leaves ports 80 and 443 free: Unharbored's IPv4 ingress container takes them
#   - an Incus project (default "unharbored") and a single-use trust token for it, valid for one hour
set -euo pipefail

PROJECT="unharbored"
POOL_SIZE=""

usage() {
  cat <<'EOF'
Unharbored · Incus host setup

Turns a fresh Ubuntu 24.04 or 26.04 machine into an Incus host for Unharbored and prints a one-time connection
string for Account › Providers › Incus. Safe to run again.

Options:
  --project NAME     Incus project for this team's servers (default: unharbored). Another team on the same host
                     runs the script again with its own project.
  --pool-size SIZE   Size of the storage pool on first run, e.g. 200GiB (default: 80% of free disk, at most 500GiB).
  -h, --help         This help.
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --project) PROJECT="${2:-}"; shift 2 ;;
    --pool-size) POOL_SIZE="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1 (try --help)" >&2; exit 2 ;;
  esac
done

ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }

echo
echo "Unharbored · Incus host setup"

[ "$(id -u)" = 0 ] || die "Run this as root: curl ... | sudo bash"
[[ "$PROJECT" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]] || die "--project: lowercase letters, digits, dashes and underscores."

. /etc/os-release
case "${ID:-}:${VERSION_ID:-}" in
  ubuntu:24.04|ubuntu:26.04) ;;
  *) die "Needs Ubuntu 24.04 or 26.04; this is ${PRETTY_NAME:-unknown}." ;;
esac
CORES=$(nproc)
MEM_GB=$(( $(awk '/MemTotal/ {print $2}' /proc/meminfo) / 1024 / 1024 ))
FREE_GB=$(( $(df --output=avail -k / | tail -1) / 1024 / 1024 ))
[ "$CORES" -ge 2 ] || die "Needs at least 2 cores; this machine has $CORES."
[ "$MEM_GB" -ge 3 ] || die "Needs at least 4 GB of memory; this machine has ${MEM_GB} GB."
[ "$FREE_GB" -ge 30 ] || die "Needs at least 30 GB free on /; there is ${FREE_GB} GB."
ok "${PRETTY_NAME}, ${CORES} cores, ${MEM_GB} GB memory, ${FREE_GB} GB free"

# The public IPv6 /64 servers take their addresses from, and the card it is on.
PARENT=$(ip -6 route show default | awk '{for (i=1;i<NF;i++) if ($i=="dev") {print $(i+1); exit}}')
[ -n "$PARENT" ] || die "No IPv6 default route. Servers need a public IPv6 /64 from your provider."
ADDR=$(ip -6 -o addr show dev "$PARENT" scope global | awk '{print $4}' | grep '/64$' | head -1 || true)
[ -n "$ADDR" ] || die "No /64 on $PARENT. Ask your provider for an IPv6 /64 routed to this machine."
PREFIX=$(python3 -c 'import ipaddress,sys; print(ipaddress.ip_interface(sys.argv[1]).network)' "$ADDR")
PUBLIC_V4=$(ip -4 -o addr show dev "$PARENT" scope global | awk '{print $4}' | cut -d/ -f1 | head -1 || true)

export DEBIAN_FRONTEND=noninteractive
KVM=no
if [ -e /dev/kvm ] || grep -qE 'vmx|svm' /proc/cpuinfo; then KVM=yes; fi
if ! command -v incus >/dev/null; then
  apt-get update -qq >/dev/null
  apt-get install -y -qq incus btrfs-progs >/dev/null
fi
if [ "$KVM" = yes ]; then
  # Ubuntu's incus package does not pull in what VMs need.
  apt-get install -y -qq qemu-system-x86 qemu-system-modules-spice genisoimage >/dev/null 2>&1 || true
  ok "KVM available: servers can also run as VMs"
else
  ok "No KVM: servers run as containers"
fi
ok "Incus $(incus version 2>/dev/null | awk '/Server version/ {print $3}') installed"

# Proxy NDP lets the host answer for each server's address on the /64.
cat > /etc/sysctl.d/90-incus-routed.conf <<EOF
net.ipv6.conf.all.forwarding=1
net.ipv6.conf.all.proxy_ndp=1
net.ipv6.conf.${PARENT}.proxy_ndp=1
EOF
sysctl -q -p /etc/sysctl.d/90-incus-routed.conf

if ! incus storage show default >/dev/null 2>&1; then
  if [ -z "$POOL_SIZE" ]; then
    SIZE=$(( FREE_GB * 8 / 10 )); [ "$SIZE" -gt 500 ] && SIZE=500
    POOL_SIZE="${SIZE}GiB"
  fi
  incus admin init --preseed >/dev/null <<EOF
config: {}
networks:
- name: incusbr0
  type: bridge
  config:
    ipv4.address: auto
    ipv4.nat: "true"
    ipv6.address: none
storage_pools:
- name: default
  driver: btrfs
  config:
    size: ${POOL_SIZE}
profiles:
- name: default
  devices:
    root: {path: /, pool: default, type: disk}
    eth0: {name: eth0, network: incusbr0, type: nic}
EOF
fi
# Servers' IPv6 comes from their own routed card, so the bridge carries IPv4 only.
incus network set incusbr0 ipv6.address none 2>/dev/null || true
# Ubuntu 26.04's AppArmor labels a container's services differently from what its package scripts start, and the
# profile Incus 6.0 writes does not let the two signal each other: systemd then cannot stop or reload nginx.
incus profile set default raw.apparmor "signal, ptrace (read, trace),"
ok "Containers can manage their own services (AppArmor)"
ok "Storage pool \"default\" ($(incus storage get default size 2>/dev/null || echo 'btrfs'))"
ok "IPv4 out through NAT on incusbr0"
ok "Public IPv6 for servers from ${PREFIX} on ${PARENT}"

if ! incus project show "$PROJECT" >/dev/null 2>&1; then
  incus project create "$PROJECT" -c features.images=false -c features.profiles=false -c features.storage.volumes=false >/dev/null
fi
ok "Incus project \"${PROJECT}\""

# The IPv4 ingress, a container Unharbored creates, takes the host's ports 80 and 443.
if ss -tlnH '( sport = :80 or sport = :443 )' | grep -v incus | grep -q .; then
  die "Something on this machine already listens on port 80 or 443 ($(ss -tlnpH '( sport = :80 or sport = :443 )' | grep -o 'users:(("[^"]*' | head -1 | cut -d'"' -f2)). The IPv4 ingress needs both; stop it first."
fi
ok "Ports 80 and 443 free for the IPv4 ingress"

incus config set core.https_address :8443
incus config set core.remote_token_expiry 1H
ok "API listening on ${PUBLIC_V4:-this machine}:8443 (client certificates only)"

TOKEN=$(incus config trust add "unharbored-${PROJECT}-$(date +%s)" --restricted --projects "$PROJECT" -q </dev/null)
CONNECTION=$(printf '{"token":"%s","ipv6_prefix":"%s","parent":"%s","project":"%s"}' "$TOKEN" "$PREFIX" "$PARENT" "$PROJECT" | base64 -w0)

echo
echo "Paste this into Unharbored, Account › Providers › Incus."
echo "It works once and expires at $(date -u -d '+1 hour' '+%H:%M') UTC (in 1 hour):"
echo
echo "uh-incus:${CONNECTION}"
echo
echo "Another team on this host: rerun with --project <name>. Help: --help"
