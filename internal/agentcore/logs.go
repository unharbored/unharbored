package agentcore

import (
	"bufio"
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/transport"
)

// Log shipping: the files that say what a server's sites are doing, tailed
// and sent in batches, with secrets taken out on the server so they never
// travel or get stored.
const (
	logPollInterval = 2 * time.Second
	logSendInterval = 5 * time.Second
	logBatchMax     = 200
	// logBufferMax caps what waits to be sent. Past it lines are dropped
	// and counted: a chatty app must not grow the agent without bound.
	logBufferMax = 2000
	logLineMax   = 4096
	// logEntryMax caps a record of several lines (an exception and its trace). The control plane
	// keeps 8192, so this stays under it.
	logEntryMax = 8000
	// entryIdle is how long a record may sit unchanged before it is taken as complete.
	entryIdle = 1500 * time.Millisecond
)

// logSource is one set of files followed.
//   - glob: the files. A %s in name is the site: from the path's first directory under
//     /home/deploy, or from nginx's per-site file name.
//   - start: when set, a line that does not match it continues the entry before it (a stack
//     trace), so an entry is one record however many lines it takes.
//   - keep: when set, only lines that match it are shipped (a busy log of which only some
//     lines matter).
type logSource struct {
	glob, name string
	start      *regexp.Regexp
	keep       *regexp.Regexp
}

// Entries that start with these begin a new record.
var (
	laravelEntry = regexp.MustCompile(`^\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}`)
	fpmEntry     = regexp.MustCompile(`^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}\]`)
	// What the kernel and system say when a server is in trouble: the OOM killer above all.
	systemTrouble = regexp.MustCompile(`(?i)out of memory|oom-kill|oom_reaper|killed process|segfault|call trace|i/o error|blocked for more than`)
	// Supervisor's own account of its programs: they died, restarted or gave up.
	supervisorEvent = regexp.MustCompile(`(?i)\b(exited|gave up|fatal|terminated|killed|crit|erro|warn|stopped)\b`)
)

// logSources are the files followed, with the source name each gets. The access logs are not
// here on purpose: they are many lines and little use stored, so they are read live (watch
// mode) and never shipped.
var logSources = []logSource{
	{glob: "/home/deploy/*/shared/storage/logs/laravel*.log", name: "laravel:%s", start: laravelEntry},
	// Supervised processes log under a root-owned folder, one per site. The old place, inside
	// the application's storage, is still read for sites not redeployed since the move.
	{glob: "/var/log/cloud-manager/*/worker.log", name: "worker:%s"},
	{glob: "/home/deploy/*/shared/storage/logs/worker.log", name: "worker:%s"},
	// What a Node or Go application, or any other supervised process, prints.
	{glob: "/var/log/cloud-manager/*/app.log", name: "app:%s"},
	{glob: "/home/deploy/*/shared/storage/logs/app.log", name: "app:%s"},
	// One error log per site (the vhost names it), and the server-wide one for the rest.
	{glob: "/var/log/nginx/*.error.log", name: "nginx.error:%s"},
	{glob: "/var/log/nginx/error.log", name: "nginx.error"},
	{glob: "/var/log/php*-fpm.log", name: "php-fpm", start: fpmEntry},
	{glob: "/var/log/supervisor/supervisord.log", name: "supervisor", keep: supervisorEvent},
	{glob: "/var/log/syslog", name: "system", keep: systemTrouble},
}

// redactions run in order over every line. Each keeps what identifies the
// secret (the key name, the scheme) and replaces the value.
var redactions = []struct {
	re   *regexp.Regexp
	with string
}{
	{regexp.MustCompile(`-----BEGIN [A-Z ]*PRIVATE KEY-----.*`), "[REDACTED PRIVATE KEY]"},
	{regexp.MustCompile(`(?i)(authorization:\s*(?:bearer|basic|token)\s+)\S+`), "${1}[REDACTED]"},
	{regexp.MustCompile(`(?i)\b(bearer\s+)[A-Za-z0-9._~+/=-]{12,}`), "${1}[REDACTED]"},
	{regexp.MustCompile(`([a-z][a-z0-9+.-]*://[^\s:/@]+:)[^\s@/]+@`), "${1}[REDACTED]@"},
	{regexp.MustCompile(`(?i)("?(?:[a-z0-9_]*(?:password|passwd|secret|token|api_?key|private_?key|access_?key)[a-z0-9_]*)"?\s*[:=]\s*"?)[^"\s,;&}]+`), "${1}[REDACTED]"},
	{regexp.MustCompile(`\b(dop_v1_|ghp_|gho_|ghs_|github_pat_|sk_live_|sk_test_|xox[abp]-)[A-Za-z0-9_-]{8,}`), "${1}[REDACTED]"},
	{regexp.MustCompile(`\b(AKIA|ASIA)[A-Z0-9]{16}\b`), "[REDACTED AWS KEY]"},
	{regexp.MustCompile(`\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}`), "[REDACTED JWT]"},
}

// redact removes what looks like a secret from one line.
func redact(line string) string {
	for _, r := range redactions {
		line = r.re.ReplaceAllString(line, r.with)
	}
	return line
}

// tailedFile is one file being followed.
type tailedFile struct {
	source string
	file   *os.File
	reader *bufio.Reader
	inode  uint64
	offset int64
	// partial holds a line read without its newline yet.
	partial string
	start   *regexp.Regexp
	keep    *regexp.Regexp
	// entry is a multi-line record still being gathered, and when it last grew.
	entry   *protocol.LogLine
	entryAt time.Time
}

// shipLogs follows the sources and sends what they gain until the session
// ends. Files found at start are read from their end, so a restart does
// not resend history; a file that appears later, or is rotated or
// truncated, is read from its start.
func (a *Agent) shipLogs(ctx context.Context, session *transport.Session) {
	t := newTailer(logSources)
	t.discover(true)
	defer t.close()

	poll := time.NewTicker(logPollInterval)
	defer poll.Stop()
	send := time.NewTicker(logSendInterval)
	defer send.Stop()
	rescan := time.NewTicker(30 * time.Second)
	defer rescan.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-rescan.C:
			t.discover(false)
		case <-poll.C:
			t.poll()
		case <-send.C:
			for {
				batch, ok := t.take()
				if !ok {
					break
				}
				env, err := protocol.NewEnvelope(protocol.TypeLogBatch, batch)
				if err != nil {
					a.log.Error("build log batch", "error", err)
					break
				}
				if err := session.Send(env); err != nil {
					if errors.Is(err, transport.ErrSessionClosed) {
						return
					}
					a.log.Warn("send logs", "error", err)
					break
				}
			}
		}
	}
}

type tailer struct {
	sources []logSource
	files   map[string]*tailedFile
	buffer  []protocol.LogLine
	dropped int
}

func newTailer(sources []logSource) *tailer {
	return &tailer{sources: sources, files: map[string]*tailedFile{}}
}

// discover opens files matching the sources that are not followed yet.
func (t *tailer) discover(atStart bool) {
	for _, s := range t.sources {
		matches, _ := filepath.Glob(s.glob)
		for _, path := range matches {
			if _, ok := t.files[path]; ok {
				continue
			}
			name := s.name
			if strings.Contains(name, "%s") {
				name = strings.Replace(name, "%s", siteOf(path), 1)
			}
			if f := openTailed(path, name, atStart); f != nil {
				f.start, f.keep = s.start, s.keep
				t.files[path] = f
			}
		}
	}
}

// siteOf takes the site from /home/deploy/<site>/..., from /var/log/cloud-manager/<site>/... (where supervised
// processes log), or from nginx's <site>.error.log.
func siteOf(path string) string {
	if strings.HasPrefix(path, "/var/log/nginx/") {
		return strings.TrimSuffix(filepath.Base(path), ".error.log")
	}
	if rest, ok := strings.CutPrefix(path, "/var/log/cloud-manager/"); ok {
		site, _, _ := strings.Cut(rest, "/")
		return site
	}
	rest := strings.TrimPrefix(path, "/home/deploy/")
	site, _, _ := strings.Cut(rest, "/")
	return site
}

func openTailed(path, source string, fromEnd bool) *tailedFile {
	f, err := os.Open(path)
	if err != nil {
		return nil
	}
	tf := &tailedFile{source: source, file: f, inode: inodeOf(f)}
	if fromEnd {
		if off, err := f.Seek(0, io.SeekEnd); err == nil {
			tf.offset = off
		}
	}
	tf.reader = bufio.NewReaderSize(f, 64*1024)
	return tf
}

func inodeOf(f *os.File) uint64 {
	st, err := f.Stat()
	if err != nil {
		return 0
	}
	if sys, ok := st.Sys().(*syscall.Stat_t); ok {
		return uint64(sys.Ino)
	}
	return 0
}

// poll reads whatever every file has gained, reopening any that was
// rotated (a new file at the path) or truncated (shorter than read so far).
func (t *tailer) poll() {
	for path, tf := range t.files {
		if st, err := os.Stat(path); err != nil {
			// Gone: a daily log rolled over, or a site was removed.
			t.flushEntry(tf)
			tf.file.Close()
			delete(t.files, path)
			continue
		} else if sys, ok := st.Sys().(*syscall.Stat_t); (ok && uint64(sys.Ino) != tf.inode) || st.Size() < tf.offset {
			t.read(tf) // whatever the old file still had
			t.flushEntry(tf)
			tf.file.Close()
			nf := openTailed(path, tf.source, false)
			if nf == nil {
				delete(t.files, path)
				continue
			}
			nf.start, nf.keep = tf.start, tf.keep
			t.files[path] = nf
			tf = nf
		}
		t.read(tf)
		// A record that has stopped growing is complete: the next entry may be a while off.
		if tf.entry != nil && time.Since(tf.entryAt) >= entryIdle {
			t.flushEntry(tf)
		}
	}
}

func (t *tailer) read(tf *tailedFile) {
	for {
		chunk, err := tf.reader.ReadString('\n')
		tf.offset += int64(len(chunk))
		if err != nil {
			// No newline yet: keep it for the next read.
			tf.partial += chunk
			return
		}
		line := strings.TrimRight(tf.partial+chunk, "\r\n")
		tf.partial = ""
		if line == "" {
			continue
		}
		t.add(tf, line)
	}
}

// add takes one line of a file: it starts, or continues, a record, or is one.
func (t *tailer) add(tf *tailedFile, line string) {
	if tf.keep != nil && !tf.keep.MatchString(line) {
		return
	}
	if tf.start == nil {
		if len(line) > logLineMax {
			line = line[:logLineMax] + " …"
		}
		t.push(tf.source, redact(line), time.Now())
		return
	}
	if tf.start.MatchString(line) || tf.entry == nil {
		t.flushEntry(tf)
		if len(line) > logEntryMax {
			line = line[:logEntryMax] + " …"
		}
		tf.entry = &protocol.LogLine{Source: tf.source, TS: time.Now().UnixMilli(), Text: redact(line)}
		tf.entryAt = time.Now()
		return
	}
	// A continuation: a stack frame, or the rest of a message. What does not fit is cut, once.
	if len(tf.entry.Text) < logEntryMax {
		tf.entry.Text += "\n" + redact(line)
		if len(tf.entry.Text) > logEntryMax {
			tf.entry.Text = tf.entry.Text[:logEntryMax] + "\n…"
		}
	}
	tf.entryAt = time.Now()
}

// flushEntry sends the record being gathered, if there is one.
func (t *tailer) flushEntry(tf *tailedFile) {
	if tf.entry == nil {
		return
	}
	t.push(tf.entry.Source, tf.entry.Text, time.UnixMilli(tf.entry.TS))
	tf.entry = nil
}

func (t *tailer) push(source, text string, at time.Time) {
	if len(t.buffer) >= logBufferMax {
		t.dropped++
		return
	}
	t.buffer = append(t.buffer, protocol.LogLine{Source: source, TS: at.UnixMilli(), Text: text})
}

// take removes up to logBatchMax lines, with the drop count since the last
// batch. ok is false when there is nothing to send.
func (t *tailer) take() (protocol.LogBatch, bool) {
	if len(t.buffer) == 0 && t.dropped == 0 {
		return protocol.LogBatch{}, false
	}
	n := min(len(t.buffer), logBatchMax)
	batch := protocol.LogBatch{Lines: append([]protocol.LogLine(nil), t.buffer[:n]...), Dropped: t.dropped}
	t.buffer = t.buffer[n:]
	t.dropped = 0
	return batch, true
}

func (t *tailer) close() {
	for _, tf := range t.files {
		t.flushEntry(tf)
		tf.file.Close()
	}
}
