// Package gateway holds agent connections on behalf of the control plane.
//
// It exists because PHP-FPM serves a request and exits, so the Laravel
// control plane cannot hold thousands of long-lived mTLS sessions. The
// gateway does that one thing; everything else, tenancy, credentials, audit,
// the state machine, provisioning, the API and the UI, stays in Laravel.
//
// See docs/adr/0007-session-gateway.md.
package gateway

import (
	"crypto/sha256"
	"crypto/x509"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"
)

var (
	// ErrUnknownIssuer means a certificate verified against some CA in the
	// pool but the gateway cannot say which organisation that CA belongs to.
	// Refusing is the only safe response: a node whose tenant is unknown
	// cannot be placed in one.
	ErrUnknownIssuer = errors.New("gateway: certificate issuer is not a known organisation CA")

	// ErrNoVerifiedChain means the TLS layer produced no verified chain,
	// which should be impossible after RequireAndVerifyClientCert.
	ErrNoVerifiedChain = errors.New("gateway: no verified certificate chain")
)

// Organisation is a tenant whose nodes this gateway accepts.
type Organisation struct {
	ID string
	// CACertificate is the organisation's certificate authority. The gateway
	// only ever verifies with it; the private key stays in the control
	// plane's vault and never reaches this process.
	CACertificate *x509.Certificate

	// RevokedSerials are the certificates of removed nodes, as lower-case hex
	// without leading zeros. Revoking by serial rather than node id means a
	// server added again under an old name, with a new certificate, works.
	RevokedSerials []string
}

// Registry maps organisations to the CAs that identify their nodes.
//
// The important decision lives here. A node's organisation is determined by
// **which CA signed its certificate**, not by its common name, not by
// anything it says in its Hello, and not by any field it controls. One CA per
// organisation means the signature is the tenancy claim, and a node from one
// tenant cannot assert membership of another without that tenant's CA key.
//
// The alternative, encoding the organisation in the node id and parsing it
// out, would make tenancy a string-handling problem. It would work until the
// first node named to look like it belongs somewhere else.
type Registry struct {
	mu sync.RWMutex

	// byFingerprint maps a CA certificate fingerprint to its organisation.
	byFingerprint map[string]string

	// pool is what the TLS layer verifies client certificates against. It
	// holds every organisation CA, so verification succeeds for any tenant
	// and the issuer lookup then says which one.
	pool *x509.CertPool

	// updatedAt records the last successful refresh, so a gateway running on
	// a stale registry can be noticed.
	updatedAt time.Time

	revoked map[string]map[string]struct{}
}

// NewRegistry returns an empty registry.
func NewRegistry() *Registry {
	return &Registry{
		byFingerprint: make(map[string]string),
		pool:          x509.NewCertPool(),
		revoked:       make(map[string]map[string]struct{}),
	}
}

// Replace swaps the whole set of organisations atomically.
//
// Whole-set replacement rather than incremental add and remove, because the
// control plane is the authority and a gateway that has drifted from it
// should converge rather than merge. An organisation removed upstream must
// stop being accepted here, and an incremental API makes forgetting that the
// default.
func (r *Registry) Replace(orgs []Organisation) error {
	byFingerprint := make(map[string]string, len(orgs))
	pool := x509.NewCertPool()
	revoked := make(map[string]map[string]struct{}, len(orgs))

	for _, org := range orgs {
		if org.ID == "" {
			return errors.New("gateway: organisation has no id")
		}
		if org.CACertificate == nil {
			return fmt.Errorf("gateway: organisation %s has no CA certificate", org.ID)
		}
		if !org.CACertificate.IsCA {
			return fmt.Errorf("gateway: certificate for organisation %s is not a CA", org.ID)
		}

		fingerprint := Fingerprint(org.CACertificate)

		// Two organisations sharing a CA would make the issuer ambiguous and
		// silently merge their tenancy. Refuse rather than pick one.
		if existing, clash := byFingerprint[fingerprint]; clash && existing != org.ID {
			return fmt.Errorf(
				"gateway: organisations %s and %s share a CA certificate, so their nodes cannot be told apart",
				existing, org.ID,
			)
		}

		byFingerprint[fingerprint] = org.ID
		pool.AddCert(org.CACertificate)

		revoked[org.ID] = make(map[string]struct{}, len(org.RevokedSerials))
		for _, serial := range org.RevokedSerials {
			revoked[org.ID][NormaliseSerial(serial)] = struct{}{}
		}
	}

	r.mu.Lock()
	defer r.mu.Unlock()
	r.byFingerprint = byFingerprint
	r.pool = pool
	r.revoked = revoked
	r.updatedAt = time.Now()

	return nil
}

// Pool returns the certificate pool for client verification.
func (r *Registry) Pool() *x509.CertPool {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return r.pool
}

// Len returns how many organisations are known.
func (r *Registry) Len() int {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return len(r.byFingerprint)
}

// UpdatedAt returns when the registry was last replaced.
func (r *Registry) UpdatedAt() time.Time {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return r.updatedAt
}

// OrganisationFor resolves the tenant that a verified chain belongs to.
//
// chain is a verified chain as produced by the TLS stack: the leaf first,
// then the CA that signed it. Reaching this function means the signature
// already checked out against the pool; this answers whose signature it was.
func (r *Registry) OrganisationFor(chain []*x509.Certificate) (string, error) {
	if len(chain) < 2 {
		// A leaf with no issuer in the chain. Either the TLS layer was
		// configured without verification, or someone presented a
		// self-signed certificate. Neither is a tenant.
		return "", ErrNoVerifiedChain
	}

	issuer := chain[1]

	r.mu.RLock()
	defer r.mu.RUnlock()

	orgID, ok := r.byFingerprint[Fingerprint(issuer)]
	if !ok {
		return "", fmt.Errorf("%w: %q", ErrUnknownIssuer, issuer.Subject.CommonName)
	}

	return orgID, nil
}

// Fingerprint is the SHA-256 of a certificate's DER encoding, hex encoded.
//
// Used as the registry key rather than the subject, because a subject is
// chosen by whoever creates the certificate and two organisations could
// easily pick the same common name. A fingerprint cannot collide by accident.
func Fingerprint(cert *x509.Certificate) string {
	sum := sha256.Sum256(cert.Raw)
	return hex.EncodeToString(sum[:])
}

// IsRevoked reports whether a node certificate has been revoked by its
// organisation.
func (r *Registry) IsRevoked(organizationID string, cert *x509.Certificate) bool {
	r.mu.RLock()
	defer r.mu.RUnlock()

	_, revoked := r.revoked[organizationID][NormaliseSerial(cert.SerialNumber.Text(16))]

	return revoked
}

// NormaliseSerial makes "00AB" and "ab" the same serial, as OpenSSL and Go
// print them differently.
func NormaliseSerial(serial string) string {
	s := strings.TrimLeft(strings.ToLower(strings.TrimSpace(serial)), "0")
	if s == "" {
		return "0"
	}

	return s
}
