# ADR 0001: The agent connects outbound, instead of us connecting in over SSH

Status: accepted
Date: 2026-11-04

## Context

Laravel Forge, the closest comparable product, drives managed servers over
inbound SSH from its control plane. Forge's own documentation asks customers
to allow-list four fixed IP addresses for SSH access, plus a fifth for its
support jumpbox.

That model requires the control plane to hold a private key granting root on
every managed server, for every customer.

## Decision

Nodes run an agent that opens an outbound mTLS connection to the control plane
and holds it. The control plane never initiates a connection to a node, and
nothing listens on the node.

The agent is bootstrapped by cloud-init with a single-use enrollment token. It
generates its own keypair, which never leaves the machine, and receives a
short-lived client certificate whose common name is the node identity assigned
by the control plane.

## Consequences

**What this buys.**

- No long-lived SSH private keys at rest in our database. A control plane
  breach does not hand an attacker root on every customer server; it hands
  them a CA, which is bad but recoverable by rotation, and which can live in a
  KMS rather than application storage.
- No inbound port on the node. A provisioned Droplet can run a default-deny
  firewall with no SSH exception.
- Works behind NAT and egress-only networks.
- A push channel for metrics, logs and health comes free, where the SSH model
  needs polling.
- Deploy latency is not dominated by connection setup.

**What this costs.**

- We ship and maintain software on customer machines, including an upgrade
  path. Forge ships nothing.
- An agent bug can take a node offline from management. Mitigated by the agent
  being irrelevant to serving traffic: a node that loses its control plane
  connection keeps running the application perfectly well and is merely
  unmanaged.
- Bootstrap is more complex than adding an SSH key.

**Break-glass.** SSH remains available as an escape hatch and for provisioning
verification, but it is not the control channel and the control plane does not
depend on it.

## Amendment: SSH once, to bootstrap (providers.md, Custom)

A machine the user already has, with no cloud API, is connected by logging in
over SSH **once**: the control plane checks the machine, runs the same install
script a user would run by hand, and discards the login. The key or password is
held in the vault for the minutes the install takes, deleted when it ends and
by a sweep of anything older than an hour, and never enters a job payload, the
audit log or the operation log. SSH is not the control channel and is never used
again for that server; repair, deploy, upgrade and removal go through the agent.

## Alternatives considered

**Inbound SSH, as Forge does.** Simpler to build and a known quantity. The
credential custody profile is the problem: for a security firm to ship a
product whose central design requires hoarding root keys for customer
infrastructure is hard to defend, and PLAN.md identifies it as the single
largest risk in the system.

**Agent polling over HTTPS.** No persistent connection to manage and trivially
firewall-friendly. Rejected because command latency becomes the poll interval,
streaming deploy logs needs either long-polling or a second mechanism, and the
connection churn at scale is worse rather than better.

## Amendment: a console through the agent

The browser console (Commands tab) is a shell on a pseudo-terminal the agent
opens, as `deploy` or as root when asked for. It needs no SSH and no inbound
port: what is typed goes browser, control plane, gateway, agent, and the output
comes back the same way, on a private channel only the person who opened it can
join. Off until the organisation turns it on under Account. Each console is
recorded (who, which server, as whom, when it ended) and audited; what is typed
or shown is never stored. A console ends when closed, after 15 minutes without
typing, or when the agent loses its connection. It adds no reach the control
plane lacked, since `exec` already runs anything as root, but it puts that in a
browser session, which is why it is opt-in.
