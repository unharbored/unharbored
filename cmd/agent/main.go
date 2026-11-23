// Command agent runs the node agent.
//
// It is installed by cloud-init at provision time and run as a systemd
// service thereafter. On first start it enrolls using the single-use token
// from user_data; after that it holds an outbound mTLS connection to the
// control plane and executes commands.
//
// Nothing listens on the node. The agent opens the connection, which is what
// lets a provisioned Droplet keep every inbound port closed.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/unharbored/unharbored/internal/agentcore"
)

func main() {
	if err := run(); err != nil && !errors.Is(err, context.Canceled) {
		fmt.Fprintf(os.Stderr, "agent: %v\n", err)
		os.Exit(1)
	}
}

func run() error {
	var (
		stateDir   = flag.String("state-dir", "/var/lib/cloud-manager", "directory holding node credentials")
		sitesRoot  = flag.String("sites-root", "/home/deploy", "parent directory of site release trees")
		enrollURL  = flag.String("enroll-url", "", "control plane enrollment endpoint, used only on first start")
		serverName = flag.String("server-name", "", "control plane certificate name to verify")
		caFile     = flag.String("ca-file", "", "PEM file holding the organisation CA, delivered with the token")
		tokenFile  = flag.String("token-file", "", "file holding the single-use enrollment token")
		enrollOnly = flag.Bool("enroll-only", false, "enroll and exit without connecting")
		logLevel   = flag.String("log-level", "info", "debug, info, warn or error")
		showVer    = flag.Bool("version", false, "print version and exit")
	)
	flag.Parse()

	if *showVer {
		fmt.Println(agentcore.Version)
		return nil
	}

	logger := newLogger(*logLevel)
	slog.SetDefault(logger)

	cfg := agentcore.Config{
		StateDir:   *stateDir,
		SitesRoot:  *sitesRoot,
		EnrollURL:  *enrollURL,
		ServerName: *serverName,
		Logger:     logger,
	}

	// The token is read from a file rather than taken as a flag so it does
	// not appear in the process table or in shell history.
	token, err := readToken(*tokenFile)
	if err != nil {
		return err
	}
	cfg.EnrollToken = token
	if *caFile != "" {
		ca, err := os.ReadFile(*caFile)
		if err != nil {
			return fmt.Errorf("read CA file: %w", err)
		}
		cfg.BootstrapCAPEM = ca
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	agent := agentcore.New(cfg)

	if err := agent.EnsureEnrolled(ctx); err != nil {
		return err
	}

	// Burn the token file once enrollment succeeded. It is single use on the
	// server too, so this is belt and braces, but leaving a dead credential
	// on disk invites someone to wonder whether it still works.
	if *tokenFile != "" {
		if err := os.Remove(*tokenFile); err != nil && !os.IsNotExist(err) {
			logger.Warn("could not remove spent token file", "path", *tokenFile, "error", err)
		}
	}

	if *enrollOnly {
		logger.Info("enrollment complete", "node_id", agent.State().Identity.NodeID)
		return nil
	}

	return agent.Run(ctx)
}

func newLogger(level string) *slog.Logger {
	var lvl slog.Level
	switch level {
	case "debug":
		lvl = slog.LevelDebug
	case "warn":
		lvl = slog.LevelWarn
	case "error":
		lvl = slog.LevelError
	default:
		lvl = slog.LevelInfo
	}
	// Structured output to stderr, which journald captures per unit.
	return slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: lvl}))
}

func trimSpace(s string) string {
	start, end := 0, len(s)
	for start < end && isSpace(s[start]) {
		start++
	}
	for end > start && isSpace(s[end-1]) {
		end--
	}
	return s[start:end]
}

func isSpace(c byte) bool {
	return c == ' ' || c == '\t' || c == '\r' || c == '\n'
}

// readToken returns the enrollment token, or "" when there is none.
//
// The token file is single use and deleted once enrollment succeeds, so on
// every start after the first it is supposed to be missing. Treating that as
// an error meant an enrolled agent could never restart: a reboot or an
// upgrade left the node unmanaged for good. Whether a missing token matters
// is EnsureEnrolled's decision, made against the saved credentials.
func readToken(path string) (string, error) {
	if path == "" {
		return "", nil
	}
	token, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return "", nil
	}
	if err != nil {
		return "", fmt.Errorf("read token file: %w", err)
	}
	return trimSpace(string(token)), nil
}
