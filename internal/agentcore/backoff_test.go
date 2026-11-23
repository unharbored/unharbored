package agentcore

import (
	"testing"

	"github.com/unharbored/unharbored/internal/transport"
)

// A routine gateway restart must not leave a long-running node waiting the
// full backoff cap: it was connected, so it should come straight back.
func TestAnEstablishedSessionResetsTheBackoff(t *testing.T) {
	attempt := 0
	for i := 0; i < 10; i++ {
		attempt = nextAttempt(attempt, false)
	}
	if transport.DefaultBackoff.Delay(attempt) < transport.DefaultBackoff.Max/2 {
		t.Fatalf("ten failures should be near the cap")
	}

	attempt = nextAttempt(attempt, true)

	if attempt != 0 {
		t.Fatalf("attempt = %d after an established session, want 0", attempt)
	}
	if d := transport.DefaultBackoff.Delay(attempt); d > 2*transport.DefaultBackoff.Min {
		t.Errorf("first retry after a clean close waits %v", d)
	}
}

func TestFailuresStillBackOff(t *testing.T) {
	if nextAttempt(3, false) != 4 {
		t.Error("a failed attempt must count")
	}
}
