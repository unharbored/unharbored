// Package recipe is the agent's configuration engine.
//
// A recipe is an ordered list of idempotent steps describing desired state:
// this file with these contents, this directory, this package installed, this
// service running. Running a recipe twice produces no changes the second
// time, which is what makes drift correction a safe thing to run on a
// schedule rather than a dangerous thing to run once.
//
// Steps are ordered and the run stops at the first failure. Configuration has
// real dependencies, and continuing past a failed step produces a half
// configured node that reports partial success, which is worse than an
// obvious failure.
//
// Every step reports whether it actually changed anything. That distinction
// drives the "nothing drifted" signal the control plane shows, and it is the
// reason Plan and Apply are separate: Plan answers the question without
// touching the node.
package recipe

import (
	"context"
	"errors"
	"fmt"
	"time"
)

// Action describes what a step did, or would do.
type Action string

const (
	// ActionNoop means the desired state was already in place.
	ActionNoop Action = "noop"
	// ActionCreate means the resource did not exist and was created.
	ActionCreate Action = "create"
	// ActionUpdate means the resource existed but differed.
	ActionUpdate Action = "update"
	// ActionDelete means the resource was removed.
	ActionDelete Action = "delete"
	// ActionRun means a command was executed. Commands report ActionRun even
	// when their effect is unknown, because the engine cannot tell whether an
	// arbitrary command changed anything.
	ActionRun Action = "run"
)

// Changed reports whether an action represents a modification.
func (a Action) Changed() bool { return a != ActionNoop }

// Step is one unit of desired state.
//
// Implementations must be idempotent: calling Apply when the system already
// matches must return ActionNoop and change nothing.
type Step interface {
	// ID is a stable identifier, unique within a recipe.
	ID() string
	// Describe is a human-readable summary for the deploy log.
	Describe() string
	// Plan reports the action Apply would take, without taking it.
	Plan(ctx context.Context) (Action, error)
	// Apply converges the system toward the desired state.
	Apply(ctx context.Context) (Action, error)
}

// Recipe is an ordered set of steps.
type Recipe struct {
	// Name identifies the recipe in logs.
	Name string
	// Steps run in order.
	Steps []Step
}

// StepResult records the outcome of one step.
type StepResult struct {
	ID          string `json:"id"`
	Description string `json:"description"`
	Action      Action `json:"action"`
	Changed     bool   `json:"changed"`
	// Error is non-empty when the step failed.
	Error      string `json:"error,omitempty"`
	DurationMS int64  `json:"duration_ms"`
}

// RunResult summarises a recipe run.
type RunResult struct {
	Recipe string       `json:"recipe"`
	DryRun bool         `json:"dry_run"`
	Steps  []StepResult `json:"steps"`
	// Changed counts steps that modified the system.
	Changed int `json:"changed"`
	// Failed is true when a step errored and the run stopped.
	Failed     bool  `json:"failed"`
	DurationMS int64 `json:"duration_ms"`
}

// ErrDuplicateStepID indicates two steps share an ID, which would make the
// run log ambiguous.
var ErrDuplicateStepID = errors.New("recipe: duplicate step id")

// Runner executes recipes.
type Runner struct {
	// DryRun plans every step without applying any of them.
	DryRun bool
	// OnStep, if set, is called as each step completes. It is how deploy log
	// streaming gets incremental output.
	OnStep func(StepResult)
	// now is injectable for tests.
	now func() time.Time
}

// NewRunner returns a Runner.
func NewRunner() *Runner { return &Runner{now: time.Now} }

// Run executes a recipe, stopping at the first failure.
func (r *Runner) Run(ctx context.Context, rec Recipe) (*RunResult, error) {
	if err := validate(rec); err != nil {
		return nil, err
	}

	now := r.now
	if now == nil {
		now = time.Now
	}

	start := now()
	out := &RunResult{
		Recipe: rec.Name,
		DryRun: r.DryRun,
		Steps:  make([]StepResult, 0, len(rec.Steps)),
	}

	for _, step := range rec.Steps {
		// Check cancellation between steps rather than relying on every step
		// to honour the context, so a cancelled run stops promptly even if a
		// step implementation is careless.
		if err := ctx.Err(); err != nil {
			out.Failed = true
			out.DurationMS = now().Sub(start).Milliseconds()
			return out, err
		}

		res := r.runStep(ctx, step, now)
		out.Steps = append(out.Steps, res)
		if r.OnStep != nil {
			r.OnStep(res)
		}

		if res.Error != "" {
			out.Failed = true
			break
		}
		if res.Changed {
			out.Changed++
		}
	}

	out.DurationMS = now().Sub(start).Milliseconds()
	return out, nil
}

func (r *Runner) runStep(ctx context.Context, step Step, now func() time.Time) StepResult {
	start := now()
	res := StepResult{ID: step.ID(), Description: step.Describe()}

	var (
		action Action
		err    error
	)
	if r.DryRun {
		action, err = step.Plan(ctx)
	} else {
		action, err = step.Apply(ctx)
	}

	res.DurationMS = now().Sub(start).Milliseconds()
	if err != nil {
		res.Error = err.Error()
		return res
	}
	res.Action = action
	res.Changed = action.Changed()
	return res
}

func validate(rec Recipe) error {
	seen := make(map[string]bool, len(rec.Steps))
	for i, step := range rec.Steps {
		id := step.ID()
		if id == "" {
			return fmt.Errorf("recipe %q: step %d has no id", rec.Name, i)
		}
		if seen[id] {
			return fmt.Errorf("%w: %q in recipe %q", ErrDuplicateStepID, id, rec.Name)
		}
		seen[id] = true
	}
	return nil
}
