// Command cloud drives Unharbored from a terminal, and from a coding agent
// through its MCP server (cloud mcp). It is a thin client over /api/v1: a URL
// and a token from the console's API tokens page are all it needs.
package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const usage = `cloud: Unharbored from the command line

  cloud login <console url>            save the URL and a token (read from stdin)
  cloud whoami
  cloud projects
  cloud sites [--project <slug>]
  cloud site <site>
  cloud services [--project <slug>]
  cloud service <name> [start|stop|restart] [--no-wait]
  cloud servers
  cloud deploy <site> [--commit sha] [--no-wait]
  cloud rollback <site> [--no-wait]
  cloud op <id> [--follow]
  cloud logs <site> [-q text] [--minutes 60] [--limit 200]
  cloud env <site> [--reveal]
  cloud env:set <site> KEY=value [KEY=value...]
  cloud env:unset <site> KEY [KEY...]
  cloud artisan <site> <command> [args...]
  cloud backups <site>
  cloud backup <site> [--no-wait]
  cloud restore <site> <backup> [--no-wait]
  cloud previews <site>
  cloud preview <site> <branch> [--follow] [--days 7|0] [--database fresh|clone|shared|sqlite] [--no-wait]
  cloud preview:delete <preview> [--no-wait]
  cloud mcp                            an MCP server on stdio, for coding agents

A site is its name or id. CM_URL and CM_TOKEN override the saved login.
`

func main() {
	if len(os.Args) < 2 || os.Args[1] == "-h" || os.Args[1] == "--help" || os.Args[1] == "help" {
		fmt.Print(usage)
		return
	}
	cmd, args := os.Args[1], os.Args[2:]
	if cmd == "login" {
		exit(login(args))
	}
	c, err := loadClient()
	if err != nil {
		exit(err)
	}
	if cmd == "mcp" {
		exit(serveMCP(c, os.Stdin, os.Stdout))
	}
	exit(run(c, cmd, args, os.Stdout))
}

func exit(err error) {
	if err != nil {
		fmt.Fprintln(os.Stderr, "cloud:", err)
		os.Exit(1)
	}
	os.Exit(0)
}

// run executes one command, writing what it shows to out.
func run(c *client, cmd string, args []string, out io.Writer) error {
	fs := flag.NewFlagSet(cmd, flag.ContinueOnError)
	noWait := fs.Bool("no-wait", false, "return once queued")
	commit := fs.String("commit", "", "deploy this commit")
	reveal := fs.Bool("reveal", false, "show contents, not just names")
	query := fs.String("q", "", "only lines containing this")
	minutes := fs.Int("minutes", 60, "how far back")
	limit := fs.Int("limit", 200, "at most this many lines")
	followPushes := fs.Bool("follow", false, "a preview redeploys on every push to its branch")
	days := fs.Int("days", 7, "delete a preview after this many idle days; 0 keeps it")
	project := fs.String("project", "", "only this project's sites, by slug (cloud projects lists them)")
	database := fs.String("database", "", "a preview's database: fresh, clone, shared or sqlite (default: the site's choice)")
	// Flags may come before or after the positional arguments.
	var pos []string
	rest := args
	for len(rest) > 0 {
		if err := fs.Parse(rest); err != nil {
			return err
		}
		if fs.NArg() == 0 {
			break
		}
		pos = append(pos, fs.Arg(0))
		rest = fs.Args()[1:]
		if cmd == "artisan" && len(pos) >= 1 {
			// Everything after the site is artisan's, flags included.
			pos = append(pos, rest...)
			break
		}
	}
	need := func(n int, what string) error {
		if len(pos) < n {
			return fmt.Errorf("usage: cloud %s %s", cmd, what)
		}
		return nil
	}
	site := func() string { return url.PathEscape(pos[0]) }

	switch cmd {
	case "whoami":
		return c.show(out, "GET", "/whoami", nil)
	case "projects":
		var r struct {
			Projects []struct {
				ID           int    `json:"id"`
				Name         string `json:"name"`
				Slug         string `json:"slug"`
				Applications int    `json:"applications"`
			} `json:"projects"`
		}
		if err := c.do("GET", "/projects", nil, &r); err != nil {
			if strings.Contains(err.Error(), "(HTTP 404)") {
				return errors.New("this server has no projects yet")
			}
			return err
		}
		for _, p := range r.Projects {
			fmt.Fprintf(out, "%-4d %-24s %-24s %d\n", p.ID, p.Name, p.Slug, p.Applications)
		}
		return nil
	case "sites":
		path := "/sites"
		if *project != "" {
			path += "?project=" + url.QueryEscape(*project)
		}
		var r struct {
			Sites []struct {
				ID          int    `json:"id"`
				Name        string `json:"name"`
				URL         string `json:"url"`
				Primary     string `json:"primary"`
				LiveRelease string `json:"live_release"`
				Project     *struct {
					Slug string `json:"slug"`
				} `json:"project"`
			} `json:"sites"`
		}
		if err := c.do("GET", path, nil, &r); err != nil {
			return err
		}
		for _, s := range r.Sites {
			slug := ""
			if s.Project != nil {
				slug = s.Project.Slug
			}
			fmt.Fprintf(out, "%-4d %-20s %-40s %-16s %-10s %s\n", s.ID, s.Name, s.URL, short(s.Primary), s.LiveRelease, slug)
		}
		return nil
	case "services":
		path := "/services"
		if *project != "" {
			path += "?project=" + url.QueryEscape(*project)
		}
		var r struct {
			Services []struct {
				ID        int    `json:"id"`
				Name      string `json:"name"`
				Template  string `json:"template"`
				Version   int    `json:"version"`
				Server    string `json:"server"`
				Project   string `json:"project"`
				Status    string `json:"status"`
				Endpoints []struct {
					Domain string `json:"domain"`
				} `json:"endpoints"`
			} `json:"services"`
		}
		if err := c.do("GET", path, nil, &r); err != nil {
			if strings.Contains(err.Error(), "(HTTP 404)") && *project == "" {
				return errors.New("this server has no services yet")
			}
			return err
		}
		for _, s := range r.Services {
			var domains []string
			for _, e := range s.Endpoints {
				domains = append(domains, e.Domain)
			}
			fmt.Fprintf(out, "%-4d %-20s %-14s v%-3d %-16s %-10s %-10s %s\n", s.ID, s.Name, s.Template, s.Version, short(s.Server), s.Status, s.Project, strings.Join(domains, ","))
		}
		return nil
	case "service":
		if err := need(1, "<name> [start|stop|restart]"); err != nil {
			return err
		}
		if len(pos) == 1 {
			return c.show(out, "GET", "/services/"+site(), nil)
		}
		switch pos[1] {
		case "start", "stop", "restart":
			return c.start(out, "/services/"+site()+"/"+pos[1], nil, !*noWait)
		}
		return errors.New("usage: cloud service <name> [start|stop|restart]")
	case "site":
		if err := need(1, "<site>"); err != nil {
			return err
		}
		return c.show(out, "GET", "/sites/"+site(), nil)
	case "servers":
		return c.show(out, "GET", "/servers", nil)
	case "deploy", "rollback", "backup":
		if err := need(1, "<site>"); err != nil {
			return err
		}
		path := map[string]string{"deploy": "/deploy", "rollback": "/rollback", "backup": "/backups"}[cmd]
		var body any
		if *commit != "" {
			body = map[string]string{"commit": *commit}
		}
		return c.start(out, "/sites/"+site()+path, body, !*noWait)
	case "restore":
		if err := need(2, "<site> <backup>"); err != nil {
			return err
		}
		return c.start(out, "/sites/"+site()+"/restore", map[string]string{"backup": pos[1]}, !*noWait)
	case "op":
		if err := need(1, "<id>"); err != nil {
			return err
		}
		if *followPushes {
			return c.follow(out, pos[0])
		}
		return c.show(out, "GET", "/operations/"+url.PathEscape(pos[0]), nil)
	case "logs":
		if err := need(1, "<site>"); err != nil {
			return err
		}
		q := url.Values{"minutes": {fmt.Sprint(*minutes)}, "limit": {fmt.Sprint(*limit)}}
		if *query != "" {
			q.Set("q", *query)
		}
		var r struct {
			Lines []struct{ At, Server, Source, Text string } `json:"lines"`
		}
		if err := c.do("GET", "/sites/"+site()+"/logs?"+q.Encode(), nil, &r); err != nil {
			return err
		}
		for _, l := range r.Lines {
			fmt.Fprintf(out, "%s %s %s %s\n", l.At, short(l.Server), l.Source, l.Text)
		}
		return nil
	case "env":
		if err := need(1, "<site>"); err != nil {
			return err
		}
		var r struct {
			Keys     []string `json:"keys"`
			Contents *string  `json:"contents"`
		}
		path := "/sites/" + site() + "/env"
		if *reveal {
			path += "?reveal=1"
		}
		if err := c.do("GET", path, nil, &r); err != nil {
			return err
		}
		if r.Contents != nil {
			fmt.Fprint(out, *r.Contents)
			return nil
		}
		fmt.Fprintln(out, strings.Join(r.Keys, "\n"))
		return nil
	case "env:set", "env:unset":
		if err := need(2, "<site> KEY[=value]..."); err != nil {
			return err
		}
		values := map[string]any{}
		for _, kv := range pos[1:] {
			if cmd == "env:unset" {
				values[kv] = nil
				continue
			}
			k, v, ok := strings.Cut(kv, "=")
			if !ok {
				return fmt.Errorf("%q is not KEY=value", kv)
			}
			values[k] = v
		}
		return c.show(out, "PATCH", "/sites/"+site()+"/env", map[string]any{"values": values})
	case "artisan":
		if err := need(2, "<site> <command> [args...]"); err != nil {
			return err
		}
		var r struct {
			ExitCode *int   `json:"exit_code"`
			Output   string `json:"output"`
		}
		if err := c.do("POST", "/sites/"+site()+"/artisan", map[string]any{"args": pos[1:]}, &r); err != nil {
			return err
		}
		fmt.Fprint(out, r.Output)
		if r.ExitCode != nil && *r.ExitCode != 0 {
			return fmt.Errorf("artisan exited %d", *r.ExitCode)
		}
		return nil
	case "previews":
		if err := need(1, "<site>"); err != nil {
			return err
		}
		return c.show(out, "GET", "/sites/"+site()+"/previews", nil)
	case "preview":
		if err := need(2, "<site> <branch>"); err != nil {
			return err
		}
		body := map[string]any{"branch": pos[1], "follow": *followPushes, "idle_days": *days}
		if *days == 0 {
			body["idle_days"] = nil
		}
		if *database != "" {
			body["database"] = *database
		}
		var r struct {
			Operation int    `json:"operation"`
			Preview   string `json:"preview"`
			URL       string `json:"preview_url"`
		}
		if err := c.do("POST", "/sites/"+site()+"/previews", body, &r); err != nil {
			return err
		}
		fmt.Fprintf(out, "preview %s at %s · operation %d\n", r.Preview, r.URL, r.Operation)
		if *noWait {
			return nil
		}
		return c.follow(out, fmt.Sprint(r.Operation))
	case "preview:delete":
		if err := need(1, "<preview>"); err != nil {
			return err
		}
		var r struct {
			Operation int `json:"operation"`
		}
		if err := c.do("DELETE", "/sites/"+site(), nil, &r); err != nil {
			return err
		}
		fmt.Fprintf(out, "operation %d\n", r.Operation)
		if *noWait {
			return nil
		}
		return c.follow(out, fmt.Sprint(r.Operation))
	case "backups":
		if err := need(1, "<site>"); err != nil {
			return err
		}
		var r struct {
			Backups []struct {
				Backup, At, Kind string
				Bytes            int64
			} `json:"backups"`
		}
		if err := c.do("GET", "/sites/"+site()+"/backups", nil, &r); err != nil {
			return err
		}
		for _, b := range r.Backups {
			fmt.Fprintf(out, "%s  %-8s %8.1f KB  %s\n", b.At, b.Kind, float64(b.Bytes)/1024, b.Backup)
		}
		return nil
	}
	return fmt.Errorf("unknown command %q\n\n%s", cmd, usage)
}

// client talks to /api/v1.
type client struct {
	base  string
	token string
	http  *http.Client
}

type config struct {
	URL   string `json:"url"`
	Token string `json:"token"`
}

func configPath() (string, error) {
	dir, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(dir, "cloud", "config.json"), nil
}

func loadClient() (*client, error) {
	var cfg config
	if p, err := configPath(); err == nil {
		if b, err := os.ReadFile(p); err == nil {
			_ = json.Unmarshal(b, &cfg)
		}
	}
	if v := os.Getenv("CM_URL"); v != "" {
		cfg.URL = v
	}
	if v := os.Getenv("CM_TOKEN"); v != "" {
		cfg.Token = v
	}
	if cfg.URL == "" || cfg.Token == "" {
		return nil, errors.New("not logged in: run cloud login <console url>, or set CM_URL and CM_TOKEN")
	}
	return &client{base: strings.TrimRight(cfg.URL, "/") + "/api/v1", token: cfg.Token, http: &http.Client{Timeout: 150 * time.Second}}, nil
}

func login(args []string) error {
	if len(args) != 1 {
		return errors.New("usage: cloud login <console url>, e.g. cloud login https://cp.example.com")
	}
	fmt.Fprint(os.Stderr, "Token (from the console's API tokens page): ")
	line, err := bufio.NewReader(os.Stdin).ReadString('\n')
	if err != nil && line == "" {
		return err
	}
	c := &client{base: strings.TrimRight(args[0], "/") + "/api/v1", token: strings.TrimSpace(line), http: &http.Client{Timeout: 30 * time.Second}}
	var who struct {
		Token  string   `json:"token"`
		Scopes []string `json:"scopes"`
	}
	if err := c.do("GET", "/whoami", nil, &who); err != nil {
		return err
	}
	p, err := configPath()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(config{URL: strings.TrimRight(args[0], "/"), Token: c.token}, "", "  ")
	if err := os.WriteFile(p, b, 0o600); err != nil {
		return err
	}
	fmt.Fprintf(os.Stderr, "Logged in as token %q (%s). Saved to %s\n", who.Token, strings.Join(who.Scopes, ", "), p)
	return nil
}

// do sends one request and decodes the JSON answer into into, if given.
func (c *client) do(method, path string, body, into any) error {
	var rd io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		rd = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, c.base+path, rd)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return err
	}
	if resp.StatusCode >= 400 {
		var e struct {
			Error   string `json:"error"`
			Message string `json:"message"`
		}
		_ = json.Unmarshal(raw, &e)
		msg := e.Error
		if msg == "" {
			msg = e.Message
		}
		if msg == "" {
			msg = strings.TrimSpace(string(raw))
		}
		return fmt.Errorf("%s (HTTP %d)", msg, resp.StatusCode)
	}
	if into == nil {
		return nil
	}
	return json.Unmarshal(raw, into)
}

// show prints an answer as indented JSON.
func (c *client) show(out io.Writer, method, path string, body any) error {
	var v any
	if err := c.do(method, path, body, &v); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(v, "", "  ")
	fmt.Fprintln(out, string(b))
	return nil
}

// start queues an operation and, if wait, follows it to the end.
func (c *client) start(out io.Writer, path string, body any, wait bool) error {
	var r struct {
		Operation int    `json:"operation"`
		URL       string `json:"url"`
	}
	if err := c.do("POST", path, body, &r); err != nil {
		return err
	}
	fmt.Fprintf(out, "operation %d · %s\n", r.Operation, r.URL)
	if !wait {
		return nil
	}
	return c.follow(out, fmt.Sprint(r.Operation))
}

type operation struct {
	ID     int    `json:"id"`
	Title  string `json:"title"`
	Status string `json:"status"`
	Error  string `json:"error"`
	Log    string `json:"log"`
	Next   int    `json:"next"`
}

// follow prints an operation's log as it grows until it finishes; a failed
// operation is an error, so a script can rely on the exit code.
func (c *client) follow(out io.Writer, id string) error {
	after := 0
	for {
		var op operation
		if err := c.do("GET", fmt.Sprintf("/operations/%s?after=%d", url.PathEscape(id), after), nil, &op); err != nil {
			return err
		}
		fmt.Fprint(out, op.Log)
		after = op.Next
		switch op.Status {
		case "succeeded":
			fmt.Fprintf(out, "== %s: succeeded\n", op.Title)
			return nil
		case "failed":
			return fmt.Errorf("%s failed: %s", op.Title, op.Error)
		}
		time.Sleep(2 * time.Second)
	}
}

func short(node string) string {
	name, _, _ := strings.Cut(node, ".")
	return name
}
