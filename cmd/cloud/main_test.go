package main

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

// fakeAPI answers like /api/v1 for one site and one deploy that finishes
// on the second look.
func fakeAPI(t *testing.T) (*client, *[]string) {
	t.Helper()
	var mu sync.Mutex
	var seen []string
	looks := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		body, _ := io.ReadAll(r.Body)
		seen = append(seen, r.Method+" "+r.URL.RequestURI()+" "+strings.TrimSpace(string(body)))
		if r.Header.Get("Authorization") != "Bearer cm_test" {
			w.WriteHeader(401)
			io.WriteString(w, `{"error":"A valid API token is needed"}`)
			return
		}
		switch {
		case r.URL.Path == "/api/v1/projects":
			io.WriteString(w, `{"projects":[{"id":1,"name":"Default","slug":"default","applications":1},{"id":2,"name":"Client A","slug":"client-a","applications":0}]}`)
		case r.URL.Path == "/api/v1/sites" && r.URL.Query().Get("project") == "nope":
			w.WriteHeader(404)
			io.WriteString(w, `{"error":"No project nope."}`)
		case r.URL.Path == "/api/v1/sites":
			io.WriteString(w, `{"sites":[{"id":1,"name":"shop","url":"https://shop.example.com","primary":"web-1.org-1","live_release":"r1","project":{"id":1,"name":"Default","slug":"default"}}]}`)
		case r.URL.Path == "/api/v1/services":
			io.WriteString(w, `{"services":[{"id":3,"name":"files","template":"rustfs","version":1,"server":"web-1.org-1","project":"default","status":"running","endpoints":[{"domain":"s3.example.com","tls":true}],"operation":null}]}`)
		case r.URL.Path == "/api/v1/services/files":
			io.WriteString(w, `{"id":3,"name":"files","status":"running"}`)
		case r.URL.Path == "/api/v1/services/files/restart":
			w.WriteHeader(202)
			io.WriteString(w, `{"operation":7,"url":"https://cp/operation?operation=7"}`)
		case r.URL.Path == "/api/v1/sites/shop/deploy":
			w.WriteHeader(202)
			io.WriteString(w, `{"operation":7,"url":"https://cp/operation?operation=7"}`)
		case r.URL.Path == "/api/v1/operations/7":
			looks++
			if looks == 1 {
				io.WriteString(w, `{"id":7,"title":"Deploy shop","status":"running","log":"build\n","next":6}`)
			} else {
				io.WriteString(w, `{"id":7,"title":"Deploy shop","status":"succeeded","log":"activate\n","next":15}`)
			}
		case r.URL.Path == "/api/v1/sites/shop/rollback":
			w.WriteHeader(403)
			io.WriteString(w, `{"error":"This token cannot deploy."}`)
		case r.URL.Path == "/api/v1/sites/shop/artisan":
			io.WriteString(w, `{"exit_code":0,"output":"Nothing to migrate.\n"}`)
		default:
			w.WriteHeader(404)
			io.WriteString(w, `{"error":"No site."}`)
		}
	}))
	t.Cleanup(srv.Close)
	return &client{base: srv.URL + "/api/v1", token: "cm_test", http: srv.Client()}, &seen
}

func TestDeployFollowsTheLogToTheEnd(t *testing.T) {
	c, seen := fakeAPI(t)
	var out bytes.Buffer
	if err := run(c, "deploy", []string{"shop"}, &out); err != nil {
		t.Fatal(err)
	}
	want := "operation 7 · https://cp/operation?operation=7\nbuild\nactivate\n== Deploy shop: succeeded\n"
	if out.String() != want {
		t.Fatalf("got\n%s", out.String())
	}
	if !strings.Contains(strings.Join(*seen, "\n"), "GET /api/v1/operations/7?after=6") {
		t.Fatalf("the second look should ask only for what is new: %v", *seen)
	}
}

func TestAnAPIRefusalIsAnError(t *testing.T) {
	c, _ := fakeAPI(t)
	err := run(c, "rollback", []string{"shop", "--no-wait"}, io.Discard)
	if err == nil || !strings.Contains(err.Error(), "cannot deploy") || !strings.Contains(err.Error(), "403") {
		t.Fatalf("err = %v", err)
	}
}

func TestArtisanPassesItsOwnFlagsThrough(t *testing.T) {
	c, seen := fakeAPI(t)
	if err := run(c, "artisan", []string{"shop", "migrate", "--force"}, io.Discard); err != nil {
		t.Fatal(err)
	}
	if last := (*seen)[len(*seen)-1]; !strings.HasSuffix(last, `{"args":["migrate","--force"]}`) {
		t.Fatal(last)
	}
}

func TestMCPListsToolsAndDeploysThroughThem(t *testing.T) {
	c, _ := fakeAPI(t)
	in := strings.Join([]string{
		`{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}`,
		`{"jsonrpc":"2.0","method":"notifications/initialized"}`,
		`{"jsonrpc":"2.0","id":2,"method":"tools/list"}`,
		`{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"deploy_site","arguments":{"site":"shop"}}}`,
		`{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"rollback_site","arguments":{"site":"shop"}}}`,
		`{"jsonrpc":"2.0","id":5,"method":"nope"}`,
	}, "\n") + "\n"
	var out bytes.Buffer
	if err := serveMCP(c, strings.NewReader(in), &out); err != nil {
		t.Fatal(err)
	}
	var replies []map[string]any
	dec := json.NewDecoder(&out)
	for dec.More() {
		var r map[string]any
		if err := dec.Decode(&r); err != nil {
			t.Fatal(err)
		}
		replies = append(replies, r)
	}
	if len(replies) != 5 {
		t.Fatalf("a notification gets no reply: got %d replies", len(replies))
	}
	if v := replies[0]["result"].(map[string]any)["protocolVersion"]; v != "2025-06-18" {
		t.Fatalf("protocol %v", v)
	}
	if n := len(replies[1]["result"].(map[string]any)["tools"].([]any)); n < 10 {
		t.Fatalf("%d tools", n)
	}
	deploy := replies[2]["result"].(map[string]any)
	text := deploy["content"].([]any)[0].(map[string]any)["text"].(string)
	if deploy["isError"] != false || !strings.Contains(text, "== Deploy shop: succeeded") {
		t.Fatalf("deploy: %v", deploy)
	}
	rollback := replies[3]["result"].(map[string]any)
	if rollback["isError"] != true || !strings.Contains(rollback["content"].([]any)[0].(map[string]any)["text"].(string), "cannot deploy") {
		t.Fatalf("a refusal is a tool error the agent can read: %v", rollback)
	}
	if replies[4]["error"].(map[string]any)["code"].(float64) != -32601 {
		t.Fatal(replies[4])
	}
}

func TestProjectsListAndFilterTheSites(t *testing.T) {
	c, seen := fakeAPI(t)
	var out bytes.Buffer
	if err := run(c, "projects", nil, &out); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.String(), "client-a") || !strings.Contains(out.String(), "Default") {
		t.Fatalf("got\n%s", out.String())
	}
	out.Reset()
	if err := run(c, "sites", []string{"--project", "default"}, &out); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(*seen, "\n"), "GET /api/v1/sites?project=default") || !strings.Contains(out.String(), "shop") || !strings.HasSuffix(strings.TrimSpace(out.String()), "default") {
		t.Fatalf("seen %v\ngot\n%s", *seen, out.String())
	}
	err := run(c, "sites", []string{"--project", "nope"}, io.Discard)
	if err == nil || !strings.Contains(err.Error(), "No project nope") {
		t.Fatalf("err = %v", err)
	}
}

func TestProjectsAgainstAnOldServerSaysSo(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(404)
		io.WriteString(w, `{"message":"Not Found"}`)
	}))
	t.Cleanup(srv.Close)
	c := &client{base: srv.URL + "/api/v1", token: "cm_test", http: srv.Client()}
	err := run(c, "projects", nil, io.Discard)
	if err == nil || err.Error() != "this server has no projects yet" {
		t.Fatalf("err = %v", err)
	}
}

func TestMCPListSitesTakesAProject(t *testing.T) {
	c, seen := fakeAPI(t)
	for _, tl := range tools() {
		if tl.Name == "list_sites" {
			if _, err := tl.run(c, map[string]any{"project": "client-a"}); err != nil {
				t.Fatal(err)
			}
		}
	}
	if !strings.Contains(strings.Join(*seen, "\n"), "GET /api/v1/sites?project=client-a") {
		t.Fatalf("%v", *seen)
	}
}

func TestServicesListShowAndRestart(t *testing.T) {
	c, seen := fakeAPI(t)
	var out bytes.Buffer
	if err := run(c, "services", []string{"--project", "default"}, &out); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(*seen, "\n"), "GET /api/v1/services?project=default") || !strings.Contains(out.String(), "rustfs") || !strings.Contains(out.String(), "s3.example.com") {
		t.Fatalf("seen %v\ngot\n%s", *seen, out.String())
	}
	out.Reset()
	if err := run(c, "service", []string{"files"}, &out); err != nil || !strings.Contains(out.String(), "running") {
		t.Fatalf("err %v got %s", err, out.String())
	}
	out.Reset()
	if err := run(c, "service", []string{"files", "restart", "--no-wait"}, &out); err != nil || !strings.Contains(out.String(), "operation 7") {
		t.Fatalf("err %v got %s", err, out.String())
	}
	if !strings.Contains(strings.Join(*seen, "\n"), "POST /api/v1/services/files/restart") {
		t.Fatalf("%v", *seen)
	}
	if err := run(c, "service", []string{"files", "explode"}, io.Discard); err == nil {
		t.Fatal("an unknown action must be refused")
	}
}

func TestMCPListServices(t *testing.T) {
	c, seen := fakeAPI(t)
	for _, tl := range tools() {
		if tl.Name == "list_services" {
			if _, err := tl.run(c, map[string]any{"project": "default"}); err != nil {
				t.Fatal(err)
			}
		}
	}
	if !strings.Contains(strings.Join(*seen, "\n"), "GET /api/v1/services?project=default") {
		t.Fatalf("%v", *seen)
	}
}
