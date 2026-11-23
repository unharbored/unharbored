# Private servers with Tailscale

Status: **built, not run on a real server.** Answers: pasted tagged auth key first (OAuth later); tailnet only for SSH and web both offered, with the web option refused where an application has a certificate; Tailscale SSH stays off (`--ssh=false`). The key form checks the key's shape only; there is no live test of it.

## What the user wants

Some servers should be reachable only by certain people. Today the firewall
(`App\Lib\Servers\Firewall`) limits SSH and the web ports to listed addresses,
which fits an office with a fixed IP but not people who move around. Tailscale
fixes that: a server joins the organisation's tailnet, and who may reach it is
decided by that tailnet's own access rules, not by an address list here.

## What we do and do not own

- **We own:** installing Tailscale on a server, joining it to the tailnet, and
  closing the public ports so only the tailnet gets through.
- **We do not own:** the tailnet, its users, or its ACLs. People are added,
  removed and restricted in the Tailscale admin console. We say so in the UI
  and link to it; we do not mirror Tailscale's access model.

## Pieces

1. **One auth key per organisation**, stored in the vault like a provider key
   (Account → Providers → Tailscale). The user creates it in Tailscale as a
   *reusable, tagged* key (suggested tag `tag:cloud-manager`) and pastes it. A
   tagged key keeps joined servers out of any one person's account and lets
   the ACL name them. The key is never shown again after saving, every
   retrieval is audited, and the page tells the user to give it an expiry.
   Later, an OAuth client can replace the pasted key.
2. **Join** (an operation, a recipe step set): add Tailscale's apt repository,
   install `tailscale`, run `tailscale up --authkey=… --hostname=<server name>
   --ssh=false`, then read back `tailscale status --json` for the server's
   tailnet address (100.x.y.z) and name. Stored on the node (`nodes.tailnet`
   JSON: address, name, joined_at). The auth key is passed to the agent for
   that one step and never written to disk on the server beyond Tailscale's
   own state.
3. **Private mode** (a firewall setting beside the allow-lists): *web* and/or
   *SSH* may be set to "tailnet only". The ufw rules become: allow in on
   `tailscale0` for those ports, deny from everywhere else. The private
   network (10.0.0.0/8) still passes for load balancers and pools. Management is
   unaffected: the agent connects out, and the browser console goes through
   the agent.
4. **Leave**: `tailscale logout`, remove `nodes.tailnet`, and private mode is
   switched off first so the server is never left unreachable.

## Lockout safeguards

- Private mode cannot be turned on until the server reports **online** in the
  tailnet (`tailscale status` from the agent), checked just before the rule
  change.
- SSH "tailnet only" asks the user to confirm they can already reach the
  server's tailnet address from their own machine; we cannot check that.
- The management path (agent out to the gateway) never depends on the tailnet.
- Turning the firewall off or leaving the tailnet restores public access.

## Limits to say plainly

- A **public site cannot be private for some visitors only**: tailnet-only web
  means the whole application is invisible to everyone outside the tailnet,
  including Let's Encrypt. HTTP-01 certificate renewal would fail, so private
  web ports are refused for an application with a certificate until the
  certificate is DNS-validated (not built). Internal tools usually do not need
  a public certificate: Tailscale can serve its own.
- Servers behind a load balancer: the balancer reaches them over the private
  network, so private web works; the balancer itself stays public.
- Needs outbound access to Tailscale's coordination servers. Custom-provider
  servers behind strict egress may not join.

## Build order (each ships alone)

1. Account → Providers: save, test and remove the auth key (vault, audit,
   `connections` permission). Test calls Tailscale's API only if an API token
   is also given; otherwise it checks the key's shape.
2. Join and leave a server (operation, recipe, `nodes.tailnet`, shown on the
   server card).
3. Private mode for SSH, then for the web ports (ufw rules, safeguards,
   tests that parse the rule set the way `FirewallTest` does).

## Questions for Matt

1. Pasted tagged auth key first, with OAuth client later: agreed?
2. Should private mode cover SSH only at first (no certificate problem), with
   web after? Recommended.
3. Do you want Tailscale SSH (`--ssh`) offered? It replaces SSH keys for
   tailnet users but is a bigger change; recommended off for now.
