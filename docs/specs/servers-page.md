# Servers: a list and a page per server

Status: **built (not yet run against real servers or looked at in a browser by the author).** Mockup: [mockups/servers.html](mockups/servers.html) (open it in a browser; click a row, a tab, a card).

## Why

`/servers` is one long page. Each server is a 277-line `NodeCard` stacking
stats, access, firewall, SSH keys, PHP, services and a row of buttons, with
Destroy beside Repair. There is no price anywhere, no server URL to link to, and
no history of what ran on a server. Laravel Cloud's console (compute, resources,
monitoring, settings) shows a better shape; we borrow the shape, not the look,
and keep every function we have.

## What we borrow, what we do not

- **Header line with chips**: provider and region, size, address, monthly cost, connection state.
- **Right-hand drawers** with tabs and a footer saying what it will cost, replacing forms that expand inline.
- **Tabs plus a time-range picker** for metrics.
- **Settings as labelled rows** (title, one-line description, control) and a Danger block at the bottom.
- Not copied: the network diagram, "Add resource" canvas, scale-to-zero, edge/CDN cards. Laravel Cloud has no servers; those are its compute model, not ours.

## Pages

**`/servers`: a list.** One row per server: name and state, provider and region, size and price, CPU/memory sparkline (last hour), application count, agent version, a ⋯ menu. Buttons: **Pools are grouped**: when any autoscale pool exists, its servers sit under a pool row (application, servers now of min-max, average CPU against the target, "open pool") and every other server under "Single servers"; with no pools there are no group rows. **New server** (a drawer, the current Add server form) and **Connect your own** (the current page). "Disconnected servers still billed" and "Retained application data" stay below as panels, shown only when they have rows.

**`/servers/{node}`: one server.** Header: name, state dot, chips, **Open console**, ⋯. Tabs:

| Tab | Holds | Comes from today |
|---|---|---|
| Overview | Plain cards: Compute, Firewall, Applications, Software. Compute, Firewall and Software open drawers. Recent activity (this server's operations). | stats, sites, `n.ops` |
| Metrics | Range picker (hour, day, 7 days). CPU and memory, disk. Network shown as "not collected yet". | `chart=1h/24h/7d`, `n.metrics` |
| Logs | The existing `/logs?server=`, embedded or linked. | Logs button |
| Commands | This server's console and operation history, **Open console**. | Console (per organisation, opt-in) |
| Access | Login user, sudo password reveal/rotate, lock down, SSH keys, Tailscale, firewall rules. | Access block |
| Settings | Name, agent upgrade, repair stack. Danger: Disconnect, Destroy (typed name). | button row |

**`/servers/pool/{id}`: one pool.** Same header pattern (name, provider and region, size, load balancer, cost now and at the maximum, the application). Tabs: Overview (Scaling and Busy hours cards that open drawers, the member servers table, Stop autoscaling in a danger block), Scaling history (each add and remove with its reason, and the "paused after 3 failures" banner with Resume only when it applies), Metrics (average CPU against the target line; server count over time is not recorded, so it is not charted), Logs. Member rows open the normal server page. The application's Servers & scaling tab keeps working and links here.

**Drawers** (Cancel and an action, footer shows the cost change where there is one): Scaling settings and Busy hours (pool), Compute (resize), Firewall (on/off, SSH list, web list, tailnet-only options), Software (PHP versions and their settings, services, containers), New server, Destroy (typed name), Console (opens as a drawer; a **Full screen** button in its header makes it cover the whole screen, the page behind it hidden, and back to the drawer again, and the choice is remembered per browser).

## Decisions made

- Drawers for editing, not inline forms.
- Overview is plain cards; a diagram of server, services and balancer is later, if ever.

## What this needs that does not exist

- **Scaling history** needed no new table: the scaler already writes each add and remove, with its reason, and each pause and resume, to the audit log. The one gap was a stranded server destroyed after never joining; that is now recorded too.
- **Pool grouping data**: `ServersPage::nodes` already knows pool membership (`site_nodes.autoscale_pool_id`, and the `pool` attribute on the server resource); the list adds the pool's application, min and max, and average CPU. Pool members keep today's rule: no Destroy or Disconnect, "stop autoscaling that application instead". "Open pool" goes to that application's Servers & scaling tab; a pool page of its own is not proposed.
- **Assumption to confirm**: "cluster" here means an autoscale pool. Managed database and cache clusters stay on the Databases page.
- **A route and controller per server** (`GET /servers/{node}`, tab as a query or sub-path). The node id has a dot in it, so it stays a path parameter, as today.
- **Price per server** in the list and chips: provider catalogs already carry prices; the node needs to remember its size so it can be shown.
- **Resize** (Compute drawer): not built for any provider. Ship the drawer read-only (size shown, "resize" absent) until it is.
- **Rename**: not possible while applications run on a server; Settings shows the field disabled with the reason until we decide it is worth building.
- **Per-server command history**: operations have a node; console history is not stored by design ("nothing typed is stored"), so Commands lists operations and says so.
- **Network metrics**: the agent does not report them.

## Built differently from the mockup

- Disk on the Metrics tab is a meter of the latest reading, not a line: the agent's samples keep disk, but only the newest is read.
- Logs is a tab that goes to the existing logs page filtered to this server (an application's logs for a pool).
- A console opens on the server itself; no application is needed. The session is recorded against the server (and the application, when opened from an application's Console page).
- Regions and sizes everywhere come from the provider's API (below).

## Regions and sizes are read from the provider

The built-in lists were a copy of what each provider sold when they were written. `CatalogCache` now keeps each provider's current regions and server sizes (with prices) for a day, with a month-long copy standing in if the provider cannot be asked and the built-in list below that. DigitalOcean, Hetzner, Vultr and Linode read it; AWS keeps its built-in list (its sizes need the account's credentials and region). The first page that needs a list after a day reads it; `providers:refresh-catalogs` (daily, `scripts/mgmt/files/cron`) keeps it warm. Sizes are shared-CPU only, cheapest first (the first is the default), 1 to 64 GB. Not done: a size is not checked against the region it is chosen for (DigitalOcean's own check still applies); the provider refuses it with its reason.

## Build order

1. Server page with Overview, Metrics and Settings; list page; routes; `ServersPage` split into list data and one-server data. The old card's actions move across unchanged (same endpoints), so no behaviour changes.
2. Drawers for Compute (read-only), Firewall, Software, New server, Destroy.
3. Access, Commands and Logs tabs.
4. Remove `NodeCard.vue` once nothing renders it.

## Tradeoffs

- More routes and pages than re-skinning the card. The cheaper alternative (tabs on the one page) gives no list, no server URL and keeps the 277-line component.
- Drawers hide a form until opened; the cost is a click, the gain is a page that reads as a summary.
- The list needs a CPU/memory sparkline per row, which means loading metrics for every server; use the cached last-hour series the page already reads.
