package agentcore

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestSecretsAreRedactedBeforeTheyLeave(t *testing.T) {
	cases := map[string]string{
		`DB_PASSWORD=hunter2 connecting`:                            `DB_PASSWORD=[REDACTED] connecting`,
		`{"password":"hunter2","user":"matt"}`:                      `{"password":"[REDACTED]","user":"matt"}`,
		`Authorization: Bearer abc.def.ghi`:                         `Authorization: Bearer [REDACTED]`,
		`token dop_v1_0123456789abcdef0123 leaked`:                  `token dop_v1_[REDACTED] leaked`,
		`pgsql://shop:s3cret@db.example.com:25060/shop`:             `pgsql://shop:[REDACTED]@db.example.com:25060/shop`,
		`key AKIAABCDEFGHIJKLMNOP used`:                             `key [REDACTED AWS KEY] used`,
		`stripe_api_key: sk_live_abcdefgh12345678`:                  `stripe_api_key: [REDACTED]`,
		`jwt eyJhbGciOiJIUzI1.eyJzdWIiOiIxMjM0.SflKxwRJSMeKKF2Q ok`: `jwt [REDACTED JWT] ok`,
		`-----BEGIN OPENSSH PRIVATE KEY----- b3BlbnNzaC1rZXk`:       `[REDACTED PRIVATE KEY]`,
		`GET /orders?page=2 200 12ms`:                               `GET /orders?page=2 200 12ms`,
	}
	for in, want := range cases {
		if got := redact(in); got != want {
			t.Errorf("redact(%q)\n got %q\nwant %q", in, got, want)
		}
	}
}

func TestTailingFollowsNewLinesRotationAndTruncation(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "app.log")
	write(t, path, "old line from before the agent started\n")

	tl := newTailer([]logSource{{glob: filepath.Join(dir, "*.log"), name: "laravel:shop"}})
	tl.discover(true)
	defer tl.close()

	appendTo(t, path, "first\nhalf a li")
	tl.poll()
	appendTo(t, path, "ne\n")
	tl.poll()
	if got := texts(tl); got != "first|half a line" {
		t.Fatalf("got %q: history skipped, a split line joined", got)
	}

	// Rotated: a new file at the same path is read from its start.
	os.Rename(path, path+".1")
	write(t, path, "after rotation\n")
	tl.poll()
	// Truncated in place.
	write(t, path, "x\n")
	tl.poll()
	if got := texts(tl); got != "after rotation|x" {
		t.Fatalf("got %q", got)
	}

	// A file that appears later is read whole.
	write(t, filepath.Join(dir, "new.log"), "new file\n")
	tl.discover(false)
	tl.poll()
	batch, _ := tl.take()
	if len(batch.Lines) != 1 || batch.Lines[0].Text != "new file" || batch.Lines[0].Source != "laravel:shop" {
		t.Fatalf("got %+v", batch)
	}
}

func TestABackedUpBufferDropsAndCounts(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "app.log")
	write(t, path, "")
	tl := newTailer([]logSource{{glob: path, name: "x"}})
	tl.discover(true)
	defer tl.close()

	appendTo(t, path, strings.Repeat("line\n", logBufferMax+5))
	tl.poll()
	batch, _ := tl.take()
	if len(batch.Lines) != logBatchMax || batch.Dropped != 5 {
		t.Fatalf("lines %d dropped %d", len(batch.Lines), batch.Dropped)
	}
}

func TestTheSiteIsTakenFromThePath(t *testing.T) {
	if got := siteOf("/home/deploy/shop/shared/storage/logs/laravel.log"); got != "shop" {
		t.Fatal(got)
	}
}

func appendTo(t *testing.T, path, s string) {
	t.Helper()
	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	f.WriteString(s)
}

func texts(tl *tailer) string {
	batch, _ := tl.take()
	var out []string
	for _, l := range batch.Lines {
		out = append(out, l.Text)
	}
	return strings.Join(out, "|")
}

func TestAStackTraceStaysOneRecord(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "laravel.log")
	write(t, path, "")
	tl := newTailer([]logSource{{glob: path, name: "laravel:shop", start: laravelEntry}})
	tl.discover(true)
	defer tl.close()

	appendTo(t, path, "[2026-11-12 10:00:00] production.ERROR: Boom {\"id\":1}\n#0 /app/a.php(10): f()\n#1 {main}\n[2026-11-12 10:00:05] production.INFO: fine\n")
	tl.poll()
	// The first record is complete because the second began; the second may still grow.
	if got := texts(tl); got != "[2026-11-12 10:00:00] production.ERROR: Boom {\"id\":1}\n#0 /app/a.php(10): f()\n#1 {main}" {
		t.Fatalf("got %q", got)
	}
	// Once it has been quiet a moment it is taken as whole.
	tl.files[path].entryAt = time.Now().Add(-2 * entryIdle)
	tl.poll()
	if got := texts(tl); got != "[2026-11-12 10:00:05] production.INFO: fine" {
		t.Fatalf("got %q", got)
	}
}

func TestARecordIsCutAtTheLimitAndSecretsInItsTraceAreRemoved(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "laravel.log")
	write(t, path, "")
	tl := newTailer([]logSource{{glob: path, name: "x", start: laravelEntry}})
	tl.discover(true)

	appendTo(t, path, "[2026-11-12 10:00:00] local.ERROR: x\npassword=hunter2\n"+strings.Repeat("#9 frame\n", 2000)+"[2026-11-12 10:00:01] next\n")
	tl.poll()
	batch, _ := tl.take()
	if len(batch.Lines) != 1 || len(batch.Lines[0].Text) > logEntryMax+8 || strings.Contains(batch.Lines[0].Text, "hunter2") {
		t.Fatalf("%d lines, %d bytes, secret kept: %v", len(batch.Lines), len(batch.Lines[0].Text), strings.Contains(batch.Lines[0].Text, "hunter2"))
	}
}

func TestOnlyTheLinesThatMatterAreKept(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "syslog")
	write(t, path, "")
	tl := newTailer([]logSource{{glob: path, name: "system", keep: systemTrouble}})
	tl.discover(true)
	defer tl.close()

	appendTo(t, path, "Nov 12 CRON[1]: (root) CMD (run)\nNov 12 kernel: Out of memory: Killed process 812 (php-fpm8.3)\nNov 12 systemd[1]: Started Daily apt\n")
	tl.poll()
	if got := texts(tl); got != "Nov 12 kernel: Out of memory: Killed process 812 (php-fpm8.3)" {
		t.Fatalf("got %q", got)
	}
}

func TestTheSiteOfAnNginxErrorLog(t *testing.T) {
	if got := siteOf("/var/log/nginx/shop.error.log"); got != "shop" {
		t.Fatal(got)
	}
}
