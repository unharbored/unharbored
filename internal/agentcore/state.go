// Package agentcore is the agent: enrollment, the reconnecting control plane
// session, and the command handlers.
package agentcore

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"

	"github.com/unharbored/unharbored/internal/pki"
)

// File names within the agent state directory.
const (
	fileIdentity = "node.json"
	fileKey      = "agent.key"
	fileCert     = "agent.crt"
	fileCA       = "ca.crt"
)

// keyFileMode is deliberately owner-read-only. The agent's private key is the
// node's entire identity.
const keyFileMode fs.FileMode = 0o600

// Identity is the durable record of what this node is.
type Identity struct {
	NodeID           string `json:"node_id"`
	OrgID            string `json:"org_id"`
	ControlPlaneAddr string `json:"control_plane_addr"`
	ServerName       string `json:"server_name"`
}

// ErrNotEnrolled means the state directory has no usable credentials.
var ErrNotEnrolled = errors.New("agentcore: node is not enrolled")

// State is the agent's on-disk credential set.
type State struct {
	Dir      string
	Identity Identity
	KeyPEM   []byte
	CertPEM  []byte
	CAPEM    []byte
}

// LoadState reads credentials from dir. It returns ErrNotEnrolled when the
// directory does not hold a complete set.
func LoadState(dir string) (*State, error) {
	s := &State{Dir: dir}

	identityRaw, err := os.ReadFile(filepath.Join(dir, fileIdentity))
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return nil, ErrNotEnrolled
		}
		return nil, fmt.Errorf("agentcore: read identity: %w", err)
	}
	if err := json.Unmarshal(identityRaw, &s.Identity); err != nil {
		return nil, fmt.Errorf("agentcore: parse identity: %w", err)
	}

	for _, f := range []struct {
		name string
		dst  *[]byte
	}{
		{fileKey, &s.KeyPEM},
		{fileCert, &s.CertPEM},
		{fileCA, &s.CAPEM},
	} {
		data, err := os.ReadFile(filepath.Join(dir, f.name))
		if err != nil {
			if errors.Is(err, fs.ErrNotExist) {
				return nil, ErrNotEnrolled
			}
			return nil, fmt.Errorf("agentcore: read %s: %w", f.name, err)
		}
		*f.dst = data
	}

	// A certificate whose identity disagrees with the recorded one means the
	// state directory was tampered with or half restored from a backup.
	// Refusing here is better than connecting as the wrong node.
	cert, err := pki.ParseCertificate(s.CertPEM)
	if err != nil {
		return nil, fmt.Errorf("agentcore: parse node certificate: %w", err)
	}
	if got := pki.NodeIDFromCertificate(cert); got != s.Identity.NodeID {
		return nil, fmt.Errorf("agentcore: certificate is for node %q but identity says %q", got, s.Identity.NodeID)
	}

	return s, nil
}

// Save writes credentials to dir, creating it if needed.
func (s *State) Save() error {
	if err := os.MkdirAll(s.Dir, 0o700); err != nil {
		return fmt.Errorf("agentcore: create state dir: %w", err)
	}

	identityRaw, err := json.MarshalIndent(s.Identity, "", "  ")
	if err != nil {
		return fmt.Errorf("agentcore: encode identity: %w", err)
	}

	files := []struct {
		name string
		data []byte
		mode fs.FileMode
	}{
		{fileIdentity, identityRaw, 0o600},
		{fileKey, s.KeyPEM, keyFileMode},
		{fileCert, s.CertPEM, 0o644},
		{fileCA, s.CAPEM, 0o644},
	}
	for _, f := range files {
		if err := writeFileAtomic(filepath.Join(s.Dir, f.name), f.data, f.mode); err != nil {
			return err
		}
	}
	return nil
}

// writeFileAtomic writes through a temporary file in the same directory and
// renames, so a crash cannot leave a half-written key behind. The mode is set
// before the rename so a private key is never briefly world readable.
func writeFileAtomic(path string, data []byte, mode fs.FileMode) error {
	dir := filepath.Dir(path)
	tmp, err := os.CreateTemp(dir, ".tmp-*")
	if err != nil {
		return fmt.Errorf("agentcore: create temp in %s: %w", dir, err)
	}
	tmpName := tmp.Name()
	defer func() { _ = os.Remove(tmpName) }()

	if err := tmp.Chmod(mode); err != nil {
		tmp.Close()
		return fmt.Errorf("agentcore: chmod %s: %w", tmpName, err)
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		return fmt.Errorf("agentcore: write %s: %w", tmpName, err)
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return fmt.Errorf("agentcore: sync %s: %w", tmpName, err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("agentcore: close %s: %w", tmpName, err)
	}
	if err := os.Rename(tmpName, path); err != nil {
		return fmt.Errorf("agentcore: rename into %s: %w", path, err)
	}
	return nil
}
