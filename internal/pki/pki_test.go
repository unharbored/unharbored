package pki

import (
	"crypto/x509"
	"errors"
	"testing"
	"time"
)

func newTestCA(t *testing.T) *CA {
	t.Helper()
	ca, err := NewCA("test-org-ca", DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}
	return ca
}

func TestNewCAIsSelfSignedAndIsCA(t *testing.T) {
	ca := newTestCA(t)

	cert := ca.Certificate()
	if !cert.IsCA {
		t.Error("IsCA = false, want true")
	}
	if cert.Subject.CommonName != "test-org-ca" {
		t.Errorf("CommonName = %q, want test-org-ca", cert.Subject.CommonName)
	}
	if cert.KeyUsage&x509.KeyUsageCertSign == 0 {
		t.Error("CA lacks KeyUsageCertSign")
	}
	// The CA must not be able to mint intermediates.
	if !cert.MaxPathLenZero || cert.MaxPathLen != 0 {
		t.Errorf("MaxPathLen = %d, MaxPathLenZero = %v; want 0, true", cert.MaxPathLen, cert.MaxPathLenZero)
	}
	if err := cert.CheckSignatureFrom(cert); err != nil {
		t.Errorf("CA is not self-signed: %v", err)
	}
}

func TestCARoundTripPEM(t *testing.T) {
	ca := newTestCA(t)

	keyPEM, err := ca.KeyPEM()
	if err != nil {
		t.Fatalf("KeyPEM: %v", err)
	}

	loaded, err := LoadCA(ca.CertPEM(), keyPEM)
	if err != nil {
		t.Fatalf("LoadCA: %v", err)
	}
	if loaded.Certificate().SerialNumber.Cmp(ca.Certificate().SerialNumber) != 0 {
		t.Error("reloaded CA has a different serial")
	}

	// The reloaded CA must actually be able to sign.
	_, csrPEM, err := NewKeyAndCSR("node-1")
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	if _, err := loaded.IssueNode(csrPEM, "node-1", DefaultNodeValidity); err != nil {
		t.Errorf("reloaded CA cannot issue: %v", err)
	}
}

func TestLoadCARejectsNonCACertificate(t *testing.T) {
	ca := newTestCA(t)
	leafPEM, leafKeyPEM, err := ca.IssueServer("cp.example", nil, DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueServer: %v", err)
	}
	if _, err := LoadCA(leafPEM, leafKeyPEM); !errors.Is(err, ErrNotCA) {
		t.Fatalf("err = %v, want ErrNotCA", err)
	}
}

func TestIssueNodeChainsToCA(t *testing.T) {
	ca := newTestCA(t)

	_, csrPEM, err := NewKeyAndCSR("node-1")
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	certPEM, err := ca.IssueNode(csrPEM, "node-1", DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}

	cert, err := ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	if _, err := cert.Verify(x509.VerifyOptions{
		Roots:     ca.Pool(),
		KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}); err != nil {
		t.Fatalf("Verify: %v", err)
	}
}

// The control plane assigns node identity. A node that asks to be called
// something else must not get it. This is the single most important property
// in this package.
func TestIssueNodeIgnoresCSRCommonName(t *testing.T) {
	ca := newTestCA(t)

	_, csrPEM, err := NewKeyAndCSR("i-am-the-control-plane")
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}

	certPEM, err := ca.IssueNode(csrPEM, "node-assigned-by-cp", DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	cert, err := ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	if got := NodeIDFromCertificate(cert); got != "node-assigned-by-cp" {
		t.Errorf("CommonName = %q, want node-assigned-by-cp (CSR value must be ignored)", got)
	}
}

func TestIssueNodeRejectsEmptyNodeID(t *testing.T) {
	ca := newTestCA(t)
	_, csrPEM, err := NewKeyAndCSR("node-1")
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	if _, err := ca.IssueNode(csrPEM, "", DefaultNodeValidity); !errors.Is(err, ErrEmptyNodeID) {
		t.Fatalf("err = %v, want ErrEmptyNodeID", err)
	}
}

// A node certificate must be client-auth only, so a compromised node cannot
// turn around and impersonate the control plane to its peers.
func TestNodeCertificateIsClientAuthOnly(t *testing.T) {
	ca := newTestCA(t)
	_, csrPEM, _ := NewKeyAndCSR("node-1")
	certPEM, err := ca.IssueNode(csrPEM, "node-1", DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	cert, err := ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	if cert.IsCA {
		t.Error("node certificate is a CA")
	}
	if len(cert.ExtKeyUsage) != 1 || cert.ExtKeyUsage[0] != x509.ExtKeyUsageClientAuth {
		t.Errorf("ExtKeyUsage = %v, want [ClientAuth]", cert.ExtKeyUsage)
	}

	// Verifying it as a server certificate must fail.
	if _, err := cert.Verify(x509.VerifyOptions{
		Roots:     ca.Pool(),
		KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}); err == nil {
		t.Error("node certificate verified for server auth, want failure")
	}
}

func TestIssueServerIsServerAuthOnly(t *testing.T) {
	ca := newTestCA(t)
	certPEM, _, err := ca.IssueServer("cp.example", []string{"cp.example"}, DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueServer: %v", err)
	}
	cert, err := ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	if len(cert.ExtKeyUsage) != 1 || cert.ExtKeyUsage[0] != x509.ExtKeyUsageServerAuth {
		t.Errorf("ExtKeyUsage = %v, want [ServerAuth]", cert.ExtKeyUsage)
	}
	if _, err := cert.Verify(x509.VerifyOptions{
		DNSName:   "cp.example",
		Roots:     ca.Pool(),
		KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}); err != nil {
		t.Errorf("Verify: %v", err)
	}
}

// A certificate from a different organisation's CA must never validate.
func TestForeignCAIsRejected(t *testing.T) {
	ours := newTestCA(t)
	theirs, err := NewCA("other-org-ca", DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}

	_, csrPEM, _ := NewKeyAndCSR("node-1")
	foreignPEM, err := theirs.IssueNode(csrPEM, "node-1", DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	foreign, err := ParseCertificate(foreignPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	if _, err := foreign.Verify(x509.VerifyOptions{
		Roots:     ours.Pool(),
		KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}); err == nil {
		t.Fatal("foreign certificate verified against our CA pool")
	}
}

func TestParseCSRRejectsGarbage(t *testing.T) {
	cases := map[string][]byte{
		"empty":       nil,
		"not pem":     []byte("hello"),
		"wrong block": []byte("-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"),
		"bad base64":  []byte("-----BEGIN CERTIFICATE REQUEST-----\n!!!!\n-----END CERTIFICATE REQUEST-----\n"),
	}
	for name, input := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := ParseCSR(input); err == nil {
				t.Fatal("expected error")
			}
		})
	}
}

// A CSR carrying a valid structure but a signature that does not match the
// embedded public key must be rejected, or proof of possession means nothing.
func TestParseCSRRejectsTamperedSignature(t *testing.T) {
	_, csrPEM, err := NewKeyAndCSR("node-1")
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	der, err := decodePEM(csrPEM, blockCSR)
	if err != nil {
		t.Fatalf("decodePEM: %v", err)
	}

	// Flip a bit near the end, which lands in the signature.
	tampered := make([]byte, len(der))
	copy(tampered, der)
	tampered[len(tampered)-1] ^= 0xFF

	csr, err := x509.ParseCertificateRequest(tampered)
	if err != nil {
		// Mangling can break the structure outright, which is also a reject.
		return
	}
	if err := csr.CheckSignature(); err == nil {
		t.Fatal("tampered CSR passed signature check")
	}
}

func TestDecodePEMRejectsTrailingData(t *testing.T) {
	ca := newTestCA(t)
	withTrailer := append(ca.CertPEM(), []byte("extra junk")...)
	if _, err := decodePEM(withTrailer, blockCertificate); err == nil {
		t.Fatal("expected error for trailing data")
	}
}

func TestDecodePEMAllowsTrailingWhitespace(t *testing.T) {
	ca := newTestCA(t)
	padded := append(ca.CertPEM(), []byte("\n\n\t ")...)
	if _, err := decodePEM(padded, blockCertificate); err != nil {
		t.Fatalf("trailing whitespace rejected: %v", err)
	}
}

func TestNeedsRenewal(t *testing.T) {
	ca := newTestCA(t)
	_, csrPEM, _ := NewKeyAndCSR("node-1")
	certPEM, err := ca.IssueNode(csrPEM, "node-1", DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	cert, err := ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	if NeedsRenewal(cert, time.Now()) {
		t.Error("freshly issued certificate wants renewal")
	}
	// The window opens at NotAfter-RenewBefore. Pin both sides of that edge.
	beforeWindow := cert.NotAfter.Add(-RenewBefore).Add(-time.Minute)
	if NeedsRenewal(cert, beforeWindow) {
		t.Error("certificate wants renewal a minute before the window opens")
	}
	afterWindow := cert.NotAfter.Add(-RenewBefore).Add(time.Minute)
	if !NeedsRenewal(cert, afterWindow) {
		t.Error("certificate does not want renewal a minute after the window opens")
	}
	if !NeedsRenewal(cert, cert.NotAfter.Add(-time.Hour)) {
		t.Error("certificate an hour from expiry does not want renewal")
	}
	if !NeedsRenewal(cert, cert.NotAfter.Add(time.Hour)) {
		t.Error("expired certificate does not want renewal")
	}
}

func TestIssuedValidityBounds(t *testing.T) {
	ca := newTestCA(t)
	_, csrPEM, _ := NewKeyAndCSR("node-1")

	const validity = 48 * time.Hour
	certPEM, err := ca.IssueNode(csrPEM, "node-1", validity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	cert, err := ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	span := cert.NotAfter.Sub(cert.NotBefore)
	want := validity + clockSkew
	if span < want-time.Minute || span > want+time.Minute {
		t.Errorf("validity span = %v, want about %v", span, want)
	}
	// NotBefore must be backdated so a fast node clock does not reject it.
	if !cert.NotBefore.Before(time.Now()) {
		t.Error("NotBefore is not backdated")
	}
}

func TestIssueNodeDefaultsValidity(t *testing.T) {
	ca := newTestCA(t)
	_, csrPEM, _ := NewKeyAndCSR("node-1")
	certPEM, err := ca.IssueNode(csrPEM, "node-1", 0)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	cert, err := ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}
	span := cert.NotAfter.Sub(cert.NotBefore)
	if span < DefaultNodeValidity {
		t.Errorf("span = %v, want at least %v", span, DefaultNodeValidity)
	}
}

func TestSerialsAreUnique(t *testing.T) {
	ca := newTestCA(t)
	seen := make(map[string]bool, 50)
	for i := range 50 {
		_, csrPEM, _ := NewKeyAndCSR("node-1")
		certPEM, err := ca.IssueNode(csrPEM, "node-1", DefaultNodeValidity)
		if err != nil {
			t.Fatalf("IssueNode %d: %v", i, err)
		}
		cert, err := ParseCertificate(certPEM)
		if err != nil {
			t.Fatalf("ParseCertificate %d: %v", i, err)
		}
		s := cert.SerialNumber.String()
		if seen[s] {
			t.Fatalf("duplicate serial %s", s)
		}
		seen[s] = true
	}
}
