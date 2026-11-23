# Incus: servers on your own bare metal

Incus splits one machine you rent or own into many servers. Unharbored talks to
the machine's Incus API and creates each server as a system container with its
own public IPv6 address. There is no monthly price per server: the machine is
already paid for.

Use it when you have, or want, a big machine that is cheaper than many small
cloud servers. Use a cloud provider instead when you need managed databases,
load balancers or multi-server applications. Incus supplies servers only, so
an application on it is a single server with local services.

## What you need

| | Minimum | Comfortable |
|---|---|---|
| OS | Ubuntu 24.04 or 26.04, fresh | |
| CPU | 2 cores | 8 or more |
| Memory | 4 GB | 32 GB or more |
| Disk | 30 GB free on `/` | 100 GB or more, NVMe |
| Network | one public IPv4 and an IPv6 /64 on the same card | |
| KVM | not needed | lets servers run as VMs later |

Most providers give each machine an IPv6 /64 for free (HostHatch, Hetzner,
Vultr, OVH). Check it is there: `ip -6 addr` shows an address ending `/64`
with `scope global`. Unharbored's control plane must be able to reach the
machine on port 8443.

## 1. Set up the machine

On the machine, as root:

```
curl -fsSL https://<your control plane>/incus-host.sh | sudo bash
```

The exact command, with your control plane's address, is on Account ›
Providers › Incus. It takes a minute or two and prints a connection string at
the end:

```
Unharbored · Incus host setup
  ✓ Ubuntu 26.04 LTS, 8 cores, 31 GB memory, 106 GB free
  ✓ KVM available: servers can also run as VMs
  ✓ Incus 6.0.5 installed
  ✓ Storage pool "default" (80GiB)
  ✓ IPv4 out through NAT on incusbr0
  ✓ Public IPv6 for servers from 2605:4840:3:18ea::/64 on eth0
  ✓ Incus project "unharbored"
  ✓ API listening on 203.0.113.20:8443 (client certificates only)

Paste this into Unharbored, Account › Providers › Incus.
It works once and expires at 20:43 UTC (in 1 hour):

uh-incus:eyJ0b2tlbiI6…
```

Running it again is safe: every step checks first, and you get a new
connection string. Options:

- `--project <name>`: a second team on the same machine runs the script with
  its own project, so the two never see each other's servers.
- `--pool-size 200GiB`: the storage pool's size, on the first run only.
  Default: 80% of free disk, at most 500 GiB.

### What it changes on the machine

- Installs Incus, and QEMU when the CPU can run VMs.
- Creates the storage pool `default` (btrfs, a file on the root disk) and the
  bridge `incusbr0`, which gives servers IPv4 out through NAT.
- Turns on proxy NDP (`/etc/sysctl.d/90-incus-routed.conf`), so the machine
  answers for each server's address on its /64.
- Adds an AppArmor rule to the default profile so services inside a server
  can be stopped and reloaded. Without it, Ubuntu 26.04 with Incus 6.0 leaves
  systemd unable to manage nginx in a container.
- Opens the Incus API on port 8443. It accepts client certificates only.
- Checks ports 80 and 443 are free. The IPv4 ingress takes them.
- Creates the Incus project and a single-use trust token for it, valid for
  one hour.

It does not touch anything else: firewall rules and user accounts stay as they
are. A web server already on ports 80 or 443 stops the script, since the
ingress needs those ports.

## 2. Connect it

In Unharbored, open Account › Providers › Incus › Connect Incus…, paste the
connection string and press Connect.

What happens:

1. Unharbored checks the machine's certificate against the fingerprint in the
   string. If they differ it stops, and the token is never sent.
2. It makes its own key and spends the token to have the machine trust that
   key, for its project only. The key cannot see or touch anything else on
   the machine.
3. It keeps the key and the machine's certificate in the vault. From then on
   it accepts the machine only if it presents that same certificate.

The panel then shows the machine's name, address, project, IPv6 range, cores
and memory. Test connection checks the key still works.

### If connecting fails

| Message | What to do |
|---|---|
| That isn't an Incus connection string | Paste the whole line that starts with `uh-incus:` |
| Couldn't reach … | Check the machine is on and port 8443 is open to the control plane |
| The host answered with a different certificate | The machine was reinstalled, or something is in the way. Run the script again |
| The host refused the connection string | It was used already or is over an hour old. Run the script again |
| The host has no project … | Run the script again; it creates the project |

## 3. Create servers

Servers › New server › Provider: Incus. Pick a size; the drawer shows how much
memory and disk the machine has not yet given to servers.

| Size | CPU | Memory | Disk |
|---|---|---|---|
| small | 1 | 1 GB | 20 GB |
| medium | 2 | 2 GB | 40 GB |
| large | 2 | 4 GB | 60 GB |
| xlarge | 4 | 8 GB | 80 GB |

Each server is an Ubuntu 24.04 system container with:

- **a public IPv6 address** of its own, such as `2605:4840:3:18ea:1:c7c6:8bd9:8fb6`.
  It is worked out from the server's name, so it never changes;
- **IPv4 out** through the machine's NAT, for apt, git and the agent.

Unharbored then installs its agent and the server stack, exactly as on a cloud
server.

### How visitors reach a server

```
visitor with IPv4  ──▶  host's IPv4 :80 / :443  ──▶  incus-ingress  ──▶  server's IPv6
visitor with IPv6  ─────────────────────────────────────────────────────▶  server's IPv6
```

When you connect Incus, Unharbored creates one extra server on the host,
**incus-ingress**. It owns the host's IPv4 ports 80 and 443 (Incus proxy
devices, in NAT mode so visitors' addresses survive) and passes each connection
to the right server by the name the visitor asked for:

- **HTTPS** is routed on the name in the TLS handshake and never decrypted.
  Certificates stay on your servers, as on any other provider.
- **HTTP** is routed on the Host header, which carries Let's Encrypt's checks
  and the redirect to HTTPS.

Servers see the real visitor's address: the ingress sends it in a PROXY
header (HTTPS, on port 10443) or `X-Forwarded-For` (HTTP), and a server
believes those only from the ingress's own IPv6 address.

The routes follow your applications: adding or removing a domain, creating or
deleting an application, a preview or a server each rewrites them, and the
operation shows in the log as "Update the IPv4 ingress routes". There is
nothing to configure.

Domains on an Incus server get two DNS records: `A` to the host's IPv4 and
`AAAA` to the server's own IPv6. Free `*.sslip.io` names use the host's IPv4,
so they work for everyone.

Until the ingress has finished setting up (a few minutes after connecting),
new servers are reachable over IPv6 only, and the New server drawer says so.
The ingress appears on the Servers page as "Incus · IPv4 ingress for the
host"; leave it running.

Not carried by the ingress: HTTP/3 (browsers use HTTP/2 instead) and anything
that is not web traffic. Visitors with IPv6 reach servers directly and are
unaffected.

## Removing a machine

1. Destroy its servers in Unharbored, the ingress last. Disconnect is blocked
   while any remain.
2. Account › Providers › Incus › Disconnect. This deletes Unharbored's key
   from the vault.
3. On the machine, revoke the key there too:

   ```
   incus config trust list
   incus config trust remove <fingerprint>
   ```

## Limits

- **One machine per team.** A team connects one Incus host. Incus clustering
  can put several machines behind one connection.
- **Single-server applications.** No managed databases, load balancers or
  object storage; applications use local services on their server.
- **Containers share the machine's kernel.** Unharbored's stack runs fine in
  one. An application that needs its own kernel modules, or Docker inside,
  will need a VM, which is not offered yet.
- **Port 8443 is open to the internet,** guarded by client certificates. To
  narrow it, allow only the control plane's address in a firewall.

## Troubleshooting on the machine

```
incus list --project unharbored          # servers and their addresses
incus info <server> --project unharbored --show-log
incus exec <server> --project unharbored -- cloud-init status --long
ip -6 neigh show proxy                   # one line per server's IPv6 address
```

If systemd inside a server cannot stop or reload a service ("Failed to kill
control group … Permission denied"), the host is missing the AppArmor rule: run
the setup script again, then restart the server with `incus restart`.
