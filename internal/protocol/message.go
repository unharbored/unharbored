// Package protocol defines the wire format spoken between the control plane
// and node agents.
//
// The transport is a single long-lived mTLS connection opened outbound by the
// agent. Both sides may originate messages at any time, so the protocol is
// symmetric rather than request/response. Correlation is by message ID: a
// reply carries ReplyTo set to the ID of the message it answers.
//
// Encoding is length-prefixed JSON. See codec.go for the framing. JSON was
// chosen over protobuf deliberately: it keeps the agent free of third-party
// dependencies (see docs/adr/0003-zero-dependency-agent.md) and makes the
// channel debuggable with standard tooling. The message volume on this
// channel is low enough that the encoding cost is irrelevant.
package protocol

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"time"
)

// Version is the wire protocol version. The agent sends it in Hello and the
// control plane rejects versions it cannot speak.
const Version = 1

// Type identifies the kind of message carried in an Envelope.
type Type string

const (
	// TypeHello is the first message on every connection, sent by the agent.
	TypeHello Type = "hello"
	// TypeHelloAck is the control plane's acceptance of a Hello.
	TypeHelloAck Type = "hello_ack"

	// TypePing and TypePong keep the connection alive and measure latency.
	// Either side may originate a Ping.
	TypePing Type = "ping"
	TypePong Type = "pong"

	// TypeCommand instructs the agent to perform work. Control plane to agent.
	TypeCommand Type = "command"
	// TypeCommandAck confirms the agent accepted a Command for execution.
	// It does not indicate the work finished.
	TypeCommandAck Type = "command_ack"
	// TypeProgress carries incremental output while a Command runs.
	TypeProgress Type = "progress"
	// TypeResult reports the terminal outcome of a Command.
	TypeResult Type = "result"

	// TypeMetrics carries a host metrics sample. Agent to control plane.
	TypeMetrics Type = "metrics"
	// TypeLogBatch carries a batch of log lines. Agent to control plane.
	TypeLogBatch Type = "log_batch"

	// TypeTerminal drives an interactive shell: open, input, resize, close.
	// Control plane to agent.
	TypeTerminal Type = "terminal"
	// TypeTerminalOut carries a shell's output, and its exit. Agent to
	// control plane.
	TypeTerminalOut Type = "terminal_out"

	// TypeError reports a protocol-level failure. It is terminal for the
	// message it references but not necessarily for the connection.
	TypeError Type = "error"
)

// Envelope wraps every message on the wire.
type Envelope struct {
	// V is the protocol version of this message.
	V int `json:"v"`
	// ID uniquely identifies this message within the connection.
	ID string `json:"id"`
	// ReplyTo, when set, is the ID of the message this one answers.
	ReplyTo string `json:"reply_to,omitempty"`
	// Type selects how Payload is interpreted.
	Type Type `json:"type"`
	// TS is the sender's clock in Unix milliseconds. Advisory only; never
	// used for ordering or correlation, because node clocks drift.
	TS int64 `json:"ts"`
	// Payload is the type-specific body.
	Payload json.RawMessage `json:"payload,omitempty"`
}

// NewEnvelope builds an Envelope around a payload, assigning a fresh ID.
func NewEnvelope(t Type, payload any) (*Envelope, error) {
	id, err := NewID()
	if err != nil {
		return nil, fmt.Errorf("protocol: generate message id: %w", err)
	}

	env := &Envelope{
		V:    Version,
		ID:   id,
		Type: t,
		TS:   time.Now().UnixMilli(),
	}

	if payload != nil {
		body, err := json.Marshal(payload)
		if err != nil {
			return nil, fmt.Errorf("protocol: marshal %s payload: %w", t, err)
		}
		env.Payload = body
	}

	return env, nil
}

// Reply builds an Envelope answering this one.
func (e *Envelope) Reply(t Type, payload any) (*Envelope, error) {
	reply, err := NewEnvelope(t, payload)
	if err != nil {
		return nil, err
	}
	reply.ReplyTo = e.ID
	return reply, nil
}

// DecodePayload unmarshals the envelope payload into dst.
func (e *Envelope) DecodePayload(dst any) error {
	if len(e.Payload) == 0 {
		return fmt.Errorf("protocol: %s envelope has no payload", e.Type)
	}
	if err := json.Unmarshal(e.Payload, dst); err != nil {
		return fmt.Errorf("protocol: decode %s payload: %w", e.Type, err)
	}
	return nil
}

// NewID returns a random 128-bit identifier as hex.
func NewID() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", err
	}
	return hex.EncodeToString(b[:]), nil
}

// Hello is the agent's opening message. The control plane already knows the
// node identity from the client certificate; Hello carries the mutable facts
// that identity does not cover.
type Hello struct {
	// NodeID must match the common name in the agent's client certificate.
	// The control plane verifies this and closes the connection on mismatch,
	// which catches a misprovisioned agent early.
	NodeID string `json:"node_id"`
	// AgentVersion is the agent build identifier.
	AgentVersion string `json:"agent_version"`
	// OS and Arch describe the host, e.g. "linux" and "amd64".
	OS   string `json:"os"`
	Arch string `json:"arch"`
	// Hostname as the node sees itself.
	Hostname string `json:"hostname"`
	// BootID changes on every host boot. The control plane uses a change here
	// to distinguish a reconnect from a reboot.
	BootID string `json:"boot_id,omitempty"`
	// StartedAt is when this agent process started, Unix milliseconds.
	StartedAt int64 `json:"started_at"`
}

// HelloAck accepts a Hello.
type HelloAck struct {
	// ServerVersion identifies the control plane build.
	ServerVersion string `json:"server_version"`
	// HeartbeatSeconds tells the agent how often to Ping when otherwise idle.
	HeartbeatSeconds int `json:"heartbeat_seconds"`
	// ResumeFrom, when non-empty, is the ID of the last command the control
	// plane believes is outstanding, letting the agent skip redundant work
	// after a reconnect.
	ResumeFrom string `json:"resume_from,omitempty"`
}

// CommandKind names a unit of work the agent knows how to perform.
type CommandKind string

const (
	// CommandApplyRecipe applies a declarative configuration recipe.
	CommandApplyRecipe CommandKind = "apply_recipe"
	// CommandDeploy runs a release deployment.
	CommandDeploy CommandKind = "deploy"
	// CommandActivateRelease swaps the current symlink to a prepared release.
	// Split from CommandDeploy so the control plane can coordinate the swap
	// across several nodes once every one of them has prepared successfully.
	CommandActivateRelease CommandKind = "activate_release"
	// CommandRollback activates a previous release.
	CommandRollback CommandKind = "rollback"
	// CommandExec runs a single allow-listed command.
	CommandExec CommandKind = "exec"
	// CommandCollectMetrics requests an immediate metrics sample.
	CommandCollectMetrics CommandKind = "collect_metrics"
	// CommandPruneReleases removes old releases of a site, never the current
	// one or the rollback target.
	CommandPruneReleases CommandKind = "prune_releases"
)

// Command instructs the agent to perform work.
type Command struct {
	// Kind selects the handler.
	Kind CommandKind `json:"kind"`
	// Args is the handler-specific argument object.
	Args json.RawMessage `json:"args,omitempty"`
	// TimeoutSeconds bounds execution. Zero means the handler default.
	TimeoutSeconds int `json:"timeout_seconds,omitempty"`
	// IdempotencyKey lets the agent recognise a command it has already run,
	// which matters because the control plane retries on connection loss.
	IdempotencyKey string `json:"idempotency_key,omitempty"`
}

// CommandAck confirms receipt. Accepted false means the agent refused the
// command outright and no Result will follow.
type CommandAck struct {
	Accepted bool   `json:"accepted"`
	Reason   string `json:"reason,omitempty"`
	// Duplicate is true when IdempotencyKey matched a completed command. The
	// cached Result follows immediately.
	Duplicate bool `json:"duplicate,omitempty"`
}

// ProgressStream names an output stream within a running command.
type ProgressStream string

const (
	StreamStdout ProgressStream = "stdout"
	StreamStderr ProgressStream = "stderr"
	// StreamStatus carries agent-generated narration rather than child
	// process output, e.g. "linking shared paths".
	StreamStatus ProgressStream = "status"
)

// Progress carries incremental output from a running command.
type Progress struct {
	Stream ProgressStream `json:"stream"`
	// Seq orders progress messages within one command, starting at 0. The
	// control plane uses it to detect gaps, since nothing guarantees that a
	// slow consumer saw every frame.
	Seq  int    `json:"seq"`
	Text string `json:"text"`
}

// ResultStatus is the terminal state of a command.
type ResultStatus string

const (
	StatusSucceeded ResultStatus = "succeeded"
	StatusFailed    ResultStatus = "failed"
	StatusTimedOut  ResultStatus = "timed_out"
	StatusCancelled ResultStatus = "cancelled"
)

// Result reports the outcome of a command.
type Result struct {
	Status ResultStatus `json:"status"`
	// ExitCode is set for commands that wrap a process.
	ExitCode int `json:"exit_code,omitempty"`
	// Error is a human-readable failure description.
	Error string `json:"error,omitempty"`
	// Detail is a handler-specific result object, e.g. the set of recipe
	// steps applied or the release that was activated.
	Detail json.RawMessage `json:"detail,omitempty"`
	// DurationMS is wall-clock execution time.
	DurationMS int64 `json:"duration_ms"`
}

// MetricSample is one host metrics observation.
type MetricSample struct {
	// CollectedAt is Unix milliseconds on the node.
	CollectedAt int64 `json:"collected_at"`
	// CPUPercent is total CPU utilisation across all cores, 0 to 100.
	CPUPercent float64 `json:"cpu_percent"`
	// Load1, Load5 and Load15 are load averages.
	Load1  float64 `json:"load_1"`
	Load5  float64 `json:"load_5"`
	Load15 float64 `json:"load_15"`
	// Memory figures are bytes.
	MemTotal     uint64 `json:"mem_total"`
	MemAvailable uint64 `json:"mem_available"`
	// SwapTotal and SwapUsed are bytes.
	SwapTotal uint64 `json:"swap_total"`
	SwapUsed  uint64 `json:"swap_used"`
	// Disks is keyed by mount point.
	Disks map[string]DiskUsage `json:"disks,omitempty"`
	// UptimeSeconds since boot.
	UptimeSeconds uint64 `json:"uptime_seconds"`
}

// DiskUsage describes one filesystem, in bytes.
type DiskUsage struct {
	Total uint64 `json:"total"`
	Free  uint64 `json:"free"`
}

// LogLine is a single shipped log record.
type LogLine struct {
	// Source names the origin, e.g. "nginx.access", "laravel", "deploy".
	Source string `json:"source"`
	// TS is Unix milliseconds.
	TS int64 `json:"ts"`
	// Text is the line, already redacted by the agent.
	Text string `json:"text"`
}

// LogBatch carries several log lines in one frame.
type LogBatch struct {
	Lines []LogLine `json:"lines"`
	// Dropped counts lines discarded by backpressure since the last batch.
	// Reporting the number is what keeps a quiet log from being mistaken for
	// a healthy one.
	Dropped int `json:"dropped,omitempty"`
}

// Terminal operations.
const (
	TerminalOpen   = "open"
	TerminalInput  = "input"
	TerminalResize = "resize"
	TerminalClose  = "close"
)

// Terminal is one instruction for an interactive shell. Session names the
// shell, so one node can hold several.
type Terminal struct {
	Session string `json:"session"`
	Op      string `json:"op"`
	// User and Dir apply to open. User must be a real account; the shell runs
	// as it, with its home as the directory unless Dir is given.
	User string `json:"user,omitempty"`
	Dir  string `json:"dir,omitempty"`
	// Cols and Rows size the screen, for open and resize.
	Cols int `json:"cols,omitempty"`
	Rows int `json:"rows,omitempty"`
	// Data is what was typed, base64, for input.
	Data string `json:"data,omitempty"`
}

// TerminalOut is what a shell wrote, or that it ended.
type TerminalOut struct {
	Session string `json:"session"`
	// Seq orders output, so a reader can tell it missed some.
	Seq int `json:"seq"`
	// Data is the output, base64.
	Data string `json:"data,omitempty"`
	// Exit is set once, when the shell ends, with its exit code.
	Exit *int `json:"exit,omitempty"`
	// Error says why a shell could not start.
	Error string `json:"error,omitempty"`
}

// ErrorCode classifies a protocol-level failure.
type ErrorCode string

const (
	ErrUnsupportedVersion ErrorCode = "unsupported_version"
	ErrIdentityMismatch   ErrorCode = "identity_mismatch"
	ErrMalformed          ErrorCode = "malformed"
	ErrUnknownType        ErrorCode = "unknown_type"
	ErrFrameTooLarge      ErrorCode = "frame_too_large"
	ErrInternal           ErrorCode = "internal"
)

// Error reports a protocol-level failure.
type Error struct {
	Code    ErrorCode `json:"code"`
	Message string    `json:"message"`
	// Fatal means the sender is closing the connection.
	Fatal bool `json:"fatal,omitempty"`
}
