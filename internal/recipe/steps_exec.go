package recipe

import (
	"context"
	"fmt"
	"os/exec"
	"strings"
)

// CommandRunner executes external commands.
//
// Everything that shells out goes through this interface so the exec-shaped
// steps are testable without a real apt or systemd, and so there is exactly
// one place to audit what the agent is allowed to run.
type CommandRunner interface {
	// Run executes name with args and returns combined output.
	Run(ctx context.Context, name string, args ...string) (output string, exitCode int, err error)
}

// ExecRunner is the production CommandRunner.
type ExecRunner struct{}

// Run implements CommandRunner.
func (ExecRunner) Run(ctx context.Context, name string, args ...string) (string, int, error) {
	cmd := exec.CommandContext(ctx, name, args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		var exitErr *exec.ExitError
		if ok := asExitError(err, &exitErr); ok {
			return string(out), exitErr.ExitCode(), nil
		}
		return string(out), -1, err
	}
	return string(out), 0, nil
}

func asExitError(err error, target **exec.ExitError) bool {
	if e, ok := err.(*exec.ExitError); ok {
		*target = e
		return true
	}
	return false
}

// StepCommand runs a command, optionally guarded by a check.
//
// A bare command is not idempotent and the engine cannot make it so. Supply
// Unless to give the step a way to decide it has nothing to do: the command
// runs only when the guard exits non-zero. Without a guard the step always
// reports ActionRun, which is honest rather than convenient.
type StepCommand struct {
	StepID string
	// Name and Args are the command to run.
	Name string
	Args []string
	// Unless is an optional guard command. When it exits zero, the step is a
	// no-op.
	Unless []string
	// Description overrides the generated summary.
	Description string
	// Runner defaults to ExecRunner.
	Runner CommandRunner
}

// ID implements Step.
func (s *StepCommand) ID() string { return s.StepID }

// Describe implements Step.
func (s *StepCommand) Describe() string {
	if s.Description != "" {
		return s.Description
	}
	return fmt.Sprintf("run %s %s", s.Name, strings.Join(s.Args, " "))
}

func (s *StepCommand) runner() CommandRunner {
	if s.Runner != nil {
		return s.Runner
	}
	return ExecRunner{}
}

// satisfied reports whether the guard says there is nothing to do.
func (s *StepCommand) satisfied(ctx context.Context) (bool, error) {
	if len(s.Unless) == 0 {
		return false, nil
	}
	_, code, err := s.runner().Run(ctx, s.Unless[0], s.Unless[1:]...)
	if err != nil {
		return false, fmt.Errorf("recipe: guard %q: %w", strings.Join(s.Unless, " "), err)
	}
	return code == 0, nil
}

// Plan implements Step.
func (s *StepCommand) Plan(ctx context.Context) (Action, error) {
	ok, err := s.satisfied(ctx)
	if err != nil {
		return "", err
	}
	if ok {
		return ActionNoop, nil
	}
	return ActionRun, nil
}

// Apply implements Step.
func (s *StepCommand) Apply(ctx context.Context) (Action, error) {
	ok, err := s.satisfied(ctx)
	if err != nil {
		return "", err
	}
	if ok {
		return ActionNoop, nil
	}

	out, code, err := s.runner().Run(ctx, s.Name, s.Args...)
	if err != nil {
		return "", fmt.Errorf("recipe: run %s: %w", s.Name, err)
	}
	if code != 0 {
		return "", fmt.Errorf("recipe: %s exited %d: %s", s.Name, code, strings.TrimSpace(out))
	}
	return ActionRun, nil
}

// StepPackage declares an apt package as installed.
//
// Only Debian and Ubuntu are supported, which matches the only base images
// the platform provisions. Adding a second package manager before there is a
// second distribution would be speculative.
type StepPackage struct {
	StepID string
	// Name is the package.
	Name string
	// Runner defaults to ExecRunner.
	Runner CommandRunner
}

// ID implements Step.
func (s *StepPackage) ID() string { return s.StepID }

// Describe implements Step.
func (s *StepPackage) Describe() string { return fmt.Sprintf("package %s installed", s.Name) }

func (s *StepPackage) runner() CommandRunner {
	if s.Runner != nil {
		return s.Runner
	}
	return ExecRunner{}
}

// installed reports whether dpkg considers the package installed.
func (s *StepPackage) installed(ctx context.Context) (bool, error) {
	out, code, err := s.runner().Run(ctx, "dpkg-query", "-W", "-f=${Status}", s.Name)
	if err != nil {
		return false, fmt.Errorf("recipe: query package %s: %w", s.Name, err)
	}
	if code != 0 {
		// dpkg-query exits non-zero for unknown packages, which is a normal
		// answer rather than a failure.
		return false, nil
	}
	// A package can be known to dpkg while removed, so match the full state.
	return strings.Contains(out, "install ok installed"), nil
}

// Plan implements Step.
func (s *StepPackage) Plan(ctx context.Context) (Action, error) {
	ok, err := s.installed(ctx)
	if err != nil {
		return "", err
	}
	if ok {
		return ActionNoop, nil
	}
	return ActionCreate, nil
}

// Apply implements Step.
func (s *StepPackage) Apply(ctx context.Context) (Action, error) {
	ok, err := s.installed(ctx)
	if err != nil {
		return "", err
	}
	if ok {
		return ActionNoop, nil
	}

	out, code, err := s.runner().Run(ctx, "apt-get", "install", "-y", "--no-install-recommends", s.Name)
	if err != nil {
		return "", fmt.Errorf("recipe: install %s: %w", s.Name, err)
	}
	if code != 0 {
		return "", fmt.Errorf("recipe: apt-get install %s exited %d: %s", s.Name, code, strings.TrimSpace(out))
	}
	return ActionCreate, nil
}

// ServiceState is the desired state of a systemd unit.
type ServiceState string

const (
	// ServiceRunning means started and enabled at boot.
	ServiceRunning ServiceState = "running"
	// ServiceStopped means stopped and disabled.
	ServiceStopped ServiceState = "stopped"
	// ServiceReloaded always issues a reload. Use it for config changes where
	// a restart would drop connections.
	ServiceReloaded ServiceState = "reloaded"
)

// StepService declares the state of a systemd unit.
type StepService struct {
	StepID string
	// Unit is the systemd unit name, e.g. "nginx".
	Unit string
	// State is the desired state.
	State ServiceState
	// Runner defaults to ExecRunner.
	Runner CommandRunner
}

// ID implements Step.
func (s *StepService) ID() string { return s.StepID }

// Describe implements Step.
func (s *StepService) Describe() string {
	return fmt.Sprintf("service %s %s", s.Unit, s.State)
}

func (s *StepService) runner() CommandRunner {
	if s.Runner != nil {
		return s.Runner
	}
	return ExecRunner{}
}

func (s *StepService) isActive(ctx context.Context) (bool, error) {
	_, code, err := s.runner().Run(ctx, "systemctl", "is-active", "--quiet", s.Unit)
	if err != nil {
		return false, fmt.Errorf("recipe: query service %s: %w", s.Unit, err)
	}
	return code == 0, nil
}

// Plan implements Step.
func (s *StepService) Plan(ctx context.Context) (Action, error) {
	// A reload is unconditional by design: the caller asked for it because
	// configuration changed, and there is no way to inspect whether the
	// running process has already picked that change up.
	if s.State == ServiceReloaded {
		return ActionRun, nil
	}

	active, err := s.isActive(ctx)
	if err != nil {
		return "", err
	}
	switch s.State {
	case ServiceRunning:
		if active {
			return ActionNoop, nil
		}
		return ActionUpdate, nil
	case ServiceStopped:
		if !active {
			return ActionNoop, nil
		}
		return ActionUpdate, nil
	default:
		return "", fmt.Errorf("recipe: unknown service state %q", s.State)
	}
}

// Apply implements Step.
func (s *StepService) Apply(ctx context.Context) (Action, error) {
	action, err := s.Plan(ctx)
	if err != nil {
		return "", err
	}
	if action == ActionNoop {
		return ActionNoop, nil
	}

	var args []string
	switch s.State {
	case ServiceRunning:
		args = []string{"enable", "--now", s.Unit}
	case ServiceStopped:
		args = []string{"disable", "--now", s.Unit}
	case ServiceReloaded:
		args = []string{"reload", s.Unit}
	}

	out, code, err := s.runner().Run(ctx, "systemctl", args...)
	if err != nil {
		return "", fmt.Errorf("recipe: systemctl %s: %w", s.Unit, err)
	}
	if code != 0 {
		return "", fmt.Errorf("recipe: systemctl %s exited %d: %s", strings.Join(args, " "), code, strings.TrimSpace(out))
	}
	return action, nil
}
