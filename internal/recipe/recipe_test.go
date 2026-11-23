package recipe

import (
	"context"
	"errors"
	"fmt"
	"testing"
)

// fakeStep is a scriptable Step for exercising the Runner.
type fakeStep struct {
	id      string
	action  Action
	err     error
	applies int
	plans   int
	onApply func()
}

func (f *fakeStep) ID() string       { return f.id }
func (f *fakeStep) Describe() string { return "fake " + f.id }

func (f *fakeStep) Plan(context.Context) (Action, error) {
	f.plans++
	if f.err != nil {
		return "", f.err
	}
	return f.action, nil
}

func (f *fakeStep) Apply(context.Context) (Action, error) {
	f.applies++
	if f.onApply != nil {
		f.onApply()
	}
	if f.err != nil {
		return "", f.err
	}
	return f.action, nil
}

func TestRunnerAppliesStepsInOrder(t *testing.T) {
	var order []string
	mk := func(id string, action Action) *fakeStep {
		return &fakeStep{id: id, action: action, onApply: func() { order = append(order, id) }}
	}

	rec := Recipe{Name: "test", Steps: []Step{
		mk("a", ActionCreate),
		mk("b", ActionNoop),
		mk("c", ActionUpdate),
	}}

	res, err := NewRunner().Run(context.Background(), rec)
	if err != nil {
		t.Fatalf("Run: %v", err)
	}

	if got := fmt.Sprint(order); got != "[a b c]" {
		t.Errorf("order = %s, want [a b c]", got)
	}
	if len(res.Steps) != 3 {
		t.Fatalf("len(Steps) = %d, want 3", len(res.Steps))
	}
	if res.Changed != 2 {
		t.Errorf("Changed = %d, want 2", res.Changed)
	}
	if res.Failed {
		t.Error("Failed = true, want false")
	}
}

// Configuration steps have ordering dependencies, so a failure must stop the
// run rather than leave a half-configured node reporting partial success.
func TestRunnerStopsAtFirstFailure(t *testing.T) {
	a := &fakeStep{id: "a", action: ActionCreate}
	b := &fakeStep{id: "b", err: errors.New("boom")}
	c := &fakeStep{id: "c", action: ActionCreate}

	res, err := NewRunner().Run(context.Background(), Recipe{Name: "test", Steps: []Step{a, b, c}})
	if err != nil {
		t.Fatalf("Run: %v", err)
	}

	if !res.Failed {
		t.Error("Failed = false, want true")
	}
	if len(res.Steps) != 2 {
		t.Fatalf("len(Steps) = %d, want 2 (should stop after the failure)", len(res.Steps))
	}
	if res.Steps[1].Error == "" {
		t.Error("failing step recorded no error")
	}
	if c.applies != 0 {
		t.Errorf("step after the failure ran %d times, want 0", c.applies)
	}
	// A failed step must not be counted as a change.
	if res.Changed != 1 {
		t.Errorf("Changed = %d, want 1", res.Changed)
	}
}

// Dry run must plan without applying. This is the whole basis for showing a
// customer what a recipe would do to their server.
func TestDryRunPlansWithoutApplying(t *testing.T) {
	a := &fakeStep{id: "a", action: ActionCreate}
	b := &fakeStep{id: "b", action: ActionNoop}

	r := NewRunner()
	r.DryRun = true

	res, err := r.Run(context.Background(), Recipe{Name: "test", Steps: []Step{a, b}})
	if err != nil {
		t.Fatalf("Run: %v", err)
	}

	if !res.DryRun {
		t.Error("DryRun = false in result")
	}
	if a.applies != 0 || b.applies != 0 {
		t.Errorf("applies = %d/%d, want 0/0 in dry run", a.applies, b.applies)
	}
	if a.plans != 1 || b.plans != 1 {
		t.Errorf("plans = %d/%d, want 1/1", a.plans, b.plans)
	}
	if res.Changed != 1 {
		t.Errorf("Changed = %d, want 1 (planned changes are still counted)", res.Changed)
	}
}

func TestOnStepCallback(t *testing.T) {
	var seen []string
	r := NewRunner()
	r.OnStep = func(res StepResult) { seen = append(seen, res.ID) }

	rec := Recipe{Name: "test", Steps: []Step{
		&fakeStep{id: "a", action: ActionCreate},
		&fakeStep{id: "b", action: ActionNoop},
	}}
	if _, err := r.Run(context.Background(), rec); err != nil {
		t.Fatalf("Run: %v", err)
	}

	if got := fmt.Sprint(seen); got != "[a b]" {
		t.Errorf("callbacks = %s, want [a b]", got)
	}
}

// The callback must fire for a failing step too, or the deploy log goes quiet
// at exactly the moment the operator needs output.
func TestOnStepFiresForFailures(t *testing.T) {
	var results []StepResult
	r := NewRunner()
	r.OnStep = func(res StepResult) { results = append(results, res) }

	rec := Recipe{Name: "test", Steps: []Step{
		&fakeStep{id: "a", action: ActionCreate},
		&fakeStep{id: "b", err: errors.New("boom")},
	}}
	if _, err := r.Run(context.Background(), rec); err != nil {
		t.Fatalf("Run: %v", err)
	}

	if len(results) != 2 {
		t.Fatalf("len(results) = %d, want 2", len(results))
	}
	if results[1].Error == "" {
		t.Error("failure callback carried no error")
	}
	if results[1].Changed {
		t.Error("failed step reported Changed = true")
	}
}

func TestDuplicateStepIDRejected(t *testing.T) {
	rec := Recipe{Name: "test", Steps: []Step{
		&fakeStep{id: "a", action: ActionNoop},
		&fakeStep{id: "a", action: ActionNoop},
	}}
	if _, err := NewRunner().Run(context.Background(), rec); !errors.Is(err, ErrDuplicateStepID) {
		t.Fatalf("err = %v, want ErrDuplicateStepID", err)
	}
}

func TestEmptyStepIDRejected(t *testing.T) {
	rec := Recipe{Name: "test", Steps: []Step{&fakeStep{id: "", action: ActionNoop}}}
	if _, err := NewRunner().Run(context.Background(), rec); err == nil {
		t.Fatal("expected error for an empty step id")
	}
}

// Cancellation must stop the run promptly even if a step implementation does
// not check the context itself.
func TestCancellationStopsRun(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())

	a := &fakeStep{id: "a", action: ActionCreate, onApply: cancel}
	b := &fakeStep{id: "b", action: ActionCreate}

	res, err := NewRunner().Run(ctx, Recipe{Name: "test", Steps: []Step{a, b}})
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v, want context.Canceled", err)
	}
	if b.applies != 0 {
		t.Errorf("step after cancellation ran %d times, want 0", b.applies)
	}
	if res == nil || !res.Failed {
		t.Error("result should be marked failed on cancellation")
	}
}

func TestEmptyRecipe(t *testing.T) {
	res, err := NewRunner().Run(context.Background(), Recipe{Name: "empty"})
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if len(res.Steps) != 0 || res.Changed != 0 || res.Failed {
		t.Errorf("unexpected result for an empty recipe: %+v", res)
	}
}

func TestActionChanged(t *testing.T) {
	cases := map[Action]bool{
		ActionNoop:   false,
		ActionCreate: true,
		ActionUpdate: true,
		ActionDelete: true,
		ActionRun:    true,
	}
	for action, want := range cases {
		if got := action.Changed(); got != want {
			t.Errorf("%s.Changed() = %v, want %v", action, got, want)
		}
	}
}
