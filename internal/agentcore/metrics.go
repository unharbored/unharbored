package agentcore

import (
	"bufio"
	"context"
	"errors"
	"os"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/transport"
)

// MetricsInterval is how often a host sample is sent. A minute is enough to
// see a disk filling or a server pinned at full CPU, and costs one small
// frame per server per minute.
const MetricsInterval = time.Minute

// metricsMounts are the filesystems reported. / holds sites, releases and
// databases on every server the platform makes.
var metricsMounts = []string{"/"}

// cpuTimes is one reading of the aggregate line of /proc/stat.
type cpuTimes struct{ idle, total uint64 }

// sendMetrics sends a host sample every interval until the session ends.
// The first sample waits one interval so CPU has two readings to compare.
func (a *Agent) sendMetrics(ctx context.Context, session *transport.Session, interval time.Duration) {
	prev, err := readCPU("/proc/stat")
	if err != nil {
		a.log.Warn("metrics unavailable", "error", err)
		return
	}
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		now, err := readCPU("/proc/stat")
		if err != nil {
			a.log.Warn("read cpu", "error", err)
			continue
		}
		sample := collectSample("/proc", metricsMounts)
		sample.CPUPercent = cpuPercent(prev, now)
		prev = now

		env, err := protocol.NewEnvelope(protocol.TypeMetrics, sample)
		if err != nil {
			a.log.Error("build metrics", "error", err)
			continue
		}
		if err := session.Send(env); err != nil {
			if errors.Is(err, transport.ErrSessionClosed) {
				return
			}
			a.log.Warn("send metrics", "error", err)
		}
	}
}

// collectSample reads everything but CPU, which needs two readings.
// Anything unreadable is left at zero rather than failing the sample.
func collectSample(proc string, mounts []string) protocol.MetricSample {
	s := protocol.MetricSample{CollectedAt: time.Now().UnixMilli()}

	if b, err := os.ReadFile(proc + "/loadavg"); err == nil {
		f := strings.Fields(string(b))
		if len(f) >= 3 {
			s.Load1, _ = strconv.ParseFloat(f[0], 64)
			s.Load5, _ = strconv.ParseFloat(f[1], 64)
			s.Load15, _ = strconv.ParseFloat(f[2], 64)
		}
	}

	if mem, err := readMeminfo(proc + "/meminfo"); err == nil {
		s.MemTotal = mem["MemTotal"]
		s.MemAvailable = mem["MemAvailable"]
		s.SwapTotal = mem["SwapTotal"]
		s.SwapUsed = mem["SwapTotal"] - mem["SwapFree"]
	}

	if b, err := os.ReadFile(proc + "/uptime"); err == nil {
		if f := strings.Fields(string(b)); len(f) > 0 {
			up, _ := strconv.ParseFloat(f[0], 64)
			s.UptimeSeconds = uint64(up)
		}
	}

	for _, m := range mounts {
		var st syscall.Statfs_t
		if err := syscall.Statfs(m, &st); err != nil {
			continue
		}
		if s.Disks == nil {
			s.Disks = map[string]protocol.DiskUsage{}
		}
		bs := uint64(st.Bsize)
		// Bavail, not Bfree: what an unprivileged process can still write,
		// which is what runs out first for PHP and the database.
		s.Disks[m] = protocol.DiskUsage{Total: st.Blocks * bs, Free: st.Bavail * bs}
	}

	return s
}

// readMeminfo returns /proc/meminfo's fields in bytes.
func readMeminfo(path string) (map[string]uint64, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	out := map[string]uint64{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		name, rest, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		fields := strings.Fields(rest)
		if len(fields) == 0 {
			continue
		}
		v, err := strconv.ParseUint(fields[0], 10, 64)
		if err != nil {
			continue
		}
		if len(fields) > 1 && fields[1] == "kB" {
			v *= 1024
		}
		out[name] = v
	}
	return out, sc.Err()
}

// readCPU reads the aggregate "cpu" line: idle is idle plus iowait.
func readCPU(path string) (cpuTimes, error) {
	f, err := os.Open(path)
	if err != nil {
		return cpuTimes{}, err
	}
	defer f.Close()

	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 5 || fields[0] != "cpu" {
			continue
		}
		var t cpuTimes
		for i, v := range fields[1:] {
			n, err := strconv.ParseUint(v, 10, 64)
			if err != nil {
				return cpuTimes{}, err
			}
			t.total += n
			if i == 3 || i == 4 { // idle, iowait
				t.idle += n
			}
		}
		return t, nil
	}
	if err := sc.Err(); err != nil {
		return cpuTimes{}, err
	}
	return cpuTimes{}, errors.New("agentcore: no cpu line in /proc/stat")
}

// cpuPercent is the busy share of the time between two readings, 0 to 100.
func cpuPercent(prev, now cpuTimes) float64 {
	total := float64(now.total - prev.total)
	if now.total <= prev.total || total == 0 {
		return 0
	}
	idle := float64(now.idle - prev.idle)
	return 100 * (total - idle) / total
}
