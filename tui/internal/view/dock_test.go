package view

import (
	"strings"
	"testing"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func dialogs() map[string]func(w, h int) state.Model {
	return map[string]func(w, h int) state.Model{
		"picker":   pickerView,
		"skills":   func(w, h int) state.Model { return panelModel(w, h, panelSkills()) },
		"text":     func(w, h int) state.Model { return panelModel(w, h, numberedText(40)) },
		"config":   configPanelModel,
		"sessions": sessionsPanelModel,
		"effort":   effortPanelModel,
		"context":  contextPanelModel,
		"ask":      askModel,
		"exec": func(w, h int) state.Model {
			return execModel(w, h, state.Exec{Title: "nvim", Argv: []string{"nvim", "a.go"}})
		},
	}
}

func TestADialogTakesThePlaceOfTheInputBandAndLeavesTheHeaderAndTheStatusLine(t *testing.T) {
	r := NewRenderer(theme.Default())
	for name, build := range dialogs() {
		for _, size := range [][2]int{{120, 40}, {80, 24}, {40, 12}, {20, 8}} {
			rows := screen(t, r, build(size[0], size[1]))
			joined := strings.Join(plainRows(rows), "\n")
			if strings.Contains(joined, "Message phycode") {
				t.Errorf("%s %dx%d: the input band is still on the screen: %q", name, size[0], size[1], plainRows(rows))
			}
			if !strings.Contains(plain(rows[0]), "phycode") {
				t.Errorf("%s %dx%d: the header is covered: %q", name, size[0], size[1], plain(rows[0]))
			}
			if last := plain(rows[len(rows)-1]); strings.HasPrefix(last, " ▌") || strings.TrimSpace(last) == "" {
				t.Errorf("%s %dx%d: the status line is covered: %q", name, size[0], size[1], last)
			}
			if dockRowsOf(rows) == nil && size[1] >= 12 {
				t.Errorf("%s %dx%d: no dock on the screen: %q", name, size[0], size[1], plainRows(rows))
			}
		}
	}
}

func TestTheTranscriptKeepsItsLastRowsAboveTheDock(t *testing.T) {
	r := NewRenderer(theme.Default())
	for name, build := range dialogs() {
		m := build(100, 40)
		m.Blocks = []state.Block{{Kind: state.KindUser, Text: "earlier question"}, {Kind: state.KindAssistant, Text: "the answer that stays"}}
		rows := screen(t, r, m)
		answer, lip := rowOf(rows, "the answer that stays"), rowOf(rows, "▄▄▄")
		if answer < 0 || lip < 0 || answer >= lip {
			t.Errorf("%s: the last block of the transcript is at row %d, the dock starts at row %d: %q", name, answer, lip, plainRows(rows))
		}
	}
}

func TestADockIsAsTallAsItsContentAndNoMore(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(100, 40)
	m.Picker = state.Picker{Open: true, Known: true}
	rows := screen(t, r, m)
	if height := len(dockRowsOf(rows)); height > 12 {
		t.Errorf("a picker with nothing in it takes %d rows: %q", height, plainRows(rows))
	}
	tall := screen(t, r, pickerView(100, 40))
	if height := len(dockRowsOf(tall)); height > dockLimit(40) {
		t.Errorf("the picker takes %d rows, want at most %d: %q", height, dockLimit(40), plainRows(tall))
	}
}

func TestTheRailIsTheColourOfTheInputUnlessTheDialogWaitsForAnAnswer(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	for name, want := range map[string]string{
		"picker": r.styles.Emphasis.Render("▌"), "config": r.styles.Emphasis.Render("▌"),
		"ask": r.styles.Warn.Render("▌"), "exec": r.styles.Warn.Render("▌"),
	} {
		rows := screen(t, r, dialogs()[name](100, 30))
		i := rowOf(rows, map[string]string{"picker": "Models", "config": "Config", "ask": "Question", "exec": "Run "}[name])
		if i < 0 || !strings.Contains(rows[i], want) {
			t.Errorf("%s: the title row has no rail %q: %q", name, want, rows[i])
		}
	}
}

func TestTheDockIsOnTheOverlaySurfaceAndItsLipDrawsItsTopEdge(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	rows := screen(t, r, pickerView(100, 40))
	lip := rowOf(rows, "▄▄▄")
	if lip < 0 || !strings.Contains(rows[lip], seqOf(lipgloss.NewStyle().Foreground(r.styles.P.Overlay))) {
		t.Fatalf("the lip is missing or is not the colour of the dock: %q", plainRows(rows))
	}
	for _, row := range rows[lip+1 : len(rows)-1] {
		if !strings.Contains(row, bgSeq(r.styles.P.Overlay)) {
			t.Errorf("a row of the dock is not on the overlay surface: %q", plain(row))
		}
	}
}
