package main

import (
	"os"
	"path/filepath"
	"testing"
)

// The token file is deleted after enrollment, so every later start finds it
// missing. That must not stop the agent: before this, any restart of an
// enrolled agent, a reboot or an upgrade, failed forever.
func TestMissingTokenFileIsNotAnError(t *testing.T) {
	token, err := readToken(filepath.Join(t.TempDir(), "enroll.token"))
	if err != nil {
		t.Fatalf("readToken on a missing file: %v", err)
	}
	if token != "" {
		t.Errorf("token = %q, want empty", token)
	}
}

func TestTokenIsReadAndTrimmed(t *testing.T) {
	path := filepath.Join(t.TempDir(), "enroll.token")
	if err := os.WriteFile(path, []byte("abc123\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	token, err := readToken(path)
	if err != nil || token != "abc123" {
		t.Fatalf("readToken = %q, %v; want abc123", token, err)
	}
}

func TestUnreadableTokenIsStillAnError(t *testing.T) {
	// A directory where the file should be is a real fault, not "already
	// enrolled", and must not be silently treated as no token.
	if _, err := readToken(t.TempDir()); err == nil {
		t.Fatal("readToken on a directory returned no error")
	}
}

func TestNoTokenFlagMeansNoToken(t *testing.T) {
	if token, err := readToken(""); err != nil || token != "" {
		t.Fatalf("readToken(\"\") = %q, %v", token, err)
	}
}
