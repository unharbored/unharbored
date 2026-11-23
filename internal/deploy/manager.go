package deploy

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"time"
)

// Sentinel errors.
var (
	ErrInvalidReleaseID  = errors.New("deploy: invalid release id")
	ErrInvalidSharedPath = errors.New("deploy: invalid shared path")
	ErrReleaseNotFound   = errors.New("deploy: release not found")
	ErrNoCurrentRelease  = errors.New("deploy: no current release")
	ErrNothingToRollBack = errors.New("deploy: no previous release to roll back to")
)

// DefaultKeep is how many releases are retained after a prune.
//
// Five is enough to roll back through a bad afternoon without letting a busy
// repository fill the disk, which is the failure mode that takes a site down
// at the least convenient moment.
const DefaultKeep = 5

// Release is one unpacked release directory.
type Release struct {
	ID        string    `json:"id"`
	Path      string    `json:"path"`
	CreatedAt time.Time `json:"created_at"`
}

// activation records that a release became current.
type activation struct {
	ReleaseID   string    `json:"release_id"`
	ActivatedAt time.Time `json:"activated_at"`
	// RolledBack marks an activation that undid a previous one, so the
	// history reads correctly rather than looking like a forward deploy.
	RolledBack bool `json:"rolled_back,omitempty"`
}

// Manager operates on one site's release tree.
type Manager struct {
	Layout Layout
	// Keep is the release retention count. Zero means DefaultKeep.
	Keep int
	// now is injectable for tests.
	now func() time.Time
}

// NewManager returns a Manager for a site root.
func NewManager(root string) *Manager {
	return &Manager{Layout: Layout{Root: root}, Keep: DefaultKeep, now: time.Now}
}

// WithClock overrides the time source, for tests.
func (m *Manager) WithClock(now func() time.Time) *Manager {
	m.now = now
	return m
}

func (m *Manager) clock() time.Time {
	if m.now == nil {
		return time.Now()
	}
	return m.now()
}

func (m *Manager) keep() int {
	if m.Keep <= 0 {
		return DefaultKeep
	}
	return m.Keep
}

// Init creates the directory skeleton. It is idempotent.
func (m *Manager) Init(context.Context) error {
	for _, dir := range []string{m.Layout.Root, m.Layout.ReleasesDir(), m.Layout.SharedDir()} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			return fmt.Errorf("deploy: create %s: %w", dir, err)
		}
	}
	return nil
}

// Prepare creates a release directory and links its shared paths.
//
// It does not make the release live. Activate does that, separately, so a
// multi-node deploy can prepare everywhere before switching anywhere.
func (m *Manager) Prepare(ctx context.Context, id string, shared []SharedPath) (*Release, error) {
	if err := ValidateReleaseID(id); err != nil {
		return nil, err
	}
	if err := m.Init(ctx); err != nil {
		return nil, err
	}

	path := m.Layout.ReleasePath(id)
	if err := os.MkdirAll(path, 0o755); err != nil {
		return nil, fmt.Errorf("deploy: create release %s: %w", id, err)
	}

	if err := m.linkShared(path, shared); err != nil {
		return nil, err
	}

	info, err := os.Stat(path)
	if err != nil {
		return nil, fmt.Errorf("deploy: stat release %s: %w", id, err)
	}

	return &Release{ID: id, Path: path, CreatedAt: info.ModTime()}, nil
}

// linkShared replaces each shared path inside the release with a symlink into
// the shared directory, creating the shared entry if it does not exist.
func (m *Manager) linkShared(releasePath string, shared []SharedPath) error {
	for _, sp := range shared {
		if err := validateRel(sp.Rel); err != nil {
			return err
		}

		if err := validateRel(sp.Target()); err != nil {
			return err
		}

		target := filepath.Join(m.Layout.SharedDir(), filepath.FromSlash(sp.Target()))
		link := filepath.Join(releasePath, filepath.FromSlash(sp.Rel))

		// Create the shared entry on first deploy so the link is never
		// dangling.
		if _, err := os.Lstat(target); errors.Is(err, fs.ErrNotExist) {
			switch sp.Kind {
			case SharedDir:
				if err := os.MkdirAll(target, 0o755); err != nil {
					return fmt.Errorf("deploy: create shared dir %s: %w", target, err)
				}
			default:
				if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
					return fmt.Errorf("deploy: create shared parent for %s: %w", target, err)
				}
				f, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY, 0o640)
				if err != nil {
					return fmt.Errorf("deploy: create shared file %s: %w", target, err)
				}
				_ = f.Close()
			}
		} else if err != nil {
			return fmt.Errorf("deploy: stat shared %s: %w", target, err)
		}

		// Whatever the build produced at this path is replaced by the link.
		if err := os.RemoveAll(link); err != nil {
			return fmt.Errorf("deploy: clear %s: %w", link, err)
		}
		if err := os.MkdirAll(filepath.Dir(link), 0o755); err != nil {
			return fmt.Errorf("deploy: create parent for %s: %w", link, err)
		}
		if err := os.Symlink(target, link); err != nil {
			return fmt.Errorf("deploy: link %s -> %s: %w", link, target, err)
		}
	}
	return nil
}

// Activate points current at a release. The swap is a rename, so there is no
// moment where current does not resolve.
func (m *Manager) Activate(ctx context.Context, id string) error {
	return m.activate(ctx, id, false)
}

func (m *Manager) activate(_ context.Context, id string, rolledBack bool) error {
	if err := ValidateReleaseID(id); err != nil {
		return err
	}

	path := m.Layout.ReleasePath(id)
	info, err := os.Stat(path)
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return fmt.Errorf("%w: %s", ErrReleaseNotFound, id)
		}
		return fmt.Errorf("deploy: stat release %s: %w", id, err)
	}
	if !info.IsDir() {
		return fmt.Errorf("%w: %s is not a directory", ErrReleaseNotFound, id)
	}

	link := m.Layout.CurrentLink()
	tmp := filepath.Join(m.Layout.Root, ".current.tmp")
	// A leftover from an interrupted activation would make Symlink fail.
	_ = os.Remove(tmp)

	if err := os.Symlink(path, tmp); err != nil {
		return fmt.Errorf("deploy: stage current link: %w", err)
	}
	if err := os.Rename(tmp, link); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("deploy: swap current link: %w", err)
	}

	// History is appended after the swap. If the process dies between the two,
	// the worst case is a missing history entry for a release that is
	// genuinely live, which Current still reports correctly from the symlink.
	return m.appendHistory(activation{
		ReleaseID:   id,
		ActivatedAt: m.clock(),
		RolledBack:  rolledBack,
	})
}

// Current returns the release ID that current points at.
func (m *Manager) Current(context.Context) (string, error) {
	target, err := os.Readlink(m.Layout.CurrentLink())
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return "", ErrNoCurrentRelease
		}
		return "", fmt.Errorf("deploy: read current link: %w", err)
	}
	return filepath.Base(target), nil
}

// Previous returns the release that a rollback would activate: the one
// immediately before the current release in chronological order.
//
// Stepping through the release list rather than through activation history is
// deliberate. History tells you which release was live before this one, which
// sounds better but oscillates: after rolling back from v3 to v2, the release
// live before v2 is v3, so a second rollback would return to the very deploy
// the operator was escaping. Walking the chronological list instead means
// repeated rollbacks keep moving backwards, which is both what operators
// expect and what Forge and Envoyer do.
//
// Deciding not to return to a release already known to be broken is a
// separate concern. It needs releases to carry a health verdict, and belongs
// with health-check-gated deploys rather than here.
func (m *Manager) Previous(ctx context.Context) (string, error) {
	current, err := m.Current(ctx)
	if err != nil {
		return "", err
	}

	// List only reports directories that exist, so a pruned or manually
	// deleted release is skipped rather than activated into a missing path.
	releases, err := m.List(ctx)
	if err != nil {
		return "", err
	}

	idx := -1
	for i, rel := range releases {
		if rel.ID == current {
			idx = i
			break
		}
	}

	switch {
	case idx > 0:
		return releases[idx-1].ID, nil
	case idx == 0:
		// Current is the oldest release on disk.
		return "", ErrNothingToRollBack
	default:
		// The current release directory is gone, which means someone removed
		// it out of band and the site is serving a dangling symlink. The most
		// useful recovery is the newest release that still exists.
		if len(releases) > 0 {
			return releases[len(releases)-1].ID, nil
		}
		return "", ErrNothingToRollBack
	}
}

// Rollback activates the previous release and returns its ID.
func (m *Manager) Rollback(ctx context.Context) (string, error) {
	prev, err := m.Previous(ctx)
	if err != nil {
		return "", err
	}
	if err := m.activate(ctx, prev, true); err != nil {
		return "", err
	}
	return prev, nil
}

// List returns releases present on disk, oldest first.
func (m *Manager) List(context.Context) ([]Release, error) {
	entries, err := os.ReadDir(m.Layout.ReleasesDir())
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return nil, nil
		}
		return nil, fmt.Errorf("deploy: read releases: %w", err)
	}

	out := make([]Release, 0, len(entries))
	for _, e := range entries {
		if !e.IsDir() {
			continue
		}
		if ValidateReleaseID(e.Name()) != nil {
			// Ignore anything that is not ours rather than risk deleting it
			// later in Prune.
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		out = append(out, Release{
			ID:        e.Name(),
			Path:      m.Layout.ReleasePath(e.Name()),
			CreatedAt: info.ModTime(),
		})
	}

	// IDs lead with a UTC timestamp, so lexical order is chronological.
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out, nil
}

// Prune removes old releases, returning the IDs it deleted.
//
// The current release and the rollback target are never removed, regardless
// of the retention count. Pruning away the thing you would roll back to turns
// a bad deploy into an outage.
func (m *Manager) Prune(ctx context.Context) ([]string, error) {
	releases, err := m.List(ctx)
	if err != nil {
		return nil, err
	}

	protected := map[string]bool{}
	if current, err := m.Current(ctx); err == nil {
		protected[current] = true
		if prev, err := m.Previous(ctx); err == nil {
			protected[prev] = true
		}
	}

	keep := m.keep()
	if len(releases) <= keep {
		return nil, nil
	}

	// Oldest first, so the candidates for deletion are at the front.
	candidates := releases[:len(releases)-keep]

	var deleted []string
	for _, rel := range candidates {
		if protected[rel.ID] {
			continue
		}
		if err := os.RemoveAll(rel.Path); err != nil {
			return deleted, fmt.Errorf("deploy: remove release %s: %w", rel.ID, err)
		}
		deleted = append(deleted, rel.ID)
	}
	return deleted, nil
}

// history reads the activation log.
//
// The log is an audit record: what became live, when, and whether it was a
// rollback. Rollback targeting deliberately does not consult it; see
// Previous for why.
func (m *Manager) history() ([]activation, error) {
	data, err := os.ReadFile(m.Layout.historyPath())
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return nil, nil
		}
		return nil, fmt.Errorf("deploy: read history: %w", err)
	}
	if len(data) == 0 {
		return nil, nil
	}

	var out []activation
	if err := json.Unmarshal(data, &out); err != nil {
		// A corrupt history must not block a deploy. Activation still works
		// from the symlink; only rollback loses its memory.
		return nil, nil
	}
	return out, nil
}

// historyLimit bounds the retained activation log. It only needs to outlive
// the release retention window.
const historyLimit = 100

// appendHistory records an activation, rewriting the log atomically.
func (m *Manager) appendHistory(a activation) error {
	history, err := m.history()
	if err != nil {
		return err
	}
	history = append(history, a)
	if len(history) > historyLimit {
		history = history[len(history)-historyLimit:]
	}

	data, err := json.MarshalIndent(history, "", "  ")
	if err != nil {
		return fmt.Errorf("deploy: encode history: %w", err)
	}

	path := m.Layout.historyPath()
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o644); err != nil {
		return fmt.Errorf("deploy: write history: %w", err)
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("deploy: replace history: %w", err)
	}
	return nil
}
