package agentcore

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"runtime"
	"sync"
	"time"

	"github.com/unharbored/unharbored/internal/enroll"
	"github.com/unharbored/unharbored/internal/pki"
	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/transport"
)

// Version is the agent build identifier, overridden at link time.
var Version = "dev"

// Config configures an Agent.
type Config struct {
	// StateDir holds credentials. Must be private to root.
	StateDir string
	// SitesRoot is the parent of per-site release trees, e.g. /home/deploy.
	SitesRoot string

	// EnrollURL is the control plane enrollment endpoint. Only used when the
	// node has no credentials yet.
	EnrollURL string
	// EnrollToken comes from cloud-init user_data. Single use.
	EnrollToken string
	// BootstrapCAPEM is the CA delivered alongside the token. The agent pins
	// it rather than trusting the host root store, so even the one
	// unauthenticated call it makes is to a verified control plane.
	BootstrapCAPEM []byte
	// ServerName is the control plane certificate name to verify.
	ServerName string

	// HeartbeatInterval controls ping frequency on an idle connection.
	HeartbeatInterval time.Duration
	// Backoff governs reconnection.
	Backoff transport.Backoff
	// ExecAllowList optionally bounds what the exec command may run.
	//
	// Empty, the default, means unrestricted: it is the customer's server and
	// they already have root on it. Set it only when an organisation wants a
	// narrower surface for its own reasons. See ErrCommandNotAllowed.
	ExecAllowList []string

	// Logger defaults to slog.Default.
	Logger *slog.Logger
	// dialTimeout bounds a connection attempt.
	DialTimeout time.Duration
}

func (c *Config) applyDefaults() {
	if c.HeartbeatInterval <= 0 {
		c.HeartbeatInterval = 30 * time.Second
	}
	if c.Backoff == (transport.Backoff{}) {
		c.Backoff = transport.DefaultBackoff
	}
	if c.Logger == nil {
		c.Logger = slog.Default()
	}
	if c.DialTimeout <= 0 {
		c.DialTimeout = 15 * time.Second
	}
	// ExecAllowList is deliberately not defaulted. Empty means unrestricted.
}

// Agent connects a node to the control plane.
type Agent struct {
	cfg   Config
	log   *slog.Logger
	state *State

	mu      sync.Mutex
	session *transport.Session

	// idempotency caches results so a command replayed after a reconnect is
	// not executed twice.
	idem *idempotencyCache

	// terms are the consoles open on this node.
	terms *terminals
}

// New builds an Agent.
func New(cfg Config) *Agent {
	cfg.applyDefaults()
	return &Agent{
		cfg:   cfg,
		log:   cfg.Logger,
		idem:  newIdempotencyCache(),
		terms: newTerminals(),
	}
}

// State returns the loaded credentials, or nil before enrollment.
func (a *Agent) State() *State { return a.state }

// EnsureEnrolled loads existing credentials, enrolling if there are none.
func (a *Agent) EnsureEnrolled(ctx context.Context) error {
	state, err := LoadState(a.cfg.StateDir)
	if err == nil {
		a.state = state
		a.log.Info("loaded existing credentials", "node_id", state.Identity.NodeID)
		return nil
	}
	if !errors.Is(err, ErrNotEnrolled) {
		return err
	}

	if a.cfg.EnrollToken == "" {
		return fmt.Errorf("%w and no enrollment token was provided", ErrNotEnrolled)
	}

	state, err = a.doEnroll(ctx)
	if err != nil {
		return err
	}
	a.state = state
	a.log.Info("enrolled", "node_id", state.Identity.NodeID)
	return nil
}

// doEnroll performs the one unauthenticated exchange in the agent's life.
func (a *Agent) doEnroll(ctx context.Context) (*State, error) {
	// The key is generated here and never leaves the node. The control plane
	// only ever sees the public half.
	keyPEM, csrPEM, err := pki.NewKeyAndCSR("node")
	if err != nil {
		return nil, err
	}

	hostname, _ := os.Hostname()
	reqBody, err := json.Marshal(enroll.Request{
		Token:        a.cfg.EnrollToken,
		CSRPEM:       string(csrPEM),
		AgentVersion: Version,
		Hostname:     hostname,
		OS:           runtime.GOOS,
		Arch:         runtime.GOARCH,
	})
	if err != nil {
		return nil, fmt.Errorf("agentcore: encode enrollment request: %w", err)
	}

	tlsCfg, err := transport.EnrollmentClientTLS(a.cfg.BootstrapCAPEM, a.cfg.ServerName)
	if err != nil {
		return nil, err
	}
	client := &http.Client{
		Timeout:   30 * time.Second,
		Transport: &http.Transport{TLSClientConfig: tlsCfg},
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, a.cfg.EnrollURL, bytes.NewReader(reqBody))
	if err != nil {
		return nil, fmt.Errorf("agentcore: build enrollment request: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("agentcore: enrollment request: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, fmt.Errorf("agentcore: read enrollment response: %w", err)
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("agentcore: enrollment rejected with status %d", resp.StatusCode)
	}

	var out enroll.Response
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, fmt.Errorf("agentcore: parse enrollment response: %w", err)
	}

	state := &State{
		Dir: a.cfg.StateDir,
		Identity: Identity{
			NodeID:           out.NodeID,
			OrgID:            out.OrgID,
			ControlPlaneAddr: out.ControlPlaneAddr,
			ServerName:       a.cfg.ServerName,
		},
		KeyPEM:  keyPEM,
		CertPEM: []byte(out.CertPEM),
		CAPEM:   []byte(out.CAPEM),
	}
	if err := state.Save(); err != nil {
		return nil, err
	}
	return state, nil
}

// Run connects and serves until ctx is cancelled, reconnecting with backoff.
//
// A node that cannot reach the control plane is not broken. It is serving the
// application perfectly well and is merely unmanaged, so reconnection is
// patient rather than aggressive.
func (a *Agent) Run(ctx context.Context) error {
	if a.state == nil {
		if err := a.EnsureEnrolled(ctx); err != nil {
			return err
		}
	}

	attempt := 0
	for {
		if err := ctx.Err(); err != nil {
			return err
		}

		err := a.runOnce(ctx)
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if err != nil {
			a.log.Warn("control plane session ended", "error", err, "attempt", attempt)
		} else {
			a.log.Info("control plane session closed")
		}

		attempt = nextAttempt(attempt, err == nil)
		delay := a.cfg.Backoff.Delay(attempt)

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(delay):
		}
	}
}

// nextAttempt is the backoff attempt after a session ends. A session that was
// established and then closed (the gateway restarted, say) resets it: before,
// every reconnect in the agent's lifetime counted as a failure, so a node
// that had been up a while waited the full two-minute cap after a routine
// gateway restart instead of about a second.
func nextAttempt(attempt int, established bool) int {
	if established {
		return 0
	}

	return attempt + 1
}

// runOnce holds one connection for its lifetime.
func (a *Agent) runOnce(ctx context.Context) error {
	tlsCfg, err := transport.ClientTLS(
		a.state.CertPEM, a.state.KeyPEM, a.state.CAPEM, a.state.Identity.ServerName,
	)
	if err != nil {
		return err
	}

	dialer := &net.Dialer{Timeout: a.cfg.DialTimeout}
	conn, err := tls.DialWithDialer(dialer, "tcp", a.state.Identity.ControlPlaneAddr, tlsCfg)
	if err != nil {
		return fmt.Errorf("agentcore: dial control plane: %w", err)
	}

	session := transport.NewSession(conn, "control-plane")
	a.mu.Lock()
	a.session = session
	a.mu.Unlock()
	defer func() {
		a.mu.Lock()
		a.session = nil
		a.mu.Unlock()
		// Nobody can see the consoles once the connection is gone.
		a.terms.closeAll()
	}()

	sessionCtx, cancel := context.WithCancel(ctx)
	defer cancel()

	// Serve before Hello, so the reply to Hello is read by the loop rather
	// than racing it.
	serveErr := make(chan error, 1)
	go func() {
		// The read deadline must exceed the heartbeat interval, since an idle
		// connection is kept alive by our own pings.
		serveErr <- session.Serve(sessionCtx, transport.HandlerFunc(a.handle), a.cfg.HeartbeatInterval*3)
	}()

	if err := a.sayHello(sessionCtx, session); err != nil {
		cancel()
		<-serveErr
		return err
	}

	go session.Heartbeat(sessionCtx, a.cfg.HeartbeatInterval, a.cfg.HeartbeatInterval)
	go a.sendMetrics(sessionCtx, session, MetricsInterval)
	go a.shipLogs(sessionCtx, session)

	a.log.Info("connected to control plane", "addr", a.state.Identity.ControlPlaneAddr)
	return <-serveErr
}

func (a *Agent) sayHello(ctx context.Context, session *transport.Session) error {
	hostname, _ := os.Hostname()
	env, err := protocol.NewEnvelope(protocol.TypeHello, protocol.Hello{
		NodeID:       a.state.Identity.NodeID,
		AgentVersion: Version,
		OS:           runtime.GOOS,
		Arch:         runtime.GOARCH,
		Hostname:     hostname,
		StartedAt:    time.Now().UnixMilli(),
	})
	if err != nil {
		return err
	}

	helloCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()

	reply, err := session.Request(helloCtx, env)
	if err != nil {
		return fmt.Errorf("agentcore: hello: %w", err)
	}
	if reply.Type == protocol.TypeError {
		var perr protocol.Error
		_ = reply.DecodePayload(&perr)
		return fmt.Errorf("agentcore: control plane rejected hello: %s (%s)", perr.Message, perr.Code)
	}
	if reply.Type != protocol.TypeHelloAck {
		return fmt.Errorf("agentcore: expected hello_ack, got %s", reply.Type)
	}
	return nil
}

// handle dispatches an inbound envelope.
//
// Transport dispatch runs on the single read loop, so real work is handed to
// a goroutine immediately. Blocking here would stall heartbeats and every
// other message on the connection.
func (a *Agent) handle(ctx context.Context, session *transport.Session, env *protocol.Envelope) {
	if env.Type == protocol.TypeTerminal {
		a.terminal(session, env)
		return
	}

	if env.Type != protocol.TypeCommand {
		a.log.Debug("ignoring unexpected message", "type", env.Type)
		return
	}

	var cmd protocol.Command
	if err := env.DecodePayload(&cmd); err != nil {
		a.replyError(session, env, protocol.ErrMalformed, err.Error())
		return
	}

	go a.execute(ctx, session, env, cmd)
}
