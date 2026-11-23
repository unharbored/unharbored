//go:build !windows

package recipe

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
)

// Owned steps are for paths the application can write.
//
// The agent runs as root. A file written as root at a path the application
// controls can be redirected: replace an ancestor such as ~/.ssh with a symlink
// to a privileged directory and root writes there, and a later chown by path
// hands the result to the application. Checking the path first does not help,
// because the check loses the race (audit 2026-11-08, finding 1).
//
// So an owned step names a Base, a directory the application cannot move (its
// home), and every operation goes through os.Root on that directory. Root opens
// each path component relative to a descriptor and refuses any link that leads
// outside Base, so there is no window to win. A file is created exclusively,
// given its owner and mode through its own descriptor (never by path), and
// renamed into place inside Base.
type confined struct {
	root     *os.Root
	rel      string
	uid, gid int
	owner    string
}

// openConfined resolves owner and confines path to base. path must be inside
// base and not base itself.
func openConfined(base, path, owner string) (*confined, error) {
	if owner == "" || base == "" {
		return nil, errors.New("recipe: an owned step needs both an owner and a base directory")
	}
	if !filepath.IsAbs(base) || !filepath.IsAbs(path) {
		return nil, fmt.Errorf("recipe: owned paths must be absolute (base %q, path %q)", base, path)
	}
	rel, err := filepath.Rel(filepath.Clean(base), filepath.Clean(path))
	if err != nil || rel == "." || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return nil, fmt.Errorf("recipe: %s is not inside %s", path, base)
	}

	account, err := user.Lookup(owner)
	if err != nil {
		return nil, fmt.Errorf("recipe: look up %s: %w", owner, err)
	}
	uid, err := strconv.Atoi(account.Uid)
	if err != nil {
		return nil, fmt.Errorf("recipe: uid of %s: %w", owner, err)
	}
	gid, err := strconv.Atoi(account.Gid)
	if err != nil {
		return nil, fmt.Errorf("recipe: gid of %s: %w", owner, err)
	}

	root, err := os.OpenRoot(base)
	if err != nil {
		return nil, fmt.Errorf("recipe: open %s: %w", base, err)
	}

	return &confined{root: root, rel: rel, uid: uid, gid: gid, owner: owner}, nil
}

func (c *confined) close() { _ = c.root.Close() }

// ownedBy reports whether info belongs to the step's owner.
func (c *confined) ownedBy(info fs.FileInfo) bool {
	st, ok := info.Sys().(*syscall.Stat_t)
	return ok && int(st.Uid) == c.uid && int(st.Gid) == c.gid
}

func (c *confined) planFile(content []byte, mode fs.FileMode) (Action, error) {
	// O_NONBLOCK so a FIFO planted at the path cannot hold the agent in open or read.
	f, err := c.root.OpenFile(c.rel, os.O_RDONLY|syscall.O_NONBLOCK, 0)
	if errors.Is(err, fs.ErrNotExist) {
		return ActionCreate, nil
	}
	if err != nil {
		return "", fmt.Errorf("recipe: open %s: %w", c.rel, err)
	}
	defer f.Close()

	info, err := f.Stat()
	if err != nil {
		return "", fmt.Errorf("recipe: stat %s: %w", c.rel, err)
	}
	if !info.Mode().IsRegular() {
		return "", fmt.Errorf("recipe: %s is not a regular file", c.rel)
	}
	current, err := io.ReadAll(io.LimitReader(f, int64(len(content))+1))
	if err != nil {
		return "", fmt.Errorf("recipe: read %s: %w", c.rel, err)
	}
	if !bytes.Equal(current, content) || modeDiffers(info.Mode(), mode) || !c.ownedBy(info) {
		return ActionUpdate, nil
	}

	return ActionNoop, nil
}

func (c *confined) writeFile(content []byte, mode fs.FileMode) error {
	dir := filepath.Dir(c.rel)
	if dir != "." {
		if err := c.root.MkdirAll(dir, 0o755); err != nil {
			return fmt.Errorf("recipe: create %s: %w", dir, err)
		}
	}

	var salt [6]byte
	if _, err := rand.Read(salt[:]); err != nil {
		return err
	}
	tmp := filepath.Join(dir, ".tmp-"+hex.EncodeToString(salt[:]))

	// O_EXCL: never write through something that is already there.
	f, err := c.root.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return fmt.Errorf("recipe: create temp in %s: %w", dir, err)
	}
	defer func() { _ = c.root.Remove(tmp) }()

	_, err = f.Write(content)
	if err == nil {
		// By descriptor, not by path: the name can be swapped, the open file cannot.
		err = f.Chown(c.uid, c.gid)
	}
	if err == nil {
		err = f.Chmod(mode)
	}
	if err == nil {
		err = f.Sync()
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return fmt.Errorf("recipe: write %s: %w", tmp, err)
	}
	if err := c.root.Rename(tmp, c.rel); err != nil {
		return fmt.Errorf("recipe: rename into %s: %w", c.rel, err)
	}

	return nil
}

func (c *confined) planDir(mode fs.FileMode) (Action, error) {
	info, err := c.root.Stat(c.rel)
	if errors.Is(err, fs.ErrNotExist) {
		return ActionCreate, nil
	}
	if err != nil {
		return "", fmt.Errorf("recipe: stat %s: %w", c.rel, err)
	}
	if !info.IsDir() {
		return "", fmt.Errorf("recipe: %s exists and is not a directory", c.rel)
	}
	if modeDiffers(info.Mode().Perm(), mode) || !c.ownedBy(info) {
		return ActionUpdate, nil
	}

	return ActionNoop, nil
}

func (c *confined) makeDir(mode fs.FileMode) error {
	if err := c.root.MkdirAll(c.rel, mode); err != nil {
		return fmt.Errorf("recipe: create %s: %w", c.rel, err)
	}
	// Open the directory and change it through the descriptor.
	d, err := c.root.Open(c.rel)
	if err != nil {
		return fmt.Errorf("recipe: open %s: %w", c.rel, err)
	}
	defer d.Close()
	if err := d.Chown(c.uid, c.gid); err != nil {
		return fmt.Errorf("recipe: chown %s: %w", c.rel, err)
	}
	if err := d.Chmod(mode); err != nil {
		return fmt.Errorf("recipe: chmod %s: %w", c.rel, err)
	}

	return nil
}

func (c *confined) planAbsent() (Action, error) {
	if _, err := c.root.Lstat(c.rel); err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return ActionNoop, nil
		}
		return "", fmt.Errorf("recipe: stat %s: %w", c.rel, err)
	}

	return ActionDelete, nil
}

func (c *confined) remove() error {
	if err := c.root.RemoveAll(c.rel); err != nil {
		return fmt.Errorf("recipe: remove %s: %w", c.rel, err)
	}

	return nil
}
