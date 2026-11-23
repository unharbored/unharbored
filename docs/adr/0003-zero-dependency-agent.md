# ADR 0003: The agent has no third-party dependencies

Status: accepted
Date: 2026-11-04

## Context

The agent runs as root on customer infrastructure. Every dependency it carries
is code we did not write, running with full privilege, on machines belonging
to people paying us partly for security assurance.

The Go module proxy is reachable from the build environment, so this is a
choice rather than a constraint.

## Decision

The agent, and every package it imports, uses only the Go standard library.
There is no `go.sum`, because there is nothing to verify.

Consequences of that rule in practice:

- The wire format is JSON via `encoding/json` rather than protobuf, which
  would need a third-party runtime and a code generator.
- Framing is a hand-written length prefix rather than a websocket library.
- Logging is `log/slog`, not zerolog or zap.
- Configuration is `flag`, not cobra or viper.
- Tests use the standard library, not testify.

## Consequences

**What this buys.**

- The supply chain attack surface of the privileged component is the Go
  standard library and nothing else.
- No dependency upgrade treadmill on software installed across a customer
  fleet, where upgrades are slow and partial.
- A single static binary with no runtime, so the cloud-init bootstrap is a
  download and a chmod.
- Build reproducibility is trivial.

**What this costs.**

- More code to write and own. The framing codec, the backoff, and the
  subscription routing are all things a library would have provided.
- JSON is larger and slower on the wire than protobuf. Irrelevant at the
  message volume this connection carries, and it makes the channel debuggable
  with standard tooling, which has already paid for itself.
- Verbose assertions without testify.

**Scope.** This binds the agent and anything it imports. It does not bind the
control plane, which runs on infrastructure we control and where the calculus
is different.

## Review trigger

Revisit if the agent needs a capability the standard library genuinely cannot
provide. Wanting a nicer API is not that.
