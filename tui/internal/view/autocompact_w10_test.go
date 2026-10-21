package view

import (
	"testing"

	"phycode/tui/internal/protocol"
)

func TestAutocompactLabelNamesTheMissingMax(t *testing.T) {
	on, off := true, false
	threshold := 100000
	cases := []struct {
		name string
		c    protocol.Context
		want string
	}{
		{"switch on, no max", protocol.Context{AutoCompact: false, AutoCompactSwitch: &on, Threshold: &threshold}, "off until context.max is set"},
		{"switch off", protocol.Context{AutoCompact: false, AutoCompactSwitch: &off, Threshold: &threshold}, "off"},
		{"switch unknown", protocol.Context{AutoCompact: false, Threshold: &threshold}, "off"},
		{"on", protocol.Context{AutoCompact: true, Threshold: &threshold}, "at 100k"},
	}
	for _, tc := range cases {
		if got := autocompactLabel(tc.c); got != tc.want {
			t.Errorf("%s: autocompactLabel = %q, want %q", tc.name, got, tc.want)
		}
	}
}
