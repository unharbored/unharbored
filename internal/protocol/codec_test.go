package protocol

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"strings"
	"sync"
	"testing"
)

func TestRoundTrip(t *testing.T) {
	var buf bytes.Buffer
	w := NewWriter(&buf)

	sent, err := NewEnvelope(TypeHello, Hello{
		NodeID:       "node-abc",
		AgentVersion: "0.1.0",
		OS:           "linux",
		Arch:         "amd64",
		Hostname:     "web-1",
	})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}
	if err := w.Write(sent); err != nil {
		t.Fatalf("Write: %v", err)
	}

	got, err := NewReader(&buf).Read()
	if err != nil {
		t.Fatalf("Read: %v", err)
	}

	if got.ID != sent.ID {
		t.Errorf("ID = %q, want %q", got.ID, sent.ID)
	}
	if got.Type != TypeHello {
		t.Errorf("Type = %q, want %q", got.Type, TypeHello)
	}
	if got.V != Version {
		t.Errorf("V = %d, want %d", got.V, Version)
	}

	var hello Hello
	if err := got.DecodePayload(&hello); err != nil {
		t.Fatalf("DecodePayload: %v", err)
	}
	if hello.NodeID != "node-abc" || hello.Hostname != "web-1" {
		t.Errorf("payload = %+v, want node-abc/web-1", hello)
	}
}

func TestRoundTripManyFrames(t *testing.T) {
	var buf bytes.Buffer
	w := NewWriter(&buf)

	const n = 50
	ids := make([]string, n)
	for i := range n {
		// Vary the payload size so the reader's buffer reuse is exercised in
		// both the grow and the shrink direction.
		env, err := NewEnvelope(TypeProgress, Progress{
			Stream: StreamStdout,
			Seq:    i,
			Text:   strings.Repeat("x", (i*37)%512),
		})
		if err != nil {
			t.Fatalf("NewEnvelope %d: %v", i, err)
		}
		ids[i] = env.ID
		if err := w.Write(env); err != nil {
			t.Fatalf("Write %d: %v", i, err)
		}
	}

	r := NewReader(&buf)
	for i := range n {
		got, err := r.Read()
		if err != nil {
			t.Fatalf("Read %d: %v", i, err)
		}
		if got.ID != ids[i] {
			t.Fatalf("frame %d: ID = %q, want %q", i, got.ID, ids[i])
		}
		var p Progress
		if err := got.DecodePayload(&p); err != nil {
			t.Fatalf("frame %d: DecodePayload: %v", i, err)
		}
		if p.Seq != i {
			t.Errorf("frame %d: Seq = %d, want %d", i, p.Seq, i)
		}
		if len(p.Text) != (i*37)%512 {
			t.Errorf("frame %d: len(Text) = %d, want %d", i, len(p.Text), (i*37)%512)
		}
	}

	if _, err := r.Read(); !errors.Is(err, io.EOF) {
		t.Errorf("after last frame: err = %v, want io.EOF", err)
	}
}

// Buffer reuse in Reader is the kind of optimisation that silently corrupts
// data one frame later. This pins the behaviour.
func TestReadDoesNotAliasPreviousFrame(t *testing.T) {
	var buf bytes.Buffer
	w := NewWriter(&buf)

	first, _ := NewEnvelope(TypeProgress, Progress{Stream: StreamStdout, Seq: 0, Text: strings.Repeat("A", 400)})
	second, _ := NewEnvelope(TypeProgress, Progress{Stream: StreamStdout, Seq: 1, Text: strings.Repeat("B", 400)})
	if err := w.Write(first); err != nil {
		t.Fatalf("Write first: %v", err)
	}
	if err := w.Write(second); err != nil {
		t.Fatalf("Write second: %v", err)
	}

	r := NewReader(&buf)
	env1, err := r.Read()
	if err != nil {
		t.Fatalf("Read first: %v", err)
	}
	// Hold the payload across the next Read, which reuses the buffer.
	if _, err := r.Read(); err != nil {
		t.Fatalf("Read second: %v", err)
	}

	var p Progress
	if err := env1.DecodePayload(&p); err != nil {
		t.Fatalf("DecodePayload after second Read: %v", err)
	}
	if p.Text != strings.Repeat("A", 400) {
		t.Errorf("first payload was corrupted by the second Read: Seq=%d Text starts %.10q", p.Seq, p.Text)
	}
}

func TestReadRejectsOversizedFrame(t *testing.T) {
	var buf bytes.Buffer
	var hdr [frameHeaderSize]byte
	binary.BigEndian.PutUint32(hdr[:], MaxFrameSize+1)
	buf.Write(hdr[:])
	buf.Write([]byte("{}"))

	_, err := NewReader(&buf).Read()
	if !errors.Is(err, ErrFrameTooLargeErr) {
		t.Fatalf("err = %v, want ErrFrameTooLargeErr", err)
	}
}

func TestWriteRejectsOversizedFrame(t *testing.T) {
	var buf bytes.Buffer
	env, err := NewEnvelope(TypeLogBatch, LogBatch{
		Lines: []LogLine{{Source: "test", Text: strings.Repeat("x", MaxFrameSize+1)}},
	})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}
	if err := NewWriter(&buf).Write(env); !errors.Is(err, ErrFrameTooLargeErr) {
		t.Fatalf("err = %v, want ErrFrameTooLargeErr", err)
	}
	if buf.Len() != 0 {
		t.Errorf("rejected frame wrote %d bytes, want 0", buf.Len())
	}
}

func TestReadRejectsZeroLengthFrame(t *testing.T) {
	var buf bytes.Buffer
	var hdr [frameHeaderSize]byte
	binary.BigEndian.PutUint32(hdr[:], 0)
	buf.Write(hdr[:])

	if _, err := NewReader(&buf).Read(); err == nil {
		t.Fatal("expected error for zero-length frame")
	}
}

func TestReadTruncatedBody(t *testing.T) {
	var buf bytes.Buffer
	var hdr [frameHeaderSize]byte
	binary.BigEndian.PutUint32(hdr[:], 128)
	buf.Write(hdr[:])
	buf.Write([]byte("not 128 bytes"))

	_, err := NewReader(&buf).Read()
	if !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Fatalf("err = %v, want io.ErrUnexpectedEOF", err)
	}
}

func TestReadRejectsEnvelopeWithoutTypeOrID(t *testing.T) {
	cases := map[string]string{
		"no type": `{"v":1,"id":"abc"}`,
		"no id":   `{"v":1,"type":"hello"}`,
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			var buf bytes.Buffer
			var hdr [frameHeaderSize]byte
			binary.BigEndian.PutUint32(hdr[:], uint32(len(body)))
			buf.Write(hdr[:])
			buf.WriteString(body)

			if _, err := NewReader(&buf).Read(); err == nil {
				t.Fatal("expected error")
			}
		})
	}
}

func TestReadRejectsMalformedJSON(t *testing.T) {
	body := `{"v":1,"id":"abc","type":`
	var buf bytes.Buffer
	var hdr [frameHeaderSize]byte
	binary.BigEndian.PutUint32(hdr[:], uint32(len(body)))
	buf.Write(hdr[:])
	buf.WriteString(body)

	if _, err := NewReader(&buf).Read(); err == nil {
		t.Fatal("expected error for malformed JSON")
	}
}

func TestReadCleanEOF(t *testing.T) {
	var buf bytes.Buffer
	if _, err := NewReader(&buf).Read(); !errors.Is(err, io.EOF) {
		t.Fatalf("err = %v, want io.EOF", err)
	}
}

// Writer is used concurrently by the heartbeat, progress and metrics
// goroutines. Frames must never interleave.
func TestWriterIsConcurrencySafe(t *testing.T) {
	var buf bytes.Buffer
	w := NewWriter(&buf)

	const writers = 8
	const perWriter = 25

	var wg sync.WaitGroup
	for g := range writers {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for i := range perWriter {
				env, err := NewEnvelope(TypeProgress, Progress{
					Stream: StreamStdout,
					Seq:    i,
					Text:   strings.Repeat("g", g*64),
				})
				if err != nil {
					t.Errorf("NewEnvelope: %v", err)
					return
				}
				if err := w.Write(env); err != nil {
					t.Errorf("Write: %v", err)
					return
				}
			}
		}()
	}
	wg.Wait()

	// Every frame must decode cleanly, which only holds if no two writes
	// interleaved their header and body.
	r := NewReader(&buf)
	for i := range writers * perWriter {
		if _, err := r.Read(); err != nil {
			t.Fatalf("frame %d: %v", i, err)
		}
	}
	if _, err := r.Read(); !errors.Is(err, io.EOF) {
		t.Errorf("trailing data after %d frames: %v", writers*perWriter, err)
	}
}

func TestReply(t *testing.T) {
	req, err := NewEnvelope(TypeCommand, Command{Kind: CommandExec})
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}
	reply, err := req.Reply(TypeCommandAck, CommandAck{Accepted: true})
	if err != nil {
		t.Fatalf("Reply: %v", err)
	}
	if reply.ReplyTo != req.ID {
		t.Errorf("ReplyTo = %q, want %q", reply.ReplyTo, req.ID)
	}
	if reply.ID == req.ID {
		t.Error("reply reused the request ID")
	}
}

func TestDecodePayloadOnEmpty(t *testing.T) {
	env, err := NewEnvelope(TypePing, nil)
	if err != nil {
		t.Fatalf("NewEnvelope: %v", err)
	}
	if env.Payload != nil {
		t.Errorf("Payload = %q, want nil", env.Payload)
	}
	var dst struct{}
	if err := env.DecodePayload(&dst); err == nil {
		t.Error("expected error decoding an empty payload")
	}
}

func TestNewIDIsUnique(t *testing.T) {
	seen := make(map[string]bool, 1000)
	for range 1000 {
		id, err := NewID()
		if err != nil {
			t.Fatalf("NewID: %v", err)
		}
		if len(id) != 32 {
			t.Fatalf("len(id) = %d, want 32", len(id))
		}
		if seen[id] {
			t.Fatalf("duplicate id %q", id)
		}
		seen[id] = true
	}
}

func TestEnvelopeJSONShape(t *testing.T) {
	// The control plane is a separate codebase in another language. Pin the
	// field names so a rename here fails here rather than there.
	env := &Envelope{V: 1, ID: "a", ReplyTo: "b", Type: TypePing, TS: 123}
	body, err := json.Marshal(env)
	if err != nil {
		t.Fatalf("Marshal: %v", err)
	}
	want := `{"v":1,"id":"a","reply_to":"b","type":"ping","ts":123}`
	if string(body) != want {
		t.Errorf("JSON = %s\nwant %s", body, want)
	}
}
