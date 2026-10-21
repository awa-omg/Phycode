package view

import (
	"fmt"
	"strings"
	"testing"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func execModel(w, h int, ex state.Exec) state.Model {
	m := newModel(w, h)
	m.Exec = &ex
	return m
}

func execPanelModel(w, h int) state.Model {
	return execModel(w, h, state.Exec{Title: "gentle-ai", Argv: []string{"gentle-ai", "tui"}})
}

func TestExecBoxShowsTheTitleTheProgramAndTheKeys(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := execModel(80, 24, state.Exec{Title: "gentle-ai", Argv: []string{"gentle-ai", "tui", "--profile", "my profile"}})
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "Run gentle-ai?") < 0 {
		t.Fatalf("the title is missing: %q", rows)
	}
	for _, want := range []string{`gentle-ai tui --profile "my profile"`, "y run · n cancel · esc cancel", "run confirmation"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("%q is missing: %q", want, rows)
		}
	}
}

func TestDisplayArgvQuotesOnlyWhatNeedsIt(t *testing.T) {
	tests := []struct {
		argv []string
		want string
	}{
		{[]string{"gentle-ai", "tui"}, "gentle-ai tui"},
		{[]string{"echo", "a b", "", `say "hi"`, "$HOME", "tab\there", `back\slash`, "é"},
			`echo "a b" "" "say \"hi\"" "$HOME" "tab\there" "back\\slash" é`},
	}
	for _, tt := range tests {
		if got := displayArgv(tt.argv); got != tt.want {
			t.Errorf("displayArgv(%q) = %q, want %q", tt.argv, got, tt.want)
		}
	}
}

func TestExecBoxFitsSmallScreens(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, size := range [][2]int{{20, 8}, {40, 12}, {80, 24}, {120, 40}} {
		t.Run(fmt.Sprintf("%dx%d", size[0], size[1]), func(t *testing.T) {
			screen(t, r, execModel(size[0], size[1], state.Exec{
				Title: strings.Repeat("title ", 10),
				Argv:  []string{strings.Repeat("arg ", 30)},
			}))
		})
	}
}
