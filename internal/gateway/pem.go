package gateway

import (
	"crypto/x509"
	"encoding/pem"
	"errors"
	"fmt"
)

// parseCACertificate decodes a PEM certificate and insists it is a CA.
//
// The check matters: a leaf certificate in the registry would verify nothing
// and place every node that presented it into the wrong tenant, or none.
func parseCACertificate(pemBytes []byte) (*x509.Certificate, error) {
	block, _ := pem.Decode(pemBytes)
	if block == nil {
		return nil, errors.New("no PEM block found")
	}

	if block.Type != "CERTIFICATE" {
		return nil, fmt.Errorf("expected a CERTIFICATE block, got %q", block.Type)
	}

	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("parse certificate: %w", err)
	}

	if !cert.IsCA {
		return nil, errors.New("certificate is not a certificate authority")
	}

	return cert, nil
}
