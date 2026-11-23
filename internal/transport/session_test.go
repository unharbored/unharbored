package transport

import (
	"context"
	"errors"
	"net"
	"sync"
	"testing"
	"time"

	"github.com/unharbored/unharbored/internal/protocol"
)

// pair wires two sessions together over an in-memory connection and runs both
// read loops for the duration of the test.
func pair(t *testing.T, aHandler, bHandler Handler) (a, b *Session) {
	t.Helper()

	connA, connB := net.Pipe()
	a = NewSession(connA, "peer-b")
	b = NewSession(connB, "peer-a")

	ctx, cancel := context.WithCancel(context.Background())
	var wg sync.WaitGroup
	wg.Add(2)
	go func() { defer wg.Done(); _ = a.Serve(ctx, aHandler, 0) }()
	go func() { defer wg.Done(); _ = b.Serve(ctx, bHandler, 0) }()

	t.Cleanup(func() {
		cancel()
		_ = a.Close()
		_ = b.Close()
		wg.Wait()
	})
	return a, b
}

func TestRequestReply(t *testing.T) {
	// b answers every command with a success result.
	responder := HandlerFunc(func(ctx context.Context, s *Session, env *protocol.Envelope) {
		reply, err := env.Reply(protocol.TypeResult, protocol.Result{Status: protocol.StatusSucceeded})
		if err != nil {
			t.Errorf("Reply: %v", err)
			return
		}
		if err := s.Send(reply); err != nil {
			t.Errorf("Send: %v", err)
		}
	})

	a, _ := pair(t, nil, responder)

	req, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	reply, err := a.Request(ctx, req)
	if err != nil {
		t.Fatalf("Request: %v", err)
	}
	if reply.ReplyTo != req.ID {
		t.Errorf("ReplyTo = %q, want %q", reply.ReplyTo, req.ID)
	}

	var res protocol.Result
	if err := reply.DecodePayload(&res); err != nil {
		t.Fatalf("DecodePayload: %v", err)
	}
	if res.Status != protocol.StatusSucceeded {
		t.Errorf("Status = %q, want succeeded", res.Status)
	}
}

// Ping is answered by the session itself so handlers never see it.
func TestPingIsAnsweredBySession(t *testing.T) {
	handlerSawPing := false
	h := HandlerFunc(func(ctx context.Context, s *Session, env *protocol.Envelope) {
		if env.Type == protocol.TypePing {
			handlerSawPing = true
		}
	})

	a, _ := pair(t, nil, h)

	ping, err := protocol.NewEnvelope(protocol.TypePing, nil)
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	reply, err := a.Request(ctx, ping)
	if err != nil {
		t.Fatalf("Request: %v", err)
	}
	if reply.Type != protocol.TypePong {
		t.Errorf("Type = %q, want pong", reply.Type)
	}
	if handlerSawPing {
		t.Error("handler was bothered with a ping")
	}
}

func TestRequestRespectsContextTimeout(t *testing.T) {
	// A handler that never replies.
	a, _ := pair(t, nil, HandlerFunc(func(context.Context, *Session, *protocol.Envelope) {}))

	req, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 100*time.Millisecond)
	defer cancel()

	if _, err := a.Request(ctx, req); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("err = %v, want DeadlineExceeded", err)
	}
}

// A request that times out must not leave its reply channel registered, or a
// long-lived session leaks a little memory on every timeout.
func TestTimedOutRequestIsDeregistered(t *testing.T) {
	a, _ := pair(t, nil, HandlerFunc(func(context.Context, *Session, *protocol.Envelope) {}))

	for range 5 {
		req, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
		if err != nil {
			t.Fatalf("NewEnvelope: %v", err)
		}
		ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
		_, _ = a.Request(ctx, req)
		cancel()
	}

	a.mu.Lock()
	n := len(a.pending)
	a.mu.Unlock()
	if n != 0 {
		t.Errorf("len(pending) = %d after timeouts, want 0", n)
	}
}

// A late reply to an abandoned request must be dropped, not block the read
// loop. If it blocked, the session would wedge after the first timeout.
func TestLateReplyDoesNotWedgeSession(t *testing.T) {
	var (
		mu      sync.Mutex
		capture *protocol.Envelope
	)
	slow := HandlerFunc(func(ctx context.Context, s *Session, env *protocol.Envelope) {
		mu.Lock()
		capture = env
		mu.Unlock()
	})

	a, b := pair(t, nil, slow)

	req, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	_, _ = a.Request(ctx, req)
	cancel()

	// Now send the reply nobody is waiting for.
	mu.Lock()
	orig := capture
	mu.Unlock()
	if orig == nil {
		t.Fatal("handler never saw the request")
	}
	late, err := orig.Reply(protocol.TypeResult, protocol.Result{Status: protocol.StatusSucceeded})
	if err != nil {
		t.Fatalf("Reply: %v", err)
	}
	if err := b.Send(late); err != nil {
		t.Fatalf("Send late reply: %v", err)
	}

	// The session must still work. A fresh ping proves the read loop lives.
	ping, _ := protocol.NewEnvelope(protocol.TypePing, nil)
	ctx2, cancel2 := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel2()
	if _, err := a.Request(ctx2, ping); err != nil {
		t.Fatalf("session wedged after a late reply: %v", err)
	}
}

func TestSendAfterCloseFails(t *testing.T) {
	a, _ := pair(t, nil, nil)
	if err := a.Close(); err != nil {
		t.Fatalf("Close: %v", err)
	}

	env, _ := protocol.NewEnvelope(protocol.TypePing, nil)
	if err := a.Send(env); !errors.Is(err, ErrSessionClosed) {
		t.Fatalf("err = %v, want ErrSessionClosed", err)
	}
	if _, err := a.Request(context.Background(), env); !errors.Is(err, ErrSessionClosed) {
		t.Fatalf("Request err = %v, want ErrSessionClosed", err)
	}
}

// Closing the session must wake anyone blocked on a reply rather than leaving
// them to wait out their context.
func TestCloseWakesPendingRequest(t *testing.T) {
	a, _ := pair(t, nil, HandlerFunc(func(context.Context, *Session, *protocol.Envelope) {}))

	req, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}

	errCh := make(chan error, 1)
	go func() {
		_, err := a.Request(context.Background(), req)
		errCh <- err
	}()

	// Give the request time to register before closing.
	time.Sleep(50 * time.Millisecond)
	_ = a.Close()

	select {
	case err := <-errCh:
		if err == nil {
			t.Fatal("Request returned nil error after Close")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("Request did not return after Close")
	}
}

func TestContextCancellationEndsServe(t *testing.T) {
	connA, connB := net.Pipe()
	t.Cleanup(func() { _ = connB.Close() })

	s := NewSession(connA, "peer")
	ctx, cancel := context.WithCancel(context.Background())

	done := make(chan error, 1)
	go func() { done <- s.Serve(ctx, nil, 0) }()

	cancel()

	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) {
			t.Fatalf("err = %v, want context.Canceled", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("Serve did not return after context cancellation")
	}
}

func TestServeReturnsOnPeerClose(t *testing.T) {
	connA, connB := net.Pipe()
	s := NewSession(connA, "peer")

	done := make(chan error, 1)
	go func() { done <- s.Serve(context.Background(), nil, 0) }()

	_ = connB.Close()

	select {
	case err := <-done:
		// A peer closing a net.Pipe surfaces as io.ErrClosedPipe rather than
		// a clean EOF, so accept either; what matters is that Serve returns.
		_ = err
	case <-time.After(2 * time.Second):
		t.Fatal("Serve did not return after peer closed")
	}
}

// A read deadline is what detects a connection that has been silently
// black-holed, which otherwise looks healthy forever.
func TestReadTimeoutEndsSession(t *testing.T) {
	connA, connB := net.Pipe()
	t.Cleanup(func() { _ = connB.Close() })

	s := NewSession(connA, "peer")
	done := make(chan error, 1)
	go func() { done <- s.Serve(context.Background(), nil, 100*time.Millisecond) }()

	select {
	case err := <-done:
		if !errors.Is(err, ErrHeartbeatTimeout) {
			t.Fatalf("err = %v, want ErrHeartbeatTimeout", err)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("Serve did not time out on an idle connection")
	}
}

func TestHeartbeatKeepsSessionAlive(t *testing.T) {
	a, _ := pair(t, nil, nil)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go a.Heartbeat(ctx, 20*time.Millisecond, time.Second)

	time.Sleep(200 * time.Millisecond)

	select {
	case <-a.Done():
		t.Fatalf("session ended while heartbeating: %v", a.Err())
	default:
	}
}

// If the peer stops answering, the heartbeat must end the session so the
// agent reconnects instead of sitting on a dead socket.
func TestHeartbeatClosesSessionWhenPeerStopsAnswering(t *testing.T) {
	connA, connB := net.Pipe()

	a := NewSession(connA, "peer")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go func() { _ = a.Serve(ctx, nil, 0) }()

	// The far end reads frames and never answers, so pings go unanswered
	// without the write side blocking.
	go func() {
		buf := make([]byte, 4096)
		for {
			if _, err := connB.Read(buf); err != nil {
				return
			}
		}
	}()

	go a.Heartbeat(ctx, 20*time.Millisecond, 100*time.Millisecond)

	select {
	case <-a.Done():
		if !errors.Is(a.Err(), ErrHeartbeatTimeout) {
			t.Fatalf("Err = %v, want ErrHeartbeatTimeout", a.Err())
		}
	case <-time.After(3 * time.Second):
		t.Fatal("heartbeat did not close an unanswered session")
	}
	_ = connB.Close()
}

func TestConcurrentRequests(t *testing.T) {
	responder := HandlerFunc(func(ctx context.Context, s *Session, env *protocol.Envelope) {
		// Reply from a goroutine, as a real handler would for slow work.
		go func() {
			reply, err := env.Reply(protocol.TypeResult, protocol.Result{Status: protocol.StatusSucceeded})
			if err != nil {
				return
			}
			_ = s.Send(reply)
		}()
	})

	a, _ := pair(t, nil, responder)

	const n = 32
	var wg sync.WaitGroup
	errs := make(chan error, n)
	for range n {
		wg.Add(1)
		go func() {
			defer wg.Done()
			req, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
			if err != nil {
				errs <- err
				return
			}
			ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
			defer cancel()
			reply, err := a.Request(ctx, req)
			if err != nil {
				errs <- err
				return
			}
			if reply.ReplyTo != req.ID {
				errs <- errors.New("reply correlated to the wrong request")
			}
		}()
	}
	wg.Wait()
	close(errs)

	for err := range errs {
		t.Errorf("concurrent request: %v", err)
	}
}

func TestDuplicateRequestIDRejected(t *testing.T) {
	a, _ := pair(t, nil, HandlerFunc(func(context.Context, *Session, *protocol.Envelope) {}))

	req, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}

	started := make(chan struct{})
	go func() {
		close(started)
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_, _ = a.Request(ctx, req)
	}()
	<-started
	time.Sleep(50 * time.Millisecond)

	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	if _, err := a.Request(ctx, req); !errors.Is(err, ErrDuplicateRequestID) {
		t.Fatalf("err = %v, want ErrDuplicateRequestID", err)
	}
}

func TestPeerID(t *testing.T) {
	a, b := pair(t, nil, nil)
	if a.PeerID() != "peer-b" {
		t.Errorf("a.PeerID() = %q, want peer-b", a.PeerID())
	}
	if b.PeerID() != "peer-a" {
		t.Errorf("b.PeerID() = %q, want peer-a", b.PeerID())
	}
}

func TestCloseIsIdempotent(t *testing.T) {
	a, _ := pair(t, nil, nil)
	_ = a.Close()
	_ = a.Close()
	_ = a.Close()
	select {
	case <-a.Done():
	default:
		t.Fatal("Done not closed")
	}
}

// ---- subscriptions ----

// A command produces an ack, progress messages and a result, all correlated
// to one ID. Request consumes a single reply, so Subscribe is what keeps the
// rest of that conversation.
func TestSubscribeReceivesEveryCorrelatedMessage(t *testing.T) {
	responder := HandlerFunc(func(ctx context.Context, s *Session, env *protocol.Envelope) {
		go func() {
			ack, _ := env.Reply(protocol.TypeCommandAck, protocol.CommandAck{Accepted: true})
			_ = s.Send(ack)
			for i := range 3 {
				p, _ := env.Reply(protocol.TypeProgress, protocol.Progress{Seq: i, Text: "step"})
				_ = s.Send(p)
			}
			res, _ := env.Reply(protocol.TypeResult, protocol.Result{Status: protocol.StatusSucceeded})
			_ = s.Send(res)
		}()
	})

	a, _ := pair(t, nil, responder)

	env, err := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}

	replies, cancel := a.Subscribe(env.ID)
	defer cancel()

	if err := a.Send(env); err != nil {
		t.Fatalf("Send: %v", err)
	}

	var types []protocol.Type
	deadline := time.After(5 * time.Second)
	for {
		select {
		case reply := <-replies:
			types = append(types, reply.Type)
			if reply.Type == protocol.TypeResult {
				want := []protocol.Type{
					protocol.TypeCommandAck,
					protocol.TypeProgress, protocol.TypeProgress, protocol.TypeProgress,
					protocol.TypeResult,
				}
				if len(types) != len(want) {
					t.Fatalf("got %v, want %v", types, want)
				}
				for i := range want {
					if types[i] != want[i] {
						t.Fatalf("got %v, want %v", types, want)
					}
				}
				return
			}
		case <-deadline:
			t.Fatalf("timed out; received %v", types)
		}
	}
}

// Cancelling a subscription must stop delivery and not wedge the read loop
// when further messages arrive for that ID.
func TestCancelledSubscriptionDropsLaterMessages(t *testing.T) {
	var captured *protocol.Envelope
	var mu sync.Mutex
	h := HandlerFunc(func(ctx context.Context, s *Session, env *protocol.Envelope) {
		mu.Lock()
		captured = env
		mu.Unlock()
	})

	a, b := pair(t, nil, h)

	env, _ := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	_, cancel := a.Subscribe(env.ID)
	if err := a.Send(env); err != nil {
		t.Fatalf("Send: %v", err)
	}

	time.Sleep(100 * time.Millisecond)
	cancel()

	mu.Lock()
	orig := captured
	mu.Unlock()
	if orig == nil {
		t.Fatal("handler never saw the command")
	}
	late, _ := orig.Reply(protocol.TypeResult, protocol.Result{Status: protocol.StatusSucceeded})
	if err := b.Send(late); err != nil {
		t.Fatalf("Send late: %v", err)
	}

	// The session must still be usable.
	ping, _ := protocol.NewEnvelope(protocol.TypePing, nil)
	ctx, cancelCtx := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancelCtx()
	if _, err := a.Request(ctx, ping); err != nil {
		t.Fatalf("session wedged after a cancelled subscription: %v", err)
	}
}

// A slow consumer must not stall the read loop, because that would stall
// heartbeats and every other message on the connection. Overflow is dropped
// and counted rather than blocking.
func TestSlowSubscriberDropsRatherThanBlocking(t *testing.T) {
	a, b := pair(t, nil, nil)

	env, _ := protocol.NewEnvelope(protocol.TypeCommand, protocol.Command{Kind: protocol.CommandExec})
	_, cancel := a.Subscribe(env.ID)
	defer cancel()

	// Flood well past the buffer without ever reading.
	total := subscriptionBuffer + 50
	for i := range total {
		p, _ := env.Reply(protocol.TypeProgress, protocol.Progress{Seq: i})
		if err := b.Send(p); err != nil {
			t.Fatalf("Send %d: %v", i, err)
		}
	}

	// The read loop must still be alive.
	ping, _ := protocol.NewEnvelope(protocol.TypePing, nil)
	ctx, cancelCtx := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancelCtx()
	if _, err := a.Request(ctx, ping); err != nil {
		t.Fatalf("read loop stalled on a slow subscriber: %v", err)
	}

	if drops := a.SubscriptionDrops(env.ID); drops == 0 {
		t.Error("overflow was not counted; a truncated log would look complete")
	}
}
