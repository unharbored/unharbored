//go:build windows

package recipe

import (
	"errors"
	"io/fs"
)

// Owned steps need Unix ownership. Agents run on Linux; this keeps the package
// building on a developer workstation.
type confined struct{}

var errOwnedUnsupported = errors.New("recipe: owned steps are not supported on this platform")

func openConfined(string, string, string) (*confined, error)     { return nil, errOwnedUnsupported }
func (c *confined) close()                                       {}
func (c *confined) planFile([]byte, fs.FileMode) (Action, error) { return "", errOwnedUnsupported }
func (c *confined) writeFile([]byte, fs.FileMode) error          { return errOwnedUnsupported }
func (c *confined) planDir(fs.FileMode) (Action, error)          { return "", errOwnedUnsupported }
func (c *confined) makeDir(fs.FileMode) error                    { return errOwnedUnsupported }
func (c *confined) planAbsent() (Action, error)                  { return "", errOwnedUnsupported }
func (c *confined) remove() error                                { return errOwnedUnsupported }
