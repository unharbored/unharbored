package enroll

import (
	"context"
	"crypto/x509"
	"errors"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/unharbored/unharbored/internal/pki"
)

func newTestService(t *testing.T, opts ...Option) (*Service, *pki.CA, *MemoryStore) {
	t.Helper()
	ca, err := pki.NewCA("test-org-ca", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}
	store := NewMemoryStore()
	return NewService(store, ca, "cp.example:9443", opts...), ca, store
}

// Returns PEM text rather than bytes, matching Request.CSRPEM. The field is
// a string so the wire format carries readable PEM instead of base64; see the
// comment on Request.
func newCSR(t *testing.T, cn string) string {
	t.Helper()
	_, csrPEM, err := pki.NewKeyAndCSR(cn)
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	return string(csrPEM)
}

func TestEnrollHappyPath(t *testing.T) {
	svc, ca, _ := newTestService(t)
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", DefaultTokenTTL)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}
	if token == "" {
		t.Fatal("empty token")
	}

	resp, err := svc.Enroll(ctx, Request{
		Token:        token,
		CSRPEM:       newCSR(t, "whatever"),
		AgentVersion: "0.1.0",
		Hostname:     "web-1",
		OS:           "linux",
		Arch:         "amd64",
	})
	if err != nil {
		t.Fatalf("Enroll: %v", err)
	}

	if resp.NodeID != "node-123" {
		t.Errorf("NodeID = %q, want node-123", resp.NodeID)
	}
	if resp.OrgID != "org-1" {
		t.Errorf("OrgID = %q, want org-1", resp.OrgID)
	}
	if resp.ControlPlaneAddr != "cp.example:9443" {
		t.Errorf("ControlPlaneAddr = %q", resp.ControlPlaneAddr)
	}

	cert, err := pki.ParseCertificate([]byte(resp.CertPEM))
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}
	if got := pki.NodeIDFromCertificate(cert); got != "node-123" {
		t.Errorf("certificate CN = %q, want node-123", got)
	}
	if _, err := cert.Verify(x509.VerifyOptions{
		Roots:     ca.Pool(),
		KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}); err != nil {
		t.Errorf("issued certificate does not verify: %v", err)
	}

	// The CA certificate must come back so the agent can pin the control
	// plane without trusting the host root store.
	if len(resp.CAPEM) == 0 {
		t.Error("response carries no CA certificate")
	}
	if resp.CAPEM != string(ca.CertPEM()) {
		t.Error("returned CA certificate does not match the issuing CA")
	}
}

// The defining property of an enrollment token.
func TestTokenIsSingleUse(t *testing.T) {
	svc, _, _ := newTestService(t)
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", DefaultTokenTTL)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	if _, err := svc.Enroll(ctx, Request{Token: token, CSRPEM: newCSR(t, "n")}); err != nil {
		t.Fatalf("first Enroll: %v", err)
	}

	_, err = svc.Enroll(ctx, Request{Token: token, CSRPEM: newCSR(t, "n")})
	if err == nil {
		t.Fatal("second Enroll succeeded, token is not single-use")
	}
	var rej *RejectionError
	if !errors.As(err, &rej) {
		t.Fatalf("err = %T, want *RejectionError", err)
	}
	if !errors.Is(rej.Cause(), ErrTokenConsumed) {
		t.Errorf("cause = %v, want ErrTokenConsumed", rej.Cause())
	}
}

// Cloud-init retries. Two agents presenting the same token concurrently must
// produce exactly one success.
func TestConcurrentEnrollYieldsOneWinner(t *testing.T) {
	svc, _, _ := newTestService(t)
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", DefaultTokenTTL)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	const racers = 16
	var (
		wg        sync.WaitGroup
		mu        sync.Mutex
		successes int
	)
	// Pre-generate CSRs so key generation does not serialise the race.
	csrs := make([]string, racers)
	for i := range csrs {
		csrs[i] = newCSR(t, "n")
	}

	start := make(chan struct{})
	for i := range racers {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			if _, err := svc.Enroll(ctx, Request{Token: token, CSRPEM: csrs[i]}); err == nil {
				mu.Lock()
				successes++
				mu.Unlock()
			}
		}()
	}
	close(start)
	wg.Wait()

	if successes != 1 {
		t.Fatalf("successes = %d, want exactly 1", successes)
	}
}

func TestExpiredTokenIsRejected(t *testing.T) {
	clock := time.Now()
	svc, _, _ := newTestService(t, WithClock(func() time.Time { return clock }))
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", time.Minute)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	clock = clock.Add(2 * time.Minute)

	_, err = svc.Enroll(ctx, Request{Token: token, CSRPEM: newCSR(t, "n")})
	var rej *RejectionError
	if !errors.As(err, &rej) {
		t.Fatalf("err = %v, want *RejectionError", err)
	}
	if !errors.Is(rej.Cause(), ErrTokenExpired) {
		t.Errorf("cause = %v, want ErrTokenExpired", rej.Cause())
	}
}

func TestUnknownTokenIsRejected(t *testing.T) {
	svc, _, _ := newTestService(t)
	ctx := context.Background()

	fake, _, err := NewToken()
	if err != nil {
		t.Fatalf("NewToken: %v", err)
	}

	_, err = svc.Enroll(ctx, Request{Token: fake, CSRPEM: newCSR(t, "n")})
	var rej *RejectionError
	if !errors.As(err, &rej) {
		t.Fatalf("err = %v, want *RejectionError", err)
	}
	if !errors.Is(rej.Cause(), ErrTokenUnknown) {
		t.Errorf("cause = %v, want ErrTokenUnknown", rej.Cause())
	}
}

// An unauthenticated caller must not be able to tell an unknown token from an
// expired or already-used one. All three must look identical on the wire.
func TestRejectionIsOpaque(t *testing.T) {
	clock := time.Now()
	svc, _, _ := newTestService(t, WithClock(func() time.Time { return clock }))
	ctx := context.Background()

	used, _ := svc.Issue(ctx, "node-a", "org-1", time.Hour)
	if _, err := svc.Enroll(ctx, Request{Token: used, CSRPEM: newCSR(t, "n")}); err != nil {
		t.Fatalf("priming Enroll: %v", err)
	}

	expired, _ := svc.Issue(ctx, "node-b", "org-1", time.Minute)
	clock = clock.Add(2 * time.Minute)

	unknown, _, _ := NewToken()

	var messages []string
	for _, token := range []string{used, expired, unknown} {
		_, err := svc.Enroll(ctx, Request{Token: token, CSRPEM: newCSR(t, "n")})
		if err == nil {
			t.Fatal("expected rejection")
		}
		if !errors.Is(err, ErrEnrollmentRejected) {
			t.Errorf("err does not unwrap to ErrEnrollmentRejected: %v", err)
		}
		messages = append(messages, err.Error())
	}

	for i := 1; i < len(messages); i++ {
		if messages[i] != messages[0] {
			t.Errorf("rejection messages differ and leak the reason:\n %q\n %q", messages[0], messages[i])
		}
	}
	// And the shared message must not name a cause.
	for _, forbidden := range []string{"expired", "consumed", "used", "unknown", "not recognised"} {
		if strings.Contains(strings.ToLower(messages[0]), forbidden) {
			t.Errorf("rejection message %q leaks %q", messages[0], forbidden)
		}
	}
}

// The node identity comes from the grant, never from the request. A caller
// holding a valid token for node-a must not be able to become node-b.
func TestEnrolleeCannotChooseItsIdentity(t *testing.T) {
	svc, _, _ := newTestService(t)
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-a", "org-1", DefaultTokenTTL)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	// The CSR asks to be node-b, and a privileged one at that.
	resp, err := svc.Enroll(ctx, Request{
		Token:    token,
		CSRPEM:   newCSR(t, "node-b"),
		Hostname: "node-b",
	})
	if err != nil {
		t.Fatalf("Enroll: %v", err)
	}
	if resp.NodeID != "node-a" {
		t.Errorf("NodeID = %q, want node-a", resp.NodeID)
	}

	cert, err := pki.ParseCertificate([]byte(resp.CertPEM))
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}
	if got := pki.NodeIDFromCertificate(cert); got != "node-a" {
		t.Errorf("certificate CN = %q, want node-a", got)
	}
}

func TestMalformedRequestsAreRejected(t *testing.T) {
	svc, _, _ := newTestService(t)
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", DefaultTokenTTL)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	cases := map[string]Request{
		"no token": {CSRPEM: newCSR(t, "n")},
		"no csr":   {Token: token},
		"bad csr":  {Token: token, CSRPEM: "not a csr"},
	}
	for name, req := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := svc.Enroll(ctx, req); err == nil {
				t.Fatal("expected rejection")
			}
		})
	}
}

// A malformed CSR still burns the token. Otherwise one token grants unlimited
// attempts, which turns a single-use credential into a durable one.
func TestMalformedCSRStillBurnsToken(t *testing.T) {
	svc, _, _ := newTestService(t)
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", DefaultTokenTTL)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	if _, err := svc.Enroll(ctx, Request{Token: token, CSRPEM: "garbage"}); err == nil {
		t.Fatal("expected rejection for garbage CSR")
	}

	// The token must now be dead even though no certificate was issued.
	_, err = svc.Enroll(ctx, Request{Token: token, CSRPEM: newCSR(t, "n")})
	var rej *RejectionError
	if !errors.As(err, &rej) {
		t.Fatalf("err = %v, want *RejectionError", err)
	}
	if !errors.Is(rej.Cause(), ErrTokenConsumed) {
		t.Errorf("cause = %v, want ErrTokenConsumed", rej.Cause())
	}
}

func TestIssueRejectsEmptyNodeID(t *testing.T) {
	svc, _, _ := newTestService(t)
	if _, err := svc.Issue(context.Background(), "", "org-1", DefaultTokenTTL); !errors.Is(err, pki.ErrEmptyNodeID) {
		t.Fatalf("err = %v, want ErrEmptyNodeID", err)
	}
}

// The control plane must store only a hash, so a database leak cannot be
// replayed into a live enrollment.
func TestStoreHoldsNoPlaintextToken(t *testing.T) {
	svc, _, store := newTestService(t)
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", DefaultTokenTTL)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	store.mu.Lock()
	defer store.mu.Unlock()
	if len(store.grants) != 1 {
		t.Fatalf("len(grants) = %d, want 1", len(store.grants))
	}
	for hash := range store.grants {
		if string(hash[:]) == token {
			t.Fatal("store key is the plaintext token")
		}
		if hash != HashToken(token) {
			t.Fatal("store key is not the token hash")
		}
	}
}

func TestHashTokenIsStable(t *testing.T) {
	token, hash, err := NewToken()
	if err != nil {
		t.Fatalf("NewToken: %v", err)
	}
	if HashToken(token) != hash {
		t.Fatal("HashToken disagrees with NewToken")
	}
	if HashToken(token+"x") == hash {
		t.Fatal("hash collides on a modified token")
	}
}

func TestNewTokenIsUnique(t *testing.T) {
	seen := make(map[string]bool, 500)
	for range 500 {
		token, _, err := NewToken()
		if err != nil {
			t.Fatalf("NewToken: %v", err)
		}
		if seen[token] {
			t.Fatal("duplicate token")
		}
		seen[token] = true
	}
}

func TestIssueDefaultsTTL(t *testing.T) {
	clock := time.Now()
	svc, _, store := newTestService(t, WithClock(func() time.Time { return clock }))
	ctx := context.Background()

	token, err := svc.Issue(ctx, "node-123", "org-1", 0)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	store.mu.Lock()
	grant := store.grants[HashToken(token)]
	store.mu.Unlock()

	want := clock.Add(DefaultTokenTTL)
	if !grant.ExpiresAt.Equal(want) {
		t.Errorf("ExpiresAt = %v, want %v", grant.ExpiresAt, want)
	}
}
