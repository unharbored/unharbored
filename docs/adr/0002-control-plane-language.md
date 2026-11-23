# ADR 0002: The control plane is Laravel, and the agent does not care

Status: resolved, Laravel
Date: 2026-11-04
Resolved: 2026-11-04

## Context

PLAN.md specifies a Laravel control plane: the team is fluent in it, the
product serves Laravel developers, and dogfooding is a genuine feature.

The machine this was built on has no PHP and no Composer, and its Docker
daemon is not running. A Laravel control plane could have been written but not
built, run, or tested. Writing a large volume of unverified PHP would have
produced something that looks like progress and is not.

## Decision

Defer the decision, and keep it cheap to make.

The node side was built in full, in Go, where every line compiles and is
tested. A Go reference control plane (`internal/cpd`) implements the server
half of the protocol so the agent can be exercised end to end. It is labelled
a development and testing tool: no database, no tenancy, no authorisation, no
audit log, and a fresh CA on every start.

Nothing in the node implementation constrains the control plane language:

- The wire format is JSON inside a length-prefixed frame. Any language can
  speak it; see `docs/specs/protocol.md`.
- The PKI uses ECDSA P-256 rather than Ed25519 specifically so PHP OpenSSL can
  sign certificates without difficulty. See ADR 0004.
- Enrollment is an HTTPS POST with a JSON body.
- The enrollment store is an interface with an in-memory implementation. A
  database-backed one is the only production requirement.

## Consequences

- Phase 0 is not complete. The credential vault, audit log, DigitalOcean API
  client and resource state machine all live in the control plane and are not
  built. PLAN.md calls the first two long poles that cannot be retrofitted
  cheaply, so they are the next work.
- The reference server must not drift into being the real one. It is a test
  fixture and its package documentation says so.
- If Go wins, the reference server becomes the starting point, and the
  protocol, PKI and command types become one shared package set. That removes
  a whole class of bug where the two ends disagree about the wire format.
- If Laravel wins, PHP 8.3 and Composer need installing, and the control plane
  reimplements the protocol against the specification.

## Resolution

Laravel, confirmed by Matt on 2026-11-04. PHP 8.3.17 turned out to be
available in WSL, so the control plane is built and tested there by the same
mechanism the Go suite uses: scripts/test-php.sh syncs source into an ext4
working directory and runs it, because Composer and PHPUnit on the Windows
drive mount are unusable (9.5 minutes to create the project, 27 seconds for
two trivial tests, against under a second warm).

Laravel 13.40 under control-plane/. The reference Go server remains a test
fixture and has not grown into the real thing.

The predicted cost arrived on schedule. PHP's native OpenSSL binding cannot
override a CSR subject when signing, which is exactly the property the PKI
depends on, so the control plane uses phpseclib. See the addendum to ADR 0004.
cmd/conformance exists to keep the two implementations honest about the wire.
