package agentcore

import (
	"encoding/base64"
	"sync"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
	"github.com/unharbored/unharbored/internal/transport"
)

// idleLimit ends a shell nobody has typed in for this long, so a closed
// browser tab does not leave a shell running on the node.
const idleLimit = 15 * time.Minute

// shell is one running interactive shell; see terminal_linux.go.
type shell interface {
	write(p []byte) error
	resize(cols, rows int)
	// close ends the shell and releases it.
	close()
}

// terminals holds the shells open on this node.
type terminals struct {
	mu     sync.Mutex
	shells map[string]shell
}

func newTerminals() *terminals { return &terminals{shells: map[string]shell{}} }

// maxShells bounds what one node will hold open.
const maxShells = 8

// terminal handles one instruction. It runs on the read loop, so nothing here
// may block: opening starts a goroutine that reads the shell.
func (a *Agent) terminal(session *transport.Session, env *protocol.Envelope) {
	var in protocol.Terminal
	if err := env.DecodePayload(&in); err != nil || in.Session == "" {
		return
	}

	out := func(o protocol.TerminalOut) {
		o.Session = in.Session
		e, err := protocol.NewEnvelope(protocol.TypeTerminalOut, o)
		if err == nil {
			_ = session.Send(e)
		}
	}

	t := a.terms
	switch in.Op {
	case protocol.TerminalOpen:
		t.mu.Lock()
		_, exists := t.shells[in.Session]
		full := len(t.shells) >= maxShells
		t.mu.Unlock()
		if exists {
			return
		}
		if full {
			out(protocol.TerminalOut{Error: "this server already has the most consoles it allows open"})
			return
		}
		go a.openShell(in, out)

	case protocol.TerminalInput:
		data, err := base64.StdEncoding.DecodeString(in.Data)
		if sh := t.get(in.Session); sh != nil && err == nil {
			_ = sh.write(data)
		}

	case protocol.TerminalResize:
		if sh := t.get(in.Session); sh != nil {
			sh.resize(in.Cols, in.Rows)
		}

	case protocol.TerminalClose:
		if sh := t.take(in.Session); sh != nil {
			sh.close()
		}
	}
}

func (t *terminals) get(id string) shell {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.shells[id]
}

func (t *terminals) take(id string) shell {
	t.mu.Lock()
	defer t.mu.Unlock()
	sh := t.shells[id]
	delete(t.shells, id)
	return sh
}

// closeAll ends every shell, when the connection to the control plane goes:
// nobody can see them any more.
func (t *terminals) closeAll() {
	t.mu.Lock()
	all := t.shells
	t.shells = map[string]shell{}
	t.mu.Unlock()
	for _, sh := range all {
		sh.close()
	}
}

// openShell starts the shell and pumps its output until it ends.
func (a *Agent) openShell(in protocol.Terminal, out func(protocol.TerminalOut)) {
	sh, read, wait, err := startShell(in.User, in.Dir, in.Cols, in.Rows)
	if err != nil {
		out(protocol.TerminalOut{Error: err.Error()})
		return
	}

	t := a.terms
	t.mu.Lock()
	t.shells[in.Session] = sh
	t.mu.Unlock()

	seq := 0
	buf := make([]byte, 16*1024)
	for {
		n, err := read(buf)
		if n > 0 {
			seq++
			out(protocol.TerminalOut{Seq: seq, Data: base64.StdEncoding.EncodeToString(buf[:n])})
		}
		if err != nil {
			break
		}
	}

	code := wait()
	if t.take(in.Session) != nil {
		sh.close()
	}
	out(protocol.TerminalOut{Seq: seq + 1, Exit: &code})
}
