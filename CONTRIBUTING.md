# Contributing

Conventions and workflow for this repository.

## Before you start

Read [PLAN.md](PLAN.md) for the architecture, [ROADMAP.md](ROADMAP.md) for
what phase we are in, and the relevant ADR in [docs/adr/](docs/adr/) before
changing anything those decisions cover.

## Prerequisites

Agent: Go 1.23 or later, and nothing else. The agent has no third-party
dependencies and that is a hard rule, not a current state. See
[ADR 0003](docs/adr/0003-zero-dependency-agent.md). That rule binds the agent
only; the control plane may take dependencies.

Control plane: PHP 8.3 with sodium, openssl, mbstring and pdo_sqlite, plus
Composer.

## The workflow

```bash
gofmt -w ./internal/ ./cmd/
go vet ./...
go test ./...
bash scripts/test-linux.sh      # required on Windows, see below
GOOS=linux GOARCH=amd64 go vet ./...

bash scripts/test-php.sh        # control plane
bash scripts/test-race.sh       # race detector, both platforms
```

Run the race detector before touching anything in `internal/transport`. It is
the only place in the system where several goroutines share a connection, and
`--stress` repeats the concurrent packages with varied parallelism because a
single pass samples very few interleavings.

### On Windows, a green `go test ./...` does not mean the tests passed

The agent targets Linux. On Windows, symlinks need elevation and POSIX
permission bits are not enforced, so 14 deploy tests and several others skip
silently. The run reports success while the core of the deploy package never
executes.

`scripts/test-linux.sh` cross-compiles the test binaries and runs them under
WSL. Go does not need to be installed inside WSL.

This is not a theoretical concern. The repeated-rollback bug fixed in `e25a503`
was invisible on Windows and failed on the first Linux run. Rolling back twice
returned to the release you had just rolled back from.

```bash
bash scripts/test-linux.sh              # everything
bash scripts/test-linux.sh deploy       # one package
VERBOSE=1 bash scripts/test-linux.sh    # per-test output
```

Expected: **165 passing, 0 skipped.**

## Code conventions

**Comments explain why, not what.** The code says what it does. A comment
earns its place by recording a decision, a constraint, or a trap. Prefer one
good paragraph on a subtle function over a line on every field.

**Name tests after the property, not the method.** `TestTokenIsSingleUse`
beats `TestConsume`. A failing test name should tell you what broke about the
system, not which function returned the wrong value.

**Test the security property directly.** If the docs claim a node cannot
choose its own identity, there is a test that tries to choose one. Every row
in [docs/specs/security-model.md](docs/specs/security-model.md) names the test
backing it; if you add a control, add the row and the test.

**Comment the bug a test is pinning.** When a test exists because something
broke, say so. See `TestRollbackTwiceKeepsMovingBackwards` and
`TestReadDoesNotAliasPreviousFrame`.

**Errors wrap with context.** `fmt.Errorf("recipe: stat %s: %w", path, err)`.
Package prefix, what was attempted, the wrapped cause.

**Sentinel errors for conditions callers branch on.** Exported, named `ErrX`,
tested with `errors.Is`.

## Adding things

### A new command kind

1. Add the constant to `protocol.CommandKind` in `internal/protocol/message.go`.
2. Add argument and result types to `internal/commands`. Both ends share them.
3. Add a case to `agentcore.dispatch` and write the handler in
   `internal/agentcore/handlers.go`.
4. Add an end-to-end test in `internal/integration`.
5. Document it in `docs/specs/protocol.md`.

If the command is unsafe to run twice, it must honour `IdempotencyKey`. The
control plane retries across reconnects.

### A new recipe step type

1. Implement `recipe.Step` in `internal/recipe`. `Plan` must not mutate, and
   `Apply` on an already-converged system must return `ActionNoop`.
2. Add the type constant and a `build` case in `internal/recipe/spec.go`.
3. Test idempotency explicitly: apply twice, assert the second is a noop.
4. Anything that shells out goes through `CommandRunner` so it is testable
   without a host.

Adding a step type widens what a control plane can instruct a root agent to
do. That is deliberately a code change requiring a new agent, not a payload
change. See [ADR 0005](docs/adr/0005-recipe-engine-not-ansible.md).

### Anything that crosses the language boundary

If you change the wire protocol, token hashing, release id construction, or
any shared enum, regenerate the conformance fixtures and look at the diff:

```bash
go run ./cmd/conformance > control-plane/tests/fixtures/conformance.json
bash scripts/test-php.sh --filter Conformance
```

If regenerating makes the PHP tests fail, that is the fixture doing its job.
Work out which side is wrong before accepting the new values.

### A new protocol message type

Additive changes within version 1 are fine: new types, new optional fields.
Receivers ignore unknown fields and must not fail on an unknown message type
that arrives without a `reply_to`.

Removing a field or changing its meaning is a version bump.

## Commits

Explain why, and name what changed about the system's behaviour. If a commit
fixes a bug, say what the bug did, not just that it is fixed.

No AI attribution trailers.

## Where things are deliberately incomplete

Do not "fix" these without reading the reason first:

- **`mode_windows.go` is a no-op.** Windows has no POSIX permission bits.
  Tests asserting modes skip there rather than relying on it.
- **`internal/cpd` is not the control plane.** It is a test fixture. No
  database, no tenancy, no authorisation, no audit log. See
  [ADR 0002](docs/adr/0002-control-plane-language.md).
- **The `deploy` command does not fetch code.** It prepares a release
  directory. Getting source onto the node needs the artifact storage decision.
- **`pki.NeedsRenewal` is unused.** Renewal is implemented and tested but
  nothing calls it yet. Nodes expire after 30 days. Tracked in the security
  model gaps table.
- **`LocalKeyManager` holds the master key in config.** It is real
  authenticated encryption, not a placeholder. The `KeyManager` interface
  exists so a KMS is an implementation swap.
- **The control plane PKI signs certificates twice.** Not a mistake.
  phpseclib's `sign()` silently discards extensions set beforehand, and what
  it discards is the client-auth restriction. See the comment in
  `CertificateAuthority::issueNodeCertificate` before simplifying it.
- **Tenancy is a bare string.** `organization_id` is a string everywhere.
  There is no organisation model or user model yet.
- **Exec is unrestricted by default.** Not an oversight. The allow list it
  replaced restricted the server's owner while blocking nothing for an
  attacker holding the control plane, because recipe command steps were never
  allow-listed either. The mechanism is opt-in; see the note in
  [docs/specs/security-model.md](docs/specs/security-model.md).
