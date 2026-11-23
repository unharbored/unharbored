//go:build linux

package agentcore

import (
	"os"
	"os/user"
	"strings"
	"testing"
	"time"
)

// Runs a real shell on a real terminal, as whoever runs the test.
func TestShellRunsCommandsAndEnds(t *testing.T) {
	// As root, which is how the agent runs, drop to an ordinary account.
	name := "nobody"
	if os.Getuid() != 0 {
		me, err := user.Current()
		if err != nil {
			t.Skip("no passwd entry for this user")
		}
		name = me.Username
	}

	sh, read, wait, err := startShell(name, "/", 80, 24)
	if err != nil {
		t.Fatal(err)
	}
	defer sh.close()

	if err := sh.write([]byte("echo console-works; exit 3\n")); err != nil {
		t.Fatal(err)
	}

	var got strings.Builder
	done := make(chan struct{})
	go func() {
		buf := make([]byte, 4096)
		for {
			n, err := read(buf)
			got.Write(buf[:n])
			if err != nil {
				close(done)
				return
			}
		}
	}()

	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("the shell did not end")
	}
	if !strings.Contains(got.String(), "console-works") {
		t.Fatalf("output was %q", got.String())
	}
	if code := wait(); code != 3 {
		t.Fatalf("exit code %d", code)
	}
}

func TestShellRefusesAnUnknownUser(t *testing.T) {
	if _, _, _, err := startShell("no-such-user-xyz", "", 80, 24); err == nil {
		t.Fatal("expected an error")
	}
}
