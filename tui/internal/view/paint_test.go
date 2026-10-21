package view

import (
	"strings"
	"testing"

	"github.com/charmbracelet/lipgloss"
	"github.com/muesli/termenv"

	"phycode/tui/internal/theme"
)

func TestPaintSetsTheSurfaceAgainAfterEveryReset(t *testing.T) {
	const seq = "\x1b[48;2;1;2;3m"
	line := "a" + "\x1b[1mb\x1b[0m" + "c" + "\x1b[2md\x1b[0m"
	got := paint(line, seq)
	if !strings.HasPrefix(got, seq) || !strings.HasSuffix(got, sgrReset) {
		t.Errorf("paint = %q, want it to start with the surface and end with a reset", got)
	}
	if n := strings.Count(got, seq); n != 3 {
		t.Errorf("the surface is set %d times in %q, want once at the start and once after each of the two resets", n, got)
	}
	if plain(got) != "abcd" || lipgloss.Width(got) != 4 {
		t.Errorf("paint changed the text: %q", plain(got))
	}
}

func TestPaintDoesNothingWithoutColour(t *testing.T) {
	if got := paint("text", ""); got != "text" {
		t.Errorf("paint(text, \"\") = %q", got)
	}
}

func TestEverySurfaceHasItsOwnSequence(t *testing.T) {
	lipgloss.SetColorProfile(termenv.TrueColor)
	defer lipgloss.SetColorProfile(termenv.ANSI)
	s := surfacesOf(theme.Lookup(""))
	seen := map[string]bool{}
	for name, seq := range map[string]string{"page": s.page, "raised": s.raised, "overlay": s.overlay, "add": s.add, "del": s.del} {
		if seq == "" {
			t.Errorf("surface %s has no escape sequence", name)
		}
		seen[seq] = true
	}
	if len(seen) < 5 {
		t.Errorf("only %d distinct surface sequences, want 5", len(seen))
	}
}

func TestAnInnerSurfaceWinsOverTheOuterOne(t *testing.T) {
	s := surfacesOf(theme.Lookup(""))
	inner := paint(fit("raised", 10), s.raised)
	outer := paint("  "+inner+"  ", s.page)
	i, j := strings.LastIndex(outer, s.raised), strings.LastIndex(outer, s.page)
	if !strings.Contains(outer, s.raised+"raised") {
		t.Errorf("the raised surface does not hold its text: %q", outer)
	}
	if i < 0 || j < 0 || !strings.HasSuffix(outer, sgrReset) {
		t.Errorf("outer = %q", outer)
	}
	if !strings.Contains(outer, sgrReset+s.page+"  ") {
		t.Errorf("the page surface does not resume after the raised band: %q", outer)
	}
}

func TestTextWithoutStyleTakesTheColourOfThePalette(t *testing.T) {
	trueColour(t)
	for _, name := range []string{"phycode", "phycode-light", "paper"} {
		st := theme.Build(theme.Lookup(name))
		r := NewRenderer(st)
		m := newModel(80, 20)
		m.Input, m.Cursor = "typed words", 3
		rows := screen(t, r, m)
		row := rows[rowOf(rows, "typed words")]
		want := seqOf(lipgloss.NewStyle().Foreground(st.P.Text))
		if !strings.Contains(r.bg.raised, want) || !strings.Contains(row, r.bg.raised+"typ") {
			t.Errorf("%s: the typed text is not drawn in the text colour %q of the palette: %q", name, want, row)
		}
	}
}
