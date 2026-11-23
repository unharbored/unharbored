//go:build windows

package recipe

import "io/fs"

// modeDiffers always reports false on Windows.
//
// Windows has no POSIX permission bits, and os.Chmod there only toggles the
// read-only flag. Comparing modes would make every file step report a
// permanent drift it can never resolve, so the check is skipped.
//
// This is a development convenience only. Agents run on Linux, where
// mode_unix.go applies and permissions are enforced for real. Any test
// asserting on file modes must skip on Windows rather than rely on this.
func modeDiffers(_, _ fs.FileMode) bool { return false }
