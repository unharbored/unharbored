package deploy

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// requireSymlinks skips when the platform or account cannot create symlinks.
// Agents run on Linux where this always works; on a Windows workstation it
// depends on Developer Mode or elevation.
func requireSymlinks(t *testing.T) {
	t.Helper()
	dir := t.TempDir()
	target := filepath.Join(dir, "target")
	if err := os.MkdirAll(target, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}
	if err := os.Symlink(target, filepath.Join(dir, "link")); err != nil {
		t.Skipf("symlinks unavailable on this host (%v); agents run on Linux where they are required", err)
	}
}

func newManager(t *testing.T) *Manager {
	t.Helper()
	m := NewManager(t.TempDir())
	if err := m.Init(context.Background()); err != nil {
		t.Fatalf("Init: %v", err)
	}
	return m
}

// ---- release IDs ----

func TestNewReleaseIDIsSortable(t *testing.T) {
	base := time.Date(2026, 11, 4, 14, 30, 22, 0, time.UTC)
	a := NewReleaseID(base, "a1b2c3d")
	b := NewReleaseID(base.Add(time.Hour), "e4f5a6b")

	if a >= b {
		t.Errorf("lexical order does not match chronological order: %q >= %q", a, b)
	}
	if !strings.HasPrefix(a, "20261104T143022Z-") {
		t.Errorf("ID = %q, want a UTC timestamp prefix", a)
	}
	if err := ValidateReleaseID(a); err != nil {
		t.Errorf("generated ID failed validation: %v", err)
	}
}

// A commit ref arrives from a webhook. One crafted to contain path
// separators must not be able to steer a deploy outside the releases tree.
func TestNewReleaseIDSanitisesHostileRefs(t *testing.T) {
	base := time.Date(2026, 11, 4, 14, 30, 22, 0, time.UTC)

	hostile := []string{
		"../../etc/cron.d/evil",
		"..",
		"../",
		"a/b/c",
		"a\\b",
		"with space",
		"semi;colon",
		"$(whoami)",
		"",
		strings.Repeat("x", 200),
	}
	for _, ref := range hostile {
		id := NewReleaseID(base, ref)
		if err := ValidateReleaseID(id); err != nil {
			t.Errorf("ref %q produced an invalid ID %q: %v", ref, id, err)
		}
		if strings.ContainsAny(id, `/\`) {
			t.Errorf("ref %q produced an ID with a path separator: %q", ref, id)
		}
		if strings.Contains(id, "..") {
			t.Errorf("ref %q produced an ID containing a parent reference: %q", ref, id)
		}
	}
}

func TestValidateReleaseIDRejectsTraversal(t *testing.T) {
	bad := []string{
		"../escape",
		"20261104T143022Z-../../etc",
		"20261104T143022Z-a/b",
		"not-a-release",
		"",
		"20261104T143022Z-",
	}
	for _, id := range bad {
		if err := ValidateReleaseID(id); !errors.Is(err, ErrInvalidReleaseID) {
			t.Errorf("ValidateReleaseID(%q) = %v, want ErrInvalidReleaseID", id, err)
		}
	}
}

func TestPrepareRejectsInvalidReleaseID(t *testing.T) {
	m := newManager(t)
	if _, err := m.Prepare(context.Background(), "../escape", nil); !errors.Is(err, ErrInvalidReleaseID) {
		t.Fatalf("err = %v, want ErrInvalidReleaseID", err)
	}
}

// ---- shared paths ----

func TestPrepareLinksSharedPaths(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()
	id := NewReleaseID(time.Now(), "abc1234")

	rel, err := m.Prepare(ctx, id, DefaultLaravelShared)
	if err != nil {
		t.Fatalf("Prepare: %v", err)
	}

	for _, sp := range DefaultLaravelShared {
		link := filepath.Join(rel.Path, sp.Rel)
		target, err := os.Readlink(link)
		if err != nil {
			t.Errorf("%s is not a symlink: %v", sp.Rel, err)
			continue
		}
		want := filepath.Join(m.Layout.SharedDir(), sp.Rel)
		if target != want {
			t.Errorf("%s -> %q, want %q", sp.Rel, target, want)
		}
		// The shared entry itself must exist, or the link dangles.
		if _, err := os.Stat(want); err != nil {
			t.Errorf("shared target %s missing: %v", want, err)
		}
	}
}

// Shared state must survive a deploy. That is the entire point of the shared
// directory, and getting it wrong loses user uploads.
func TestSharedContentSurvivesNewRelease(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	first := NewReleaseID(time.Now(), "aaa1111")
	r1, err := m.Prepare(ctx, first, DefaultLaravelShared)
	if err != nil {
		t.Fatalf("Prepare first: %v", err)
	}
	// Write through the release's link, as the application would.
	if err := os.WriteFile(filepath.Join(r1.Path, ".env"), []byte("APP_KEY=secret\n"), 0o640); err != nil {
		t.Fatalf("write .env: %v", err)
	}
	upload := filepath.Join(r1.Path, "storage", "avatar.png")
	if err := os.WriteFile(upload, []byte("png"), 0o644); err != nil {
		t.Fatalf("write upload: %v", err)
	}

	second := NewReleaseID(time.Now().Add(time.Minute), "bbb2222")
	r2, err := m.Prepare(ctx, second, DefaultLaravelShared)
	if err != nil {
		t.Fatalf("Prepare second: %v", err)
	}

	env, err := os.ReadFile(filepath.Join(r2.Path, ".env"))
	if err != nil {
		t.Fatalf("read .env from new release: %v", err)
	}
	if string(env) != "APP_KEY=secret\n" {
		t.Errorf(".env = %q, want the shared content", env)
	}
	if _, err := os.Stat(filepath.Join(r2.Path, "storage", "avatar.png")); err != nil {
		t.Errorf("upload did not survive the deploy: %v", err)
	}
}

// A build that produces its own .env or storage must be replaced by the link,
// not left in place, or the release silently uses the wrong configuration.
func TestPrepareReplacesBuildArtifactsWithLinks(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()
	id := NewReleaseID(time.Now(), "ccc3333")

	// Seed the shared .env so we can tell which one wins.
	if err := os.WriteFile(filepath.Join(m.Layout.SharedDir(), ".env"), []byte("SHARED\n"), 0o640); err != nil {
		t.Fatalf("seed shared .env: %v", err)
	}

	// Simulate a build that shipped its own .env and storage directory.
	path := m.Layout.ReleasePath(id)
	if err := os.MkdirAll(filepath.Join(path, "storage", "junk"), 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}
	if err := os.WriteFile(filepath.Join(path, ".env"), []byte("FROM_BUILD\n"), 0o644); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	if _, err := m.Prepare(ctx, id, DefaultLaravelShared); err != nil {
		t.Fatalf("Prepare: %v", err)
	}

	env, err := os.ReadFile(filepath.Join(path, ".env"))
	if err != nil {
		t.Fatalf("read .env: %v", err)
	}
	if string(env) != "SHARED\n" {
		t.Errorf(".env = %q, want the shared copy to win", env)
	}
	if _, err := os.Stat(filepath.Join(path, "storage", "junk")); err == nil {
		t.Error("build's storage directory survived; it should have been replaced by the link")
	}
}

func TestPrepareRejectsEscapingSharedPath(t *testing.T) {
	m := newManager(t)
	ctx := context.Background()
	id := NewReleaseID(time.Now(), "ddd4444")

	bad := []string{"../outside", "/etc/passwd", "a/../../b", ""}
	for _, rel := range bad {
		_, err := m.Prepare(ctx, id, []SharedPath{{Rel: rel, Kind: SharedFile}})
		if !errors.Is(err, ErrInvalidSharedPath) {
			t.Errorf("shared path %q: err = %v, want ErrInvalidSharedPath", rel, err)
		}
	}
}

// ---- activation ----

func TestActivateAndCurrent(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	if _, err := m.Current(ctx); !errors.Is(err, ErrNoCurrentRelease) {
		t.Fatalf("Current before any deploy = %v, want ErrNoCurrentRelease", err)
	}

	id := NewReleaseID(time.Now(), "aaa1111")
	if _, err := m.Prepare(ctx, id, nil); err != nil {
		t.Fatalf("Prepare: %v", err)
	}
	if err := m.Activate(ctx, id); err != nil {
		t.Fatalf("Activate: %v", err)
	}

	got, err := m.Current(ctx)
	if err != nil {
		t.Fatalf("Current: %v", err)
	}
	if got != id {
		t.Errorf("Current = %q, want %q", got, id)
	}
}

// Activation replaces an existing link. If this failed, the second deploy to
// any server would break.
func TestActivateReplacesExistingLink(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	first := NewReleaseID(time.Now(), "aaa1111")
	second := NewReleaseID(time.Now().Add(time.Minute), "bbb2222")
	for _, id := range []string{first, second} {
		if _, err := m.Prepare(ctx, id, nil); err != nil {
			t.Fatalf("Prepare %s: %v", id, err)
		}
	}

	if err := m.Activate(ctx, first); err != nil {
		t.Fatalf("Activate first: %v", err)
	}
	if err := m.Activate(ctx, second); err != nil {
		t.Fatalf("Activate second: %v", err)
	}

	got, err := m.Current(ctx)
	if err != nil {
		t.Fatalf("Current: %v", err)
	}
	if got != second {
		t.Errorf("Current = %q, want %q", got, second)
	}

	// No staging artifact may survive.
	if _, err := os.Stat(filepath.Join(m.Layout.Root, ".current.tmp")); err == nil {
		t.Error("staging link .current.tmp was left behind")
	}
}

func TestActivateUnknownRelease(t *testing.T) {
	m := newManager(t)
	id := NewReleaseID(time.Now(), "nope123")
	if err := m.Activate(context.Background(), id); !errors.Is(err, ErrReleaseNotFound) {
		t.Fatalf("err = %v, want ErrReleaseNotFound", err)
	}
}

// ---- rollback ----

func TestRollbackReturnsToPreviousRelease(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	ids := make([]string, 3)
	base := time.Now()
	for i := range ids {
		ids[i] = NewReleaseID(base.Add(time.Duration(i)*time.Minute), "rel")
		if _, err := m.Prepare(ctx, ids[i], nil); err != nil {
			t.Fatalf("Prepare %d: %v", i, err)
		}
		if err := m.Activate(ctx, ids[i]); err != nil {
			t.Fatalf("Activate %d: %v", i, err)
		}
	}

	got, err := m.Rollback(ctx)
	if err != nil {
		t.Fatalf("Rollback: %v", err)
	}
	if got != ids[1] {
		t.Errorf("Rollback went to %q, want %q", got, ids[1])
	}
	if current, _ := m.Current(ctx); current != ids[1] {
		t.Errorf("Current = %q, want %q", current, ids[1])
	}
}

// Repeated rollbacks must keep moving backwards through the release list.
//
// The tempting implementation, "activate whatever was live before the current
// release", oscillates: after rolling back from v3 to v2, the release live
// before v2 was v3, so a second rollback returns the operator to the exact
// deploy they were escaping. This test exists because that is the bug the
// first implementation shipped with.
func TestRollbackTwiceKeepsMovingBackwards(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	ids := make([]string, 3)
	base := time.Now()
	for i := range ids {
		ids[i] = NewReleaseID(base.Add(time.Duration(i)*time.Minute), "rel")
		if _, err := m.Prepare(ctx, ids[i], nil); err != nil {
			t.Fatalf("Prepare %d: %v", i, err)
		}
		if err := m.Activate(ctx, ids[i]); err != nil {
			t.Fatalf("Activate %d: %v", i, err)
		}
	}

	// v3 -> v2
	if got, err := m.Rollback(ctx); err != nil || got != ids[1] {
		t.Fatalf("first Rollback = %q, %v; want %q", got, err, ids[1])
	}
	// v2 -> v1. Walking activation history would give v3 here, sending the
	// operator back into the release they just rolled out of.
	got, err := m.Rollback(ctx)
	if err != nil {
		t.Fatalf("second Rollback: %v", err)
	}
	if got != ids[0] {
		t.Errorf("second Rollback = %q, want %q (activation history would wrongly give %q)", got, ids[0], ids[2])
	}

	// A third rollback has nowhere left to go.
	if _, err := m.Rollback(ctx); !errors.Is(err, ErrNothingToRollBack) {
		t.Errorf("third Rollback = %v, want ErrNothingToRollBack", err)
	}
}

// A dangling current symlink means someone deleted the live release by hand.
// Rolling back should recover to the newest surviving release rather than
// refusing to act.
func TestPreviousRecoversFromDeletedCurrentRelease(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	ids := make([]string, 2)
	base := time.Now()
	for i := range ids {
		ids[i] = NewReleaseID(base.Add(time.Duration(i)*time.Minute), "rel")
		if _, err := m.Prepare(ctx, ids[i], nil); err != nil {
			t.Fatalf("Prepare %d: %v", i, err)
		}
	}
	if err := m.Activate(ctx, ids[1]); err != nil {
		t.Fatalf("Activate: %v", err)
	}

	// The live release is removed out of band.
	if err := os.RemoveAll(m.Layout.ReleasePath(ids[1])); err != nil {
		t.Fatalf("RemoveAll: %v", err)
	}

	prev, err := m.Previous(ctx)
	if err != nil {
		t.Fatalf("Previous: %v", err)
	}
	if prev != ids[0] {
		t.Errorf("Previous = %q, want %q", prev, ids[0])
	}
}

func TestRollbackWithNoHistory(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	id := NewReleaseID(time.Now(), "only1234")
	if _, err := m.Prepare(ctx, id, nil); err != nil {
		t.Fatalf("Prepare: %v", err)
	}
	if err := m.Activate(ctx, id); err != nil {
		t.Fatalf("Activate: %v", err)
	}

	if _, err := m.Rollback(ctx); !errors.Is(err, ErrNothingToRollBack) {
		t.Fatalf("err = %v, want ErrNothingToRollBack", err)
	}
}

// A rollback target that was pruned off disk must be skipped rather than
// activated into a missing directory.
func TestPreviousSkipsDeletedReleases(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	ids := make([]string, 3)
	base := time.Now()
	for i := range ids {
		ids[i] = NewReleaseID(base.Add(time.Duration(i)*time.Minute), "rel")
		if _, err := m.Prepare(ctx, ids[i], nil); err != nil {
			t.Fatalf("Prepare %d: %v", i, err)
		}
		if err := m.Activate(ctx, ids[i]); err != nil {
			t.Fatalf("Activate %d: %v", i, err)
		}
	}

	// Remove the natural rollback target out of band.
	if err := os.RemoveAll(m.Layout.ReleasePath(ids[1])); err != nil {
		t.Fatalf("RemoveAll: %v", err)
	}

	prev, err := m.Previous(ctx)
	if err != nil {
		t.Fatalf("Previous: %v", err)
	}
	if prev != ids[0] {
		t.Errorf("Previous = %q, want %q", prev, ids[0])
	}
}

// ---- listing and pruning ----

func TestListIsChronological(t *testing.T) {
	m := newManager(t)
	ctx := context.Background()

	base := time.Now()
	var want []string
	for i := range 4 {
		id := NewReleaseID(base.Add(time.Duration(i)*time.Minute), "rel")
		want = append(want, id)
		if _, err := m.Prepare(ctx, id, nil); err != nil {
			t.Fatalf("Prepare: %v", err)
		}
	}

	got, err := m.List(ctx)
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(got) != len(want) {
		t.Fatalf("len = %d, want %d", len(got), len(want))
	}
	for i := range want {
		if got[i].ID != want[i] {
			t.Errorf("List[%d] = %q, want %q", i, got[i].ID, want[i])
		}
	}
}

// Anything not matching our ID format is ignored, so Prune can never delete a
// directory somebody else put there.
func TestListIgnoresForeignDirectories(t *testing.T) {
	m := newManager(t)
	ctx := context.Background()

	if err := os.MkdirAll(filepath.Join(m.Layout.ReleasesDir(), "important-backup"), 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}
	id := NewReleaseID(time.Now(), "rel")
	if _, err := m.Prepare(ctx, id, nil); err != nil {
		t.Fatalf("Prepare: %v", err)
	}

	got, err := m.List(ctx)
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(got) != 1 || got[0].ID != id {
		t.Fatalf("List = %+v, want only %q", got, id)
	}
}

func TestPruneKeepsRetentionCount(t *testing.T) {
	m := newManager(t)
	m.Keep = 3
	ctx := context.Background()

	base := time.Now()
	ids := make([]string, 6)
	for i := range ids {
		ids[i] = NewReleaseID(base.Add(time.Duration(i)*time.Minute), "rel")
		if _, err := m.Prepare(ctx, ids[i], nil); err != nil {
			t.Fatalf("Prepare: %v", err)
		}
	}

	deleted, err := m.Prune(ctx)
	if err != nil {
		t.Fatalf("Prune: %v", err)
	}
	if len(deleted) != 3 {
		t.Fatalf("deleted %d, want 3: %v", len(deleted), deleted)
	}

	remaining, err := m.List(ctx)
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(remaining) != 3 {
		t.Fatalf("remaining = %d, want 3", len(remaining))
	}
	// The three kept must be the newest.
	for i, rel := range remaining {
		if rel.ID != ids[i+3] {
			t.Errorf("remaining[%d] = %q, want %q", i, rel.ID, ids[i+3])
		}
	}
}

// Pruning away the release you would roll back to turns a bad deploy into an
// outage. Both the current release and the rollback target are protected.
func TestPruneNeverRemovesCurrentOrRollbackTarget(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	m.Keep = 1
	ctx := context.Background()

	base := time.Now()
	ids := make([]string, 4)
	for i := range ids {
		ids[i] = NewReleaseID(base.Add(time.Duration(i)*time.Minute), "rel")
		if _, err := m.Prepare(ctx, ids[i], nil); err != nil {
			t.Fatalf("Prepare: %v", err)
		}
	}

	// Activate an old release, so current is not simply the newest.
	if err := m.Activate(ctx, ids[0]); err != nil {
		t.Fatalf("Activate: %v", err)
	}
	if err := m.Activate(ctx, ids[1]); err != nil {
		t.Fatalf("Activate: %v", err)
	}

	if _, err := m.Prune(ctx); err != nil {
		t.Fatalf("Prune: %v", err)
	}

	// ids[1] is current and ids[0] is the rollback target. Both must survive
	// despite Keep=1 and despite being the two oldest on disk.
	for _, id := range []string{ids[0], ids[1]} {
		if _, err := os.Stat(m.Layout.ReleasePath(id)); err != nil {
			t.Errorf("protected release %s was pruned: %v", id, err)
		}
	}
	// Rollback must still work afterwards.
	if got, err := m.Rollback(ctx); err != nil || got != ids[0] {
		t.Errorf("Rollback after prune = %q, %v; want %q", got, err, ids[0])
	}
}

func TestPruneBelowRetentionIsNoop(t *testing.T) {
	m := newManager(t)
	m.Keep = 5
	ctx := context.Background()

	for i := range 3 {
		id := NewReleaseID(time.Now().Add(time.Duration(i)*time.Minute), "rel")
		if _, err := m.Prepare(ctx, id, nil); err != nil {
			t.Fatalf("Prepare: %v", err)
		}
	}

	deleted, err := m.Prune(ctx)
	if err != nil {
		t.Fatalf("Prune: %v", err)
	}
	if len(deleted) != 0 {
		t.Errorf("deleted %v, want none", deleted)
	}
}

// ---- durability ----

func TestInitIsIdempotent(t *testing.T) {
	m := NewManager(t.TempDir())
	ctx := context.Background()
	for range 3 {
		if err := m.Init(ctx); err != nil {
			t.Fatalf("Init: %v", err)
		}
	}
	for _, dir := range []string{m.Layout.ReleasesDir(), m.Layout.SharedDir()} {
		if info, err := os.Stat(dir); err != nil || !info.IsDir() {
			t.Errorf("%s missing after Init: %v", dir, err)
		}
	}
}

func TestPrepareIsIdempotent(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()
	id := NewReleaseID(time.Now(), "rel")

	for range 2 {
		if _, err := m.Prepare(ctx, id, DefaultLaravelShared); err != nil {
			t.Fatalf("Prepare: %v", err)
		}
	}
}

// A corrupt history file must not block deploys. Activation works from the
// symlink; only rollback memory is lost.
func TestCorruptHistoryDoesNotBlockActivation(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	id := NewReleaseID(time.Now(), "rel")
	if _, err := m.Prepare(ctx, id, nil); err != nil {
		t.Fatalf("Prepare: %v", err)
	}
	if err := os.WriteFile(m.Layout.historyPath(), []byte("{not json"), 0o644); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	if err := m.Activate(ctx, id); err != nil {
		t.Fatalf("Activate with corrupt history: %v", err)
	}
	if got, _ := m.Current(ctx); got != id {
		t.Errorf("Current = %q, want %q", got, id)
	}
}

func TestHistoryIsBounded(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	ctx := context.Background()

	a := NewReleaseID(time.Now(), "aaa")
	b := NewReleaseID(time.Now().Add(time.Minute), "bbb")
	for _, id := range []string{a, b} {
		if _, err := m.Prepare(ctx, id, nil); err != nil {
			t.Fatalf("Prepare: %v", err)
		}
	}

	for i := range historyLimit + 20 {
		id := a
		if i%2 == 1 {
			id = b
		}
		if err := m.Activate(ctx, id); err != nil {
			t.Fatalf("Activate %d: %v", i, err)
		}
	}

	history, err := m.history()
	if err != nil {
		t.Fatalf("history: %v", err)
	}
	if len(history) > historyLimit {
		t.Errorf("len(history) = %d, want at most %d", len(history), historyLimit)
	}
}

// An application in a repository subfolder links its shared files from inside
// that folder, to the same place under shared/ they always lived.
func TestPrepareLinksSharedPathToSeparateTarget(t *testing.T) {
	requireSymlinks(t)

	m := newManager(t)
	id := NewReleaseID(time.Now(), "abc1234")
	shared := []SharedPath{
		{Rel: "apps/web/.env", Link: ".env", Kind: SharedFile},
		{Rel: "apps/web/storage", Link: "storage", Kind: SharedDir},
	}

	rel, err := m.Prepare(context.Background(), id, shared)
	if err != nil {
		t.Fatalf("Prepare: %v", err)
	}
	for _, sp := range shared {
		got, err := os.Readlink(filepath.Join(rel.Path, filepath.FromSlash(sp.Rel)))
		if err != nil {
			t.Fatalf("%s is not a symlink: %v", sp.Rel, err)
		}
		if want := filepath.Join(m.Layout.SharedDir(), sp.Link); got != want {
			t.Errorf("%s -> %q, want %q", sp.Rel, got, want)
		}
	}

	if _, err := m.Prepare(context.Background(), NewReleaseID(time.Now().Add(time.Second), "def5678"),
		[]SharedPath{{Rel: ".env", Link: "../escape", Kind: SharedFile}}); !errors.Is(err, ErrInvalidSharedPath) {
		t.Errorf("escaping link: err = %v, want ErrInvalidSharedPath", err)
	}
}
