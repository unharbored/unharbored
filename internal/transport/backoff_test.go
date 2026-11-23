package transport

import (
	"math"
	"testing"
	"time"
)

func TestBackoffGrowsAndCaps(t *testing.T) {
	b := Backoff{Min: time.Second, Max: time.Minute, Factor: 2, Jitter: 0}

	want := []time.Duration{
		1 * time.Second,
		2 * time.Second,
		4 * time.Second,
		8 * time.Second,
		16 * time.Second,
		32 * time.Second,
		time.Minute, // capped
		time.Minute,
	}
	for attempt, expect := range want {
		if got := b.Delay(attempt); got != expect {
			t.Errorf("Delay(%d) = %v, want %v", attempt, got, expect)
		}
	}
}

// A large attempt count must not overflow into a negative duration, which
// would turn the reconnect loop into a hot spin.
func TestBackoffDoesNotOverflow(t *testing.T) {
	b := Backoff{Min: time.Second, Max: time.Minute, Factor: 2, Jitter: 0}

	for _, attempt := range []int{62, 63, 64, 1000, math.MaxInt32} {
		got := b.Delay(attempt)
		if got <= 0 {
			t.Errorf("Delay(%d) = %v, want positive", attempt, got)
		}
		if got > b.Max {
			t.Errorf("Delay(%d) = %v, exceeds Max %v", attempt, got, b.Max)
		}
	}
}

func TestBackoffNegativeAttempt(t *testing.T) {
	b := Backoff{Min: time.Second, Max: time.Minute, Factor: 2, Jitter: 0}
	if got := b.Delay(-5); got != time.Second {
		t.Errorf("Delay(-5) = %v, want %v", got, time.Second)
	}
}

// Jitter must stay inside [d*(1-jitter), d] and must actually vary, or a
// control plane restart brings every agent back in lockstep.
func TestBackoffJitterRangeAndSpread(t *testing.T) {
	b := Backoff{Min: 10 * time.Second, Max: time.Minute, Factor: 2, Jitter: 0.5}

	const samples = 300
	seen := make(map[time.Duration]bool, samples)
	lo := time.Duration(float64(10*time.Second) * 0.5)
	hi := 10 * time.Second

	for range samples {
		d := b.Delay(0)
		if d < lo || d > hi {
			t.Fatalf("Delay = %v, want within [%v, %v]", d, lo, hi)
		}
		seen[d] = true
	}

	// With 53 bits of randomness, 300 samples colliding into a handful of
	// values would mean the jitter is not doing anything.
	if len(seen) < samples/2 {
		t.Errorf("only %d distinct delays across %d samples; jitter looks degenerate", len(seen), samples)
	}
}

func TestBackoffFullJitter(t *testing.T) {
	b := Backoff{Min: time.Second, Max: time.Minute, Factor: 2, Jitter: 1}
	for range 100 {
		d := b.Delay(3) // 8s before jitter
		if d < 0 || d > 8*time.Second {
			t.Fatalf("Delay = %v, want within [0, 8s]", d)
		}
	}
}

// Jitter above 1 would otherwise produce negative delays.
func TestBackoffClampsOversizedJitter(t *testing.T) {
	b := Backoff{Min: time.Second, Max: time.Minute, Factor: 2, Jitter: 5}
	for range 100 {
		if d := b.Delay(2); d < 0 {
			t.Fatalf("Delay = %v, want non-negative", d)
		}
	}
}

func TestBackoffZeroValuesUseDefaults(t *testing.T) {
	var b Backoff
	d := b.Delay(0)
	if d <= 0 {
		t.Fatalf("Delay(0) = %v, want positive", d)
	}
	// Must still be capped rather than growing without bound.
	if got := b.Delay(100); got > time.Minute {
		t.Errorf("Delay(100) = %v, want capped at the default max", got)
	}
}

func TestBackoffFactorBelowOneFallsBack(t *testing.T) {
	// A factor under 1 would shrink the delay on every attempt, which is the
	// opposite of backoff. Treat it as a misconfiguration.
	b := Backoff{Min: time.Second, Max: time.Minute, Factor: 0.5, Jitter: 0}
	if b.Delay(0) >= b.Delay(1) {
		t.Error("delay did not grow with a sub-unit factor")
	}
}

func TestDefaultBackoffIsSane(t *testing.T) {
	b := DefaultBackoff
	if b.Min <= 0 || b.Max <= b.Min {
		t.Fatalf("DefaultBackoff bounds look wrong: %+v", b)
	}
	if b.Jitter <= 0 {
		t.Error("DefaultBackoff has no jitter; agents will reconnect in lockstep")
	}
	if got := b.Delay(0); got > b.Min {
		t.Errorf("Delay(0) = %v, want at most Min %v", got, b.Min)
	}
	if got := b.Delay(50); got > b.Max {
		t.Errorf("Delay(50) = %v, want at most Max %v", got, b.Max)
	}
}

func TestRandFloatRange(t *testing.T) {
	for range 1000 {
		v := randFloat()
		if v < 0 || v >= 1 {
			t.Fatalf("randFloat = %v, want [0,1)", v)
		}
	}
}
