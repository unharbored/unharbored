package transport

import (
	"crypto/tls"
	"errors"
	"net"
	"testing"
	"time"

	"github.com/unharbored/unharbored/internal/pki"
)

type testPKI struct {
	ca                *pki.CA
	serverCert        []byte
	serverKey         []byte
	caPEM             []byte
	nodeCert, nodeKey []byte
	nodeID            string
}

func newTestPKI(t *testing.T, nodeID string) *testPKI {
	t.Helper()

	ca, err := pki.NewCA("test-org-ca", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}
	serverCert, serverKey, err := ca.IssueServer("cp.test", []string{"cp.test"}, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueServer: %v", err)
	}
	nodeKey, csrPEM, err := pki.NewKeyAndCSR(nodeID)
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	nodeCert, err := ca.IssueNode(csrPEM, nodeID, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}

	return &testPKI{
		ca:         ca,
		serverCert: serverCert,
		serverKey:  serverKey,
		caPEM:      ca.CertPEM(),
		nodeCert:   nodeCert,
		nodeKey:    nodeKey,
		nodeID:     nodeID,
	}
}

// serve starts a TLS listener and returns its address plus a channel carrying
// the node ID of the first peer to complete a handshake.
func serve(t *testing.T, cfg *tls.Config) (addr string, peers chan string, errs chan error) {
	t.Helper()

	ln, err := tls.Listen("tcp", "127.0.0.1:0", cfg)
	if err != nil {
		t.Fatalf("Listen: %v", err)
	}
	t.Cleanup(func() { _ = ln.Close() })

	peers = make(chan string, 1)
	errs = make(chan error, 1)

	go func() {
		conn, err := ln.Accept()
		if err != nil {
			errs <- err
			return
		}
		defer conn.Close()

		tlsConn, ok := conn.(*tls.Conn)
		if !ok {
			errs <- errors.New("not a TLS connection")
			return
		}
		_ = tlsConn.SetDeadline(time.Now().Add(5 * time.Second))
		if err := tlsConn.Handshake(); err != nil {
			errs <- err
			return
		}
		id, err := PeerNodeID(tlsConn.ConnectionState())
		if err != nil {
			errs <- err
			return
		}
		peers <- id
	}()

	return ln.Addr().String(), peers, errs
}

func dial(t *testing.T, addr string, cfg *tls.Config) (*tls.Conn, error) {
	t.Helper()
	d := &net.Dialer{Timeout: 5 * time.Second}
	conn, err := tls.DialWithDialer(d, "tcp", addr, cfg)
	if err != nil {
		return nil, err
	}
	t.Cleanup(func() { _ = conn.Close() })
	if err := conn.Handshake(); err != nil {
		return nil, err
	}
	return conn, nil
}

func TestMutualTLSHandshakeSucceeds(t *testing.T) {
	p := newTestPKI(t, "node-42")

	serverCfg, err := ServerTLS(p.serverCert, p.serverKey, p.caPEM)
	if err != nil {
		t.Fatalf("ServerTLS: %v", err)
	}
	addr, peers, errs := serve(t, serverCfg)

	clientCfg, err := ClientTLS(p.nodeCert, p.nodeKey, p.caPEM, "cp.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}
	if _, err := dial(t, addr, clientCfg); err != nil {
		t.Fatalf("dial: %v", err)
	}

	select {
	case id := <-peers:
		if id != "node-42" {
			t.Errorf("PeerNodeID = %q, want node-42", id)
		}
	case err := <-errs:
		t.Fatalf("server: %v", err)
	case <-time.After(5 * time.Second):
		t.Fatal("timed out waiting for handshake")
	}
}

// The identity the control plane acts on comes from the certificate, so a
// connection without one must never reach the protocol layer.
func TestServerRejectsClientWithoutCertificate(t *testing.T) {
	p := newTestPKI(t, "node-42")

	serverCfg, err := ServerTLS(p.serverCert, p.serverKey, p.caPEM)
	if err != nil {
		t.Fatalf("ServerTLS: %v", err)
	}
	addr, peers, _ := serve(t, serverCfg)

	// A client that pins our CA but presents no certificate of its own.
	anonCfg, err := EnrollmentClientTLS(p.caPEM, "cp.test")
	if err != nil {
		t.Fatalf("EnrollmentClientTLS: %v", err)
	}

	conn, err := dial(t, addr, anonCfg)
	if err == nil {
		// Some stacks defer the rejection to the first read. Force it.
		_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
		buf := make([]byte, 1)
		if _, rerr := conn.Read(buf); rerr == nil {
			t.Fatal("anonymous client completed a mutually authenticated handshake")
		}
	}

	select {
	case id := <-peers:
		t.Fatalf("server accepted an anonymous peer as %q", id)
	case <-time.After(500 * time.Millisecond):
		// Correct: no peer was authenticated.
	}
}

// A certificate from another organisation's CA must not authenticate, or
// tenant isolation is nominal.
func TestServerRejectsForeignCertificate(t *testing.T) {
	ours := newTestPKI(t, "node-42")
	theirs := newTestPKI(t, "node-42")

	serverCfg, err := ServerTLS(ours.serverCert, ours.serverKey, ours.caPEM)
	if err != nil {
		t.Fatalf("ServerTLS: %v", err)
	}
	addr, peers, _ := serve(t, serverCfg)

	// The foreign client trusts our server CA so the failure under test is
	// specifically our server rejecting their client certificate.
	clientCfg, err := ClientTLS(theirs.nodeCert, theirs.nodeKey, ours.caPEM, "cp.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}

	conn, err := dial(t, addr, clientCfg)
	if err == nil {
		_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
		buf := make([]byte, 1)
		if _, rerr := conn.Read(buf); rerr == nil {
			t.Fatal("foreign certificate completed the handshake")
		}
	}

	select {
	case id := <-peers:
		t.Fatalf("server authenticated a foreign certificate as %q", id)
	case <-time.After(500 * time.Millisecond):
	}
}

// The agent pins the organisation CA, so a control plane impersonator holding
// any other certificate must be refused even if it is otherwise valid.
func TestClientRejectsForeignServer(t *testing.T) {
	ours := newTestPKI(t, "node-42")
	impostor := newTestPKI(t, "node-42")

	serverCfg, err := ServerTLS(impostor.serverCert, impostor.serverKey, impostor.caPEM)
	if err != nil {
		t.Fatalf("ServerTLS: %v", err)
	}
	addr, _, _ := serve(t, serverCfg)

	clientCfg, err := ClientTLS(ours.nodeCert, ours.nodeKey, ours.caPEM, "cp.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}

	if _, err := dial(t, addr, clientCfg); err == nil {
		t.Fatal("agent trusted a control plane signed by an unknown CA")
	}
}

// Server name verification must be on. Without it, anyone holding any
// certificate from our CA, including a node certificate, could stand in for
// the control plane.
func TestClientVerifiesServerName(t *testing.T) {
	p := newTestPKI(t, "node-42")

	serverCfg, err := ServerTLS(p.serverCert, p.serverKey, p.caPEM)
	if err != nil {
		t.Fatalf("ServerTLS: %v", err)
	}
	addr, _, _ := serve(t, serverCfg)

	clientCfg, err := ClientTLS(p.nodeCert, p.nodeKey, p.caPEM, "not-the-control-plane.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}

	if _, err := dial(t, addr, clientCfg); err == nil {
		t.Fatal("agent accepted a certificate for the wrong server name")
	}
}

func TestTLS13IsRequired(t *testing.T) {
	p := newTestPKI(t, "node-42")

	serverCfg, err := ServerTLS(p.serverCert, p.serverKey, p.caPEM)
	if err != nil {
		t.Fatalf("ServerTLS: %v", err)
	}
	if serverCfg.MinVersion != tls.VersionTLS13 {
		t.Errorf("server MinVersion = %x, want TLS 1.3", serverCfg.MinVersion)
	}
	addr, _, _ := serve(t, serverCfg)

	clientCfg, err := ClientTLS(p.nodeCert, p.nodeKey, p.caPEM, "cp.test")
	if err != nil {
		t.Fatalf("ClientTLS: %v", err)
	}
	if clientCfg.MinVersion != tls.VersionTLS13 {
		t.Errorf("client MinVersion = %x, want TLS 1.3", clientCfg.MinVersion)
	}

	// A client capped at 1.2 must fail against a 1.3-only server.
	downgraded := clientCfg.Clone()
	downgraded.MinVersion = tls.VersionTLS12
	downgraded.MaxVersion = tls.VersionTLS12

	if _, err := dial(t, addr, downgraded); err == nil {
		t.Fatal("server negotiated TLS 1.2")
	}
}

func TestPeerNodeIDWithoutVerifiedChain(t *testing.T) {
	if _, err := PeerNodeID(tls.ConnectionState{}); !errors.Is(err, ErrNoClientCertificate) {
		t.Fatalf("err = %v, want ErrNoClientCertificate", err)
	}
}

func TestTLSConfigRejectsBadMaterial(t *testing.T) {
	p := newTestPKI(t, "node-42")

	t.Run("client bad keypair", func(t *testing.T) {
		if _, err := ClientTLS([]byte("nope"), []byte("nope"), p.caPEM, "cp.test"); err == nil {
			t.Fatal("expected error")
		}
	})
	t.Run("client bad ca", func(t *testing.T) {
		if _, err := ClientTLS(p.nodeCert, p.nodeKey, []byte("nope"), "cp.test"); err == nil {
			t.Fatal("expected error")
		}
	})
	t.Run("server bad keypair", func(t *testing.T) {
		if _, err := ServerTLS([]byte("nope"), []byte("nope"), p.caPEM); err == nil {
			t.Fatal("expected error")
		}
	})
	t.Run("server bad ca", func(t *testing.T) {
		if _, err := ServerTLS(p.serverCert, p.serverKey, []byte("nope")); err == nil {
			t.Fatal("expected error")
		}
	})
	t.Run("enrollment bad ca", func(t *testing.T) {
		if _, err := EnrollmentClientTLS([]byte("nope"), "cp.test"); err == nil {
			t.Fatal("expected error")
		}
	})
}

func TestServerRequiresAndVerifiesClientCert(t *testing.T) {
	p := newTestPKI(t, "node-42")
	cfg, err := ServerTLS(p.serverCert, p.serverKey, p.caPEM)
	if err != nil {
		t.Fatalf("ServerTLS: %v", err)
	}
	if cfg.ClientAuth != tls.RequireAndVerifyClientCert {
		t.Errorf("ClientAuth = %v, want RequireAndVerifyClientCert", cfg.ClientAuth)
	}
	if cfg.ClientCAs == nil {
		t.Error("ClientCAs is nil")
	}
}
