// Command gateway holds agent connections on behalf of the control plane.
//
// It exists because PHP-FPM serves a request and exits, so the Laravel
// control plane cannot hold thousands of long-lived mTLS sessions. This does
// that one thing. Tenancy, credentials, audit, the state machine,
// provisioning, the API and the UI all stay in Laravel.
//
// Two listeners:
//
//	-agent-addr    mutually authenticated, faces nodes
//	-api-addr      shared secret, faces the control plane only
//
// The API listener must not be reachable from outside. Anything that can call
// it can run commands as root on every connected node, and the shared secret
// is a second line of defence rather than the first.
//
// See docs/adr/0007-session-gateway.md.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/unharbored/unharbored/internal/gateway"
)

func main() {
	if err := run(); err != nil && !errors.Is(err, context.Canceled) {
		fmt.Fprintf(os.Stderr, "gateway: %v\n", err)
		os.Exit(1)
	}
}

func run() error {
	var (
		agentAddr = flag.String("agent-addr", "0.0.0.0:9443", "mTLS listener for agents")
		apiAddr   = flag.String("api-addr", "127.0.0.1:9444", "control plane API, must not be publicly reachable")
		certFile  = flag.String("cert-file", "", "PEM certificate presented to agents, issued by an organisation CA")
		keyFile   = flag.String("key-file", "", "PEM private key for the certificate")
		eventsURL = flag.String("events-url", "", "control plane endpoint to post node events to")
		tokenFile = flag.String("token-file", "", "file holding the shared secret for the control plane link")
		heartbeat = flag.Int("heartbeat-seconds", 30, "heartbeat interval advertised to agents")
		logLevel  = flag.String("log-level", "info", "debug, info, warn or error")
		showVer   = flag.Bool("version", false, "print version and exit")
	)
	flag.Parse()

	if *showVer {
		fmt.Println(gateway.Version)

		return nil
	}

	logger := newLogger(*logLevel)
	slog.SetDefault(logger)

	if *certFile == "" || *keyFile == "" {
		return errors.New("-cert-file and -key-file are required")
	}

	certPEM, err := os.ReadFile(*certFile)
	if err != nil {
		return fmt.Errorf("read certificate: %w", err)
	}

	keyPEM, err := os.ReadFile(*keyFile)
	if err != nil {
		return fmt.Errorf("read key: %w", err)
	}

	// Read from a file rather than a flag, so the secret does not appear in
	// the process table or in shell history.
	if *tokenFile == "" {
		return errors.New("-token-file is required; the control plane link must be authenticated")
	}

	tokenBytes, err := os.ReadFile(*tokenFile)
	if err != nil {
		return fmt.Errorf("read token file: %w", err)
	}

	token := trimSpace(string(tokenBytes))
	if token == "" {
		return errors.New("the token file is empty")
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	// Events are best-effort behind a bounded queue, so a control plane
	// deploy cannot stall command dispatch or the heartbeats that keep nodes
	// connected.
	var events gateway.EventSink = gateway.DiscardEvents{}

	if *eventsURL != "" {
		sink := gateway.NewControlPlaneEvents(*eventsURL, token, logger)
		defer sink.Close()

		events = sink
	} else {
		logger.Warn("no -events-url; node connections will not be reported to the control plane")
	}

	// The registry starts empty. The control plane fills it by calling
	// PUT /v1/organisations, which means a freshly started gateway refuses
	// every node until it has been told who exists. That is the right
	// default: accepting a node whose tenant is unknown is worse than
	// refusing it until the control plane says otherwise.
	registry := gateway.NewRegistry()

	server := gateway.NewServer(registry, gateway.Config{
		ServerCertPEM:    certPEM,
		ServerKeyPEM:     keyPEM,
		HeartbeatSeconds: *heartbeat,
		Events:           events,
		Logger:           logger,
	})
	defer server.Close()

	boundAgent, err := server.Listen(ctx, *agentAddr)
	if err != nil {
		return err
	}

	api := &http.Server{
		Addr:              *apiAddr,
		Handler:           gateway.NewAPI(server, token, logger).Handler(),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		<-ctx.Done()

		shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()

		_ = api.Shutdown(shutdownCtx)
	}()

	logger.Info("gateway ready",
		"version", gateway.Version,
		"agents", boundAgent,
		"api", *apiAddr,
		"events", *eventsURL != "")

	if err := api.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return fmt.Errorf("api listener: %w", err)
	}

	<-ctx.Done()
	logger.Info("shutting down")

	return nil
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
