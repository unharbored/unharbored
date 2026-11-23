package gateway

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
)

// API is the control plane's way in.
//
// Everything here is privileged: a caller that can reach it can run commands
// as root on every connected node. It authenticates with a shared secret and
// is expected to be on a network the outside world cannot reach. The secret
// is a second line of defence, not the first.
type API struct {
	server *Server
	token  string
	log    *slog.Logger
}

// NewAPI builds the control plane API.
func NewAPI(server *Server, token string, logger *slog.Logger) *API {
	if logger == nil {
		logger = slog.Default()
	}

	return &API{server: server, token: token, log: logger}
}

// Handler returns the HTTP routes.
func (a *API) Handler() http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("POST /v1/nodes/{nodeID}/commands", a.authorized(a.sendCommand))
	mux.HandleFunc("POST /v1/nodes/{nodeID}/terminal", a.authorized(a.terminal))
	mux.HandleFunc("GET /v1/nodes", a.authorized(a.listNodes))
	mux.HandleFunc("GET /v1/nodes/{nodeID}", a.authorized(a.nodeStatus))
	mux.HandleFunc("PUT /v1/organisations", a.authorized(a.replaceOrganisations))

	// Unauthenticated on purpose: it reveals nothing and a health check that
	// needs a credential is a health check that fails for the wrong reasons.
	mux.HandleFunc("GET /health", a.health)

	return mux
}

func (a *API) authorized(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if err := AuthorizeRequest(r, a.token); err != nil {
			a.log.Warn("rejected an unauthorised call", "remote", r.RemoteAddr, "path", r.URL.Path)
			writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})

			return
		}

		next(w, r)
	}
}

// commandRequest is what the control plane posts to run something on a node.
type commandRequest struct {
	Kind string `json:"kind"`
	// Args is passed through to the agent untouched. The gateway does not
	// interpret it: knowing what a command means is the control plane's job,
	// and the agent's, and not a third party's.
	Args json.RawMessage `json:"args,omitempty"`
	// TimeoutSeconds bounds how long the agent may take.
	TimeoutSeconds int `json:"timeout_seconds,omitempty"`
	// IdempotencyKey makes a retried command safe. Commands that are unsafe
	// to repeat, such as release activation, must carry one.
	IdempotencyKey string `json:"idempotency_key,omitempty"`
	// WaitSeconds bounds how long the gateway holds this HTTP request open
	// waiting for the result.
	WaitSeconds int `json:"wait_seconds,omitempty"`
}

// commandResponse mirrors protocol.Result, plus whether the outcome is known.
type commandResponse struct {
	Status     string          `json:"status"`
	ExitCode   int             `json:"exit_code,omitempty"`
	Error      string          `json:"error,omitempty"`
	Detail     json.RawMessage `json:"detail,omitempty"`
	DurationMS int64           `json:"duration_ms"`

	// Outcome is false when the gateway cannot say whether the command ran.
	// A node that disconnects mid-command is the case this exists for: the
	// work may have completed, so the control plane must not treat it as a
	// clean failure and retry blindly.
	Outcome bool `json:"outcome_known"`
}

const defaultWaitSeconds = 120

// OrganizationHeader names the organisation a node call is for.
const OrganizationHeader = "X-Organization-ID"

// organisation reads the organisation a call is for. It is required on every
// node call: a node id is only meaningful inside an organisation, so a call
// that does not say which one is refused rather than guessed.
func organisation(w http.ResponseWriter, r *http.Request) (string, bool) {
	id := r.Header.Get(OrganizationHeader)
	if id == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": OrganizationHeader + " is required"})

		return "", false
	}

	return id, true
}

func (a *API) sendCommand(w http.ResponseWriter, r *http.Request) {
	nodeID := r.PathValue("nodeID")
	organizationID, ok := organisation(w, r)
	if !ok {
		return
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, 8<<20))
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "unreadable request"})

		return
	}

	var req commandRequest
	if err := json.Unmarshal(body, &req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "malformed request"})

		return
	}

	if req.Kind == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "no command kind"})

		return
	}

	wait := req.WaitSeconds
	if wait <= 0 {
		wait = defaultWaitSeconds
	}

	ctx, cancel := context.WithTimeout(r.Context(), time.Duration(wait)*time.Second)
	defer cancel()

	result, err := a.server.Send(ctx, organizationID, nodeID, protocol.Command{
		Kind:           protocol.CommandKind(req.Kind),
		Args:           req.Args,
		TimeoutSeconds: req.TimeoutSeconds,
		IdempotencyKey: req.IdempotencyKey,
	})
	if err != nil {
		switch {
		case errors.Is(err, ErrNodeNotConnected):
			// 409 rather than 404: the node may well exist and simply not be
			// connected right now, which is a different thing from unknown
			// and the control plane handles it differently.
			writeJSON(w, http.StatusConflict, commandResponse{
				Status:  "unavailable",
				Error:   err.Error(),
				Outcome: false,
			})

		case errors.Is(err, context.DeadlineExceeded):
			// The command may still be running on the node. Saying "failed"
			// would invite a retry of something already in flight.
			writeJSON(w, http.StatusGatewayTimeout, commandResponse{
				Status:  "timed_out_waiting",
				Error:   "the gateway stopped waiting; the command may still be running",
				Outcome: false,
			})

		case errors.Is(err, ErrShuttingDown):
			writeJSON(w, http.StatusServiceUnavailable, commandResponse{
				Status:  "unavailable",
				Error:   err.Error(),
				Outcome: false,
			})

		default:
			writeJSON(w, http.StatusBadGateway, commandResponse{
				Status:  "failed",
				Error:   err.Error(),
				Outcome: false,
			})
		}

		return
	}

	writeJSON(w, http.StatusOK, commandResponse{
		Status:     string(result.Status),
		ExitCode:   result.ExitCode,
		Error:      result.Error,
		Detail:     result.Detail,
		DurationMS: result.DurationMS,
		Outcome:    true,
	})
}

// terminal passes an instruction to a console on a node. The shell's output
// does not come back here: it is posted to the control plane as it is made.
func (a *API) terminal(w http.ResponseWriter, r *http.Request) {
	var t protocol.Terminal
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&t); err != nil || t.Session == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "malformed request"})

		return
	}

	switch t.Op {
	case protocol.TerminalOpen, protocol.TerminalInput, protocol.TerminalResize, protocol.TerminalClose:
	default:
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "unknown operation"})

		return
	}

	organizationID, ok := organisation(w, r)
	if !ok {
		return
	}

	if err := a.server.Terminal(organizationID, r.PathValue("nodeID"), t); err != nil {
		status := http.StatusBadGateway
		if errors.Is(err, ErrNodeNotConnected) {
			status = http.StatusConflict
		}
		writeJSON(w, status, map[string]string{"error": err.Error()})

		return
	}

	writeJSON(w, http.StatusOK, map[string]bool{"sent": true})
}

func (a *API) listNodes(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"nodes": a.server.Nodes(r.URL.Query().Get("organization_id")),
	})
}

func (a *API) nodeStatus(w http.ResponseWriter, r *http.Request) {
	nodeID := r.PathValue("nodeID")
	organizationID, ok := organisation(w, r)
	if !ok {
		return
	}

	for _, node := range a.server.Nodes(organizationID) {
		if node.NodeID == nodeID {
			writeJSON(w, http.StatusOK, map[string]any{"connected": true, "node": node})

			return
		}
	}

	writeJSON(w, http.StatusOK, map[string]any{"connected": false, "node_id": nodeID})
}

// organisationsRequest replaces the whole set of tenants the gateway accepts.
type organisationsRequest struct {
	Organisations []struct {
		ID string `json:"id"`
		// CACertificatePEM is the organisation CA. Only the certificate: the
		// private key stays in the control plane's vault and the gateway has
		// no use for it, since it only ever verifies.
		CACertificatePEM string `json:"ca_certificate_pem"`
		// RevokedSerials are removed nodes' certificate serials, in hex.
		RevokedSerials []string `json:"revoked_serials"`
	} `json:"organisations"`
}

func (a *API) replaceOrganisations(w http.ResponseWriter, r *http.Request) {
	body, err := io.ReadAll(io.LimitReader(r.Body, 8<<20))
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "unreadable request"})

		return
	}

	var req organisationsRequest
	if err := json.Unmarshal(body, &req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "malformed request"})

		return
	}

	orgs := make([]Organisation, 0, len(req.Organisations))

	for _, item := range req.Organisations {
		cert, err := parseCACertificate([]byte(item.CACertificatePEM))
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{
				"error": "organisation " + item.ID + ": " + err.Error(),
			})

			return
		}

		orgs = append(orgs, Organisation{ID: item.ID, CACertificate: cert, RevokedSerials: item.RevokedSerials})
	}

	if err := a.server.registry.Replace(orgs); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})

		return
	}

	disconnected := a.server.DisconnectRevoked()
	a.log.Info("organisation registry replaced", "count", len(orgs), "disconnected_revoked", disconnected)

	writeJSON(w, http.StatusOK, map[string]any{"organisations": len(orgs), "disconnected": disconnected})
}

func (a *API) health(w http.ResponseWriter, _ *http.Request) {
	registry := a.server.registry

	// A gateway with no organisations cannot accept any node, so report it
	// rather than looking healthy while refusing every connection.
	healthy := registry.Len() > 0

	status := http.StatusOK
	if !healthy {
		status = http.StatusServiceUnavailable
	}

	writeJSON(w, status, map[string]any{
		"status":           map[bool]string{true: "ok", false: "no organisations"}[healthy],
		"version":          Version,
		"organisations":    registry.Len(),
		"connected_nodes":  len(a.server.Nodes("")),
		"registry_updated": registry.UpdatedAt(),
	})
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}
