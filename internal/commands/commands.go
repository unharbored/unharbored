// Package commands defines the argument and result payloads for every
// command the control plane can send an agent.
//
// It sits in its own package so the agent and the control plane share one
// definition rather than two that drift. The protocol package deliberately
// does not import it: protocol owns the envelope, this owns what rides
// inside.
package commands

import (
	"github.com/unharbored/unharbored/internal/deploy"
	"github.com/unharbored/unharbored/internal/recipe"
)

// ApplyRecipe converges the node toward a declared configuration.
type ApplyRecipe struct {
	Spec recipe.Spec `json:"spec"`
	// DryRun plans without changing anything.
	DryRun bool `json:"dry_run,omitempty"`
}

// ApplyRecipeResult reports what the run did.
type ApplyRecipeResult struct {
	Run *recipe.RunResult `json:"run"`
}

// PrepareRelease creates a release directory and links its shared paths. It
// does not make the release live.
type PrepareRelease struct {
	// Site is the site directory name under the agent's sites root.
	Site string `json:"site"`
	// ReleaseID must satisfy deploy.ValidateReleaseID.
	ReleaseID string `json:"release_id"`
	// Shared defaults to deploy.DefaultLaravelShared when empty.
	Shared []deploy.SharedPath `json:"shared,omitempty"`
}

// PrepareReleaseResult reports the prepared release.
type PrepareReleaseResult struct {
	Release *deploy.Release `json:"release"`
}

// ActivateRelease swaps the current symlink.
//
// It is a separate command from PrepareRelease so the control plane can stage
// every node in a pool before switching any of them.
type ActivateRelease struct {
	Site      string `json:"site"`
	ReleaseID string `json:"release_id"`
}

// ActivateReleaseResult reports the live release.
type ActivateReleaseResult struct {
	Site    string `json:"site"`
	Current string `json:"current"`
	// Previous is what the site was serving before, empty on first deploy.
	Previous string `json:"previous,omitempty"`
}

// Rollback activates the release before the current one.
type Rollback struct {
	Site string `json:"site"`
}

// RollbackResult reports the release rolled back to.
type RollbackResult struct {
	Site    string `json:"site"`
	Current string `json:"current"`
}

// PruneReleases removes old releases, never the current or rollback target.
type PruneReleases struct {
	Site string `json:"site"`
	// Keep overrides the retention count when positive.
	Keep int `json:"keep,omitempty"`
}

// PruneReleasesResult lists what was removed.
type PruneReleasesResult struct {
	Site    string   `json:"site"`
	Deleted []string `json:"deleted"`
}

// Exec runs a single allow-listed command.
//
// This is the escape hatch behind "run an artisan command from the
// dashboard". It is allow-listed rather than free-form because the agent runs
// as root and an unrestricted exec would make every other control in the
// system decorative.
type Exec struct {
	Name string   `json:"name"`
	Args []string `json:"args,omitempty"`
	// Dir is relative to the site root when Site is set, absolute otherwise.
	Dir string `json:"dir,omitempty"`
	// Site, when set, runs the command in that site's current release.
	Site string `json:"site,omitempty"`
}

// ExecResult carries the command outcome.
type ExecResult struct {
	ExitCode int    `json:"exit_code"`
	Output   string `json:"output"`
}
