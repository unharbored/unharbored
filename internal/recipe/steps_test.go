package recipe

import (
	"context"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

// fakeRunner scripts command results for the exec-shaped steps.
type fakeRunner struct {
	// results maps "name arg1 arg2" to a scripted outcome.
	results map[string]fakeResult
	calls   []string
}

type fakeResult struct {
	output string
	code   int
	err    error
}

func (f *fakeRunner) Run(_ context.Context, name string, args ...string) (string, int, error) {
	key := strings.TrimSpace(name + " " + strings.Join(args, " "))
	f.calls = append(f.calls, key)
	if res, ok := f.results[key]; ok {
		return res.output, res.code, res.err
	}
	return "", 0, nil
}

func (f *fakeRunner) called(key string) bool {
	for _, c := range f.calls {
		if c == key {
			return true
		}
	}
	return false
}

// ---- StepFile ----

func TestFileCreatesAndIsIdempotent(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "nested", "app.conf")

	step := &StepFile{StepID: "conf", Path: path, Content: []byte("server {}\n")}
	ctx := context.Background()

	if action, err := step.Plan(ctx); err != nil || action != ActionCreate {
		t.Fatalf("Plan = %v, %v; want create, nil", action, err)
	}

	action, err := step.Apply(ctx)
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionCreate {
		t.Errorf("Apply = %v, want create", action)
	}

	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("ReadFile: %v", err)
	}
	if string(got) != "server {}\n" {
		t.Errorf("content = %q", got)
	}

	// Second apply must be a no-op. This is the property that makes drift
	// correction safe to run on a schedule.
	action, err = step.Apply(ctx)
	if err != nil {
		t.Fatalf("second Apply: %v", err)
	}
	if action != ActionNoop {
		t.Errorf("second Apply = %v, want noop", action)
	}
}

func TestFileUpdatesChangedContent(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "app.conf")
	if err := os.WriteFile(path, []byte("old\n"), 0o644); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	step := &StepFile{StepID: "conf", Path: path, Content: []byte("new\n")}
	ctx := context.Background()

	if action, err := step.Plan(ctx); err != nil || action != ActionUpdate {
		t.Fatalf("Plan = %v, %v; want update, nil", action, err)
	}
	if action, err := step.Apply(ctx); err != nil || action != ActionUpdate {
		t.Fatalf("Apply = %v, %v; want update, nil", action, err)
	}

	got, _ := os.ReadFile(path)
	if string(got) != "new\n" {
		t.Errorf("content = %q, want new", got)
	}
}

// Writing through a temp file and renaming is what stops a reloading nginx
// from reading a half-written config. Verify no stray temp files survive.
func TestFileLeavesNoTempFiles(t *testing.T) {
	dir := t.TempDir()
	step := &StepFile{StepID: "conf", Path: filepath.Join(dir, "app.conf"), Content: []byte("x")}
	if _, err := step.Apply(context.Background()); err != nil {
		t.Fatalf("Apply: %v", err)
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatalf("ReadDir: %v", err)
	}
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), ".tmp-") {
			t.Errorf("temp file left behind: %s", e.Name())
		}
	}
	if len(entries) != 1 {
		t.Errorf("entries = %d, want 1", len(entries))
	}
}

func TestFileModeIsApplied(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("POSIX permission bits are not meaningful on Windows; see mode_windows.go")
	}

	dir := t.TempDir()
	path := filepath.Join(dir, "secret.env")
	step := &StepFile{StepID: "env", Path: path, Content: []byte("APP_KEY=x\n"), Mode: 0o600, Sensitive: true}

	if _, err := step.Apply(context.Background()); err != nil {
		t.Fatalf("Apply: %v", err)
	}

	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("Stat: %v", err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("mode = %o, want 600", info.Mode().Perm())
	}
}

// A mode change alone must be detected, not just a content change.
func TestFileDetectsModeDrift(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("POSIX permission bits are not meaningful on Windows")
	}

	dir := t.TempDir()
	path := filepath.Join(dir, "secret.env")
	step := &StepFile{StepID: "env", Path: path, Content: []byte("k=v\n"), Mode: 0o600}

	if _, err := step.Apply(context.Background()); err != nil {
		t.Fatalf("Apply: %v", err)
	}
	// Someone widens the permissions out of band.
	if err := os.Chmod(path, 0o644); err != nil {
		t.Fatalf("Chmod: %v", err)
	}

	action, err := step.Plan(context.Background())
	if err != nil {
		t.Fatalf("Plan: %v", err)
	}
	if action != ActionUpdate {
		t.Errorf("Plan = %v, want update after mode drift", action)
	}
	if _, err := step.Apply(context.Background()); err != nil {
		t.Fatalf("Apply: %v", err)
	}
	info, _ := os.Stat(path)
	if info.Mode().Perm() != 0o600 {
		t.Errorf("mode = %o after correction, want 600", info.Mode().Perm())
	}
}

// Sensitive files must never put their contents in a log line.
func TestFileRedactsSensitiveDescription(t *testing.T) {
	step := &StepFile{
		StepID:    "env",
		Path:      "/srv/app/shared/.env",
		Content:   []byte("DB_PASSWORD=hunter2\n"),
		Sensitive: true,
	}
	desc := step.Describe()
	if strings.Contains(desc, "hunter2") {
		t.Errorf("Describe leaked the content: %q", desc)
	}
	if !strings.Contains(desc, "redacted") {
		t.Errorf("Describe = %q, want it to say redacted", desc)
	}
}

func TestFileRejectsDirectoryAtPath(t *testing.T) {
	dir := t.TempDir()
	step := &StepFile{StepID: "conf", Path: dir, Content: []byte("x")}
	if _, err := step.Plan(context.Background()); err == nil {
		t.Fatal("expected error when a directory occupies the path")
	}
}

// ---- StepDir ----

func TestDirCreatesAndIsIdempotent(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "releases", "shared")

	step := &StepDir{StepID: "shared", Path: path}
	ctx := context.Background()

	if action, err := step.Apply(ctx); err != nil || action != ActionCreate {
		t.Fatalf("Apply = %v, %v; want create, nil", action, err)
	}
	if info, err := os.Stat(path); err != nil || !info.IsDir() {
		t.Fatalf("directory not created: %v", err)
	}
	if action, err := step.Apply(ctx); err != nil || action != ActionNoop {
		t.Fatalf("second Apply = %v, %v; want noop, nil", action, err)
	}
}

func TestDirRejectsFileAtPath(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "thing")
	if err := os.WriteFile(path, []byte("x"), 0o644); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}
	step := &StepDir{StepID: "d", Path: path}
	if _, err := step.Plan(context.Background()); err == nil {
		t.Fatal("expected error when a file occupies the path")
	}
}

// ---- StepSymlink ----

func TestSymlinkCreatesAndRepoints(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("creating symlinks on Windows needs elevation; agents run on Linux")
	}

	dir := t.TempDir()
	r1 := filepath.Join(dir, "releases", "r1")
	r2 := filepath.Join(dir, "releases", "r2")
	for _, p := range []string{r1, r2} {
		if err := os.MkdirAll(p, 0o755); err != nil {
			t.Fatalf("MkdirAll: %v", err)
		}
	}
	link := filepath.Join(dir, "current")
	ctx := context.Background()

	step := &StepSymlink{StepID: "current", Path: link, Target: r1}
	if action, err := step.Apply(ctx); err != nil || action != ActionCreate {
		t.Fatalf("Apply = %v, %v; want create, nil", action, err)
	}
	if got, _ := os.Readlink(link); got != r1 {
		t.Errorf("link -> %q, want %q", got, r1)
	}
	if action, err := step.Apply(ctx); err != nil || action != ActionNoop {
		t.Fatalf("second Apply = %v, %v; want noop, nil", action, err)
	}

	// Repointing is the release activation path. It must succeed over an
	// existing link.
	step2 := &StepSymlink{StepID: "current", Path: link, Target: r2}
	if action, err := step2.Apply(ctx); err != nil || action != ActionUpdate {
		t.Fatalf("repoint = %v, %v; want update, nil", action, err)
	}
	if got, _ := os.Readlink(link); got != r2 {
		t.Errorf("link -> %q, want %q", got, r2)
	}
}

// Clobbering a real directory here would destroy a running application.
func TestSymlinkRefusesToReplaceRealDirectory(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symlink semantics differ on Windows; agents run on Linux")
	}

	dir := t.TempDir()
	path := filepath.Join(dir, "current")
	if err := os.MkdirAll(path, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}

	step := &StepSymlink{StepID: "current", Path: path, Target: filepath.Join(dir, "r1")}
	if _, err := step.Plan(context.Background()); err == nil {
		t.Fatal("expected refusal to replace a real directory with a symlink")
	}
}

// ---- StepAbsent ----

func TestAbsentRemovesAndIsIdempotent(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "stale")
	if err := os.MkdirAll(filepath.Join(path, "child"), 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}

	step := &StepAbsent{StepID: "stale", Path: path}
	ctx := context.Background()

	if action, err := step.Apply(ctx); err != nil || action != ActionDelete {
		t.Fatalf("Apply = %v, %v; want delete, nil", action, err)
	}
	if _, err := os.Stat(path); !errors.Is(err, fs.ErrNotExist) {
		t.Errorf("path still exists: %v", err)
	}
	if action, err := step.Apply(ctx); err != nil || action != ActionNoop {
		t.Fatalf("second Apply = %v, %v; want noop, nil", action, err)
	}
}

// ---- StepCommand ----

func TestCommandRunsWhenGuardFails(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"test -f /etc/thing": {code: 1},
	}}
	step := &StepCommand{
		StepID: "c", Name: "touch", Args: []string{"/etc/thing"},
		Unless: []string{"test", "-f", "/etc/thing"},
		Runner: fr,
	}

	action, err := step.Apply(context.Background())
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionRun {
		t.Errorf("Apply = %v, want run", action)
	}
	if !fr.called("touch /etc/thing") {
		t.Errorf("command was not run; calls = %v", fr.calls)
	}
}

// The guard is what makes an arbitrary command idempotent.
func TestCommandSkippedWhenGuardPasses(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"test -f /etc/thing": {code: 0},
	}}
	step := &StepCommand{
		StepID: "c", Name: "touch", Args: []string{"/etc/thing"},
		Unless: []string{"test", "-f", "/etc/thing"},
		Runner: fr,
	}

	action, err := step.Apply(context.Background())
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionNoop {
		t.Errorf("Apply = %v, want noop", action)
	}
	if fr.called("touch /etc/thing") {
		t.Errorf("guarded command ran anyway; calls = %v", fr.calls)
	}
}

func TestCommandWithoutGuardAlwaysRuns(t *testing.T) {
	fr := &fakeRunner{}
	step := &StepCommand{StepID: "c", Name: "echo", Args: []string{"hi"}, Runner: fr}

	for range 2 {
		action, err := step.Apply(context.Background())
		if err != nil {
			t.Fatalf("Apply: %v", err)
		}
		if action != ActionRun {
			t.Errorf("Apply = %v, want run", action)
		}
	}
}

func TestCommandNonZeroExitIsAnError(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"false": {code: 1, output: "it failed"},
	}}
	step := &StepCommand{StepID: "c", Name: "false", Runner: fr}

	if _, err := step.Apply(context.Background()); err == nil {
		t.Fatal("expected error for a non-zero exit")
	} else if !strings.Contains(err.Error(), "it failed") {
		t.Errorf("error lost the command output: %v", err)
	}
}

// ---- StepPackage ----

func TestPackageInstallsWhenMissing(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"dpkg-query -W -f=${Status} nginx": {code: 1},
	}}
	step := &StepPackage{StepID: "p", Name: "nginx", Runner: fr}

	action, err := step.Apply(context.Background())
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionCreate {
		t.Errorf("Apply = %v, want create", action)
	}
	if !fr.called("apt-get install -y --no-install-recommends nginx") {
		t.Errorf("apt-get was not invoked; calls = %v", fr.calls)
	}
}

func TestPackageNoopWhenInstalled(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"dpkg-query -W -f=${Status} nginx": {code: 0, output: "install ok installed"},
	}}
	step := &StepPackage{StepID: "p", Name: "nginx", Runner: fr}

	action, err := step.Apply(context.Background())
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionNoop {
		t.Errorf("Apply = %v, want noop", action)
	}
	if fr.called("apt-get install -y --no-install-recommends nginx") {
		t.Error("apt-get ran for an already installed package")
	}
}

// dpkg knows about removed packages too. Matching on the full status string
// is what distinguishes "installed" from "was installed once".
func TestPackageTreatsRemovedAsMissing(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"dpkg-query -W -f=${Status} nginx": {code: 0, output: "deinstall ok config-files"},
	}}
	step := &StepPackage{StepID: "p", Name: "nginx", Runner: fr}

	action, err := step.Plan(context.Background())
	if err != nil {
		t.Fatalf("Plan: %v", err)
	}
	if action != ActionCreate {
		t.Errorf("Plan = %v, want create for a removed package", action)
	}
}

// ---- StepService ----

func TestServiceStartsWhenInactive(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"systemctl is-active --quiet nginx": {code: 3},
	}}
	step := &StepService{StepID: "s", Unit: "nginx", State: ServiceRunning, Runner: fr}

	action, err := step.Apply(context.Background())
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionUpdate {
		t.Errorf("Apply = %v, want update", action)
	}
	if !fr.called("systemctl enable --now nginx") {
		t.Errorf("service was not started; calls = %v", fr.calls)
	}
}

func TestServiceNoopWhenAlreadyRunning(t *testing.T) {
	fr := &fakeRunner{results: map[string]fakeResult{
		"systemctl is-active --quiet nginx": {code: 0},
	}}
	step := &StepService{StepID: "s", Unit: "nginx", State: ServiceRunning, Runner: fr}

	action, err := step.Apply(context.Background())
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionNoop {
		t.Errorf("Apply = %v, want noop", action)
	}
	if fr.called("systemctl enable --now nginx") {
		t.Error("systemctl enable ran for an already active service")
	}
}

// A reload is unconditional: there is no way to know whether the running
// process already picked up the new configuration.
func TestServiceReloadAlwaysRuns(t *testing.T) {
	fr := &fakeRunner{}
	step := &StepService{StepID: "s", Unit: "nginx", State: ServiceReloaded, Runner: fr}

	action, err := step.Apply(context.Background())
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if action != ActionRun {
		t.Errorf("Apply = %v, want run", action)
	}
	if !fr.called("systemctl reload nginx") {
		t.Errorf("reload was not issued; calls = %v", fr.calls)
	}
}

func TestServiceUnknownStateIsAnError(t *testing.T) {
	step := &StepService{StepID: "s", Unit: "nginx", State: "sideways", Runner: &fakeRunner{}}
	if _, err := step.Plan(context.Background()); err == nil {
		t.Fatal("expected error for an unknown service state")
	}
}

// ---- integration through the Runner ----

func TestRecipeConvergesThenReportsNoDrift(t *testing.T) {
	dir := t.TempDir()
	fr := &fakeRunner{results: map[string]fakeResult{
		"dpkg-query -W -f=${Status} nginx":  {code: 0, output: "install ok installed"},
		"systemctl is-active --quiet nginx": {code: 0},
	}}

	rec := Recipe{Name: "site", Steps: []Step{
		&StepDir{StepID: "root", Path: filepath.Join(dir, "srv", "app")},
		&StepFile{StepID: "vhost", Path: filepath.Join(dir, "etc", "nginx", "app.conf"), Content: []byte("server {}\n")},
		&StepPackage{StepID: "nginx-pkg", Name: "nginx", Runner: fr},
		&StepService{StepID: "nginx-svc", Unit: "nginx", State: ServiceRunning, Runner: fr},
	}}

	first, err := NewRunner().Run(context.Background(), rec)
	if err != nil {
		t.Fatalf("first Run: %v", err)
	}
	if first.Failed {
		t.Fatalf("first run failed: %+v", first.Steps)
	}
	if first.Changed != 2 {
		t.Errorf("first run Changed = %d, want 2 (dir and file)", first.Changed)
	}

	second, err := NewRunner().Run(context.Background(), rec)
	if err != nil {
		t.Fatalf("second Run: %v", err)
	}
	if second.Changed != 0 {
		t.Errorf("second run Changed = %d, want 0; recipe is not idempotent", second.Changed)
	}
}
