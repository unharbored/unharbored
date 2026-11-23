package transport

import (
	"crypto/rand"
	"encoding/binary"
	"math"
	"time"
)

// Backoff computes reconnect delays.
//
// Jitter is not optional here. Every agent in a region watches the same
// control plane, so a control plane restart disconnects all of them at once.
// Without jitter they would return in lockstep and do it again on the next
// failure, which is how a brief outage becomes a sustained one.
type Backoff struct {
	// Min is the delay after the first failure.
	Min time.Duration
	// Max caps the delay.
	Max time.Duration
	// Factor multiplies the delay per attempt.
	Factor float64
	// Jitter is the fraction of the computed delay to randomise, 0 to 1.
	// At 0.5 the actual delay lands uniformly in [d/2, d].
	Jitter float64
}

// DefaultBackoff is tuned for an agent reconnecting to the control plane.
// A node that cannot reach the control plane is not down; it is running the
// customer's application perfectly well and merely unmanaged. There is no
// reason to retry aggressively.
var DefaultBackoff = Backoff{
	Min:    time.Second,
	Max:    2 * time.Minute,
	Factor: 2,
	Jitter: 0.5,
}

// Delay returns the wait before the given attempt. Attempt 0 is the first
// retry, so Delay(0) is approximately Min.
func (b Backoff) Delay(attempt int) time.Duration {
	if attempt < 0 {
		attempt = 0
	}
	min := b.Min
	if min <= 0 {
		min = time.Second
	}
	max := b.Max
	if max <= 0 {
		max = time.Minute
	}
	factor := b.Factor
	if factor < 1 {
		factor = 2
	}

	// Compute in float but guard the conversion. A large attempt count
	// overflows int64 and would otherwise produce a negative duration, which
	// turns a backoff into a hot loop.
	d := float64(min) * math.Pow(factor, float64(attempt))
	if math.IsInf(d, 0) || d >= float64(max) {
		d = float64(max)
	}

	delay := time.Duration(d)
	if delay > max {
		delay = max
	}

	if b.Jitter > 0 {
		delay = applyJitter(delay, b.Jitter)
	}
	if delay < 0 {
		delay = min
	}
	return delay
}

// applyJitter scales d down by a random fraction up to jitter.
func applyJitter(d time.Duration, jitter float64) time.Duration {
	if jitter > 1 {
		jitter = 1
	}
	span := float64(d) * jitter
	return time.Duration(float64(d) - span*randFloat())
}

// randFloat returns a uniform float in [0, 1) from the crypto source.
//
// math/rand would be fine for jitter, but using one random source throughout
// removes the question of whether any given call site picked the right one.
func randFloat() float64 {
	var b [8]byte
	if _, err := rand.Read(b[:]); err != nil {
		// A failing system RNG is fatal elsewhere; here, degrade to the
		// midpoint rather than panicking in a reconnect loop.
		return 0.5
	}
	// Take 53 bits, the mantissa width of a float64.
	v := binary.BigEndian.Uint64(b[:]) >> 11
	return float64(v) / float64(uint64(1)<<53)
}
