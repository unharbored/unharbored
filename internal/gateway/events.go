package gateway

import (
	"bytes"
	"context"
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/http"
	"sync"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
)

// NodeEvent reports a node connecting or disconnecting.
type NodeEvent struct {
	NodeID         string    `json:"node_id"`
	OrganizationID string    `json:"organization_id"`
	At             time.Time `json:"at"`
	AgentVersion   string    `json:"agent_version,omitempty"`
	Hostname       string    `json:"hostname,omitempty"`
	OS             string    `json:"os,omitempty"`
	Arch           string    `json:"arch,omitempty"`
	// Reason carries why a session ended, for disconnects.
	Reason string `json:"reason,omitempty"`
}

// ProgressEvent is one line of output from a running command.
type ProgressEvent struct {
	NodeID         string    `json:"node_id"`
	OrganizationID string    `json:"organization_id"`
	CommandID      string    `json:"command_id"`
	Stream         string    `json:"stream"`
	Seq            int       `json:"seq"`
	Text           string    `json:"text"`
	At             time.Time `json:"at"`
}

// MetricsEvent carries a host metrics sample.
type MetricsEvent struct {
	NodeID         string                `json:"node_id"`
	OrganizationID string                `json:"organization_id"`
	Sample         protocol.MetricSample `json:"sample"`
}

// LogsEvent carries a batch of shipped log lines.
type LogsEvent struct {
	NodeID         string            `json:"node_id"`
	OrganizationID string            `json:"organization_id"`
	Batch          protocol.LogBatch `json:"batch"`
}

// TerminalEvent is what an interactive shell wrote, or that it ended.
type TerminalEvent struct {
	NodeID         string `json:"node_id"`
	OrganizationID string `json:"organization_id"`
	Session        string `json:"session"`
	Seq            int    `json:"seq"`
	// Data is base64.
	Data  string `json:"data,omitempty"`
	Exit  *int   `json:"exit,omitempty"`
	Error string `json:"error,omitempty"`
}

// EventSink receives everything the gateway learns.
//
// Implementations must not block for long. The progress path runs inside a
// command dispatch, and metrics and logs run on the session read loop, so a
// slow sink stalls the thing it is reporting on.
type EventSink interface {
	NodeConnected(ctx context.Context, e NodeEvent)
	NodeDisconnected(ctx context.Context, e NodeEvent)
	Progress(ctx context.Context, e ProgressEvent)
	Metrics(ctx context.Context, e MetricsEvent)
	Logs(ctx context.Context, e LogsEvent)
	// Terminal carries a console's output. It is kept off the queue the rest
	// share: typing waits on it, and a backlog of metrics must not delay it.
	Terminal(ctx context.Context, e TerminalEvent)
}

// DiscardEvents drops everything. Useful in tests and when running the
// gateway standalone.
type DiscardEvents struct{}

func (DiscardEvents) NodeConnected(context.Context, NodeEvent)    {}
func (DiscardEvents) NodeDisconnected(context.Context, NodeEvent) {}
func (DiscardEvents) Progress(context.Context, ProgressEvent)     {}
func (DiscardEvents) Metrics(context.Context, MetricsEvent)       {}
func (DiscardEvents) Logs(context.Context, LogsEvent)             {}
func (DiscardEvents) Terminal(context.Context, TerminalEvent)     {}

// ControlPlaneEvents posts events to the Laravel control plane.
//
// Delivery is asynchronous and best-effort, behind a bounded queue. That is a
// deliberate trade: the alternative is that a slow or restarting control
// plane stalls command dispatch and, through the session read loop, the
// heartbeats that keep nodes connected. Losing a progress line is survivable;
// dropping every node in the fleet because the control plane is deploying is
// not.
//
// Node connect and disconnect events are the ones that matter most, so the
// queue is sized to make losing them unlikely, and drops are counted and
// logged rather than silent.
type ControlPlaneEvents struct {
	endpoint string
	token    string
	client   *http.Client
	log      *slog.Logger

	queue chan envelope
	// terminal is a queue of its own, with its own worker, so shell output
	// does not wait behind metrics and logs.
	terminal chan envelope
	wg       sync.WaitGroup

	mu      sync.Mutex
	dropped int
}

type envelope struct {
	Kind string `json:"kind"`
	Body any    `json:"body"`
}

// Event kinds, matching what the control plane dispatches on.
const (
	KindNodeConnected    = "node.connected"
	KindNodeDisconnected = "node.disconnected"
	KindProgress         = "command.progress"
	KindMetrics          = "node.metrics"
	KindLogs             = "node.logs"
	KindTerminal         = "terminal.output"
)

// eventQueueSize bounds memory when the control plane is unreachable. Deep
// enough to ride out a deploy, shallow enough that it cannot grow unbounded.
const eventQueueSize = 4096

// terminalQueueSize is smaller: output that is seconds old is no use to anyone
// watching a screen, so a deep backlog would only delay what matters.
const terminalQueueSize = 1024

// NewControlPlaneEvents starts a sink that posts to the control plane.
func NewControlPlaneEvents(endpoint, token string, logger *slog.Logger) *ControlPlaneEvents {
	if logger == nil {
		logger = slog.Default()
	}

	e := &ControlPlaneEvents{
		endpoint: endpoint,
		token:    token,
		client:   &http.Client{Timeout: 15 * time.Second},
		log:      logger,
		queue:    make(chan envelope, eventQueueSize),
		terminal: make(chan envelope, terminalQueueSize),
	}

	e.wg.Add(2)

	go e.deliver(e.queue)
	go e.deliver(e.terminal)

	return e
}

func (e *ControlPlaneEvents) NodeConnected(_ context.Context, ev NodeEvent) {
	e.enqueue(envelope{Kind: KindNodeConnected, Body: ev})
}

func (e *ControlPlaneEvents) NodeDisconnected(_ context.Context, ev NodeEvent) {
	e.enqueue(envelope{Kind: KindNodeDisconnected, Body: ev})
}

func (e *ControlPlaneEvents) Progress(_ context.Context, ev ProgressEvent) {
	e.enqueue(envelope{Kind: KindProgress, Body: ev})
}

func (e *ControlPlaneEvents) Metrics(_ context.Context, ev MetricsEvent) {
	e.enqueue(envelope{Kind: KindMetrics, Body: ev})
}

func (e *ControlPlaneEvents) Logs(_ context.Context, ev LogsEvent) {
	e.enqueue(envelope{Kind: KindLogs, Body: ev})
}

func (e *ControlPlaneEvents) Terminal(_ context.Context, ev TerminalEvent) {
	e.enqueueTo(e.terminal, envelope{Kind: KindTerminal, Body: ev})
}

func (e *ControlPlaneEvents) enqueue(env envelope) { e.enqueueTo(e.queue, env) }

func (e *ControlPlaneEvents) enqueueTo(q chan envelope, env envelope) {
	select {
	case q <- env:
	default:
		// Never block the caller. See the type comment.
		e.mu.Lock()
		e.dropped++
		count := e.dropped
		e.mu.Unlock()

		// Log sparsely. A control plane outage would otherwise produce a log
		// line per event, which is its own outage.
		if count == 1 || count%1000 == 0 {
			e.log.Warn("dropping events; the control plane is not keeping up",
				"dropped_total", count)
		}
	}
}

// Dropped reports how many events were discarded.
func (e *ControlPlaneEvents) Dropped() int {
	e.mu.Lock()
	defer e.mu.Unlock()

	return e.dropped
}

func (e *ControlPlaneEvents) deliver(queue chan envelope) {
	defer e.wg.Done()

	for env := range queue {
		body, err := json.Marshal(env)
		if err != nil {
			continue
		}

		req, err := http.NewRequest(http.MethodPost, e.endpoint, bytes.NewReader(body))
		if err != nil {
			continue
		}

		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Accept", "application/json")
		req.Header.Set(AuthHeader, e.token)

		resp, err := e.client.Do(req)
		if err != nil {
			e.log.Warn("could not deliver event", "kind", env.Kind, "error", err)

			continue
		}

		if resp.StatusCode >= 400 {
			e.log.Warn("control plane refused event", "kind", env.Kind, "status", resp.StatusCode)
		}

		_ = resp.Body.Close()
	}
}

// Close drains the queue and stops the delivery goroutine.
func (e *ControlPlaneEvents) Close() {
	close(e.queue)
	close(e.terminal)
	e.wg.Wait()
}

// AuthHeader carries the shared secret between the gateway and the control
// plane, in both directions.
const AuthHeader = "X-Gateway-Token"

// AuthorizeRequest checks the shared secret in constant time.
//
// The gateway acts on behalf of the control plane, so anything that can call
// it can run commands as root on every node in the fleet. This link must not
// be reachable from outside; the shared secret is a second line, not the
// first.
func AuthorizeRequest(r *http.Request, expected string) error {
	if expected == "" {
		return fmt.Errorf("gateway: no shared secret is configured")
	}

	presented := r.Header.Get(AuthHeader)

	// Constant time, so the comparison does not leak the secret a byte at a
	// time to anyone willing to measure.
	if subtle.ConstantTimeCompare([]byte(presented), []byte(expected)) != 1 {
		return fmt.Errorf("gateway: invalid or missing %s", AuthHeader)
	}

	return nil
}
