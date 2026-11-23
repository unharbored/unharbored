// Package transport carries the protocol over a mutually authenticated TLS
// connection that the agent opens outbound to the control plane.
//
// The direction matters. Nothing listens on the node, no inbound port is
// opened, and the control plane holds no SSH key that grants root on a
// customer machine. The node's only credential is a short-lived client
// certificate whose common name is its identity, and the only thing it trusts
// is the organisation CA pinned at enrollment.
package transport

import (
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"

	"github.com/unharbored/unharbored/internal/pki"
)

var (
	// ErrNoClientCertificate is returned when a peer connects without one.
	ErrNoClientCertificate = errors.New("transport: peer presented no client certificate")
	// ErrIdentityMismatch is returned when the identity a peer claims in its
	// Hello disagrees with the identity in its certificate.
	ErrIdentityMismatch = errors.New("transport: hello identity does not match certificate")
)

// minTLSVersion is TLS 1.3 with no fallback. Both ends of this connection are
// software we ship, so there is no legacy peer to accommodate and no reason
// to negotiate anything weaker.
const minTLSVersion = tls.VersionTLS13

// ClientTLS builds the agent-side TLS configuration.
//
// serverName is verified against the control plane certificate. The CA pool
// is built solely from caPEM, so the host's root store is irrelevant: a
// compromised or coerced public CA cannot be used to impersonate the control
// plane to one of our agents.
func ClientTLS(certPEM, keyPEM, caPEM []byte, serverName string) (*tls.Config, error) {
	cert, err := tls.X509KeyPair(certPEM, keyPEM)
	if err != nil {
		return nil, fmt.Errorf("transport: load client keypair: %w", err)
	}

	pool, err := poolFromPEM(caPEM)
	if err != nil {
		return nil, err
	}

	return &tls.Config{
		Certificates: []tls.Certificate{cert},
		RootCAs:      pool,
		ServerName:   serverName,
		MinVersion:   minTLSVersion,
	}, nil
}

// ServerTLS builds the control plane TLS configuration.
//
// Client certificates are required and verified against the organisation CA.
// An agent that has not enrolled, or whose certificate was issued by anyone
// else, cannot complete the handshake, so unauthenticated traffic never
// reaches the protocol layer.
func ServerTLS(certPEM, keyPEM, caPEM []byte) (*tls.Config, error) {
	cert, err := tls.X509KeyPair(certPEM, keyPEM)
	if err != nil {
		return nil, fmt.Errorf("transport: load server keypair: %w", err)
	}

	pool, err := poolFromPEM(caPEM)
	if err != nil {
		return nil, err
	}

	return &tls.Config{
		Certificates: []tls.Certificate{cert},
		ClientCAs:    pool,
		ClientAuth:   tls.RequireAndVerifyClientCert,
		MinVersion:   minTLSVersion,
	}, nil
}

// EnrollmentClientTLS builds the TLS configuration for the one unauthenticated
// call an agent makes: enrollment itself.
//
// The agent has no certificate yet, so the connection is server-authenticated
// only. The CA is still pinned, because cloud-init delivered it alongside the
// token and the agent has no reason to trust anything else.
func EnrollmentClientTLS(caPEM []byte, serverName string) (*tls.Config, error) {
	pool, err := poolFromPEM(caPEM)
	if err != nil {
		return nil, err
	}
	return &tls.Config{
		RootCAs:    pool,
		ServerName: serverName,
		MinVersion: minTLSVersion,
	}, nil
}

// PeerNodeID extracts the node identity from a completed TLS handshake.
//
// The identity comes from the verified certificate chain, never from anything
// the peer says about itself. VerifiedChains is populated by the TLS stack
// only after a successful verification against ClientCAs, so reaching this
// function at all means the certificate chained to our CA.
func PeerNodeID(state tls.ConnectionState) (string, error) {
	if len(state.VerifiedChains) == 0 || len(state.VerifiedChains[0]) == 0 {
		return "", ErrNoClientCertificate
	}
	leaf := state.VerifiedChains[0][0]
	id := pki.NodeIDFromCertificate(leaf)
	if id == "" {
		return "", fmt.Errorf("transport: peer certificate has no common name")
	}
	return id, nil
}

func poolFromPEM(caPEM []byte) (*x509.CertPool, error) {
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(caPEM) {
		return nil, errors.New("transport: no usable certificate in CA PEM")
	}
	return pool, nil
}
