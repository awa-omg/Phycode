package view

import (
	"fmt"
	"strings"
	"time"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func modelCount(n int) string {
	return fmt.Sprintf("%d %s", n, plural(n, "model", "models"))
}

func queryAge(pr protocol.Provider, now time.Time) string {
	if pr.Refreshing {
		return "refreshing…"
	}
	t, ok := state.QueriedAt(pr)
	if !ok {
		return "not listed yet"
	}
	return relativeAge(now.Sub(t))
}

func relativeAge(d time.Duration) string {
	switch {
	case d < time.Minute:
		return "just now"
	case d < time.Hour:
		return fmt.Sprintf("%dm ago", int(d/time.Minute))
	case d < 24*time.Hour:
		return fmt.Sprintf("%dh ago", int(d/time.Hour))
	}
	return fmt.Sprintf("%dd ago", int(d/(24*time.Hour)))
}

func hostOf(endpoint string) string {
	endpoint = oneLine(endpoint)
	if _, rest, ok := strings.Cut(endpoint, "://"); ok {
		return strings.TrimRight(rest, "/")
	}
	return endpoint
}
