# ADR 0006: Build strategy follows the compute model

Status: accepted
Date: 2026-11-04

## Context

A deploy has to get application source onto a server, install dependencies,
and build frontend assets. There are two ways to do it and the question was
framed as a choice between them:

- **Build on the server.** The agent clones the repository and runs
  `composer install` and the asset build on the node. Simple, and what Forge
  does.
- **Build once, ship the result.** A builder produces a finished artifact,
  uploads it, and every node fetches the identical thing. Prevents version
  skew, and needs object storage plus somewhere to build.

The framing was wrong. These are not competing answers to one question.

## Decision

**The build strategy is a property of an environment's compute model, not a
platform-wide choice.** Both are built, and the environment's compute model
selects between them.

| Compute model | Build strategy | Why |
|---|---|---|
| Fixed server, or a fixed pool behind a load balancer | Each server builds its own code | The set of servers is known and stable. Every one of them was provisioned with a build toolchain, and provisioning is a deliberate act. |
| Autoscale pool | Prebuilt image or artifact, fetched at boot | A node that boots at 03:00 has nobody to ask and no time to spend. It must come up already carrying the release. |

The second row is not a preference. A node joining an autoscale pool has to
reach a healthy state before the load balancer sends it traffic, and
`composer install` plus an asset build inside that window is neither fast nor
reliable. Worse, it would resolve dependencies independently, so a node that
scaled out on Tuesday could be running different package versions from one
that scaled out on Monday, with no deploy having happened in between.

So the rule is simple: **if the platform can create a server without a human
present, that server cannot be responsible for building its own code.**

## Consequences

**The environment model gains a compute mode** that determines more than
scaling behaviour. Choosing an autoscale pool implies artifact builds,
implies a build host, and implies object storage. The UI should present it
that way rather than offering build strategy as a separate toggle a user can
set into an invalid combination.

**Phase ordering is unchanged.** Phase 1 is a single fixed server, so it ships
server-side builds and nothing else. Artifact builds arrive with Phase 3 and
Phase 4, where multi-node and autoscaling make them necessary. PLAN.md §2.6
already described boot-time artifact pull for autoscale pools; this records
why it is the only option there rather than the faster one.

**The protocol needs no change.** `deploy` prepares a release directory and
`activate_release` makes it live. How the directory gets populated is an
implementation detail of the prepare step, which is why the two were split in
the first place.

**Two code paths to maintain, eventually.** Accepted, because the alternative
is one path that is wrong for one of the two cases. Forcing artifact builds on
a single-server customer adds object storage and a build host to a deployment
that needed neither; forcing server-side builds on an autoscale pool does not
work at all.

**Converting an environment between modes is a real operation.** Moving a
fixed server to an autoscale pool changes how it gets its code. That
conversion needs to exist and needs a pre-flight check, alongside the state
externalisation checks PLAN.md §2.4 already requires for the single-to-multi
node conversion.

## Open

Whether the autoscale tier ships a container image or a tarball plus a base
image is not settled. The tarball path reuses the Phase 3 artifact pipeline
and avoids a registry; the image path is the obvious fit if the DOKS tier in
Phase 8 lands first. Deferred until Phase 4, since nothing before then
depends on the answer.
