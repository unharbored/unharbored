// Package pki issues and verifies the certificates that authenticate agents
// to the control plane and the control plane to agents.
//
// The trust model is a private CA per organisation, held by the control
// plane. Every node gets a short-lived leaf certificate whose common name is
// the node ID. Nothing else in the system is authorised to mint these, and
// nothing on the node can change the identity it was issued.
//
// ECDSA P-256 is used throughout rather than Ed25519. Ed25519 would be the
// better primitive in isolation, but the control plane is a separate codebase
// in another language and P-256 is the option with no cross-language sharp
// edges. See docs/adr/0004-ecdsa-p256.md.
package pki

import (
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"time"
)

const (
	// DefaultCAValidity is how long a freshly created CA is good for.
	DefaultCAValidity = 10 * 365 * 24 * time.Hour

	// DefaultNodeValidity is the lifetime of an issued node certificate.
	// Short on purpose: a leaked node key stops being useful quickly, and a
	// short lifetime forces the renewal path to be exercised constantly
	// rather than discovered to be broken during an incident.
	DefaultNodeValidity = 30 * 24 * time.Hour

	// RenewBefore is how long ahead of expiry an agent should renew.
	RenewBefore = 7 * 24 * time.Hour

	// clockSkew backdates NotBefore so a node whose clock runs slightly fast
	// does not reject a certificate that was just issued for it.
	clockSkew = 5 * time.Minute
)

// PEM block types.
const (
	blockCertificate = "CERTIFICATE"
	blockECKey       = "EC PRIVATE KEY"
	blockCSR         = "CERTIFICATE REQUEST"
)

var (
	// ErrNotCA is returned when a certificate expected to be a CA is not one.
	ErrNotCA = errors.New("pki: certificate is not a certificate authority")
	// ErrBadCSR is returned when a CSR is malformed or self-inconsistent.
	ErrBadCSR = errors.New("pki: invalid certificate signing request")
	// ErrEmptyNodeID is returned when issuing without an identity.
	ErrEmptyNodeID = errors.New("pki: node id must not be empty")
)

// CA signs node and server certificates.
type CA struct {
	cert *x509.Certificate
	key  crypto.Signer
	der  []byte
}

// NewCA creates a self-signed certificate authority.
func NewCA(commonName string, validity time.Duration) (*CA, error) {
	if validity <= 0 {
		validity = DefaultCAValidity
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, fmt.Errorf("pki: generate CA key: %w", err)
	}

	serial, err := newSerial()
	if err != nil {
		return nil, err
	}

	now := time.Now()
	tmpl := &x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: commonName},
		NotBefore:             now.Add(-clockSkew),
		NotAfter:              now.Add(validity),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageCRLSign | x509.KeyUsageDigitalSignature,
		BasicConstraintsValid: true,
		IsCA:                  true,
		// The CA signs leaves only. Nothing in this system needs an
		// intermediate, so forbid one.
		MaxPathLen:     0,
		MaxPathLenZero: true,
	}

	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, key.Public(), key)
	if err != nil {
		return nil, fmt.Errorf("pki: self-sign CA: %w", err)
	}

	cert, err := x509.ParseCertificate(der)
	if err != nil {
		return nil, fmt.Errorf("pki: parse freshly created CA: %w", err)
	}

	return &CA{cert: cert, key: key, der: der}, nil
}

// LoadCA reconstructs a CA from PEM-encoded certificate and private key.
func LoadCA(certPEM, keyPEM []byte) (*CA, error) {
	certDER, err := decodePEM(certPEM, blockCertificate)
	if err != nil {
		return nil, fmt.Errorf("pki: decode CA certificate: %w", err)
	}
	cert, err := x509.ParseCertificate(certDER)
	if err != nil {
		return nil, fmt.Errorf("pki: parse CA certificate: %w", err)
	}
	if !cert.IsCA {
		return nil, ErrNotCA
	}

	keyDER, err := decodePEM(keyPEM, blockECKey)
	if err != nil {
		return nil, fmt.Errorf("pki: decode CA key: %w", err)
	}
	key, err := x509.ParseECPrivateKey(keyDER)
	if err != nil {
		return nil, fmt.Errorf("pki: parse CA key: %w", err)
	}

	return &CA{cert: cert, key: key, der: certDER}, nil
}

// Certificate returns the CA certificate.
func (ca *CA) Certificate() *x509.Certificate { return ca.cert }

// CertPEM returns the PEM-encoded CA certificate.
func (ca *CA) CertPEM() []byte {
	return pem.EncodeToMemory(&pem.Block{Type: blockCertificate, Bytes: ca.der})
}

// KeyPEM returns the PEM-encoded CA private key.
//
// This is the most sensitive secret in the system: it mints identities for
// every node in an organisation. Callers must treat the result as key
// material, never log it, and encrypt it at rest.
func (ca *CA) KeyPEM() ([]byte, error) {
	ecKey, ok := ca.key.(*ecdsa.PrivateKey)
	if !ok {
		return nil, fmt.Errorf("pki: CA key is %T, expected *ecdsa.PrivateKey", ca.key)
	}
	der, err := x509.MarshalECPrivateKey(ecKey)
	if err != nil {
		return nil, fmt.Errorf("pki: marshal CA key: %w", err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: blockECKey, Bytes: der}), nil
}

// Pool returns a certificate pool trusting only this CA.
func (ca *CA) Pool() *x509.CertPool {
	pool := x509.NewCertPool()
	pool.AddCert(ca.cert)
	return pool
}

// IssueNode signs a node certificate from a CSR.
//
// nodeID is supplied by the caller, not read from the CSR. The control plane
// decides what a node is called at enrollment time; a node asking to be
// called something else must not be able to influence the result. Only the
// public key and the proof of possession are taken from the request.
func (ca *CA) IssueNode(csrPEM []byte, nodeID string, validity time.Duration) ([]byte, error) {
	if nodeID == "" {
		return nil, ErrEmptyNodeID
	}
	if validity <= 0 {
		validity = DefaultNodeValidity
	}

	csr, err := ParseCSR(csrPEM)
	if err != nil {
		return nil, err
	}

	serial, err := newSerial()
	if err != nil {
		return nil, err
	}

	now := time.Now()
	tmpl := &x509.Certificate{
		SerialNumber: serial,
		Subject:      pkix.Name{CommonName: nodeID},
		NotBefore:    now.Add(-clockSkew),
		NotAfter:     now.Add(validity),
		KeyUsage:     x509.KeyUsageDigitalSignature | x509.KeyUsageKeyEncipherment,
		// Client auth only. A node certificate must never be usable to
		// impersonate the control plane to another node.
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
		BasicConstraintsValid: true,
		IsCA:                  false,
	}

	der, err := x509.CreateCertificate(rand.Reader, tmpl, ca.cert, csr.PublicKey, ca.key)
	if err != nil {
		return nil, fmt.Errorf("pki: sign node certificate: %w", err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: blockCertificate, Bytes: der}), nil
}

// IssueServer signs a control plane server certificate.
//
// Unlike node certificates this one is generated whole rather than from a
// CSR, because the control plane holds the CA and has no reason to go through
// a request it would immediately approve.
func (ca *CA) IssueServer(commonName string, dnsNames []string, validity time.Duration) (certPEM, keyPEM []byte, err error) {
	if validity <= 0 {
		validity = DefaultNodeValidity
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, fmt.Errorf("pki: generate server key: %w", err)
	}

	serial, err := newSerial()
	if err != nil {
		return nil, nil, err
	}

	now := time.Now()
	tmpl := &x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: commonName},
		DNSNames:              dnsNames,
		NotBefore:             now.Add(-clockSkew),
		NotAfter:              now.Add(validity),
		KeyUsage:              x509.KeyUsageDigitalSignature | x509.KeyUsageKeyEncipherment,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		IsCA:                  false,
	}

	der, err := x509.CreateCertificate(rand.Reader, tmpl, ca.cert, key.Public(), ca.key)
	if err != nil {
		return nil, nil, fmt.Errorf("pki: sign server certificate: %w", err)
	}
	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, nil, fmt.Errorf("pki: marshal server key: %w", err)
	}

	certPEM = pem.EncodeToMemory(&pem.Block{Type: blockCertificate, Bytes: der})
	keyPEM = pem.EncodeToMemory(&pem.Block{Type: blockECKey, Bytes: keyDER})
	return certPEM, keyPEM, nil
}

// NewKeyAndCSR generates a node private key and a signing request for it.
// This runs on the node; the private key never leaves it.
//
// The common name in the request is advisory. The control plane overrides it
// with the identity it assigned at enrollment.
func NewKeyAndCSR(commonName string) (keyPEM, csrPEM []byte, err error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, fmt.Errorf("pki: generate node key: %w", err)
	}

	tmpl := &x509.CertificateRequest{
		Subject:            pkix.Name{CommonName: commonName},
		SignatureAlgorithm: x509.ECDSAWithSHA256,
	}
	csrDER, err := x509.CreateCertificateRequest(rand.Reader, tmpl, key)
	if err != nil {
		return nil, nil, fmt.Errorf("pki: create CSR: %w", err)
	}

	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, nil, fmt.Errorf("pki: marshal node key: %w", err)
	}

	keyPEM = pem.EncodeToMemory(&pem.Block{Type: blockECKey, Bytes: keyDER})
	csrPEM = pem.EncodeToMemory(&pem.Block{Type: blockCSR, Bytes: csrDER})
	return keyPEM, csrPEM, nil
}

// ParseCSR decodes and validates a PEM-encoded certificate signing request.
func ParseCSR(csrPEM []byte) (*x509.CertificateRequest, error) {
	der, err := decodePEM(csrPEM, blockCSR)
	if err != nil {
		return nil, fmt.Errorf("%w: %v", ErrBadCSR, err)
	}
	csr, err := x509.ParseCertificateRequest(der)
	if err != nil {
		return nil, fmt.Errorf("%w: %v", ErrBadCSR, err)
	}
	// Proof of possession. Without this check anyone could request a
	// certificate over somebody else's public key.
	if err := csr.CheckSignature(); err != nil {
		return nil, fmt.Errorf("%w: signature: %v", ErrBadCSR, err)
	}
	return csr, nil
}

// ParseCertificate decodes a PEM-encoded certificate.
func ParseCertificate(certPEM []byte) (*x509.Certificate, error) {
	der, err := decodePEM(certPEM, blockCertificate)
	if err != nil {
		return nil, err
	}
	return x509.ParseCertificate(der)
}

// NodeIDFromCertificate extracts the node identity a certificate asserts.
func NodeIDFromCertificate(cert *x509.Certificate) string {
	return cert.Subject.CommonName
}

// NeedsRenewal reports whether a certificate is close enough to expiry that
// the agent should request a new one.
func NeedsRenewal(cert *x509.Certificate, now time.Time) bool {
	return now.Add(RenewBefore).After(cert.NotAfter)
}

// decodePEM extracts a single block of the expected type.
func decodePEM(data []byte, wantType string) ([]byte, error) {
	block, rest := pem.Decode(data)
	if block == nil {
		return nil, errors.New("no PEM block found")
	}
	if block.Type != wantType {
		return nil, fmt.Errorf("expected PEM block %q, got %q", wantType, block.Type)
	}
	if len(trimSpace(rest)) != 0 {
		return nil, fmt.Errorf("unexpected trailing data after %q block", wantType)
	}
	return block.Bytes, nil
}

// trimSpace drops ASCII whitespace without pulling in strings/bytes for one
// call. PEM trailers are newline-padded and that is the only slack we allow.
func trimSpace(b []byte) []byte {
	start := 0
	for start < len(b) && isSpace(b[start]) {
		start++
	}
	end := len(b)
	for end > start && isSpace(b[end-1]) {
		end--
	}
	return b[start:end]
}

func isSpace(c byte) bool {
	return c == ' ' || c == '\t' || c == '\r' || c == '\n'
}

// newSerial draws a random 128-bit certificate serial.
func newSerial() (*big.Int, error) {
	limit := new(big.Int).Lsh(big.NewInt(1), 128)
	serial, err := rand.Int(rand.Reader, limit)
	if err != nil {
		return nil, fmt.Errorf("pki: generate serial: %w", err)
	}
	return serial, nil
}
