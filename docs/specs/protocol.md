# Agent protocol specification

Version 1.

This is the contract between the control plane and a node agent. It is written
so a control plane in any language can implement the server half. The Go
implementation lives in `internal/protocol` and `internal/transport`.

## Transport

A single long-lived TCP connection carrying TLS 1.3, opened outbound by the
agent. There is no fallback to earlier TLS versions: both ends are software we
ship, so there is no legacy peer to accommodate.

- The agent presents a client certificate. The server requires and verifies it
  against the organisation CA.
- The agent verifies the server certificate against the same CA, pinned at
  enrollment, and checks the server name. The host root store is not consulted.
- Peer identity is the common name of the verified leaf certificate. Nothing a
  peer asserts about itself is used for identity.

## Framing

Each message is a 4-byte big-endian unsigned length followed by that many
bytes of UTF-8 JSON.

```
+--------+--------+--------+--------+----------------------+
|          length (uint32 BE)       |   JSON payload       |
+--------+--------+--------+--------+----------------------+
```

A frame larger than 8 MiB (`MaxFrameSize`) must be rejected and the connection
closed. Do not attempt to drain an oversized frame: the length cannot be
trusted. A zero-length frame is invalid.

## Envelope

Every message is an envelope.

```json
{
  "v": 1,
  "id": "6f1b3c5d9e2a4b8c1d0e7f3a5b6c8d9e",
  "reply_to": "a1b2...",
  "type": "command",
  "ts": 1793203822000,
  "payload": { }
}
```

| Field | Type | Notes |
|---|---|---|
| `v` | int | Protocol version. Required. |
| `id` | string | 128 bits of randomness, hex. Unique within the connection. Required. |
| `reply_to` | string | The `id` this message answers. Omitted for originating messages. |
| `type` | string | Selects the payload shape. Required. |
| `ts` | int | Sender clock, Unix milliseconds. Advisory only. |
| `payload` | object | Type-specific. Omitted for ping and pong. |

`ts` must never be used for ordering or correlation. Node clocks drift, and a
freshly booted Droplet may have a badly wrong clock until NTP settles.

An envelope with no `type` or no `id` is invalid.

## Message flow

The protocol is symmetric. Either side may originate a message at any time.

### Connection establishment

```
agent                                control plane
  |-- TLS handshake, mutual auth ------->|
  |                                      |
  |-- hello ---------------------------->|
  |<------------------------- hello_ack -|
```

The agent sends `hello` immediately after the handshake. The control plane
must verify that `hello.node_id` equals the common name in the client
certificate, and close the connection on mismatch. Identity comes from the
certificate; `hello` carries only the mutable facts the certificate does not.

Until `hello` succeeds, the node must not be registered as connected and must
not be sent commands.

### Keepalive

Either side may send `ping`; the peer answers `pong` with `reply_to` set. The
agent pings on an idle connection at the interval advertised in
`hello_ack.heartbeat_seconds`.

Implementations should set a read deadline of roughly three heartbeat
intervals. Without one, a connection silently black-holed by a middlebox looks
healthy indefinitely.

A session implementation should answer `ping` itself rather than surfacing it
to application handlers.

### Commands

A command is a conversation, not a request and a reply. Four message types
share one correlation ID.

```
control plane                            agent
  |-- command (id=X) -------------------->|
  |<----------------- command_ack (→X) ---|
  |<----------------- progress   (→X) ----|
  |<----------------- progress   (→X) ----|
  |<----------------- result     (→X) ----|
```

Implementers should note that a one-shot request/reply helper is not enough
here. It consumes the ack and deregisters, after which progress and the result
are dropped. The Go implementation provides `Session.Subscribe` for this.

`command_ack` with `accepted: false` is terminal: no `result` follows.

`result` is always terminal.

### Telemetry

`metrics` and `log_batch` are originated by the agent without a `reply_to`.
Both are reserved in version 1; the agent does not yet emit them.

## Message types

### hello (agent to control plane)

```json
{"node_id": "node-123", "agent_version": "0.1.0", "os": "linux",
 "arch": "amd64", "hostname": "web-1", "boot_id": "...", "started_at": 1793203822000}
```

`boot_id` changes on host boot, letting the control plane distinguish a
reconnect from a reboot.

### hello_ack (control plane to agent)

```json
{"server_version": "1.0.0", "heartbeat_seconds": 30, "resume_from": ""}
```

### command (control plane to agent)

```json
{"kind": "apply_recipe", "args": { }, "timeout_seconds": 600,
 "idempotency_key": "deploy-42"}
```

Kinds: `apply_recipe`, `deploy`, `activate_release`, `rollback`, `exec`,
`collect_metrics`.

`deploy` and `activate_release` are separate so a multi-node deploy can stage
every node before switching any of them.

**`idempotency_key` is not optional in practice.** The control plane retries
commands across reconnects. An agent that has already completed a command with
a given key must return the cached result without re-executing. Release
activation and database migrations are both unsafe to repeat.

### command_ack (agent to control plane)

```json
{"accepted": true, "duplicate": false, "reason": ""}
```

`duplicate: true` means the idempotency key matched a completed command; the
cached `result` follows immediately.

### progress (agent to control plane)

```json
{"stream": "status", "seq": 0, "text": "create: directory /srv/app"}
```

`stream` is `stdout`, `stderr` or `status`. `seq` starts at 0 per command and
lets the receiver detect gaps, because nothing guarantees a slow consumer saw
every frame.

### result (agent to control plane)

```json
{"status": "succeeded", "exit_code": 0, "error": "", "detail": { }, "duration_ms": 1234}
```

`status` is `succeeded`, `failed`, `timed_out` or `cancelled`. `detail` is a
command-specific object; see `internal/commands`.

### error

```json
{"code": "identity_mismatch", "message": "...", "fatal": true}
```

Codes: `unsupported_version`, `identity_mismatch`, `malformed`,
`unknown_type`, `frame_too_large`, `internal`. `fatal: true` means the sender
is closing the connection.

## Enrollment

Enrollment is the one unauthenticated exchange and is out of band from the
session protocol: an HTTPS POST to `/enroll`, server-authenticated only,
against the CA delivered by cloud-init alongside the token.

Request:

```json
{"token": "...", "csr_pem": "...", "agent_version": "0.1.0",
 "hostname": "web-1", "os": "linux", "arch": "amd64"}
```

Response:

```json
{"node_id": "node-123", "org_id": "org-1", "cert_pem": "...", "ca_pem": "...",
 "control_plane_addr": "cp.example:9443", "not_after": "2026-12-04T00:00:00Z"}
```

Rules the server must follow:

1. **The token is single use.** Consumption must be atomic; cloud-init retries.
2. **Store only a hash of the token.** A database leak must not be replayable.
3. **The node identity comes from the grant, not the CSR.** The CSR subject is
   advisory and must be overwritten with the identity decided before the node
   existed. A node must not be able to choose what it is called.
4. **Verify the CSR signature** for proof of possession.
5. **A malformed CSR still burns the token.** Otherwise one token grants
   unlimited attempts and is no longer single use.
6. **Every failure returns one opaque error.** Unknown, expired and already
   used must be indistinguishable to the caller. The real reason belongs in
   the audit log.

The issued certificate must be client-auth only, so a compromised node cannot
impersonate the control plane to another node.

## Versioning

The control plane rejects a `hello` whose `v` it cannot speak, with
`unsupported_version`.

Within version 1, new message types and new payload fields are additive.
Receivers must ignore unknown fields and must not fail on an unknown message
type that arrives without a `reply_to`. Removing a field or changing its
meaning requires a version bump.
