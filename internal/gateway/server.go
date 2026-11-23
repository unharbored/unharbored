package gateway

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"sync"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/transport"
)

// Version identifies the gateway build, overridden at link time.
var Version = "dev"

var (
	// ErrNodeNotConnected means no session exists for that node right now.
	ErrNodeNotConnected = errors.New("gateway: node is not connected")
	// ErrShuttingDown means the gateway is closing and will not accept work.
	ErrShuttingDown = errors.New("gateway: shutting down")
)

// Config configures a Server.
type Config struct {
	// ServerCertPEM and ServerKeyPEM authenticate the gateway to agents. The
	// certificate must be issued by an organisation CA the agents pin.
	ServerCertPEM []byte
	ServerKeyPEM  []byte

	// HeartbeatSeconds is advertised to agents in HelloAck.
	HeartbeatSeconds int

	// SessionReadTimeout bounds how long a session waits for any inbound
	// frame. Must exceed the heartbeat interval, since an agent's ping is
	// what resets it on an otherwise idle connection.
	SessionReadTimeout time.Duration

	// Events receives node lifecycle and command output. The production
	// implementation posts them to Laravel; tests use a fake.
	Events EventSink

	Logger *slog.Logger
}

func (c *Config) applyDefaults() {
	if c.HeartbeatSeconds <= 0 {
		c.HeartbeatSeconds = 30
	}
	if c.SessionReadTimeout <= 0 {
		c.SessionReadTimeout = time.Duration(c.HeartbeatSeconds*3) * time.Second
	}
	if c.Logger == nil {
		c.Logger = slog.Default()
	}
	if c.Events == nil {
		c.Events = DiscardEvents{}
	}
}

// connectedNode is one live agent session.
type connectedNode struct {
	nodeID         string
	organizationID string
	certificate    *x509.Certificate
	session        *transport.Session
	connectedAt    time.Time
	agentVersion   string
	hostname       string
}

// nodeKey identifies a session. Node ids alone are not unique across
// organisations: each organisation's CA can issue any name, so two tenants may
// present the same one. Keying by both means a certificate from organisation A
// can never occupy, replace or receive the commands of organisation B's node.
type nodeKey struct {
	organizationID string
	nodeID         string
}

// Server accepts agent sessions and relays commands to them.
type Server struct {
	cfg      Config
	log      *slog.Logger
	registry *Registry

	mu      sync.RWMutex
	nodes   map[nodeKey]*connectedNode
	closing bool

	wg sync.WaitGroup
}

// NewServer builds a Server.
func NewServer(registry *Registry, cfg Config) *Server {
	cfg.applyDefaults()

	return &Server{
		cfg:      cfg,
		log:      cfg.Logger,
		registry: registry,
		nodes:    make(map[nodeKey]*connectedNode),
	}
}

// Listen starts the agent session listener and returns the bound address.
func (s *Server) Listen(ctx context.Context, addr string) (string, error) {
	cert, err := tls.X509KeyPair(s.cfg.ServerCertPEM, s.cfg.ServerKeyPEM)
	if err != nil {
		return "", fmt.Errorf("gateway: load server keypair: %w", err)
	}

	// The CA pool is read per handshake rather than captured once, so a
	// registry refresh takes effect for new connections without a restart.
	// An organisation added upstream can connect immediately; one removed
	// stops being accepted.
	tlsCfg := &tls.Config{
		Certificates: []tls.Certificate{cert},
		MinVersion:   tls.VersionTLS13,
		GetConfigForClient: func(*tls.ClientHelloInfo) (*tls.Config, error) {
			return &tls.Config{
				Certificates: []tls.Certificate{cert},
				ClientAuth:   tls.RequireAndVerifyClientCert,
				ClientCAs:    s.registry.Pool(),
				MinVersion:   tls.VersionTLS13,
			}, nil
		},
	}

	ln, err := tls.Listen("tcp", addr, tlsCfg)
	if err != nil {
		return "", fmt.Errorf("gateway: listen: %w", err)
	}

	go func() {
		<-ctx.Done()
		_ = ln.Close()
	}()
	go s.accept(ctx, ln)

	s.log.Info("gateway listening for agents", "addr", ln.Addr().String())

	return ln.Addr().String(), nil
}

func (s *Server) accept(ctx context.Context, ln net.Listener) {
	for {
		conn, err := ln.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return
			}
			s.log.Error("accept", "error", err)

			continue
		}

		s.wg.Add(1)

		go func() {
			defer s.wg.Done()
			s.serve(ctx, conn)
		}()
	}
}

func (s *Server) serve(ctx context.Context, conn net.Conn) {
	tlsConn, ok := conn.(*tls.Conn)
	if !ok {
		_ = conn.Close()

		return
	}

	// Handshake explicitly so identity is available before any protocol
	// message is read.
	_ = tlsConn.SetDeadline(time.Now().Add(30 * time.Second))

	if err := tlsConn.HandshakeContext(ctx); err != nil {
		s.log.Warn("handshake failed", "remote", conn.RemoteAddr(), "error", err)
		_ = conn.Close()

		return
	}

	_ = tlsConn.SetDeadline(time.Time{})

	state := tlsConn.ConnectionState()

	nodeID, err := transport.PeerNodeID(state)
	if err != nil {
		s.log.Warn("no usable peer identity", "remote", conn.RemoteAddr(), "error", err)
		_ = conn.Close()

		return
	}

	// Tenancy comes from the signature on the certificate, never from
	// anything the node says about itself.
	organizationID, err := s.registry.OrganisationFor(state.VerifiedChains[0])
	if err != nil {
		s.log.Warn("could not place node in an organisation",
			"node_id", nodeID, "remote", conn.RemoteAddr(), "error", err)
		_ = conn.Close()

		return
	}

	leaf := state.PeerCertificates[0]
	if s.registry.IsRevoked(organizationID, leaf) {
		s.log.Warn("refused a revoked certificate",
			"node_id", nodeID, "org", organizationID, "serial", leaf.SerialNumber.Text(16), "remote", conn.RemoteAddr())
		_ = conn.Close()

		return
	}

	session := transport.NewSession(conn, nodeID)
	handler := &sessionHandler{
		server: s,
		node: &connectedNode{
			nodeID:         nodeID,
			organizationID: organizationID,
			certificate:    leaf,
			session:        session,
			connectedAt:    time.Now(),
		},
	}

	// Not registered until Hello succeeds, so a connection that never
	// identifies itself cannot be sent commands.
	serveErr := session.Serve(ctx, handler, s.cfg.SessionReadTimeout)

	s.mu.Lock()
	if current, ok := s.nodes[nodeKey{organizationID, nodeID}]; ok && current.session == session {
		delete(s.nodes, nodeKey{organizationID, nodeID})
	}
	s.mu.Unlock()

	if handler.registered {
		s.log.Info("node disconnected", "node_id", nodeID, "org", organizationID, "error", serveErr)

		// Deliberately not the request context: it is already cancelled by
		// the time a disconnect is noticed, and the control plane still needs
		// to be told.
		s.cfg.Events.NodeDisconnected(context.WithoutCancel(ctx), NodeEvent{
			NodeID:         nodeID,
			OrganizationID: organizationID,
			At:             time.Now(),
			Reason:         errString(serveErr),
		})
	}
}

// DisconnectRevoked closes the session of every connected node whose
// certificate is now revoked, so removing a server takes effect at once
// rather than at its next reconnect. It returns how many it closed.
func (s *Server) DisconnectRevoked() int {
	s.mu.Lock()
	var revoked []*connectedNode
	for _, node := range s.nodes {
		if node.certificate != nil && s.registry.IsRevoked(node.organizationID, node.certificate) {
			revoked = append(revoked, node)
		}
	}
	s.mu.Unlock()

	for _, node := range revoked {
		s.log.Info("disconnecting a revoked node", "node_id", node.nodeID, "org", node.organizationID)
		_ = node.session.Close()
	}

	return len(revoked)
}

// Send dispatches a command to a node and waits for its result.
//
// A command is a conversation rather than a request and a reply: an ack, then
// any number of progress messages, then a result, all correlated to one
// message id. Progress is forwarded to the event sink as it arrives rather
// than buffered, so a deploy log appears while the deploy is still running.
func (s *Server) Send(ctx context.Context, organizationID, nodeID string, cmd protocol.Command) (*protocol.Result, error) {
	s.mu.RLock()
	node, ok := s.nodes[nodeKey{organizationID, nodeID}]
	closing := s.closing
	s.mu.RUnlock()

	if closing {
		return nil, ErrShuttingDown
	}

	if !ok {
		return nil, fmt.Errorf("%w: %s", ErrNodeNotConnected, nodeID)
	}

	env, err := protocol.NewEnvelope(protocol.TypeCommand, cmd)
	if err != nil {
		return nil, err
	}

	// Subscribe before sending. A fast agent can ack before Send returns.
	replies, cancel := node.session.Subscribe(env.ID)
	defer cancel()

	if err := node.session.Send(env); err != nil {
		return nil, err
	}

	for {
		select {
		case reply := <-replies:
			switch reply.Type {
			case protocol.TypeCommandAck:
				var ack protocol.CommandAck
				if err := reply.DecodePayload(&ack); err != nil {
					return nil, err
				}

				if !ack.Accepted {
					return nil, fmt.Errorf("gateway: agent refused command: %s", ack.Reason)
				}

			case protocol.TypeProgress:
				var p protocol.Progress
				if err := reply.DecodePayload(&p); err != nil {
					continue
				}

				s.cfg.Events.Progress(ctx, ProgressEvent{
					NodeID:         nodeID,
					OrganizationID: node.organizationID,
					CommandID:      env.ID,
					Stream:         string(p.Stream),
					Seq:            p.Seq,
					Text:           p.Text,
					At:             time.Now(),
				})

			case protocol.TypeResult:
				var res protocol.Result
				if err := reply.DecodePayload(&res); err != nil {
					return nil, err
				}

				if dropped := node.session.SubscriptionDrops(env.ID); dropped > 0 {
					s.log.Warn("progress messages dropped", "node_id", nodeID, "dropped", dropped)
				}

				return &res, nil

			case protocol.TypeError:
				var perr protocol.Error
				_ = reply.DecodePayload(&perr)

				return nil, fmt.Errorf("gateway: command rejected: %s (%s)", perr.Message, perr.Code)
			}

		case <-node.session.Done():
			// The node went away mid-command. The command may well have run,
			// so the caller must treat this as unknown rather than failed.
			// An idempotency key is what makes retrying it safe.
			return nil, fmt.Errorf("%w: session ended mid-command", ErrNodeNotConnected)

		case <-ctx.Done():
			return nil, ctx.Err()
		}
	}
}

// Terminal passes one instruction to a console on a node. Nothing is waited
// for: the shell's answer comes back as output events.
func (s *Server) Terminal(organizationID, nodeID string, t protocol.Terminal) error {
	s.mu.RLock()
	node, ok := s.nodes[nodeKey{organizationID, nodeID}]
	closing := s.closing
	s.mu.RUnlock()

	if closing {
		return ErrShuttingDown
	}
	if !ok {
		return fmt.Errorf("%w: %s", ErrNodeNotConnected, nodeID)
	}

	env, err := protocol.NewEnvelope(protocol.TypeTerminal, t)
	if err != nil {
		return err
	}

	return node.session.Send(env)
}

// NodeStatus describes a connected node.
type NodeStatus struct {
	NodeID         string    `json:"node_id"`
	OrganizationID string    `json:"organization_id"`
	ConnectedAt    time.Time `json:"connected_at"`
	AgentVersion   string    `json:"agent_version,omitempty"`
	Hostname       string    `json:"hostname,omitempty"`
}

// Nodes lists connected nodes, optionally filtered to one organisation.
func (s *Server) Nodes(organizationID string) []NodeStatus {
	s.mu.RLock()
	defer s.mu.RUnlock()

	out := make([]NodeStatus, 0, len(s.nodes))

	for _, node := range s.nodes {
		if organizationID != "" && node.organizationID != organizationID {
			continue
		}

		out = append(out, NodeStatus{
			NodeID:         node.nodeID,
			OrganizationID: node.organizationID,
			ConnectedAt:    node.connectedAt,
			AgentVersion:   node.agentVersion,
			Hostname:       node.hostname,
		})
	}

	return out
}

// IsConnected reports whether a node currently holds a session.
func (s *Server) IsConnected(organizationID, nodeID string) bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	_, ok := s.nodes[nodeKey{organizationID, nodeID}]

	return ok
}

// Close stops accepting work and ends every session.
func (s *Server) Close() {
	s.mu.Lock()
	s.closing = true
	sessions := make([]*transport.Session, 0, len(s.nodes))

	for _, node := range s.nodes {
		sessions = append(sessions, node.session)
	}
	s.mu.Unlock()

	for _, session := range sessions {
		_ = session.Close()
	}

	s.wg.Wait()
}

// sessionHandler processes messages from one node.
type sessionHandler struct {
	server     *Server
	node       *connectedNode
	registered bool
}

// Handle implements transport.Handler.
func (h *sessionHandler) Handle(ctx context.Context, session *transport.Session, env *protocol.Envelope) {
	switch env.Type {
	case protocol.TypeHello:
		h.handleHello(ctx, session, env)

	case protocol.TypeMetrics:
		var sample protocol.MetricSample
		if err := env.DecodePayload(&sample); err == nil {
			h.server.cfg.Events.Metrics(ctx, MetricsEvent{
				NodeID:         h.node.nodeID,
				OrganizationID: h.node.organizationID,
				Sample:         sample,
			})
		}

	case protocol.TypeLogBatch:
		var batch protocol.LogBatch
		if err := env.DecodePayload(&batch); err == nil {
			h.server.cfg.Events.Logs(ctx, LogsEvent{
				NodeID:         h.node.nodeID,
				OrganizationID: h.node.organizationID,
				Batch:          batch,
			})
		}

	case protocol.TypeTerminalOut:
		var out protocol.TerminalOut
		if err := env.DecodePayload(&out); err == nil {
			h.server.cfg.Events.Terminal(ctx, TerminalEvent{
				NodeID:         h.node.nodeID,
				OrganizationID: h.node.organizationID,
				Session:        out.Session,
				Seq:            out.Seq,
				Data:           out.Data,
				Exit:           out.Exit,
				Error:          out.Error,
			})
		}

	default:
		h.server.log.Debug("unhandled message", "node_id", h.node.nodeID, "type", env.Type)
	}
}

func (h *sessionHandler) handleHello(ctx context.Context, session *transport.Session, env *protocol.Envelope) {
	var hello protocol.Hello
	if err := env.DecodePayload(&hello); err != nil {
		h.reject(session, env, protocol.ErrMalformed, "hello payload is malformed")

		return
	}

	if env.V != protocol.Version {
		h.reject(session, env, protocol.ErrUnsupportedVersion,
			fmt.Sprintf("protocol version %d is not supported", env.V))

		return
	}

	// The certificate is authoritative. A Hello claiming a different node
	// means a misprovisioned agent at best, so close rather than quietly
	// trusting the certificate and ignoring the discrepancy.
	if hello.NodeID != h.node.nodeID {
		h.server.log.Warn("hello identity mismatch",
			"certificate", h.node.nodeID, "claimed", hello.NodeID)
		h.reject(session, env, protocol.ErrIdentityMismatch,
			"hello node id does not match the client certificate")
		_ = session.Close()

		return
	}

	h.node.agentVersion = hello.AgentVersion
	h.node.hostname = hello.Hostname

	h.server.mu.Lock()
	// A reconnecting node replaces its previous session. The old one is
	// almost certainly a half-closed connection the gateway has not noticed.
	key := nodeKey{h.node.organizationID, h.node.nodeID}
	if existing, ok := h.server.nodes[key]; ok && existing.session != session {
		_ = existing.session.Close()
	}
	h.server.nodes[key] = h.node
	h.server.mu.Unlock()

	h.registered = true

	ack, err := env.Reply(protocol.TypeHelloAck, protocol.HelloAck{
		ServerVersion:    Version,
		HeartbeatSeconds: h.server.cfg.HeartbeatSeconds,
	})
	if err != nil {
		return
	}

	if err := session.Send(ack); err != nil {
		h.server.log.Error("send hello_ack", "node_id", h.node.nodeID, "error", err)

		return
	}

	h.server.log.Info("node connected",
		"node_id", h.node.nodeID,
		"org", h.node.organizationID,
		"agent_version", hello.AgentVersion,
		"hostname", hello.Hostname)

	h.server.cfg.Events.NodeConnected(ctx, NodeEvent{
		NodeID:         h.node.nodeID,
		OrganizationID: h.node.organizationID,
		At:             time.Now(),
		AgentVersion:   hello.AgentVersion,
		Hostname:       hello.Hostname,
		OS:             hello.OS,
		Arch:           hello.Arch,
	})
}

func (h *sessionHandler) reject(session *transport.Session, env *protocol.Envelope, code protocol.ErrorCode, msg string) {
	reply, err := env.Reply(protocol.TypeError, protocol.Error{Code: code, Message: msg, Fatal: true})
	if err != nil {
		return
	}

	_ = session.Send(reply)
}

func errString(err error) string {
	if err == nil {
		return ""
	}

	return err.Error()
}
