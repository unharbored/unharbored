# ADR 0007: A Go session gateway holds agent connections

Status: accepted and built (`internal/gateway`, `cmd/gateway`, `app/Gateway`).
Proven on a real Droplet 2026-11-05.
Date: 2026-11-05

## The problem

ADR 0001 says agents open an outbound mTLS connection to the control plane
and hold it. ADR 0002 says the control plane is Laravel. Both are settled, and
together they do not work.

PHP-FPM serves a request and exits. It cannot hold a connection open between
requests, let alone thousands of them, and nothing about Laravel changes that.
The enrollment endpoint is fine: it is an ordinary HTTPS request and the live
test proves it works. The session is not a request. It is a long-lived
bidirectional stream carrying heartbeats, commands, progress and results, and
a node keeps it open for its entire life.

So the control plane as it stands can enroll an agent and then never speak to
it again. That is the gap.

It was not visible until now because `internal/cpd` existed and spoke the
protocol, and it was labelled a test fixture, which made it easy to read past.

## Options

**A. Laravel Octane with Swoole.** Octane keeps the application resident
between requests, and Swoole underneath it can run raw TCP servers. But Octane
exposes an HTTP server, not a socket server, so this means writing Swoole
server code beside Octane rather than through it. Asynchronous PHP with mTLS
client verification at scale is a niche skill to hire for and an unusual thing
to operate, and the memory cost per idle connection is high.

**B. ReactPHP or AMPHP.** A pure-PHP event loop that can do TLS. Same
objections, plus single-threaded execution means several processes and a way
to route a command to whichever process holds that node. Realistically caps
out well below what is wanted, and the failure mode at the cap is agents
unable to reconnect.

**C. A Go session gateway.** A small service that holds every agent
connection and relays between them and Laravel. Laravel keeps everything it
is good at: tenancy, credentials, audit, the state machine, provisioning, the
API and the UI. The gateway does the one thing PHP cannot.

**D. Agents poll over HTTP.** Makes the whole system PHP-native and discards
ADR 0001's push channel. Command latency becomes the poll interval, deploy
log streaming needs a second mechanism, and the connection churn at scale is
worse rather than better. ADR 0001 already rejected this and nothing has
changed.

## Decision

Option C, and it is less new work than it sounds.

**The gateway already exists.** `internal/cpd` is a complete implementation of
the server half of the protocol: mTLS listener, client certificate
verification against the organisation CA, session registry, command dispatch
with correlated acks, progress and results. It is tested end to end against a
real agent. Calling it a test fixture was accurate about its intent and
misleading about its capability.

What is missing is not the gateway. It is the boundary between the gateway and
Laravel:

- **Laravel to gateway.** "Send this command to node X, tell me what happened."
  An HTTP call from Laravel to the gateway.
- **Gateway to Laravel.** Node connected, node disconnected, command result,
  progress line, metrics sample, log batch. HTTP callbacks, or a queue for the
  high-volume ones.
- **Which gateway holds which node.** With one gateway instance this is a map
  in memory. With several it needs a shared registry, most likely Redis, and
  Laravel needs to route to the right instance.
- **Authentication between them.** The gateway acts on behalf of the control
  plane, so the link needs mTLS of its own or a shared secret, and it should
  not be reachable from outside.
- **Certificate distribution.** The gateway verifies agent certificates, so it
  needs each organisation's CA certificate. The CA *private* key stays in the
  vault and never reaches the gateway, which only ever verifies.

This also sharpens ADR 0002. The accurate statement is not "the control plane
is Laravel" but "the control plane's business logic is Laravel and its
connection layer is Go". The split falls along a real seam: request-shaped
work in PHP, connection-shaped work in Go.

## Consequences

**Two deployables instead of one.** The honest cost. It means two things to
build, release, monitor and roll back, and a version skew question whenever
the protocol changes.

**A failure mode that did not exist.** If the gateway is down, every node is
unmanaged while continuing to serve traffic perfectly. That is the same
failure as a node losing its connection, which ADR 0001 already accepts as
survivable, but it is now a single point rather than a per-node one.

**The protocol becomes a genuine three-party contract.** Agent, gateway and
Laravel. `cmd/conformance` already exists for exactly this reason and will
need to cover the gateway boundary too.

**Go stops being only the agent.** ADR 0003's zero-dependency rule binds the
agent. The gateway is server-side and may take dependencies, but it shares
`internal/protocol`, `internal/transport` and `internal/pki` with the agent,
so in practice it will stay close to dependency-free by inheritance.

**Operationally it is small.** One Go binary, no database, holding sockets and
forwarding JSON. It should be the least interesting component to run.

## What this does not decide

Whether the gateway is one process or several, and what the shared registry
is, are deferred until there is more than one. A single gateway is sufficient
well past the point where anything else here is proven.

## Open question

The alternative worth weighing seriously is D, polling,
because it keeps the system to one deployable and one language. The reason to
reject it is latency and log streaming, and if those turn out to matter less
than operational simplicity, D is defensible and this decision should be
revisited before the gateway boundary is built rather than after.
