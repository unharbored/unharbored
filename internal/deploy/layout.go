// Package deploy manages release directories on a node.
//
// The layout is the conventional atomic-release shape:
//
//	<root>/
//	  releases/
//	    20261104T143022Z-a1b2c3d/
//	    20261104T151140Z-e4f5a6b/
//	  shared/
//	    .env
//	    storage/
//	  current -> releases/20261104T151140Z-e4f5a6b
//
// Preparing a release and activating it are deliberately separate operations.
// A multi-node deploy prepares every node first, and only swaps the current
// symlink once all of them have reported success. Combining the two would
// mean the first node serves new code while the last is still unpacking.
//
// Activation is a symlink rename, which is atomic on POSIX. There is no
// window in which the current path does not resolve.
package deploy

import (
	"fmt"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Layout describes where a site's files live.
type Layout struct {
	// Root is the site directory, e.g. /home/deploy/example.com.
	Root string
}

// ReleasesDir holds unpacked releases.
func (l Layout) ReleasesDir() string { return filepath.Join(l.Root, "releases") }

// SharedDir holds state that outlives any one release: the environment file,
// user uploads, and anything else that must not be replaced on deploy.
func (l Layout) SharedDir() string { return filepath.Join(l.Root, "shared") }

// CurrentLink is the symlink the web server and CLI resolve through.
func (l Layout) CurrentLink() string { return filepath.Join(l.Root, "current") }

// historyPath records activations in order.
func (l Layout) historyPath() string { return filepath.Join(l.Root, ".deploy-history.json") }

// ReleasePath returns the directory for a release ID.
func (l Layout) ReleasePath(id string) string { return filepath.Join(l.ReleasesDir(), id) }

// releaseIDPattern matches IDs produced by NewReleaseID.
//
// Release IDs become filesystem paths, so they are validated rather than
// trusted. A commit SHA arrives from a webhook, and a crafted one containing
// path separators or a parent reference would otherwise let a deploy write
// outside the releases directory.
var releaseIDPattern = regexp.MustCompile(`^[0-9]{8}T[0-9]{6}Z-[a-zA-Z0-9._-]{1,64}$`)

// NewReleaseID builds a sortable release identifier.
//
// The timestamp leads so lexical order matches chronological order, which is
// what lets listing and pruning work without parsing.
func NewReleaseID(t time.Time, ref string) string {
	ref = sanitizeRef(ref)
	return fmt.Sprintf("%s-%s", t.UTC().Format("20060102T150405Z"), ref)
}

// sanitizeRef reduces a git ref or SHA to characters safe in a path segment.
func sanitizeRef(ref string) string {
	if ref == "" {
		return "unknown"
	}
	var b strings.Builder
	for _, r := range ref {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9':
			b.WriteRune(r)
		case r == '.' || r == '_' || r == '-':
			b.WriteRune(r)
		default:
			// Drop anything else rather than substituting, so two different
			// refs cannot collapse to the same sanitised value through a
			// shared replacement character.
		}
	}
	out := b.String()
	// A ref made entirely of dropped characters, or one that is only dots,
	// would produce a path segment like ".." or "".
	out = strings.Trim(out, ".")
	if out == "" {
		return "unknown"
	}
	if len(out) > 64 {
		out = out[:64]
	}
	return out
}

// ValidateReleaseID rejects identifiers that are not safe as a path segment.
func ValidateReleaseID(id string) error {
	if !releaseIDPattern.MatchString(id) {
		return fmt.Errorf("%w: %q", ErrInvalidReleaseID, id)
	}
	return nil
}

// SharedKind distinguishes a shared file from a shared directory.
type SharedKind string

const (
	// SharedFile is a single file, such as .env.
	SharedFile SharedKind = "file"
	// SharedDir is a directory, such as storage.
	SharedDir SharedKind = "dir"
)

// SharedPath is a path inside the release that is replaced by a symlink into
// the shared directory.
type SharedPath struct {
	// Rel is the path relative to the release root, e.g. ".env" or
	// "storage/app".
	Rel string
	// Kind selects whether the shared entry is created as a file or
	// directory when it does not yet exist.
	Kind SharedKind
	// Link is where the entry lives under the shared directory. Empty means
	// the same as Rel. An application in a repository subfolder sets Rel to
	// "apps/web/.env" and Link to ".env", so its shared files stay where
	// they always were.
	Link string
}

// Target is the path under the shared directory this entry links to.
func (sp SharedPath) Target() string {
	if sp.Link != "" {
		return sp.Link
	}
	return sp.Rel
}

// DefaultLaravelShared is the conventional set for a Laravel application.
//
// storage is shared because it holds user uploads and framework caches that
// must survive a deploy, and .env because it is configuration rather than
// code. Both would otherwise be lost on every release.
var DefaultLaravelShared = []SharedPath{
	{Rel: ".env", Kind: SharedFile},
	{Rel: "storage", Kind: SharedDir},
}

// isAbsAnyPlatform reports whether a path is absolute under either POSIX or
// Windows rules. Validation of node-bound paths must not depend on the host
// the agent was compiled on.
func isAbsAnyPlatform(p string) bool {
	if p == "" {
		return false
	}
	if p[0] == '/' || p[0] == '\\' {
		return true
	}
	// Drive-letter form, e.g. C:\ or C:/.
	if len(p) >= 3 && p[1] == ':' && (p[2] == '\\' || p[2] == '/') {
		c := p[0]
		if (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') {
			return true
		}
	}
	return filepath.IsAbs(p)
}

// validateRel rejects shared paths that escape the release directory.
func validateRel(rel string) error {
	if rel == "" {
		return fmt.Errorf("%w: empty", ErrInvalidSharedPath)
	}
	// These paths describe a Linux filesystem regardless of where this code
	// is compiled, so do not rely on filepath.IsAbs: on Windows it reports
	// false for "/etc/passwd", which would let an absolute POSIX path through
	// when the test suite runs on a developer workstation.
	if isAbsAnyPlatform(rel) {
		return fmt.Errorf("%w: %q is absolute", ErrInvalidSharedPath, rel)
	}
	// Normalise with forward slashes so the check behaves the same whichever
	// platform the test suite runs on.
	clean := filepath.ToSlash(filepath.Clean(rel))
	if clean == ".." || strings.HasPrefix(clean, "../") {
		return fmt.Errorf("%w: %q escapes the release directory", ErrInvalidSharedPath, rel)
	}
	if strings.Contains(clean, "..") {
		for _, seg := range strings.Split(clean, "/") {
			if seg == ".." {
				return fmt.Errorf("%w: %q escapes the release directory", ErrInvalidSharedPath, rel)
			}
		}
	}
	return nil
}
