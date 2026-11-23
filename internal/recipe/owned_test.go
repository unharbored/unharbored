//go:build !windows

package recipe

import (
	"context"
	"os"
	"os/user"
	"path/filepath"
	"syscall"
	"testing"
	"time"
)

// The tests run as an ordinary user, so the owner is that user: chown to oneself
// is permitted, and what is under test is the confinement, not the uid change.
func me(t *testing.T) string {
	t.Helper()
	u, err := user.Current()
	if err != nil {
		t.Fatal(err)
	}
	return u.Username
}

func TestOwnedFileIsWrittenInsideItsBaseWithItsMode(t *testing.T) {
	base := t.TempDir()
	step := &StepFile{StepID: "f", Path: filepath.Join(base, ".ssh", "key"), Content: []byte("secret"), Mode: 0o600, Owner: me(t), Base: base}

	if action, err := step.Apply(context.Background()); err != nil || action != ActionCreate {
		t.Fatalf("Apply = %v, %v", action, err)
	}
	info, err := os.Stat(step.Path)
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %v, err = %v", info, err)
	}
	if got, _ := os.ReadFile(step.Path); string(got) != "secret" {
		t.Errorf("content = %q", got)
	}
	if action, _ := step.Plan(context.Background()); action != ActionNoop {
		t.Errorf("a second plan should be a no-op, got %v", action)
	}
}

// Audit 2026-11-08, finding 1: an ancestor swapped for a symlink to a privileged
// directory must not redirect a root write.
func TestOwnedFileDoesNotFollowAnAncestorSymlinkOut(t *testing.T) {
	base := t.TempDir()
	outside := t.TempDir()
	if err := os.Symlink(outside, filepath.Join(base, ".ssh")); err != nil {
		t.Fatal(err)
	}

	step := &StepFile{StepID: "f", Path: filepath.Join(base, ".ssh", "authorized_keys"), Content: []byte("attacker"), Mode: 0o600, Owner: me(t), Base: base}
	if _, err := step.Apply(context.Background()); err == nil {
		t.Fatal("a write through a symlink that leaves the base succeeded")
	}
	if entries, _ := os.ReadDir(outside); len(entries) != 0 {
		t.Errorf("something was written outside the base: %v", entries)
	}
}

func TestOwnedStepsRefuseAPathOutsideTheirBase(t *testing.T) {
	base := t.TempDir()
	other := t.TempDir()
	for _, p := range []string{filepath.Join(other, "x"), filepath.Join(base, "..", "x"), base} {
		step := &StepFile{StepID: "f", Path: p, Content: []byte("x"), Owner: me(t), Base: base}
		if _, err := step.Plan(context.Background()); err == nil {
			t.Errorf("%s was accepted", p)
		}
	}
	// An owner without a base is refused rather than treated as unconfined.
	if _, err := (&StepFile{StepID: "f", Path: filepath.Join(base, "x"), Owner: me(t)}).Plan(context.Background()); err == nil {
		t.Error("an owner with no base was accepted")
	}
}

// A FIFO planted at the path must not hold the agent: root would block reading it.
func TestOwnedPlanDoesNotBlockOnAFifo(t *testing.T) {
	base := t.TempDir()
	fifo := filepath.Join(base, "env")
	if err := syscall.Mkfifo(fifo, 0o600); err != nil {
		t.Skip("mkfifo unavailable")
	}

	done := make(chan error, 1)
	go func() {
		_, err := (&StepFile{StepID: "f", Path: fifo, Content: []byte("x"), Owner: me(t), Base: base}).Plan(context.Background())
		done <- err
	}()

	select {
	case err := <-done:
		if err == nil {
			t.Error("a FIFO was treated as a regular file")
		}
	case <-time.After(3 * time.Second):
		t.Fatal("Plan blocked on a FIFO")
	}
}

func TestOwnedDirAndAbsentStayInsideTheirBase(t *testing.T) {
	base := t.TempDir()
	dir := &StepDir{StepID: "d", Path: filepath.Join(base, "site", "shared"), Mode: 0o700, Owner: me(t), Base: base}
	if _, err := dir.Apply(context.Background()); err != nil {
		t.Fatal(err)
	}
	if info, err := os.Stat(dir.Path); err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("dir = %v, %v", info, err)
	}

	// Removal through a swapped ancestor must leave the outside alone.
	outside := t.TempDir()
	victim := filepath.Join(outside, "important")
	if err := os.WriteFile(victim, []byte("keep"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(base, "link")); err != nil {
		t.Fatal(err)
	}
	gone := &StepAbsent{StepID: "a", Path: filepath.Join(base, "link", "important"), Owner: me(t), Base: base}
	_, _ = gone.Apply(context.Background())
	if _, err := os.Stat(victim); err != nil {
		t.Errorf("a file outside the base was removed: %v", err)
	}
}

func TestOwnedFieldsTravelInTheSpec(t *testing.T) {
	spec := StepSpec{ID: "f", Type: TypeFile, Path: "/home/deploy/.env", Owner: "deploy", Base: "/home/deploy"}
	step, err := spec.build(nil)
	if err != nil {
		t.Fatal(err)
	}
	f := step.(*StepFile)
	if f.Owner != "deploy" || f.Base != "/home/deploy" {
		t.Errorf("owner/base lost: %+v", f)
	}
}
