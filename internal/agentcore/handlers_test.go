package agentcore

import (
	"errors"
	"testing"
)

// Exec is unrestricted by default. It is the customer's server, they already
// have root on it, and the allow list this replaced restricted the owner
// while blocking nothing for an attacker who had taken the control plane:
// recipe command steps were never allow-listed.
func TestExecIsUnrestrictedByDefault(t *testing.T) {
	agent := New(Config{StateDir: t.TempDir(), SitesRoot: t.TempDir()})

	for _, name := range []string{
		"php",
		"rm",
		"systemctl",
		"some-vendor-tool",
		"/opt/vendor/bin/tool",      // absolute paths are ordinary here
		"../relative/path/to/thing", // odd, but still the owner's business
	} {
		if err := agent.checkAllowed(name); err != nil {
			t.Errorf("checkAllowed(%q) = %v, want nil by default", name, err)
		}
	}
}

// An empty command is a malformed request rather than a policy question.
func TestExecRejectsEmptyCommandName(t *testing.T) {
	agent := New(Config{StateDir: t.TempDir(), SitesRoot: t.TempDir()})

	if err := agent.checkAllowed(""); !errors.Is(err, ErrCommandNotAllowed) {
		t.Fatalf("err = %v, want ErrCommandNotAllowed", err)
	}
}

// The restriction still exists for organisations that want one.
func TestConfiguredAllowListIsEnforced(t *testing.T) {
	agent := New(Config{
		StateDir:      t.TempDir(),
		SitesRoot:     t.TempDir(),
		ExecAllowList: []string{"php", "composer"},
	})

	for _, name := range []string{"php", "composer"} {
		if err := agent.checkAllowed(name); err != nil {
			t.Errorf("checkAllowed(%q) = %v, want nil", name, err)
		}
	}

	for _, name := range []string{"rm", "sh", "bash", "curl", "node"} {
		if err := agent.checkAllowed(name); !errors.Is(err, ErrCommandNotAllowed) {
			t.Errorf("checkAllowed(%q) = %v, want ErrCommandNotAllowed", name, err)
		}
	}
}

// With a list configured, a path must not be able to satisfy it. Otherwise
// "/tmp/evil/php" passes a base-name comparison and defeats the restriction
// the operator deliberately asked for.
func TestConfiguredAllowListRejectsPaths(t *testing.T) {
	agent := New(Config{
		StateDir:      t.TempDir(),
		SitesRoot:     t.TempDir(),
		ExecAllowList: []string{"php"},
	})

	for _, name := range []string{
		"/usr/bin/php",
		"/tmp/evil/php",
		"../../bin/php",
		`C:\windows\system32\php.exe`,
		`evil\php`,
	} {
		if err := agent.checkAllowed(name); !errors.Is(err, ErrCommandNotAllowed) {
			t.Errorf("checkAllowed(%q) = %v, want ErrCommandNotAllowed", name, err)
		}
	}
}

// Site names become paths and arrive from the control plane, so containment
// is checked regardless of the exec policy. This is a correctness boundary
// rather than a permission one: it stops a bug writing outside the tree.
func TestSiteNamesAreContained(t *testing.T) {
	root := t.TempDir()
	agent := New(Config{StateDir: t.TempDir(), SitesRoot: root})

	for _, site := range []string{"", ".", "..", "../escape", "a/b", `a\b`} {
		if _, err := agent.siteManager(site); err == nil {
			t.Errorf("siteManager(%q) succeeded, want rejection", site)
		}
	}

	if _, err := agent.siteManager("example.com"); err != nil {
		t.Errorf("siteManager(%q) = %v, want nil", "example.com", err)
	}
}
