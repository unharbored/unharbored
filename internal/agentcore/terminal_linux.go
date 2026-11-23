//go:build linux

package agentcore

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"strconv"
	"sync"
	"syscall"
	"time"
	"unsafe"
)

// ptyShell is a shell on a pseudo-terminal. The agent has no dependencies, so
// the terminal is made with the system calls directly.
type ptyShell struct {
	master *os.File
	cmd    *exec.Cmd

	mu       sync.Mutex
	last     time.Time
	closed   bool
	stopIdle chan struct{}
}

func ioctl(f *os.File, req uintptr, arg unsafe.Pointer) error {
	if _, _, e := syscall.Syscall(syscall.SYS_IOCTL, f.Fd(), req, uintptr(arg)); e != 0 {
		return e
	}
	return nil
}

type winsize struct{ rows, cols, x, y uint16 }

// startShell runs the user's login shell on a new terminal. It returns the
// shell, a reader for its output, and a wait that gives the exit code.
func startShell(username, dir string, cols, rows int) (shell, func([]byte) (int, error), func() int, error) {
	if username == "" {
		username = "deploy"
	}
	u, err := user.Lookup(username)
	if err != nil {
		return nil, nil, nil, fmt.Errorf("there is no user %q on this server", username)
	}
	uid, _ := strconv.Atoi(u.Uid)
	gid, _ := strconv.Atoi(u.Gid)

	master, err := os.OpenFile("/dev/ptmx", os.O_RDWR, 0)
	if err != nil {
		return nil, nil, nil, fmt.Errorf("open a terminal: %w", err)
	}
	var n uint32
	if err := ioctl(master, syscall.TIOCGPTN, unsafe.Pointer(&n)); err != nil {
		master.Close()
		return nil, nil, nil, err
	}
	var unlock int32
	if err := ioctl(master, syscall.TIOCSPTLCK, unsafe.Pointer(&unlock)); err != nil {
		master.Close()
		return nil, nil, nil, err
	}
	slavePath := "/dev/pts/" + strconv.Itoa(int(n))
	// The slave belongs to the user the shell runs as.
	_ = os.Chown(slavePath, uid, gid)
	slave, err := os.OpenFile(slavePath, os.O_RDWR|syscall.O_NOCTTY, 0)
	if err != nil {
		master.Close()
		return nil, nil, nil, err
	}
	defer slave.Close()

	sh := u.HomeDir
	cmdPath := "/bin/bash"
	if _, err := os.Stat(cmdPath); err != nil {
		cmdPath = "/bin/sh"
	}
	if dir == "" {
		dir = sh
	}

	cmd := exec.Command(cmdPath, "-l")
	cmd.Dir = dir
	cmd.Env = []string{
		"TERM=xterm-256color",
		"HOME=" + u.HomeDir,
		"USER=" + username,
		"LOGNAME=" + username,
		"LANG=C.UTF-8",
		"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
	}
	cmd.Stdin, cmd.Stdout, cmd.Stderr = slave, slave, slave
	cmd.SysProcAttr = &syscall.SysProcAttr{
		Setsid:     true,
		Setctty:    true,
		Ctty:       0,
		Credential: &syscall.Credential{Uid: uint32(uid), Gid: uint32(gid)},
	}
	if err := cmd.Start(); err != nil {
		master.Close()
		return nil, nil, nil, fmt.Errorf("start the shell: %w", err)
	}

	s := &ptyShell{master: master, cmd: cmd, last: time.Now(), stopIdle: make(chan struct{})}
	s.resize(cols, rows)
	go s.watchIdle()

	read := func(p []byte) (int, error) { return master.Read(p) }
	wait := func() int {
		err := cmd.Wait()
		var ee *exec.ExitError
		if errors.As(err, &ee) {
			return ee.ExitCode()
		}
		if err != nil {
			return 1
		}
		return 0
	}

	return s, read, wait, nil
}

func (s *ptyShell) write(p []byte) error {
	s.mu.Lock()
	s.last = time.Now()
	s.mu.Unlock()
	_, err := s.master.Write(p)
	return err
}

func (s *ptyShell) resize(cols, rows int) {
	if cols < 1 || rows < 1 || cols > 1000 || rows > 1000 {
		return
	}
	ws := winsize{rows: uint16(rows), cols: uint16(cols)}
	_ = ioctl(s.master, syscall.TIOCSWINSZ, unsafe.Pointer(&ws))
}

// watchIdle ends the shell when nobody has typed for idleLimit.
func (s *ptyShell) watchIdle() {
	t := time.NewTicker(time.Minute)
	defer t.Stop()
	for {
		select {
		case <-s.stopIdle:
			return
		case <-t.C:
			s.mu.Lock()
			idle := time.Since(s.last) > idleLimit
			s.mu.Unlock()
			if idle {
				s.close()
				return
			}
		}
	}
}

func (s *ptyShell) close() {
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return
	}
	s.closed = true
	close(s.stopIdle)
	s.mu.Unlock()

	// Hang up the terminal, as closing a window does, then make sure.
	if s.cmd.Process != nil {
		_ = syscall.Kill(-s.cmd.Process.Pid, syscall.SIGHUP)
		time.AfterFunc(3*time.Second, func() { _ = syscall.Kill(-s.cmd.Process.Pid, syscall.SIGKILL) })
	}
	_ = s.master.Close()
}
