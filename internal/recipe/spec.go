package recipe

import (
	"encoding/base64"
	"errors"
	"fmt"
	"io/fs"
)

// Spec is the serialisable form of a Recipe.
//
// The control plane composes recipes and sends them over the agent
// connection, so the wire format is the real interface here. Steps are a
// tagged union rather than a Go interface because the control plane is a
// separate codebase in another language and needs a format it can emit
// without reimplementing this package.
//
// Only the step types named below can ever be constructed from a Spec. That
// is the point: an agent running as root must not accept an open-ended
// instruction set, and a new capability should require shipping a new agent
// rather than sending a novel payload.
type Spec struct {
	Name  string     `json:"name"`
	Steps []StepSpec `json:"steps"`
}

// StepType names a step implementation.
type StepType string

const (
	TypeFile    StepType = "file"
	TypeDir     StepType = "dir"
	TypeSymlink StepType = "symlink"
	TypeAbsent  StepType = "absent"
	TypeCommand StepType = "command"
	TypePackage StepType = "package"
	TypeService StepType = "service"
)

// StepSpec is one step in wire form.
type StepSpec struct {
	ID   string   `json:"id"`
	Type StepType `json:"type"`

	// Path is used by file, dir, symlink and absent.
	Path string `json:"path,omitempty"`

	// ContentB64 is the file body, base64 encoded so arbitrary bytes survive
	// JSON without escaping games.
	ContentB64 string `json:"content_b64,omitempty"`
	// Mode is the octal permission string, e.g. "0640". Empty means the step
	// default.
	Mode string `json:"mode,omitempty"`
	// Sensitive suppresses the content from logs. Set it for anything
	// carrying credentials.
	Sensitive bool `json:"sensitive,omitempty"`

	// Owner and Base mark a path the application can write (file, dir and
	// absent). The step then belongs to Owner and stays inside Base, a
	// directory the application cannot move; see owned_unix.go.
	Owner string `json:"owner,omitempty"`
	Base  string `json:"base,omitempty"`

	// Target is the symlink destination.
	Target string `json:"target,omitempty"`

	// Name and Args describe a command; Name is also the package or unit
	// name for package and service steps.
	Name string   `json:"name,omitempty"`
	Args []string `json:"args,omitempty"`
	// Unless is a guard command that makes a command step idempotent.
	Unless []string `json:"unless,omitempty"`
	// Description overrides the generated summary.
	Description string `json:"description,omitempty"`

	// State is the desired service state.
	State ServiceState `json:"state,omitempty"`
}

// ErrUnknownStepType is returned for a step type the agent does not
// implement. It is deliberately not a passthrough to a shell.
var ErrUnknownStepType = errors.New("recipe: unknown step type")

// Build converts a Spec into an executable Recipe.
//
// runner is injected into every exec-shaped step so the whole recipe can be
// tested without touching the host.
func (s Spec) Build(runner CommandRunner) (Recipe, error) {
	out := Recipe{Name: s.Name, Steps: make([]Step, 0, len(s.Steps))}

	for i, spec := range s.Steps {
		step, err := spec.build(runner)
		if err != nil {
			return Recipe{}, fmt.Errorf("recipe %q step %d (%s): %w", s.Name, i, spec.ID, err)
		}
		out.Steps = append(out.Steps, step)
	}
	return out, nil
}

func (spec StepSpec) build(runner CommandRunner) (Step, error) {
	if spec.ID == "" {
		return nil, errors.New("step has no id")
	}

	switch spec.Type {
	case TypeFile:
		if spec.Path == "" {
			return nil, errors.New("file step has no path")
		}
		content, err := decodeContent(spec.ContentB64)
		if err != nil {
			return nil, err
		}
		mode, err := parseMode(spec.Mode)
		if err != nil {
			return nil, err
		}
		return &StepFile{
			StepID:    spec.ID,
			Path:      spec.Path,
			Content:   content,
			Mode:      mode,
			Sensitive: spec.Sensitive,
			Owner:     spec.Owner,
			Base:      spec.Base,
		}, nil

	case TypeDir:
		if spec.Path == "" {
			return nil, errors.New("dir step has no path")
		}
		mode, err := parseMode(spec.Mode)
		if err != nil {
			return nil, err
		}
		return &StepDir{StepID: spec.ID, Path: spec.Path, Mode: mode, Owner: spec.Owner, Base: spec.Base}, nil

	case TypeSymlink:
		if spec.Path == "" || spec.Target == "" {
			return nil, errors.New("symlink step needs both path and target")
		}
		return &StepSymlink{StepID: spec.ID, Path: spec.Path, Target: spec.Target}, nil

	case TypeAbsent:
		if spec.Path == "" {
			return nil, errors.New("absent step has no path")
		}
		return &StepAbsent{StepID: spec.ID, Path: spec.Path, Owner: spec.Owner, Base: spec.Base}, nil

	case TypeCommand:
		if spec.Name == "" {
			return nil, errors.New("command step has no name")
		}
		return &StepCommand{
			StepID:      spec.ID,
			Name:        spec.Name,
			Args:        spec.Args,
			Unless:      spec.Unless,
			Description: spec.Description,
			Runner:      runner,
		}, nil

	case TypePackage:
		if spec.Name == "" {
			return nil, errors.New("package step has no name")
		}
		return &StepPackage{StepID: spec.ID, Name: spec.Name, Runner: runner}, nil

	case TypeService:
		if spec.Name == "" {
			return nil, errors.New("service step has no unit name")
		}
		switch spec.State {
		case ServiceRunning, ServiceStopped, ServiceReloaded:
		default:
			return nil, fmt.Errorf("invalid service state %q", spec.State)
		}
		return &StepService{StepID: spec.ID, Unit: spec.Name, State: spec.State, Runner: runner}, nil

	default:
		return nil, fmt.Errorf("%w: %q", ErrUnknownStepType, spec.Type)
	}
}

func decodeContent(b64 string) ([]byte, error) {
	if b64 == "" {
		return nil, nil
	}
	content, err := base64.StdEncoding.DecodeString(b64)
	if err != nil {
		return nil, fmt.Errorf("decode content: %w", err)
	}
	return content, nil
}

// parseMode reads an octal permission string such as "0640".
func parseMode(s string) (fs.FileMode, error) {
	if s == "" {
		return 0, nil
	}
	var mode uint32
	for _, c := range s {
		if c < '0' || c > '7' {
			return 0, fmt.Errorf("invalid octal mode %q", s)
		}
		mode = mode*8 + uint32(c-'0')
	}
	// Permission bits only. A mode carrying setuid or type bits is either a
	// mistake or an attempt to create something privileged.
	if mode > 0o777 {
		return 0, fmt.Errorf("mode %q exceeds permission bits", s)
	}
	return fs.FileMode(mode), nil
}

// EncodeContent is a helper for building a Spec.
func EncodeContent(b []byte) string {
	return base64.StdEncoding.EncodeToString(b)
}
