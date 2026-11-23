package transport

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"sync"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
)

// Handler processes envelopes that are not replies to an outstanding request.
//
// Handle must not block. The session dispatches from its single read loop, so
// a slow handler stalls heartbeats and every other inbound message on the
// connection. Handlers that do real work must hand off to a goroutine and
// report completion by sending a Result later.
type Handler interface {
	Handle(ctx context.Context, s *Session, env *protocol.Envelope)
}

// HandlerFunc adapts a function to Handler.
type HandlerFunc func(ctx context.Context, s *Session, env *protocol.Envelope)

// Handle implements Handler.
func (f HandlerFunc) Handle(ctx context.Context, s *Session, env *protocol.Envelope) {
	f(ctx, s, env)
}

var (
	// ErrSessionClosed is returned once a session is no longer usable.
	ErrSessionClosed = errors.New("transport: session closed")
	// ErrHeartbeatTimeout is returned when the peer stops answering pings.
	ErrHeartbeatTimeout = errors.New("transport: heartbeat timed out")
	// ErrDuplicateRequestID indicates a message ID collision, which should be
	// impossible with 128 bits of entropy and therefore means a bug.
	ErrDuplicateRequestID = errors.New("transport: duplicate request id")
)

// Session is one multiplexed connection. It is safe for concurrent use.
type Session struct {
	conn   net.Conn
	r      *protocol.Reader
	w      *protocol.Writer
	peerID string

	mu      sync.Mutex
	pending map[string]chan *protocol.Envelope
	subs    map[string]*subscription
	closed  bool

	closeOnce sync.Once
	done      chan struct{}
	// closeErr records why the session ended, so Wait can report something
	// more useful than "closed".
	closeErr error
}

// NewSession wraps a connection. peerID is the authenticated identity of the
// far end: the node ID for a control plane session, or the control plane's
// name for an agent session.
func NewSession(conn net.Conn, peerID string) *Session {
	return &Session{
		conn:    conn,
		r:       protocol.NewReader(conn),
		w:       protocol.NewWriter(conn),
		peerID:  peerID,
		pending: make(map[string]chan *protocol.Envelope),
		subs:    make(map[string]*subscription),
		done:    make(chan struct{}),
	}
}

// subscription receives every message correlated to one ID.
type subscription struct {
	ch chan *protocol.Envelope
	// dropped counts messages discarded because the consumer fell behind.
	// Reporting the number is what keeps a truncated deploy log from being
	// mistaken for a short one.
	dropped int
}

// subscriptionBuffer sizes a subscription channel. Deep enough that a
// consumer briefly busy writing to a database does not lose progress lines.
const subscriptionBuffer = 256

// Subscribe registers for every message whose ReplyTo matches id, until the
// returned cancel function is called.
//
// Request is not sufficient for a command. A command produces an ack, then
// any number of progress messages, then a result, all under the same
// correlation ID, and Request consumes exactly one reply and deregisters.
// Subscribe is what keeps the rest of that conversation.
func (s *Session) Subscribe(id string) (<-chan *protocol.Envelope, func()) {
	sub := &subscription{ch: make(chan *protocol.Envelope, subscriptionBuffer)}

	s.mu.Lock()
	s.subs[id] = sub
	s.mu.Unlock()

	var once sync.Once
	cancel := func() {
		once.Do(func() {
			s.mu.Lock()
			delete(s.subs, id)
			s.mu.Unlock()
		})
	}
	return sub.ch, cancel
}

// SubscriptionDrops reports how many messages a subscription discarded.
func (s *Session) SubscriptionDrops(id string) int {
	s.mu.Lock()
	defer s.mu.Unlock()
	if sub, ok := s.subs[id]; ok {
		return sub.dropped
	}
	return 0
}

// PeerID returns the authenticated identity of the far end.
func (s *Session) PeerID() string { return s.peerID }

// RemoteAddr returns the peer address.
func (s *Session) RemoteAddr() net.Addr { return s.conn.RemoteAddr() }

// Done is closed when the session ends.
func (s *Session) Done() <-chan struct{} { return s.done }

// Err returns why the session ended, or nil while it is still running.
func (s *Session) Err() error {
	select {
	case <-s.done:
		s.mu.Lock()
		defer s.mu.Unlock()
		return s.closeErr
	default:
		return nil
	}
}

// Send writes an envelope. It does not wait for a reply.
func (s *Session) Send(env *protocol.Envelope) error {
	s.mu.Lock()
	closed := s.closed
	s.mu.Unlock()
	if closed {
		return ErrSessionClosed
	}
	return s.w.Write(env)
}

// Request sends an envelope and waits for the reply that carries its ID in
// ReplyTo. The context bounds the wait.
func (s *Session) Request(ctx context.Context, env *protocol.Envelope) (*protocol.Envelope, error) {
	ch := make(chan *protocol.Envelope, 1)

	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return nil, ErrSessionClosed
	}
	if _, exists := s.pending[env.ID]; exists {
		s.mu.Unlock()
		return nil, ErrDuplicateRequestID
	}
	s.pending[env.ID] = ch
	s.mu.Unlock()

	// Always clear the registration, including on the error paths, or a
	// timed-out request leaks a channel for the life of the session.
	defer func() {
		s.mu.Lock()
		delete(s.pending, env.ID)
		s.mu.Unlock()
	}()

	if err := s.w.Write(env); err != nil {
		return nil, err
	}

	select {
	case reply := <-ch:
		return reply, nil
	case <-ctx.Done():
		return nil, ctx.Err()
	case <-s.done:
		if err := s.Err(); err != nil {
			return nil, err
		}
		return nil, ErrSessionClosed
	}
}

// Serve runs the read loop until the connection ends or ctx is cancelled.
//
// readTimeout bounds how long the session will wait for any inbound frame. It
// must exceed the heartbeat interval, since a Ping from the peer is what
// resets it on an otherwise idle connection. Zero disables the timeout, which
// is only appropriate in tests: a connection that is silently black-holed by
// a middlebox looks healthy forever without it.
func (s *Session) Serve(ctx context.Context, h Handler, readTimeout time.Duration) error {
	// Cancelling the context must interrupt a blocking read, and the only
	// portable way to do that is to close the connection underneath it.
	stop := context.AfterFunc(ctx, func() {
		s.closeWith(ctx.Err())
	})
	defer stop()

	for {
		if readTimeout > 0 {
			if err := s.conn.SetReadDeadline(time.Now().Add(readTimeout)); err != nil {
				return s.closeWith(fmt.Errorf("transport: set read deadline: %w", err))
			}
		}

		env, err := s.r.Read()
		if err != nil {
			if errors.Is(err, io.EOF) {
				return s.closeWith(nil)
			}
			if ctxErr := ctx.Err(); ctxErr != nil {
				return s.closeWith(ctxErr)
			}
			var netErr net.Error
			if errors.As(err, &netErr) && netErr.Timeout() {
				return s.closeWith(ErrHeartbeatTimeout)
			}
			return s.closeWith(err)
		}

		// Replies go to whoever registered for them. Everything else goes to
		// the handler.
		if env.ReplyTo != "" {
			s.mu.Lock()
			sub, isSub := s.subs[env.ReplyTo]
			ch, isPending := s.pending[env.ReplyTo]
			s.mu.Unlock()

			if isSub {
				// Never block the read loop on a slow consumer. Dropping a
				// progress line is survivable; stalling every other message
				// on the connection, including heartbeats, is not.
				select {
				case sub.ch <- env:
				default:
					s.mu.Lock()
					sub.dropped++
					s.mu.Unlock()
				}
				continue
			}
			if isPending {
				// Buffered with capacity 1 and registered once, so this
				// never blocks.
				ch <- env
				continue
			}
			// A reply to a request that already timed out. Dropping it is
			// correct; nobody is waiting.
			continue
		}

		// Answer pings here rather than burdening every handler with it.
		if env.Type == protocol.TypePing {
			pong, err := env.Reply(protocol.TypePong, nil)
			if err == nil {
				_ = s.Send(pong)
			}
			continue
		}

		if h != nil {
			h.Handle(ctx, s, env)
		}
	}
}

// Heartbeat pings the peer on an interval and ends the session if a ping goes
// unanswered. Run it alongside Serve.
func (s *Session) Heartbeat(ctx context.Context, interval, timeout time.Duration) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-s.done:
			return
		case <-ticker.C:
			ping, err := protocol.NewEnvelope(protocol.TypePing, nil)
			if err != nil {
				continue
			}
			pingCtx, cancel := context.WithTimeout(ctx, timeout)
			_, err = s.Request(pingCtx, ping)
			cancel()
			if err != nil {
				if errors.Is(err, ErrSessionClosed) || ctx.Err() != nil {
					return
				}
				s.closeWith(ErrHeartbeatTimeout)
				return
			}
		}
	}
}

// Close ends the session.
func (s *Session) Close() error {
	return s.closeWith(nil)
}

func (s *Session) closeWith(cause error) error {
	s.closeOnce.Do(func() {
		s.mu.Lock()
		s.closed = true
		s.closeErr = cause
		// Wake everyone waiting on a reply. Request selects on s.done;
		// subscribers select on it too, so the channels are simply dropped
		// rather than closed, which avoids a send on a closed channel from
		// the read loop.
		s.pending = make(map[string]chan *protocol.Envelope)
		s.subs = make(map[string]*subscription)
		s.mu.Unlock()

		_ = s.conn.Close()
		close(s.done)
	})
	return cause
}
