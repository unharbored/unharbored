package protocol

import (
	"bufio"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"sync"
)

// MaxFrameSize bounds a single encoded message. Anything larger is a bug or
// an attack, and either way we want the connection torn down rather than the
// agent allocating unbounded memory on behalf of whoever is on the far end.
//
// Deploy logs and metrics samples are the largest legitimate payloads and sit
// three orders of magnitude below this.
const MaxFrameSize = 8 << 20 // 8 MiB

// ErrFrameTooLargeErr is returned when a frame exceeds MaxFrameSize.
var ErrFrameTooLargeErr = errors.New("protocol: frame exceeds maximum size")

// frameHeaderSize is the width of the big-endian length prefix.
const frameHeaderSize = 4

// Writer encodes envelopes onto a stream. It is safe for concurrent use;
// callers on both sides of the connection write from several goroutines
// (heartbeat, progress, metrics) and frames must not interleave.
type Writer struct {
	mu  sync.Mutex
	w   *bufio.Writer
	hdr [frameHeaderSize]byte
}

// NewWriter wraps w.
func NewWriter(w io.Writer) *Writer {
	return &Writer{w: bufio.NewWriter(w)}
}

// Write encodes and flushes one envelope.
func (fw *Writer) Write(env *Envelope) error {
	body, err := json.Marshal(env)
	if err != nil {
		return fmt.Errorf("protocol: marshal envelope: %w", err)
	}
	if len(body) > MaxFrameSize {
		return fmt.Errorf("%w: %d bytes", ErrFrameTooLargeErr, len(body))
	}

	fw.mu.Lock()
	defer fw.mu.Unlock()

	binary.BigEndian.PutUint32(fw.hdr[:], uint32(len(body)))
	if _, err := fw.w.Write(fw.hdr[:]); err != nil {
		return fmt.Errorf("protocol: write frame header: %w", err)
	}
	if _, err := fw.w.Write(body); err != nil {
		return fmt.Errorf("protocol: write frame body: %w", err)
	}
	if err := fw.w.Flush(); err != nil {
		return fmt.Errorf("protocol: flush frame: %w", err)
	}
	return nil
}

// Reader decodes envelopes from a stream. A Reader is not safe for concurrent
// use; one goroutine should own the read side of a connection.
type Reader struct {
	r   *bufio.Reader
	hdr [frameHeaderSize]byte
	// buf is reused across reads to avoid an allocation per frame. The decoded
	// Envelope does not alias it, because encoding/json copies into the
	// destination and json.RawMessage is explicitly copied below.
	buf []byte
}

// NewReader wraps r.
func NewReader(r io.Reader) *Reader {
	return &Reader{r: bufio.NewReader(r)}
}

// Read decodes the next envelope. It returns io.EOF when the stream ends
// cleanly between frames.
func (fr *Reader) Read() (*Envelope, error) {
	if _, err := io.ReadFull(fr.r, fr.hdr[:]); err != nil {
		if errors.Is(err, io.EOF) {
			return nil, io.EOF
		}
		return nil, fmt.Errorf("protocol: read frame header: %w", err)
	}

	size := binary.BigEndian.Uint32(fr.hdr[:])
	if size == 0 {
		return nil, errors.New("protocol: zero-length frame")
	}
	if size > MaxFrameSize {
		// Do not attempt to drain the frame. We cannot trust the length, so
		// the only safe response is to surface the error and let the caller
		// close the connection.
		return nil, fmt.Errorf("%w: %d bytes", ErrFrameTooLargeErr, size)
	}

	if cap(fr.buf) < int(size) {
		fr.buf = make([]byte, size)
	}
	body := fr.buf[:size]

	if _, err := io.ReadFull(fr.r, body); err != nil {
		// A truncated body is never a clean EOF, so translate it.
		if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) {
			return nil, fmt.Errorf("protocol: truncated frame body: %w", io.ErrUnexpectedEOF)
		}
		return nil, fmt.Errorf("protocol: read frame body: %w", err)
	}

	var env Envelope
	if err := json.Unmarshal(body, &env); err != nil {
		return nil, fmt.Errorf("protocol: unmarshal envelope: %w", err)
	}

	// json.Unmarshal makes RawMessage alias the input buffer, which we reuse.
	// Copy it so the envelope stays valid after the next Read.
	if len(env.Payload) > 0 {
		payload := make(json.RawMessage, len(env.Payload))
		copy(payload, env.Payload)
		env.Payload = payload
	}

	if env.Type == "" {
		return nil, errors.New("protocol: envelope has no type")
	}
	if env.ID == "" {
		return nil, errors.New("protocol: envelope has no id")
	}

	return &env, nil
}
