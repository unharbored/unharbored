package gateway

import (
	"crypto/x509"
	"errors"
	"testing"

	"github.com/unharbored/unharbored/internal/pki"
)

func newOrg(t *testing.T, id string) (Organisation, *pki.CA) {
	t.Helper()

	ca, err := pki.NewCA(id+" CA", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}

	return Organisation{ID: id, CACertificate: ca.Certificate()}, ca
}

// Issues a node certificate and returns the verified-chain shape the TLS
// stack would produce: leaf first, then its issuer.
func chainFor(t *testing.T, ca *pki.CA, nodeID string) []*x509.Certificate {
	t.Helper()

	_, csr, err := pki.NewKeyAndCSR(nodeID)
	if err != nil {
		t.Fatalf("NewKeyAndCSR: %v", err)
	}
	certPEM, err := ca.IssueNode(csr, nodeID, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	leaf, err := pki.ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}

	return []*x509.Certificate{leaf, ca.Certificate()}
}

func TestResolvesOrganisationFromIssuer(t *testing.T) {
	orgA, caA := newOrg(t, "org-a")
	orgB, caB := newOrg(t, "org-b")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA, orgB}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	if got, err := r.OrganisationFor(chainFor(t, caA, "web-1")); err != nil || got != "org-a" {
		t.Errorf("org = %q, %v; want org-a", got, err)
	}
	if got, err := r.OrganisationFor(chainFor(t, caB, "web-1")); err != nil || got != "org-b" {
		t.Errorf("org = %q, %v; want org-b", got, err)
	}
}

// The property the whole design rests on. A node's tenancy comes from the
// signature on its certificate, so naming itself after another tenant
// achieves nothing.
func TestANodeCannotClaimAnotherTenantByItsName(t *testing.T) {
	orgA, caA := newOrg(t, "org-a")
	orgB, _ := newOrg(t, "org-b")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA, orgB}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	// A node in org-a with a name designed to look like it belongs to org-b.
	for _, hostile := range []string{
		"web-1.org-b",
		"org-b",
		"web-1.org-b.org-a",
		"../org-b/web-1",
	} {
		got, err := r.OrganisationFor(chainFor(t, caA, hostile))
		if err != nil {
			t.Fatalf("OrganisationFor(%q): %v", hostile, err)
		}
		if got != "org-a" {
			t.Errorf("node named %q resolved to %q; tenancy must follow the issuer", hostile, got)
		}
	}
}

func TestAnUnknownIssuerIsRefused(t *testing.T) {
	orgA, _ := newOrg(t, "org-a")
	_, strangerCA := newOrg(t, "not-registered")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	_, err := r.OrganisationFor(chainFor(t, strangerCA, "web-1"))
	if !errors.Is(err, ErrUnknownIssuer) {
		t.Fatalf("err = %v, want ErrUnknownIssuer", err)
	}
}

// A chain with no issuer means verification did not happen, or the peer sent
// something self-signed. Either way it is not a tenant.
func TestAChainWithoutAnIssuerIsRefused(t *testing.T) {
	orgA, caA := newOrg(t, "org-a")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	leafOnly := chainFor(t, caA, "web-1")[:1]

	if _, err := r.OrganisationFor(leafOnly); !errors.Is(err, ErrNoVerifiedChain) {
		t.Fatalf("err = %v, want ErrNoVerifiedChain", err)
	}
	if _, err := r.OrganisationFor(nil); !errors.Is(err, ErrNoVerifiedChain) {
		t.Fatalf("err = %v, want ErrNoVerifiedChain", err)
	}
}

// Two organisations sharing a CA would make the issuer ambiguous and silently
// merge their tenancy.
func TestSharedCACertificatesAreRefused(t *testing.T) {
	orgA, ca := newOrg(t, "org-a")
	orgB := Organisation{ID: "org-b", CACertificate: ca.Certificate()}

	r := NewRegistry()
	err := r.Replace([]Organisation{orgA, orgB})

	if err == nil {
		t.Fatal("two organisations were allowed to share a CA")
	}
	// Both names should appear, since the operator has to fix one of them.
	for _, name := range []string{"org-a", "org-b"} {
		if !contains(err.Error(), name) {
			t.Errorf("error does not name %s: %v", name, err)
		}
	}
}

func TestTheSameOrganisationTwiceIsHarmless(t *testing.T) {
	orgA, _ := newOrg(t, "org-a")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA, orgA}); err != nil {
		t.Fatalf("Replace: %v", err)
	}
	if r.Len() != 1 {
		t.Errorf("Len = %d, want 1", r.Len())
	}
}

func TestReplaceRejectsIncompleteOrganisations(t *testing.T) {
	orgA, _ := newOrg(t, "org-a")

	cases := map[string][]Organisation{
		"no id":             {{ID: "", CACertificate: orgA.CACertificate}},
		"no certificate":    {{ID: "org-a", CACertificate: nil}},
		"not a certificate": {{ID: "org-a", CACertificate: leafOf(t)}},
	}

	for name, orgs := range cases {
		t.Run(name, func(t *testing.T) {
			if err := NewRegistry().Replace(orgs); err == nil {
				t.Fatal("expected an error")
			}
		})
	}
}

// An organisation removed upstream must stop being accepted here. Whole-set
// replacement is what makes that the default rather than something to
// remember.
func TestReplaceRemovesOrganisationsThatAreGone(t *testing.T) {
	orgA, caA := newOrg(t, "org-a")
	orgB, _ := newOrg(t, "org-b")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA, orgB}); err != nil {
		t.Fatalf("Replace: %v", err)
	}
	if _, err := r.OrganisationFor(chainFor(t, caA, "web-1")); err != nil {
		t.Fatalf("org-a should resolve: %v", err)
	}

	// org-a is removed upstream.
	if err := r.Replace([]Organisation{orgB}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	if _, err := r.OrganisationFor(chainFor(t, caA, "web-1")); !errors.Is(err, ErrUnknownIssuer) {
		t.Errorf("a removed organisation still resolves: %v", err)
	}
	if r.Len() != 1 {
		t.Errorf("Len = %d, want 1", r.Len())
	}
}

// A failed Replace must not leave the registry half updated, or a transient
// configuration error silently drops tenants.
func TestAFailedReplaceLeavesTheRegistryUnchanged(t *testing.T) {
	orgA, caA := newOrg(t, "org-a")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	bad := []Organisation{{ID: "org-b", CACertificate: nil}}
	if err := r.Replace(bad); err == nil {
		t.Fatal("expected an error")
	}

	if _, err := r.OrganisationFor(chainFor(t, caA, "web-1")); err != nil {
		t.Errorf("a failed Replace disturbed the registry: %v", err)
	}
	if r.Len() != 1 {
		t.Errorf("Len = %d, want 1", r.Len())
	}
}

func TestPoolAcceptsEveryRegisteredOrganisation(t *testing.T) {
	orgA, caA := newOrg(t, "org-a")
	orgB, caB := newOrg(t, "org-b")

	r := NewRegistry()
	if err := r.Replace([]Organisation{orgA, orgB}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	for _, ca := range []*pki.CA{caA, caB} {
		leaf := chainFor(t, ca, "web-1")[0]
		if _, err := leaf.Verify(x509.VerifyOptions{
			Roots:     r.Pool(),
			KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
		}); err != nil {
			t.Errorf("a registered organisation does not verify against the pool: %v", err)
		}
	}
}

func TestFingerprintDistinguishesCertificatesWithTheSameName(t *testing.T) {
	// Two CAs can easily be given the same common name, so the registry keys
	// on the certificate itself rather than on what it calls itself.
	first, err := pki.NewCA("identical", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}
	second, err := pki.NewCA("identical", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}

	if Fingerprint(first.Certificate()) == Fingerprint(second.Certificate()) {
		t.Fatal("two distinct CAs share a fingerprint")
	}
}

func leafOf(t *testing.T) *x509.Certificate {
	t.Helper()
	ca, err := pki.NewCA("issuer", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}
	_, csr, _ := pki.NewKeyAndCSR("leaf")
	certPEM, err := ca.IssueNode(csr, "leaf", pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueNode: %v", err)
	}
	cert, err := pki.ParseCertificate(certPEM)
	if err != nil {
		t.Fatalf("ParseCertificate: %v", err)
	}
	return cert
}

func contains(haystack, needle string) bool {
	return len(haystack) >= len(needle) && (func() bool {
		for i := 0; i+len(needle) <= len(haystack); i++ {
			if haystack[i:i+len(needle)] == needle {
				return true
			}
		}
		return false
	})()
}
