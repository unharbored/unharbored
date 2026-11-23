//go:build !windows

package recipe

import "io/fs"

// modeDiffers compares permission bits.
//
// Agents run on Linux, so this is the real implementation. The Windows
// variant is a deliberate no-op so the engine and its tests remain usable on
// a developer workstation; see mode_windows.go.
func modeDiffers(current, desired fs.FileMode) bool {
	return current.Perm() != desired.Perm()
}
