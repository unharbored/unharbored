package agentcore

import (
	"os"
	"path/filepath"
	"testing"
)

func TestCPUPercentIsTheBusyShareBetweenReadings(t *testing.T) {
	dir := t.TempDir()
	stat := filepath.Join(dir, "stat")
	// user nice system idle iowait irq softirq
	write(t, stat, "cpu  100 0 100 700 100 0 0\ncpu0 1 1 1 1\n")
	a, err := readCPU(stat)
	if err != nil {
		t.Fatal(err)
	}
	write(t, stat, "cpu  250 0 150 750 150 0 0\n")
	b, err := readCPU(stat)
	if err != nil {
		t.Fatal(err)
	}
	// 300 more ticks, 100 of them idle or iowait.
	if got := cpuPercent(a, b); got < 66.6 || got > 66.7 {
		t.Fatalf("cpu = %v, want 66.67", got)
	}
	if got := cpuPercent(b, a); got != 0 {
		t.Fatalf("a counter that went backwards (a reboot) reads %v, want 0", got)
	}
}

func TestSampleReadsLoadMemoryAndUptime(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, "loadavg"), "0.50 0.25 0.10 1/200 1234\n")
	write(t, filepath.Join(dir, "meminfo"), "MemTotal:        1000 kB\nMemAvailable:     250 kB\nSwapTotal:        100 kB\nSwapFree:          40 kB\n")
	write(t, filepath.Join(dir, "uptime"), "3600.42 7000.00\n")

	s := collectSample(dir, []string{dir})
	if s.Load1 != 0.5 || s.Load15 != 0.1 {
		t.Fatalf("load = %v %v", s.Load1, s.Load15)
	}
	if s.MemTotal != 1024000 || s.MemAvailable != 256000 || s.SwapUsed != 61440 {
		t.Fatalf("memory = %+v", s)
	}
	if s.UptimeSeconds != 3600 {
		t.Fatalf("uptime = %d", s.UptimeSeconds)
	}
	if d, ok := s.Disks[dir]; !ok || d.Total == 0 || d.Free > d.Total {
		t.Fatalf("disk = %+v", s.Disks)
	}
}

func write(t *testing.T, path, content string) {
	t.Helper()
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}
