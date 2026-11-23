// Package enroll implements the one-time handshake that turns a freshly
// booted Droplet into a node the control plane trusts.
//
// The flow:
//
//  1. The control plane mints an enrollment token bound to a node identity it
//     has already decided on, and embeds it in the Droplet's cloud-init
//     user_data.
//  2. Cloud-init installs the agent and runs it once with the token.
//  3. The agent generates a private key, which never leaves the node, and a
//     CSR over it.
//  4. The agent presents token and CSR to the control plane over a
//     server-authenticated TLS connection. It has no client certificate yet,
//     so the token is the only thing authenticating it.
//  5. The control plane burns the token, signs the CSR with the node identity
//     it assigned, and returns the certificate plus the CA certificate.
//  6. Every subsequent connection is mutually authenticated. The token is
//     dead and the agent never needs it again.
//
// The token is the weakest link by construction, so it is single-use,
// short-lived, and bound to one node identity. A token that leaks after
// cloud-init has run is worthless, and one that leaks before only lets the
// holder become the node it already described.
package enroll

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"
	"sync"
	"time"

	"github.com/unharbored/unharbored/internal/pki"
)

const (
	// TokenBytes is the entropy in an enrollment token.
	TokenBytes = 32

	// DefaultTokenTTL bounds how long a token stays usable. Cloud-init
	// normally enrolls within a minute of boot; an hour is generous slack for
	// a slow image or a retried provision, and still short enough that a
	// token found later is useless.
	DefaultTokenTTL = time.Hour
)

// Sentinel errors. Callers should map all of them to one opaque failure on
// the wire. Telling an unauthenticated caller whether a token was unknown,
// expired or already used hands them an oracle for free.
var (
	ErrTokenUnknown   = errors.New("enroll: token not recognised")
	ErrTokenExpired   = errors.New("enroll: token expired")
	ErrTokenConsumed  = errors.New("enroll: token already used")
	ErrTokenMalformed = errors.New("enroll: token malformed")
)

// ErrEnrollmentRejected is the single error surfaced to an unauthenticated
// caller, regardless of the underlying cause.
var ErrEnrollmentRejected = errors.New("enroll: enrollment rejected")

// TokenHash is the stored form of a token. The plaintext token exists only in
// cloud-init user_data and in the agent's memory during enrollment; the
// control plane keeps nothing that could be replayed if its database leaked.
type TokenHash [sha256.Size]byte

// HashToken derives the stored form of a token.
func HashToken(token string) TokenHash {
	return sha256.Sum256([]byte(token))
}

// NewToken mints a fresh enrollment token and returns it with its hash.
func NewToken() (token string, hash TokenHash, err error) {
	buf := make([]byte, TokenBytes)
	if _, err := rand.Read(buf); err != nil {
		return "", TokenHash{}, fmt.Errorf("enroll: generate token: %w", err)
	}
	token = base64.RawURLEncoding.EncodeToString(buf)
	return token, HashToken(token), nil
}

// Grant is what a token entitles its bearer to become. The control plane
// decides every field before the node exists.
type Grant struct {
	// NodeID is the identity that will be burned into the node certificate.
	NodeID string
	// OrgID scopes the node to an organisation, and therefore to a CA.
	OrgID string
	// ExpiresAt bounds the token's usable lifetime.
	ExpiresAt time.Time
	// ConsumedAt is set once the token has been redeemed.
	ConsumedAt time.Time
}

// Store persists pending enrollment grants.
//
// Consume must be atomic. Two agents racing with the same token, which
// happens when cloud-init retries, must not both succeed.
type Store interface {
	// Put records a grant against a token hash.
	Put(ctx context.Context, hash TokenHash, grant Grant) error
	// Consume atomically validates and burns a token, returning the grant it
	// carried. It returns ErrTokenUnknown, ErrTokenExpired or
	// ErrTokenConsumed as appropriate.
	Consume(ctx context.Context, hash TokenHash, now time.Time) (Grant, error)
}

// MemoryStore is an in-memory Store for tests and the reference control
// plane. Production uses a database-backed implementation so that burning a
// token survives a restart.
type MemoryStore struct {
	mu     sync.Mutex
	grants map[TokenHash]Grant
}

// NewMemoryStore returns an empty MemoryStore.
func NewMemoryStore() *MemoryStore {
	return &MemoryStore{grants: make(map[TokenHash]Grant)}
}

// Put records a grant.
func (s *MemoryStore) Put(_ context.Context, hash TokenHash, grant Grant) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.grants[hash] = grant
	return nil
}

// Consume atomically validates and burns a token.
func (s *MemoryStore) Consume(_ context.Context, hash TokenHash, now time.Time) (Grant, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	grant, ok := s.grants[hash]
	if !ok {
		return Grant{}, ErrTokenUnknown
	}
	if !grant.ConsumedAt.IsZero() {
		return Grant{}, ErrTokenConsumed
	}
	if now.After(grant.ExpiresAt) {
		return Grant{}, ErrTokenExpired
	}

	// Burn it. The record is kept rather than deleted so that a replay is
	// reported as consumed rather than unknown, which is the difference
	// between "someone retried" and "someone is guessing tokens" in an audit
	// log.
	grant.ConsumedAt = now
	s.grants[hash] = grant

	return grant, nil
}

// Request is what the agent presents to enroll.
type Request struct {
	// Token is the plaintext enrollment token from cloud-init.
	Token string `json:"token"`
	// CSRPEM is a certificate signing request over a key the agent just
	// generated, as PEM text. Its subject is ignored; see pki.CA.IssueNode.
	//
	// A string rather than []byte on purpose. Go encodes []byte to base64 in
	// JSON, which would make the field unreadable on the wire and would
	// silently disagree with a control plane written in any other language
	// that sees "PEM" in the spec and sends PEM. That exact mismatch broke
	// the first live enrollment against the Laravel control plane.
	CSRPEM string `json:"csr_pem"`
	// The remaining fields are reported by the node for the audit log. None
	// of them influence the identity it is granted.
	AgentVersion string `json:"agent_version"`
	Hostname     string `json:"hostname"`
	OS           string `json:"os"`
	Arch         string `json:"arch"`
}

// Response is what the control plane returns on success.
type Response struct {
	// NodeID is the identity the control plane assigned.
	NodeID string `json:"node_id"`
	// OrgID scopes the node.
	OrgID string `json:"org_id"`
	// CertPEM is the agent's signed client certificate, as PEM text.
	CertPEM string `json:"cert_pem"`
	// CAPEM is the CA certificate the agent must pin when dialling the
	// control plane. Shipping it here is what lets the agent verify the
	// control plane without trusting the host's root store.
	CAPEM string `json:"ca_pem"`
	// ControlPlaneAddr is the host:port the agent should connect to.
	ControlPlaneAddr string `json:"control_plane_addr"`
	// NotAfter is when the issued certificate expires.
	NotAfter time.Time `json:"not_after"`
}

// Service performs enrollment on the control plane side.
type Service struct {
	store            Store
	ca               *pki.CA
	controlPlaneAddr string
	certValidity     time.Duration
	// now is injectable for tests.
	now func() time.Time
}

// Option configures a Service.
type Option func(*Service)

// WithCertValidity overrides the issued certificate lifetime.
func WithCertValidity(d time.Duration) Option {
	return func(s *Service) { s.certValidity = d }
}

// WithClock overrides the time source, for tests.
func WithClock(now func() time.Time) Option {
	return func(s *Service) { s.now = now }
}

// NewService builds an enrollment service.
func NewService(store Store, ca *pki.CA, controlPlaneAddr string, opts ...Option) *Service {
	s := &Service{
		store:            store,
		ca:               ca,
		controlPlaneAddr: controlPlaneAddr,
		certValidity:     pki.DefaultNodeValidity,
		now:              time.Now,
	}
	for _, opt := range opts {
		opt(s)
	}
	return s
}

// Issue mints a token for a node the control plane is about to create, and
// returns the plaintext to embed in cloud-init.
func (s *Service) Issue(ctx context.Context, nodeID, orgID string, ttl time.Duration) (string, error) {
	if nodeID == "" {
		return "", pki.ErrEmptyNodeID
	}
	if ttl <= 0 {
		ttl = DefaultTokenTTL
	}

	token, hash, err := NewToken()
	if err != nil {
		return "", err
	}

	grant := Grant{
		NodeID:    nodeID,
		OrgID:     orgID,
		ExpiresAt: s.now().Add(ttl),
	}
	if err := s.store.Put(ctx, hash, grant); err != nil {
		return "", fmt.Errorf("enroll: persist grant: %w", err)
	}

	return token, nil
}

// Enroll validates a request, burns the token and issues a certificate.
//
// The returned error is always ErrEnrollmentRejected on failure. The caller
// is unauthenticated, so the specific reason belongs in the audit log and
// nowhere else. Cause returns the detail for that purpose.
func (s *Service) Enroll(ctx context.Context, req Request) (*Response, error) {
	if req.Token == "" || req.CSRPEM == "" {
		return nil, &RejectionError{cause: ErrTokenMalformed}
	}

	now := s.now()
	grant, err := s.store.Consume(ctx, HashToken(req.Token), now)
	if err != nil {
		return nil, &RejectionError{cause: err}
	}

	// Parse before issuing so a malformed CSR does not burn a token without
	// producing a certificate. The token is already consumed at this point,
	// which is deliberate: a caller that can submit garbage CSRs should not
	// get unlimited attempts against one token.
	if _, err := pki.ParseCSR([]byte(req.CSRPEM)); err != nil {
		return nil, &RejectionError{cause: err}
	}

	certPEM, err := s.ca.IssueNode([]byte(req.CSRPEM), grant.NodeID, s.certValidity)
	if err != nil {
		return nil, &RejectionError{cause: err}
	}

	cert, err := pki.ParseCertificate(certPEM)
	if err != nil {
		return nil, &RejectionError{cause: err}
	}

	return &Response{
		NodeID:           grant.NodeID,
		OrgID:            grant.OrgID,
		CertPEM:          string(certPEM),
		CAPEM:            string(s.ca.CertPEM()),
		ControlPlaneAddr: s.controlPlaneAddr,
		NotAfter:         cert.NotAfter,
	}, nil
}

// RejectionError is the opaque failure returned to an unauthenticated caller.
// It unwraps to ErrEnrollmentRejected so callers can test for it, while
// Cause exposes the real reason for the audit log.
type RejectionError struct {
	cause error
}

func (e *RejectionError) Error() string { return ErrEnrollmentRejected.Error() }

// Unwrap makes errors.Is(err, ErrEnrollmentRejected) true.
func (e *RejectionError) Unwrap() error { return ErrEnrollmentRejected }

// Cause returns the underlying reason. Log it; never send it.
func (e *RejectionError) Cause() error { return e.cause }
