package agentcore

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/unharbored/unharbored/internal/commands"
	"github.com/unharbored/unharbored/internal/deploy"
	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/recipe"
	"github.com/unharbored/unharbored/internal/transport"
)

// Exec is unrestricted by default.
//
// It is the customer's server. They own it, they pay for it, and they already
// have root on it, so a list of commands we are willing to run on their
// behalf restricts the legitimate owner and nobody else.
//
// The earlier version of this shipped an allow list justified as stopping a
// compromised control plane from running arbitrary commands. That reasoning
// did not survive contact with the rest of the agent: a recipe can carry a
// command step, command steps are not allow-listed, and a control plane that
// wanted arbitrary execution would simply send one. The list blocked `tail`
// and `systemctl status` for the person who owns the machine while blocking
// nothing at all for an attacker who had already taken the control plane.
//
// So the restriction is now opt-in. An organisation that wants a narrower
// surface, because of a compliance regime or because it runs nodes on behalf
// of someone else, can configure one. The default is to get out of the way.
//
// See docs/specs/security-model.md for what actually bounds a compromised
// control plane, which is the agent's closed step set and the fact that it
// ships as a signed binary rather than an interpreter.
//
// ErrCommandNotAllowed is returned when an allow list is configured and the
// command is not on it.
var ErrCommandNotAllowed = errors.New("agentcore: command is not allow-listed")

// defaultCommandTimeout bounds a command that did not ask for one.
const defaultCommandTimeout = 10 * time.Minute

// execute runs one command and reports its result.
func (a *Agent) execute(ctx context.Context, session *transport.Session, env *protocol.Envelope, cmd protocol.Command) {
	// A command replayed after a reconnect must not run twice. Migrations and
	// release activation are both unsafe to repeat.
	if cmd.IdempotencyKey != "" {
		if cached, ok := a.idem.get(cmd.IdempotencyKey); ok {
			a.send(session, env, protocol.TypeCommandAck, protocol.CommandAck{Accepted: true, Duplicate: true})
			a.send(session, env, protocol.TypeResult, cached)
			return
		}
	}

	a.send(session, env, protocol.TypeCommandAck, protocol.CommandAck{Accepted: true})

	timeout := defaultCommandTimeout
	if cmd.TimeoutSeconds > 0 {
		timeout = time.Duration(cmd.TimeoutSeconds) * time.Second
	}
	runCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	start := time.Now()
	detail, err := a.dispatch(runCtx, session, env, cmd)
	result := protocol.Result{DurationMS: time.Since(start).Milliseconds()}

	switch {
	case err == nil:
		result.Status = protocol.StatusSucceeded
		result.Detail = detail
	case errors.Is(runCtx.Err(), context.DeadlineExceeded):
		result.Status = protocol.StatusTimedOut
		result.Error = fmt.Sprintf("command exceeded %s", timeout)
	case errors.Is(ctx.Err(), context.Canceled):
		result.Status = protocol.StatusCancelled
		result.Error = err.Error()
	default:
		result.Status = protocol.StatusFailed
		result.Error = err.Error()
	}

	if cmd.IdempotencyKey != "" && result.Status == protocol.StatusSucceeded {
		a.idem.put(cmd.IdempotencyKey, result)
	}
	a.send(session, env, protocol.TypeResult, result)
}

// dispatch selects a handler and returns its detail payload.
func (a *Agent) dispatch(ctx context.Context, session *transport.Session, env *protocol.Envelope, cmd protocol.Command) (json.RawMessage, error) {
	switch cmd.Kind {
	case protocol.CommandApplyRecipe:
		return a.applyRecipe(ctx, session, env, cmd.Args)
	case protocol.CommandDeploy:
		return a.prepareRelease(ctx, cmd.Args)
	case protocol.CommandActivateRelease:
		return a.activateRelease(ctx, cmd.Args)
	case protocol.CommandRollback:
		return a.rollback(ctx, cmd.Args)
	case protocol.CommandPruneReleases:
		return a.pruneReleases(ctx, cmd.Args)
	case protocol.CommandExec:
		return a.execCommand(ctx, cmd.Args)
	default:
		return nil, fmt.Errorf("agentcore: unsupported command %q", cmd.Kind)
	}
}

func (a *Agent) applyRecipe(ctx context.Context, session *transport.Session, env *protocol.Envelope, args json.RawMessage) (json.RawMessage, error) {
	var in commands.ApplyRecipe
	if err := json.Unmarshal(args, &in); err != nil {
		return nil, fmt.Errorf("agentcore: decode apply_recipe args: %w", err)
	}

	rec, err := in.Spec.Build(recipe.ExecRunner{})
	if err != nil {
		return nil, err
	}

	runner := recipe.NewRunner()
	runner.DryRun = in.DryRun

	// Stream each step as it completes so the dashboard shows progress rather
	// than a spinner followed by a wall of text.
	var seq int
	var seqMu sync.Mutex
	runner.OnStep = func(res recipe.StepResult) {
		seqMu.Lock()
		n := seq
		seq++
		seqMu.Unlock()

		text := fmt.Sprintf("%s: %s", res.Action, res.Description)
		if res.Error != "" {
			text = fmt.Sprintf("failed: %s: %s", res.Description, res.Error)
		}
		a.sendProgress(session, env, protocol.StreamStatus, n, text)
	}

	run, err := runner.Run(ctx, rec)
	if err != nil {
		return nil, err
	}
	if run.Failed {
		// Surface the failing step rather than a generic failure.
		for _, step := range run.Steps {
			if step.Error != "" {
				return nil, fmt.Errorf("recipe step %q failed: %s", step.ID, step.Error)
			}
		}
		return nil, errors.New("agentcore: recipe failed")
	}

	return json.Marshal(commands.ApplyRecipeResult{Run: run})
}

func (a *Agent) prepareRelease(ctx context.Context, args json.RawMessage) (json.RawMessage, error) {
	var in commands.PrepareRelease
	if err := json.Unmarshal(args, &in); err != nil {
		return nil, fmt.Errorf("agentcore: decode deploy args: %w", err)
	}

	mgr, err := a.siteManager(in.Site)
	if err != nil {
		return nil, err
	}

	shared := in.Shared
	if len(shared) == 0 {
		shared = deploy.DefaultLaravelShared
	}

	rel, err := mgr.Prepare(ctx, in.ReleaseID, shared)
	if err != nil {
		return nil, err
	}
	return json.Marshal(commands.PrepareReleaseResult{Release: rel})
}

func (a *Agent) activateRelease(ctx context.Context, args json.RawMessage) (json.RawMessage, error) {
	var in commands.ActivateRelease
	if err := json.Unmarshal(args, &in); err != nil {
		return nil, fmt.Errorf("agentcore: decode activate_release args: %w", err)
	}

	mgr, err := a.siteManager(in.Site)
	if err != nil {
		return nil, err
	}

	// Record what was live so the control plane can report the transition.
	previous, _ := mgr.Current(ctx)

	if err := mgr.Activate(ctx, in.ReleaseID); err != nil {
		return nil, err
	}
	current, err := mgr.Current(ctx)
	if err != nil {
		return nil, err
	}

	return json.Marshal(commands.ActivateReleaseResult{
		Site:     in.Site,
		Current:  current,
		Previous: previous,
	})
}

func (a *Agent) rollback(ctx context.Context, args json.RawMessage) (json.RawMessage, error) {
	var in commands.Rollback
	if err := json.Unmarshal(args, &in); err != nil {
		return nil, fmt.Errorf("agentcore: decode rollback args: %w", err)
	}

	mgr, err := a.siteManager(in.Site)
	if err != nil {
		return nil, err
	}
	target, err := mgr.Rollback(ctx)
	if err != nil {
		return nil, err
	}
	return json.Marshal(commands.RollbackResult{Site: in.Site, Current: target})
}

func (a *Agent) pruneReleases(ctx context.Context, args json.RawMessage) (json.RawMessage, error) {
	var in commands.PruneReleases
	if err := json.Unmarshal(args, &in); err != nil {
		return nil, fmt.Errorf("agentcore: decode prune_releases args: %w", err)
	}

	mgr, err := a.siteManager(in.Site)
	if err != nil {
		return nil, err
	}
	if in.Keep > 0 {
		mgr.Keep = in.Keep
	}
	deleted, err := mgr.Prune(ctx)
	if err != nil {
		return nil, err
	}
	if deleted == nil {
		deleted = []string{}
	}
	return json.Marshal(commands.PruneReleasesResult{Site: in.Site, Deleted: deleted})
}

func (a *Agent) execCommand(ctx context.Context, args json.RawMessage) (json.RawMessage, error) {
	var in commands.Exec
	if err := json.Unmarshal(args, &in); err != nil {
		return nil, fmt.Errorf("agentcore: decode exec args: %w", err)
	}

	if err := a.checkAllowed(in.Name); err != nil {
		return nil, err
	}

	dir, err := a.resolveExecDir(ctx, in)
	if err != nil {
		return nil, err
	}

	cmd := exec.CommandContext(ctx, in.Name, in.Args...)
	cmd.Dir = dir
	out, err := cmd.CombinedOutput()

	res := commands.ExecResult{Output: string(out)}
	var exitErr *exec.ExitError
	switch {
	case err == nil:
		res.ExitCode = 0
	case errors.As(err, &exitErr):
		res.ExitCode = exitErr.ExitCode()
	default:
		return nil, fmt.Errorf("agentcore: run %s: %w", in.Name, err)
	}

	if res.ExitCode != 0 {
		// Encode the detail anyway so the operator sees the output, then
		// report failure.
		detail, _ := json.Marshal(res)
		return detail, fmt.Errorf("agentcore: %s exited %d", in.Name, res.ExitCode)
	}
	return json.Marshal(res)
}

// checkAllowed enforces the exec allow list, when one is configured.
//
// An empty list means unrestricted, which is the default. See the comment on
// ErrCommandNotAllowed for why.
func (a *Agent) checkAllowed(name string) error {
	if name == "" {
		return fmt.Errorf("%w: empty command", ErrCommandNotAllowed)
	}

	if len(a.cfg.ExecAllowList) == 0 {
		// Unrestricted. Absolute paths are fine here: running
		// /opt/vendor/bin/tool on your own server is an ordinary thing to
		// want, and refusing it would be the restriction this default exists
		// to remove.
		return nil
	}

	// With a list configured, require a bare command name. Otherwise
	// "/tmp/evil/php" would satisfy a naive base-name comparison and defeat
	// the list the operator deliberately asked for.
	if strings.ContainsAny(name, `/\`) {
		return fmt.Errorf(
			"%w: %q must be a bare command name when an allow list is configured",
			ErrCommandNotAllowed, name,
		)
	}

	for _, allowed := range a.cfg.ExecAllowList {
		if name == allowed {
			return nil
		}
	}

	return fmt.Errorf("%w: %q", ErrCommandNotAllowed, name)
}

// resolveExecDir determines the working directory for an exec.
func (a *Agent) resolveExecDir(ctx context.Context, in commands.Exec) (string, error) {
	if in.Site == "" {
		return in.Dir, nil
	}

	mgr, err := a.siteManager(in.Site)
	if err != nil {
		return "", err
	}
	current, err := mgr.Current(ctx)
	if err != nil {
		return "", err
	}

	base := mgr.Layout.ReleasePath(current)
	if in.Dir == "" {
		return base, nil
	}

	// A relative Dir must stay inside the release.
	joined := filepath.Join(base, filepath.FromSlash(in.Dir))
	if !withinDir(base, joined) {
		return "", fmt.Errorf("agentcore: working directory %q escapes the release", in.Dir)
	}
	return joined, nil
}

// siteManager builds a deploy manager for a site, rejecting names that would
// escape the sites root.
func (a *Agent) siteManager(site string) (*deploy.Manager, error) {
	if site == "" {
		return nil, errors.New("agentcore: no site specified")
	}
	// Site names become path segments and arrive from the control plane. A
	// crafted one must not be able to reach outside the sites root.
	if strings.ContainsAny(site, `/\`) || site == "." || site == ".." {
		return nil, fmt.Errorf("agentcore: invalid site name %q", site)
	}

	root := filepath.Join(a.cfg.SitesRoot, site)
	if !withinDir(a.cfg.SitesRoot, root) {
		return nil, fmt.Errorf("agentcore: site %q escapes the sites root", site)
	}
	return deploy.NewManager(root), nil
}

// withinDir reports whether target is base or lies beneath it.
func withinDir(base, target string) bool {
	rel, err := filepath.Rel(base, target)
	if err != nil {
		return false
	}
	if rel == "." {
		return true
	}
	return rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))
}

// ---- transport helpers ----

func (a *Agent) send(session *transport.Session, req *protocol.Envelope, t protocol.Type, payload any) {
	env, err := req.Reply(t, payload)
	if err != nil {
		a.log.Error("build reply", "type", t, "error", err)
		return
	}
	if err := session.Send(env); err != nil && !errors.Is(err, transport.ErrSessionClosed) {
		a.log.Error("send reply", "type", t, "error", err)
	}
}

func (a *Agent) sendProgress(session *transport.Session, req *protocol.Envelope, stream protocol.ProgressStream, seq int, text string) {
	a.send(session, req, protocol.TypeProgress, protocol.Progress{Stream: stream, Seq: seq, Text: text})
}

func (a *Agent) replyError(session *transport.Session, req *protocol.Envelope, code protocol.ErrorCode, msg string) {
	a.send(session, req, protocol.TypeError, protocol.Error{Code: code, Message: msg})
}

// ---- idempotency ----

// idempotencyCacheSize bounds retained results. The control plane only
// replays commands that were in flight across a reconnect, so a small cache
// covers the real case without growing without bound.
const idempotencyCacheSize = 256

type idempotencyCache struct {
	mu      sync.Mutex
	results map[string]protocol.Result
	order   []string
}

func newIdempotencyCache() *idempotencyCache {
	return &idempotencyCache{results: make(map[string]protocol.Result)}
}

func (c *idempotencyCache) get(key string) (protocol.Result, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	res, ok := c.results[key]
	return res, ok
}

func (c *idempotencyCache) put(key string, res protocol.Result) {
	c.mu.Lock()
	defer c.mu.Unlock()

	if _, exists := c.results[key]; !exists {
		c.order = append(c.order, key)
	}
	c.results[key] = res

	for len(c.order) > idempotencyCacheSize {
		oldest := c.order[0]
		c.order = c.order[1:]
		delete(c.results, oldest)
	}
}
