package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"strings"
	"time"
)

// An MCP server on stdio: newline-delimited JSON-RPC 2.0, the tools below,
// each a call to /api/v1 with the same token the CLI uses. The token's
// scope bounds what an agent can do, whatever it asks for.

type rpcRequest struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id,omitempty"`
	Method  string          `json:"method"`
	Params  json.RawMessage `json:"params,omitempty"`
}

type rpcResponse struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id"`
	Result  any             `json:"result,omitempty"`
	Error   *rpcError       `json:"error,omitempty"`
}

type rpcError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

type tool struct {
	Name        string         `json:"name"`
	Description string         `json:"description"`
	InputSchema map[string]any `json:"inputSchema"`
	Annotations map[string]any `json:"annotations,omitempty"`
	// run turns the arguments into what the agent reads.
	run func(c *client, args map[string]any) (string, error)
}

func schema(required []string, props map[string]any) map[string]any {
	if props == nil {
		props = map[string]any{}
	}
	s := map[string]any{"type": "object", "properties": props}
	if len(required) > 0 {
		s["required"] = required
	}
	return s
}

var siteProp = map[string]any{"type": "string", "description": "The site's name or id, as list_sites shows it."}

func str(args map[string]any, key string) string {
	v, _ := args[key].(string)
	return v
}

// viaCLI runs the same code path as the terminal command and returns what
// it printed, so the CLI and the agent see the same thing.
func viaCLI(c *client, cmd string, argv ...string) (string, error) {
	var buf bytes.Buffer
	err := run(c, cmd, argv, &buf)
	return buf.String(), err
}

// waitFor follows an operation for up to 15 minutes, keeping the end of its
// log: enough to say what happened without flooding the agent's context.
func waitFor(c *client, started string) (string, error) {
	var buf bytes.Buffer
	done := make(chan error, 1)
	var id string
	fmt.Sscanf(started, "operation %s", &id)
	go func() { done <- c.follow(&buf, id) }()
	select {
	case err := <-done:
		out := buf.String()
		if len(out) > 6000 {
			out = "…\n" + out[len(out)-6000:]
		}
		return started + out, err
	case <-time.After(15 * time.Minute):
		return started + "still running after 15 minutes; check it with get_operation", nil
	}
}

func tools() []tool {
	readOnly := map[string]any{"readOnlyHint": true}
	return []tool{
		{Name: "list_projects", Description: "The team's projects: id, name, slug and how many sites each holds. Projects group sites; they do not restrict access.",
			InputSchema: schema(nil, nil), Annotations: readOnly,
			run: func(c *client, _ map[string]any) (string, error) { return viaCLI(c, "projects") }},
		{Name: "list_sites", Description: "Every site: id, name, URL, primary server, live release and project slug. Give project to list only that project's sites.",
			InputSchema: schema(nil, map[string]any{"project": map[string]any{"type": "string", "description": "A project's slug, as list_projects shows it."}}), Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) {
				if p := str(a, "project"); p != "" {
					return viaCLI(c, "sites", "--project", p)
				}
				return viaCLI(c, "sites")
			}},
		{Name: "list_services", Description: "Template services (object storage and the like) installed on the team's servers: id, name, template, version, server, project slug, status and addresses. Give project to list only that project's services. Never includes credentials.",
			InputSchema: schema(nil, map[string]any{"project": map[string]any{"type": "string", "description": "A project's slug, as list_projects shows it."}}), Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) {
				if p := str(a, "project"); p != "" {
					return viaCLI(c, "services", "--project", p)
				}
				return viaCLI(c, "services")
			}},
		{Name: "get_site", Description: "One site: its servers, whether settings are waiting for a deploy, and its recent operations.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp}), Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) { return viaCLI(c, "site", str(a, "site")) }},
		{Name: "list_servers", Description: "Every server, whether its agent is connected, and its latest CPU, memory and disk use in percent.",
			InputSchema: schema(nil, nil), Annotations: readOnly,
			run: func(c *client, _ map[string]any) (string, error) { return viaCLI(c, "servers") }},
		{Name: "deploy_site", Description: "Deploy the site's branch (or one commit) to all its servers with zero downtime, and wait for the result. Migrations run on the primary after a database backup. Returns the end of the deploy log.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp, "commit": map[string]any{"type": "string", "description": "Optional: a commit SHA to deploy instead of the branch head."}}),
			run: func(c *client, a map[string]any) (string, error) {
				argv := []string{str(a, "site"), "--no-wait"}
				if s := str(a, "commit"); s != "" {
					argv = append(argv, "--commit", s)
				}
				started, err := viaCLI(c, "deploy", argv...)
				if err != nil {
					return started, err
				}
				return waitFor(c, started)
			}},
		{Name: "rollback_site", Description: "Switch the site back to its previous release on every server and wait for the result. Code only: the database is not rolled back.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp}), Annotations: map[string]any{"destructiveHint": true},
			run: func(c *client, a map[string]any) (string, error) {
				started, err := viaCLI(c, "rollback", str(a, "site"), "--no-wait")
				if err != nil {
					return started, err
				}
				return waitFor(c, started)
			}},
		{Name: "get_operation", Description: "An operation (deploy, rollback, backup, restore…) by id: status, stages, error and full log.",
			InputSchema: schema([]string{"id"}, map[string]any{"id": map[string]any{"type": "integer"}}), Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) {
				id := fmt.Sprint(a["id"])
				if f, ok := a["id"].(float64); ok {
					id = fmt.Sprint(int(f))
				}
				return viaCLI(c, "op", id)
			}},
		{Name: "search_logs", Description: "The site's Laravel, worker and nginx error log lines from all its servers, oldest first, already redacted. Use query to find an error.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp,
				"query":   map[string]any{"type": "string", "description": "Only lines containing this."},
				"minutes": map[string]any{"type": "integer", "description": "How far back, up to 10080 (a week). Default 60."},
				"limit":   map[string]any{"type": "integer", "description": "At most this many lines, default 100."}}),
			Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) {
				argv := []string{str(a, "site"), "--limit", "100"}
				if q := str(a, "query"); q != "" {
					argv = append(argv, "-q", q)
				}
				if m, ok := a["minutes"].(float64); ok {
					argv = append(argv, "--minutes", fmt.Sprint(int(m)))
				}
				if l, ok := a["limit"].(float64); ok {
					argv[2] = fmt.Sprint(int(l))
				}
				return viaCLI(c, "logs", argv...)
			}},
		{Name: "get_env_keys", Description: "The names of the site's .env keys. Values are not shown.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp}), Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) { return viaCLI(c, "env", str(a, "site")) }},
		{Name: "set_env", Description: "Set .env keys for the site (a null value removes the key). Saved encrypted and versioned; takes effect on the next deploy. Needs a manage token.",
			InputSchema: schema([]string{"site", "values"}, map[string]any{"site": siteProp,
				"values": map[string]any{"type": "object", "description": "KEY: value pairs; keys UPPER_CASE.", "additionalProperties": map[string]any{"type": []string{"string", "null"}}}}),
			run: func(c *client, a map[string]any) (string, error) {
				var out any
				err := c.do("PATCH", "/sites/"+str(a, "site")+"/env", map[string]any{"values": a["values"]}, &out)
				b, _ := json.Marshal(out)
				return string(b), err
			}},
		{Name: "run_artisan", Description: "Run php artisan on the site's primary server, in the live release, and return its output and exit code. Not for commands that never return (tinker, queue:work). Needs a manage token.",
			InputSchema: schema([]string{"site", "args"}, map[string]any{"site": siteProp,
				"args": map[string]any{"type": "array", "items": map[string]any{"type": "string"}, "description": `The command and its arguments, e.g. ["migrate:status"].`}}),
			Annotations: map[string]any{"destructiveHint": true},
			run: func(c *client, a map[string]any) (string, error) {
				argv := []string{str(a, "site")}
				list, _ := a["args"].([]any)
				for _, v := range list {
					argv = append(argv, fmt.Sprint(v))
				}
				return viaCLI(c, "artisan", argv...)
			}},
		{Name: "list_previews", Description: "The site's previews: each a branch running at its own URL on the preview server.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp}), Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) { return viaCLI(c, "previews", str(a, "site")) }},
		{Name: "create_preview", Description: "Run a branch of the site at its own URL (branch.site.preview domain), with no production credentials, and wait until it is up. Its database is fresh (empty, of the site's kind, seeded), a clone of the site's data, the site's own (shared: migrations never run) or sqlite, as the site chooses unless database is given. Returns the URL. Use it to show or check work on a branch.",
			InputSchema: schema([]string{"site", "branch"}, map[string]any{"site": siteProp,
				"branch":    map[string]any{"type": "string", "description": "The branch to run; it must be pushed already."},
				"follow":    map[string]any{"type": "boolean", "description": "Redeploy on every push to the branch. Default false."},
				"idle_days": map[string]any{"type": "integer", "description": "Delete after this many days without a deploy; 0 keeps it. Default 7."},
				"database":  map[string]any{"type": "string", "enum": []string{"fresh", "clone", "shared", "sqlite"}, "description": "Where its data comes from. Default: the site's setting."}}),
			run: func(c *client, a map[string]any) (string, error) {
				argv := []string{str(a, "site"), str(a, "branch")}
				if f, _ := a["follow"].(bool); f {
					argv = append(argv, "--follow")
				}
				if d, ok := a["idle_days"].(float64); ok {
					argv = append(argv, "--days", fmt.Sprint(int(d)))
				}
				if d := str(a, "database"); d != "" {
					argv = append(argv, "--database", d)
				}
				var buf bytes.Buffer
				err := run(c, "preview", argv, &buf)
				out := buf.String()
				if len(out) > 6000 {
					out = out[:300] + "\n…\n" + out[len(out)-5000:]
				}
				return out, err
			}},
		{Name: "delete_preview", Description: "Delete a preview (as list_previews names it): its code, database and files on the preview server, and its URL.",
			InputSchema: schema([]string{"preview"}, map[string]any{"preview": map[string]any{"type": "string", "description": "The preview's name, e.g. demo-feature-x."}}),
			Annotations: map[string]any{"destructiveHint": true},
			run: func(c *client, a map[string]any) (string, error) {
				return viaCLI(c, "preview:delete", str(a, "preview"))
			}},
		{Name: "list_backups", Description: "The site's database backups from the last 14 days, newest first, with what took each.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp}), Annotations: readOnly,
			run: func(c *client, a map[string]any) (string, error) { return viaCLI(c, "backups", str(a, "site")) }},
		{Name: "back_up_site", Description: "Back up the site's database now and wait for it.",
			InputSchema: schema([]string{"site"}, map[string]any{"site": siteProp}),
			run: func(c *client, a map[string]any) (string, error) {
				started, err := viaCLI(c, "backup", str(a, "site"), "--no-wait")
				if err != nil {
					return started, err
				}
				return waitFor(c, started)
			}},
		{Name: "restore_backup", Description: "Replace the site's database with a backup, as list_backups names it. The site is in maintenance mode meanwhile, and what is there now is backed up first. Needs a manage token.",
			InputSchema: schema([]string{"site", "backup"}, map[string]any{"site": siteProp, "backup": map[string]any{"type": "string"}}),
			Annotations: map[string]any{"destructiveHint": true},
			run: func(c *client, a map[string]any) (string, error) {
				started, err := viaCLI(c, "restore", str(a, "site"), str(a, "backup"), "--no-wait")
				if err != nil {
					return started, err
				}
				return waitFor(c, started)
			}},
	}
}

func serveMCP(c *client, in io.Reader, out io.Writer) error {
	byName := map[string]tool{}
	listed := []tool{}
	for _, t := range tools() {
		byName[t.Name] = t
		listed = append(listed, t)
	}
	enc := json.NewEncoder(out)
	sc := bufio.NewScanner(in)
	sc.Buffer(make([]byte, 1<<20), 16<<20)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		var req rpcRequest
		if err := json.Unmarshal([]byte(line), &req); err != nil {
			_ = enc.Encode(rpcResponse{JSONRPC: "2.0", ID: json.RawMessage("null"), Error: &rpcError{Code: -32700, Message: "parse error"}})
			continue
		}
		if len(req.ID) == 0 {
			continue // a notification, such as notifications/initialized
		}
		resp := rpcResponse{JSONRPC: "2.0", ID: req.ID}
		switch req.Method {
		case "initialize":
			var p struct {
				ProtocolVersion string `json:"protocolVersion"`
			}
			_ = json.Unmarshal(req.Params, &p)
			version := p.ProtocolVersion
			if version == "" {
				version = "2025-06-18"
			}
			resp.Result = map[string]any{
				"protocolVersion": version,
				"capabilities":    map[string]any{"tools": map[string]any{}},
				"serverInfo":      map[string]any{"name": "cloud-manager", "version": "1"},
				"instructions":    "Deploy, roll back, read logs, run artisan and back up or restore sites on cloud-manager. What you can do is bounded by the token's scope (read, deploy or manage). Deploys and restores wait for their result.",
			}
		case "ping":
			resp.Result = map[string]any{}
		case "tools/list":
			resp.Result = map[string]any{"tools": listed}
		case "tools/call":
			var p struct {
				Name      string         `json:"name"`
				Arguments map[string]any `json:"arguments"`
			}
			if err := json.Unmarshal(req.Params, &p); err != nil {
				resp.Error = &rpcError{Code: -32602, Message: "invalid params"}
				break
			}
			t, ok := byName[p.Name]
			if !ok {
				resp.Error = &rpcError{Code: -32602, Message: "unknown tool " + p.Name}
				break
			}
			if p.Arguments == nil {
				p.Arguments = map[string]any{}
			}
			text, err := t.run(c, p.Arguments)
			if err != nil {
				text = strings.TrimSpace(text + "\nerror: " + err.Error())
			}
			resp.Result = map[string]any{"content": []map[string]any{{"type": "text", "text": text}}, "isError": err != nil}
		default:
			resp.Error = &rpcError{Code: -32601, Message: "method not found: " + req.Method}
		}
		if err := enc.Encode(resp); err != nil {
			return err
		}
	}
	return sc.Err()
}
