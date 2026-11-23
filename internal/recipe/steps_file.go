package recipe

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
)

// File declares a file with specific contents and permissions.
//
// Writes go through a temporary file in the same directory followed by a
// rename, so a reader never observes a partially written config. An nginx
// reload racing a config rewrite is exactly the kind of thing that only shows
// up under load.
type StepFile struct {
	// StepID is the identifier within the recipe.
	StepID string
	// Path is the destination.
	Path string
	// Content is the desired file body.
	Content []byte
	// Mode is the desired permission bits. Zero means 0644.
	Mode fs.FileMode
	// Sensitive suppresses content from logs and diffs. Set it for anything
	// carrying credentials, such as a rendered .env.
	Sensitive bool
	// Owner and Base make this an owned step: the file belongs to Owner, and
	// every operation stays inside Base. Set both for any path the application
	// can write; see owned_unix.go.
	Owner string
	Base  string
}

// ID implements Step.
func (s *StepFile) ID() string { return s.StepID }

// Describe implements Step.
func (s *StepFile) Describe() string {
	if s.Sensitive {
		return fmt.Sprintf("file %s (%d bytes, contents redacted)", s.Path, len(s.Content))
	}
	return fmt.Sprintf("file %s (%d bytes)", s.Path, len(s.Content))
}

func (s *StepFile) mode() fs.FileMode {
	if s.Mode == 0 {
		return 0o644
	}
	return s.Mode
}

// Plan implements Step.
func (s *StepFile) Plan(context.Context) (Action, error) {
	if s.Owner != "" {
		c, err := openConfined(s.Base, s.Path, s.Owner)
		if err != nil {
			return "", err
		}
		defer c.close()

		return c.planFile(s.Content, s.mode())
	}

	info, err := os.Stat(s.Path)
	if errors.Is(err, fs.ErrNotExist) {
		return ActionCreate, nil
	}
	if err != nil {
		return "", fmt.Errorf("recipe: stat %s: %w", s.Path, err)
	}
	if info.IsDir() {
		return "", fmt.Errorf("recipe: %s is a directory, expected a file", s.Path)
	}

	current, err := os.ReadFile(s.Path)
	if err != nil {
		return "", fmt.Errorf("recipe: read %s: %w", s.Path, err)
	}
	if !bytes.Equal(current, s.Content) {
		return ActionUpdate, nil
	}
	if modeDiffers(info.Mode(), s.mode()) {
		return ActionUpdate, nil
	}
	return ActionNoop, nil
}

// Apply implements Step.
func (s *StepFile) Apply(ctx context.Context) (Action, error) {
	action, err := s.Plan(ctx)
	if err != nil {
		return "", err
	}
	if action == ActionNoop {
		return ActionNoop, nil
	}

	if s.Owner != "" {
		c, err := openConfined(s.Base, s.Path, s.Owner)
		if err != nil {
			return "", err
		}
		defer c.close()
		if err := c.writeFile(s.Content, s.mode()); err != nil {
			return "", err
		}

		return action, nil
	}

	dir := filepath.Dir(s.Path)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", fmt.Errorf("recipe: create %s: %w", dir, err)
	}

	tmp, err := os.CreateTemp(dir, ".tmp-*")
	if err != nil {
		return "", fmt.Errorf("recipe: create temp in %s: %w", dir, err)
	}
	tmpName := tmp.Name()
	// Best-effort cleanup if anything below fails before the rename.
	defer func() { _ = os.Remove(tmpName) }()

	if _, err := tmp.Write(s.Content); err != nil {
		tmp.Close()
		return "", fmt.Errorf("recipe: write %s: %w", tmpName, err)
	}
	// Flush to disk before the rename. Without this a crash between rename
	// and writeback can leave a correctly named, empty config file.
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return "", fmt.Errorf("recipe: sync %s: %w", tmpName, err)
	}
	if err := tmp.Close(); err != nil {
		return "", fmt.Errorf("recipe: close %s: %w", tmpName, err)
	}
	// Set the mode before the rename so the file is never briefly readable at
	// the default mode. This matters for secrets.
	if err := os.Chmod(tmpName, s.mode()); err != nil {
		return "", fmt.Errorf("recipe: chmod %s: %w", tmpName, err)
	}
	if err := os.Rename(tmpName, s.Path); err != nil {
		return "", fmt.Errorf("recipe: rename into %s: %w", s.Path, err)
	}

	return action, nil
}

// StepDir declares a directory.
type StepDir struct {
	StepID string
	Path   string
	// Mode is the desired permission bits. Zero means 0755.
	Mode fs.FileMode
	// Owner and Base make this an owned step, as for StepFile.
	Owner string
	Base  string
}

// ID implements Step.
func (s *StepDir) ID() string { return s.StepID }

// Describe implements Step.
func (s *StepDir) Describe() string { return fmt.Sprintf("directory %s", s.Path) }

func (s *StepDir) mode() fs.FileMode {
	if s.Mode == 0 {
		return 0o755
	}
	return s.Mode
}

// Plan implements Step.
func (s *StepDir) Plan(context.Context) (Action, error) {
	if s.Owner != "" {
		c, err := openConfined(s.Base, s.Path, s.Owner)
		if err != nil {
			return "", err
		}
		defer c.close()

		return c.planDir(s.mode())
	}

	info, err := os.Stat(s.Path)
	if errors.Is(err, fs.ErrNotExist) {
		return ActionCreate, nil
	}
	if err != nil {
		return "", fmt.Errorf("recipe: stat %s: %w", s.Path, err)
	}
	if !info.IsDir() {
		return "", fmt.Errorf("recipe: %s exists and is not a directory", s.Path)
	}
	if modeDiffers(info.Mode().Perm(), s.mode()) {
		return ActionUpdate, nil
	}
	return ActionNoop, nil
}

// Apply implements Step.
func (s *StepDir) Apply(ctx context.Context) (Action, error) {
	action, err := s.Plan(ctx)
	if err != nil {
		return "", err
	}
	if action == ActionNoop {
		return ActionNoop, nil
	}
	if s.Owner != "" {
		c, err := openConfined(s.Base, s.Path, s.Owner)
		if err != nil {
			return "", err
		}
		defer c.close()
		if err := c.makeDir(s.mode()); err != nil {
			return "", err
		}

		return action, nil
	}
	if err := os.MkdirAll(s.Path, s.mode()); err != nil {
		return "", fmt.Errorf("recipe: create %s: %w", s.Path, err)
	}
	// MkdirAll honours umask, so set the mode explicitly afterwards.
	if err := os.Chmod(s.Path, s.mode()); err != nil {
		return "", fmt.Errorf("recipe: chmod %s: %w", s.Path, err)
	}
	return action, nil
}

// StepSymlink declares a symbolic link.
//
// This is the primitive behind atomic release activation, so replacing an
// existing link must not go through a window where the path does not exist.
// The link is created under a temporary name and renamed over the target,
// which is atomic on POSIX.
type StepSymlink struct {
	StepID string
	// Path is the link itself.
	Path string
	// Target is what the link points at.
	Target string
}

// ID implements Step.
func (s *StepSymlink) ID() string { return s.StepID }

// Describe implements Step.
func (s *StepSymlink) Describe() string {
	return fmt.Sprintf("symlink %s -> %s", s.Path, s.Target)
}

// Plan implements Step.
func (s *StepSymlink) Plan(context.Context) (Action, error) {
	current, err := os.Readlink(s.Path)
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return ActionCreate, nil
		}
		// A non-symlink at the path is a conflict we refuse to resolve
		// silently, because clobbering a real directory here would destroy
		// an application.
		if info, statErr := os.Lstat(s.Path); statErr == nil && info.Mode()&fs.ModeSymlink == 0 {
			return "", fmt.Errorf("recipe: %s exists and is not a symlink", s.Path)
		}
		return "", fmt.Errorf("recipe: readlink %s: %w", s.Path, err)
	}
	if current == s.Target {
		return ActionNoop, nil
	}
	return ActionUpdate, nil
}

// Apply implements Step.
func (s *StepSymlink) Apply(ctx context.Context) (Action, error) {
	action, err := s.Plan(ctx)
	if err != nil {
		return "", err
	}
	if action == ActionNoop {
		return ActionNoop, nil
	}

	dir := filepath.Dir(s.Path)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", fmt.Errorf("recipe: create %s: %w", dir, err)
	}

	tmpName := filepath.Join(dir, fmt.Sprintf(".link-%s.tmp", filepath.Base(s.Path)))
	// A leftover from an interrupted run would make Symlink fail with EEXIST.
	_ = os.Remove(tmpName)

	if err := os.Symlink(s.Target, tmpName); err != nil {
		return "", fmt.Errorf("recipe: symlink %s: %w", tmpName, err)
	}
	if err := os.Rename(tmpName, s.Path); err != nil {
		_ = os.Remove(tmpName)
		return "", fmt.Errorf("recipe: rename link into %s: %w", s.Path, err)
	}
	return action, nil
}

// StepAbsent declares that a path must not exist.
type StepAbsent struct {
	StepID string
	Path   string
	// Owner and Base make this an owned step, as for StepFile: the removal
	// stays inside Base.
	Owner string
	Base  string
}

// ID implements Step.
func (s *StepAbsent) ID() string { return s.StepID }

// Describe implements Step.
func (s *StepAbsent) Describe() string { return fmt.Sprintf("absent %s", s.Path) }

// Plan implements Step.
func (s *StepAbsent) Plan(context.Context) (Action, error) {
	if s.Owner != "" {
		c, err := openConfined(s.Base, s.Path, s.Owner)
		if err != nil {
			return "", err
		}
		defer c.close()

		return c.planAbsent()
	}

	if _, err := os.Lstat(s.Path); err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return ActionNoop, nil
		}
		return "", fmt.Errorf("recipe: stat %s: %w", s.Path, err)
	}
	return ActionDelete, nil
}

// Apply implements Step.
func (s *StepAbsent) Apply(ctx context.Context) (Action, error) {
	action, err := s.Plan(ctx)
	if err != nil {
		return "", err
	}
	if action == ActionNoop {
		return ActionNoop, nil
	}
	if s.Owner != "" {
		c, err := openConfined(s.Base, s.Path, s.Owner)
		if err != nil {
			return "", err
		}
		defer c.close()
		if err := c.remove(); err != nil {
			return "", err
		}

		return ActionDelete, nil
	}
	if err := os.RemoveAll(s.Path); err != nil {
		return "", fmt.Errorf("recipe: remove %s: %w", s.Path, err)
	}
	return ActionDelete, nil
}
