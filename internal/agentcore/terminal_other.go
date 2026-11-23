//go:build !linux

package agentcore

import "errors"

// startShell is only available on Linux, which is what nodes run.
func startShell(string, string, int, int) (shell, func([]byte) (int, error), func() int, error) {
	return nil, nil, nil, errors.New("consoles need a Linux server")
}
