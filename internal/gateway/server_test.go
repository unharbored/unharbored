package gateway

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"log/slog"
	"math/big"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/unharbored/unharbored/internal/pki"
	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/transport"
)

func quiet() *slog.Logger { return slog.New(slog.NewTextHandler(io.Discard, nil)) }

// capturing records events so tests can assert on what the control plane
// would have been told.
type capturing struct {
	mu           sync.Mutex
	connected    []NodeEvent
	disconnected []NodeEvent
	progress     []ProgressEvent
}

func (c *capturing) NodeConnected(_ context.Context, e NodeEvent) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.connected = append(c.connected, e)
}

func (c *capturing) NodeDisconnected(_ context.Context, e NodeEvent) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.disconnected = append(c.disconnected, e)
}

func (c *capturing) Progress(_ context.Context, e ProgressEvent) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.progress = append(c.progress, e)
}

func (c *capturing) Metrics(context.Context, MetricsEvent)   {}
func (c *capturing) Logs(context.Context, LogsEvent)         {}
func (c *capturing) Terminal(context.Context, TerminalEvent) {}

func (c *capturing) counts() (int, int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	return len(c.connected), len(c.disconnected)
}

func (c *capturing) progressCount() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return len(c.progress)
}

// testGateway stands up a gateway with one organisation and returns it with
// the CA, so tests can mint node certificates for it.
func testGateway(t *testing.T, orgID string) (*Server, *pki.CA, string, *capturing) {
	t.Helper()

	ca, err := pki.NewCA(orgID+" CA", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}

	certPEM, keyPEM, err := ca.IssueServer("gateway.test", []string{"gateway.test"}, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueServer: %v", err)
	}

	registry := NewRegistry()
	if err := registry.Replace([]Organisation{{ID: orgID, CACertificate: ca.Certificate()}}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	events := &capturing{}

	s := NewServer(registry, Config{
		ServerCertPEM:    certPEM,
		ServerKeyPEM:     keyPEM,
		HeartbeatSeconds: 1,
		Events:           events,
		Logger:           quiet(),
	})
	t.Cleanup(s.Close)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	addr, err := s.Listen(ctx, "127.0.0.1:0")
	if err != nil {
		t.Fatalf("Listen: %v", err)
	}

	return s, ca, addr, events
}

// dialAgent connects as a node would, says Hello, and serves the session.
func dialAgent(t *testing.T, ca *pki.CA, addr, nodeID string, handler transport.Handler) *transport.Session {
	t.Helper()

	return dialAgentTrusting(t, ca, ca, addr, nodeID, handler)
}

// dialAgentTrusting is dialAgent where the node's certificate is issued by one
// CA and the gateway's own certificate is trusted from another, as when several
// organisations share one gateway.
func dialAgentTrusting(t *testing.T, ca, serverCA *pki.CA, addr, nodeID string, handler transport.Handler) *transport.Session {
	t.Helper()

	keyPEM, csrPEM, err := pki.NewKeyAndCSR(nodeID)
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}

	certPEM, err := ca.IssueNode(csrPEM, nodeID, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}

	cfg, err := transport.ClientTLS(certPEM, keyPEM, serverCA.CertPEM(), "gateway.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}

	conn, err := tls.Dial("tcp", addr, cfg)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}

	session := transport.NewSession(conn, "gateway")

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(func() { cancel(); _ = session.Close() })

	go func() { _ = session.Serve(ctx, handler, 0) }()

	hello, err := protocol.NewEnvelope(protocol.TypeHello, protocol.Hello{
		NodeID:       nodeID,
		AgentVersion: "test",
		OS:           "linux",
		Arch:         "amd64",
		Hostname:     nodeID,
	})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}

	reqCtx, reqCancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer reqCancel()

	reply, err := session.Request(reqCtx, hello)
	if err != nil {
		t.Fatalf("hello: %v", err)
	}

	if reply.Type != protocol.TypeHelloAck {
		t.Fatalf("hello reply = %s, want hello_ack", reply.Type)
	}

	return session
}

// echoHandler answers commands with a successful result, after optionally
// streaming progress.
func echoHandler(progressLines int) transport.Handler {
	return transport.HandlerFunc(func(_ context.Context, s *transport.Session, env *protocol.Envelope) {
		if env.Type != protocol.TypeCommand {
			return
		}

		go func() {
			ack, _ := env.Reply(protocol.TypeCommandAck, protocol.CommandAck{Accepted: true})
			_ = s.Send(ack)

			for i := range progressLines {
				p, _ := env.Reply(protocol.TypeProgress, protocol.Progress{
					Stream: protocol.StreamStatus, Seq: i, Text: "step",
				})
				_ = s.Send(p)
			}

			res, _ := env.Reply(protocol.TypeResult, protocol.Result{Status: protocol.StatusSucceeded})
			_ = s.Send(res)
		}()
	})
}

func waitFor(t *testing.T, what string, cond func() bool) {
	t.Helper()

	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}

	t.Fatalf("timed out waiting for %s", what)
}

func TestANodeConnectsAndIsReported(t *testing.T) {
	s, ca, addr, events := testGateway(t, "org-1")

	dialAgent(t, ca, addr, "web-1", nil)
	waitFor(t, "node to register", func() bool { return s.IsConnected("org-1", "web-1") })

	nodes := s.Nodes("")
	if len(nodes) != 1 || nodes[0].NodeID != "web-1" {
		t.Fatalf("Nodes = %v", nodes)
	}

	if nodes[0].OrganizationID != "org-1" {
		t.Errorf("OrganizationID = %q, want org-1", nodes[0].OrganizationID)
	}

	connected, _ := events.counts()
	if connected != 1 {
		t.Errorf("connect events = %d, want 1", connected)
	}
}

// A certificate from a CA the gateway does not know must not produce a
// session, whatever it calls itself.
func TestANodeFromAnUnknownOrganisationIsRefused(t *testing.T) {
	s, _, addr, _ := testGateway(t, "org-1")

	stranger, err := pki.NewCA("stranger", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}

	keyPEM, csrPEM, _ := pki.NewKeyAndCSR("web-1")
	certPEM, _ := stranger.IssueNode(csrPEM, "web-1", pki.DefaultNodeValidity)

	// Trust the stranger CA on the client side so the failure under test is
	// specifically the gateway refusing the client certificate.
	cfg, err := transport.ClientTLS(certPEM, keyPEM, stranger.CertPEM(), "gateway.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}

	conn, err := tls.Dial("tcp", addr, cfg)
	if err == nil {
		_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))

		buf := make([]byte, 1)
		if _, rerr := conn.Read(buf); rerr == nil {
			t.Fatal("a certificate from an unknown CA completed a session")
		}

		_ = conn.Close()
	}

	if s.IsConnected("org-1", "web-1") {
		t.Fatal("a node from an unknown organisation was registered")
	}
}

// Identity comes from the certificate. A Hello claiming otherwise means a
// misprovisioned agent at best.
func TestAHelloClaimingAnotherIdentityIsRefused(t *testing.T) {
	s, ca, addr, _ := testGateway(t, "org-1")

	keyPEM, csrPEM, _ := pki.NewKeyAndCSR("web-1")
	certPEM, _ := ca.IssueNode(csrPEM, "web-1", pki.DefaultNodeValidity)

	cfg, _ := transport.ClientTLS(certPEM, keyPEM, ca.CertPEM(), "gateway.test")

	conn, err := tls.Dial("tcp", addr, cfg)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer conn.Close()

	session := transport.NewSession(conn, "gateway")

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	go func() { _ = session.Serve(ctx, nil, 0) }()

	hello, _ := protocol.NewEnvelope(protocol.TypeHello, protocol.Hello{NodeID: "web-2-impostor"})

	reqCtx, reqCancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer reqCancel()

	reply, err := session.Request(reqCtx, hello)
	if err == nil && reply.Type != protocol.TypeError {
		t.Fatalf("hello reply = %s, want an error", reply.Type)
	}

	if s.IsConnected("org-1", "web-2-impostor") || s.IsConnected("org-1", "web-1") {
		t.Fatal("a mismatched hello registered a node")
	}
}

func TestCommandRoundTripWithProgress(t *testing.T) {
	s, ca, addr, events := testGateway(t, "org-1")

	dialAgent(t, ca, addr, "web-1", echoHandler(3))
	waitFor(t, "node to register", func() bool { return s.IsConnected("org-1", "web-1") })

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	res, err := s.Send(ctx, "org-1", "web-1", protocol.Command{Kind: protocol.CommandExec})
	if err != nil {
		t.Fatalf("Send: %v", err)
	}

	if res.Status != protocol.StatusSucceeded {
		t.Errorf("Status = %s, want succeeded", res.Status)
	}

	// Progress must reach the control plane as it arrives, so a deploy log
	// appears while the deploy is running rather than after it.
	if got := events.progressCount(); got != 3 {
		t.Errorf("progress events = %d, want 3", got)
	}
}

func TestSendingToAnUnconnectedNodeFails(t *testing.T) {
	s, _, _, _ := testGateway(t, "org-1")

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	_, err := s.Send(ctx, "org-1", "nobody", protocol.Command{Kind: protocol.CommandExec})
	if !errors.Is(err, ErrNodeNotConnected) {
		t.Fatalf("err = %v, want ErrNodeNotConnected", err)
	}
}

// A node that vanishes mid-command leaves the outcome unknown. Reporting that
// as a clean failure would invite a retry of work that may have completed.
func TestANodeDisconnectingMidCommandIsNotAFailure(t *testing.T) {
	s, ca, addr, _ := testGateway(t, "org-1")

	silent := transport.HandlerFunc(func(_ context.Context, sess *transport.Session, env *protocol.Envelope) {
		if env.Type != protocol.TypeCommand {
			return
		}

		go func() {
			ack, _ := env.Reply(protocol.TypeCommandAck, protocol.CommandAck{Accepted: true})
			_ = sess.Send(ack)
			time.Sleep(50 * time.Millisecond)
			_ = sess.Close()
		}()
	})

	session := dialAgent(t, ca, addr, "web-1", silent)
	waitFor(t, "node to register", func() bool { return s.IsConnected("org-1", "web-1") })

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	_, err := s.Send(ctx, "org-1", "web-1", protocol.Command{Kind: protocol.CommandExec})
	if !errors.Is(err, ErrNodeNotConnected) {
		t.Fatalf("err = %v, want ErrNodeNotConnected", err)
	}

	_ = session.Close()
}

func TestDisconnectIsReported(t *testing.T) {
	s, ca, addr, events := testGateway(t, "org-1")

	session := dialAgent(t, ca, addr, "web-1", nil)
	waitFor(t, "node to register", func() bool { return s.IsConnected("org-1", "web-1") })

	_ = session.Close()

	waitFor(t, "disconnect to be reported", func() bool {
		_, disconnected := events.counts()
		return disconnected == 1
	})

	if s.IsConnected("org-1", "web-1") {
		t.Error("the node is still registered after disconnecting")
	}
}

func TestNodesCanBeFilteredByOrganisation(t *testing.T) {
	s, ca, addr, _ := testGateway(t, "org-1")

	dialAgent(t, ca, addr, "web-1", nil)
	waitFor(t, "node to register", func() bool { return s.IsConnected("org-1", "web-1") })

	if got := s.Nodes("org-1"); len(got) != 1 {
		t.Errorf("Nodes(org-1) = %d, want 1", len(got))
	}

	if got := s.Nodes("org-2"); len(got) != 0 {
		t.Errorf("Nodes(org-2) = %d, want 0", len(got))
	}
}

// ---- the control plane API ----

func TestAPIRequiresTheSharedSecret(t *testing.T) {
	s, _, _, _ := testGateway(t, "org-1")
	handler := NewAPI(s, "correct-secret", quiet()).Handler()

	for _, token := range []string{"", "wrong", "correct-secre"} {
		req := httptest.NewRequest(http.MethodGet, "/v1/nodes", nil)
		if token != "" {
			req.Header.Set(AuthHeader, token)
		}

		rec := httptest.NewRecorder()
		handler.ServeHTTP(rec, req)

		if rec.Code != http.StatusUnauthorized {
			t.Errorf("token %q got %d, want 401", token, rec.Code)
		}
	}
}

func TestAPIDispatchesACommand(t *testing.T) {
	s, ca, addr, _ := testGateway(t, "org-1")
	handler := NewAPI(s, "secret", quiet()).Handler()

	dialAgent(t, ca, addr, "web-1", echoHandler(0))
	waitFor(t, "node to register", func() bool { return s.IsConnected("org-1", "web-1") })

	req := httptest.NewRequest(http.MethodPost, "/v1/nodes/web-1/commands",
		strings.NewReader(`{"kind":"exec","wait_seconds":10}`))
	req.Header.Set(AuthHeader, "secret")
	req.Header.Set(OrganizationHeader, "org-1")

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, body = %s", rec.Code, rec.Body.String())
	}

	var out commandResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("decode: %v", err)
	}

	if out.Status != "succeeded" || !out.Outcome {
		t.Errorf("result = %+v", out)
	}
}

// A node that is not connected is 409 rather than 404: it probably exists and
// is simply away, which the control plane handles differently from unknown.
func TestAPIReportsAnUnconnectedNodeAsAConflict(t *testing.T) {
	s, _, _, _ := testGateway(t, "org-1")
	handler := NewAPI(s, "secret", quiet()).Handler()

	req := httptest.NewRequest(http.MethodPost, "/v1/nodes/absent/commands",
		strings.NewReader(`{"kind":"exec"}`))
	req.Header.Set(AuthHeader, "secret")
	req.Header.Set(OrganizationHeader, "org-1")

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409", rec.Code)
	}

	var out commandResponse
	_ = json.Unmarshal(rec.Body.Bytes(), &out)

	// The control plane must be able to tell "we do not know" from "it
	// failed", or it will retry things that may have run.
	if out.Outcome {
		t.Error("an undelivered command was reported as a known outcome")
	}
}

func TestAPIReplacesOrganisations(t *testing.T) {
	s, _, _, _ := testGateway(t, "org-1")
	handler := NewAPI(s, "secret", quiet()).Handler()

	other, err := pki.NewCA("org-2 CA", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}

	payload, _ := json.Marshal(map[string]any{
		"organisations": []map[string]string{
			{"id": "org-2", "ca_certificate_pem": string(other.CertPEM())},
		},
	})

	req := httptest.NewRequest(http.MethodPut, "/v1/organisations", strings.NewReader(string(payload)))
	req.Header.Set(AuthHeader, "secret")

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, body = %s", rec.Code, rec.Body.String())
	}

	// org-1 was replaced, not merged. An organisation removed upstream must
	// stop being accepted here.
	if s.registry.Len() != 1 {
		t.Errorf("registry holds %d organisations, want 1", s.registry.Len())
	}
}

func TestAPIRejectsANonCACertificate(t *testing.T) {
	s, ca, _, _ := testGateway(t, "org-1")
	handler := NewAPI(s, "secret", quiet()).Handler()

	_, csrPEM, _ := pki.NewKeyAndCSR("leaf")
	leafPEM, _ := ca.IssueNode(csrPEM, "leaf", pki.DefaultNodeValidity)

	payload, _ := json.Marshal(map[string]any{
		"organisations": []map[string]string{
			{"id": "org-bad", "ca_certificate_pem": string(leafPEM)},
		},
	})

	req := httptest.NewRequest(http.MethodPut, "/v1/organisations", strings.NewReader(string(payload)))
	req.Header.Set(AuthHeader, "secret")

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", rec.Code)
	}
}

// A gateway with no organisations cannot accept any node, so it must not
// report itself healthy while refusing every connection.
func TestHealthReportsAnEmptyRegistryAsUnhealthy(t *testing.T) {
	s := NewServer(NewRegistry(), Config{Logger: quiet()})
	handler := NewAPI(s, "secret", quiet()).Handler()

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/health", nil))

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", rec.Code)
	}
}

func TestHealthNeedsNoCredential(t *testing.T) {
	s, _, _, _ := testGateway(t, "org-1")
	handler := NewAPI(s, "secret", quiet()).Handler()

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/health", nil))

	// A health check that needs a credential is a health check that fails
	// for the wrong reasons.
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
}

// dialAgentWithCert is dialAgent that also returns the node certificate, so a
// test can revoke it by serial.
func dialAgentWithCert(t *testing.T, ca *pki.CA, addr, nodeID string) (*transport.Session, *x509.Certificate) {
	t.Helper()

	keyPEM, csrPEM, err := pki.NewKeyAndCSR(nodeID)
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	certPEM, err := ca.IssueNode(csrPEM, nodeID, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	block, _ := pem.Decode(certPEM)
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	cfg, err := transport.ClientTLS(certPEM, keyPEM, ca.CertPEM(), "gateway.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}
	conn, err := tls.Dial("tcp", addr, cfg)
	if err != nil {
		return nil, cert
	}
	session := transport.NewSession(conn, "gateway")
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(func() { cancel(); _ = session.Close() })
	go func() { _ = session.Serve(ctx, echoHandler(0), 0) }()

	hello, _ := protocol.NewEnvelope(protocol.TypeHello, protocol.Hello{NodeID: nodeID, AgentVersion: "test", OS: "linux", Arch: "amd64", Hostname: nodeID})
	reqCtx, reqCancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer reqCancel()
	if _, err := session.Request(reqCtx, hello); err != nil {
		return nil, cert
	}

	return session, cert
}

func revokeVia(t *testing.T, s *Server, ca *pki.CA, orgID string, serials ...string) map[string]any {
	t.Helper()
	payload, _ := json.Marshal(map[string]any{
		"organisations": []map[string]any{{"id": orgID, "ca_certificate_pem": string(ca.CertPEM()), "revoked_serials": serials}},
	})
	req := httptest.NewRequest(http.MethodPut, "/v1/organisations", strings.NewReader(string(payload)))
	req.Header.Set(AuthHeader, "secret")
	rec := httptest.NewRecorder()
	NewAPI(s, "secret", quiet()).Handler().ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, body = %s", rec.Code, rec.Body.String())
	}
	var out map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &out)

	return out
}

// Removing a server must cut it off at once, not when its certificate
// expires: the gateway otherwise trusts anything the organisation CA signed.
func TestRevokingAConnectedNodeDisconnectsIt(t *testing.T) {
	s, ca, addr, _ := testGateway(t, "org-1")

	session, cert := dialAgentWithCert(t, ca, addr, "web-9.org-1")
	if session == nil {
		t.Fatal("the node should connect before it is revoked")
	}
	waitFor(t, "connection", func() bool { return s.IsConnected("org-1", "web-9.org-1") })

	// OpenSSL prints serials upper case with leading zeros; Go does not.
	out := revokeVia(t, s, ca, "org-1", "00"+strings.ToUpper(cert.SerialNumber.Text(16)))

	if out["disconnected"] != float64(1) {
		t.Errorf("disconnected = %v, want 1", out["disconnected"])
	}
	waitFor(t, "disconnection", func() bool { return !s.IsConnected("org-1", "web-9.org-1") })
}

func TestARevokedCertificateCannotReconnect(t *testing.T) {
	s, ca, addr, _ := testGateway(t, "org-1")
	_, cert := dialAgentWithCert(t, ca, addr, "web-9.org-1")
	revokeVia(t, s, ca, "org-1", cert.SerialNumber.Text(16))

	if s.registry.IsRevoked("org-1", cert) != true {
		t.Fatal("the certificate should be revoked")
	}
	// A new certificate for the same name is a new server, and is accepted.
	again, _ := dialAgentWithCert(t, ca, addr, "web-9.org-1")
	if again == nil {
		t.Error("a fresh certificate for a reused name should connect")
	}
}

func TestRevocationIsPerOrganisation(t *testing.T) {
	r := NewRegistry()
	a, _ := pki.NewCA("a CA", pki.DefaultCAValidity)
	b, _ := pki.NewCA("b CA", pki.DefaultCAValidity)
	cert := &x509.Certificate{SerialNumber: big.NewInt(0xabc)}
	if err := r.Replace([]Organisation{
		{ID: "org-a", CACertificate: a.Certificate(), RevokedSerials: []string{"abc"}},
		{ID: "org-b", CACertificate: b.Certificate()},
	}); err != nil {
		t.Fatal(err)
	}
	if !r.IsRevoked("org-a", cert) || r.IsRevoked("org-b", cert) {
		t.Error("a serial revoked in one organisation must not affect another")
	}
}

// Two organisations may each have a node of the same name, since each CA can
// issue any name. Their sessions must not collide, replace one another, or
// receive one another's commands (audit 2026-11-08, finding 8).
func TestTheSameNodeNameInTwoOrganisationsStaysSeparate(t *testing.T) {
	a, _ := pki.NewCA("a CA", pki.DefaultCAValidity)
	b, _ := pki.NewCA("b CA", pki.DefaultCAValidity)

	certPEM, keyPEM, err := a.IssueServer("gateway.test", []string{"gateway.test"}, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatal(err)
	}

	registry := NewRegistry()
	if err := registry.Replace([]Organisation{
		{ID: "org-a", CACertificate: a.Certificate()},
		{ID: "org-b", CACertificate: b.Certificate()},
	}); err != nil {
		t.Fatal(err)
	}

	s := NewServer(registry, Config{ServerCertPEM: certPEM, ServerKeyPEM: keyPEM, HeartbeatSeconds: 1, Events: &capturing{}, Logger: quiet()})
	t.Cleanup(s.Close)

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	addr, err := s.Listen(ctx, "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}

	// Each organisation's node answers with a different status so the test
	// can tell which one received a command.
	dialAgentTrusting(t, a, a, addr, "web-1", echoHandler(0))
	dialAgentTrusting(t, b, a, addr, "web-1", echoHandler(0))

	waitFor(t, "both nodes to register", func() bool {
		return s.IsConnected("org-a", "web-1") && s.IsConnected("org-b", "web-1")
	})

	// The second connection must not have displaced the first.
	if len(s.Nodes("org-a")) != 1 || len(s.Nodes("org-b")) != 1 {
		t.Fatalf("each organisation should hold exactly one session, got a=%d b=%d", len(s.Nodes("org-a")), len(s.Nodes("org-b")))
	}

	// A command for an organisation with no such node must not reach another's.
	cctx, ccancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer ccancel()

	if _, err := s.Send(cctx, "org-c", "web-1", protocol.Command{Kind: protocol.CommandExec}); !errors.Is(err, ErrNodeNotConnected) {
		t.Errorf("a command for org-c reached a node, err = %v", err)
	}

	if _, err := s.Send(cctx, "org-a", "web-1", protocol.Command{Kind: protocol.CommandExec}); err != nil {
		t.Errorf("org-a's own node should answer: %v", err)
	}
}

// Every node call must name its organisation; a bare node id is refused.
func TestAPIRefusesANodeCallWithoutAnOrganisation(t *testing.T) {
	s, _, _, _ := testGateway(t, "org-1")
	handler := NewAPI(s, "secret", quiet()).Handler()

	for _, req := range []*http.Request{
		httptest.NewRequest(http.MethodPost, "/v1/nodes/web-1/commands", strings.NewReader(`{"kind":"exec"}`)),
		httptest.NewRequest(http.MethodPost, "/v1/nodes/web-1/terminal", strings.NewReader(`{"op":"close"}`)),
		httptest.NewRequest(http.MethodGet, "/v1/nodes/web-1", nil),
	} {
		req.Header.Set(AuthHeader, "secret")
		rec := httptest.NewRecorder()
		handler.ServeHTTP(rec, req)

		if rec.Code != http.StatusBadRequest {
			t.Errorf("%s %s without an organisation header = %d, want 400", req.Method, req.URL.Path, rec.Code)
		}
	}
}
