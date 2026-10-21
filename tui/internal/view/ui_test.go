package view

import (
	"fmt"
	"reflect"
	"strings"
	"testing"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/theme"
)

func TestSplitHintSeparatesTheKeysFromWhatTheyDo(t *testing.T) {
	cases := []struct{ hint, key, what string }{
		{"enter use", "enter", "use"},
		{"ctrl+s save default", "ctrl+s", "save default"},
		{"↑↓ move", "↑↓", "move"},
		{"↑↓ PgUp PgDn scroll", "↑↓ PgUp PgDn", "scroll"},
		{"/ commands", "/", "commands"},
		{"@ subagents", "@", "subagents"},
		{"y run", "y", "run"},
		{"esc", "esc", ""},
		{"type to filter", "", "type to filter"},
		{"a new thing", "", "a new thing"},
		{"←→ preset or protocol", "←→", "preset or protocol"},
	}
	for _, c := range cases {
		if key, what := splitHint(c.hint); key != c.key || what != c.what {
			t.Errorf("splitHint(%q) = %q, %q; want %q, %q", c.hint, key, what, c.key, c.what)
		}
	}
}

func TestHintRowDrawsEveryKeyBrightAndEverythingElseMuted(t *testing.T) {
	r := NewRenderer(theme.Default())
	s := theme.Default()
	got := r.hintRow("enter use · type to filter")
	want := s.Key.Render("enter") + s.Dim.Render(" use") + s.Dim.Render(" · ") + s.Dim.Render("type to filter")
	if got != want {
		t.Errorf("hintRow = %q, want %q", got, want)
	}
}

func TestHintsBreakBetweenHintsAndNeverInsideOne(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, width := range []int{12, 20, 33, 60} {
		for _, row := range r.hints("↑↓ move · enter use · ctrl+f favourite · ctrl+s save default · esc close", width) {
			if lipgloss.Width(row) > width && len(strings.Fields(plain(row))) > 3 {
				t.Errorf("width %d: row %q is wider and holds more than one hint", width, plain(row))
			}
		}
	}
}

func TestMatchedMarksOnlyTheMatchedRunes(t *testing.T) {
	base, mark := theme.Default().Output, theme.Default().Match
	segs := matched("qwen-coder", []int{5, 6, 7}, base, mark)
	var texts []string
	for _, s := range segs {
		texts = append(texts, s.text)
	}
	if want := []string{"qwen-", "cod", "er"}; !reflect.DeepEqual(texts, want) {
		t.Errorf("segments = %v, want %v", texts, want)
	}
	if segs[1].style.Render("x") != mark.Render("x") || segs[0].style.Render("x") != base.Render("x") {
		t.Error("the matched run is not drawn with the match style")
	}
	if got := matched("abc", nil, base, mark); len(got) != 1 || got[0].text != "abc" {
		t.Errorf("without positions = %+v, want the text whole", got)
	}
	if got := matched("é-x", []int{0}, base, mark); got[0].text != "é" {
		t.Errorf("positions count runes, not bytes: %+v", got)
	}
}

func TestHeadingRunsARuleToTheInfoAndDropsTheInfoWhenItWouldLeaveNoRule(t *testing.T) {
	r := NewRenderer(theme.Default())
	title := []segment{{text: "local", style: r.styles.Heading}}
	info := []segment{{text: "openai · 127.0.0.1", style: r.styles.Dim}}
	row := plain(r.heading(title, info, 40))
	if lipgloss.Width(row) != 40 || !strings.HasPrefix(row, "local ─") || !strings.HasSuffix(row, "openai · 127.0.0.1") {
		t.Errorf("heading = %q, want the title, a rule, and the info at the right edge", row)
	}
	short := plain(r.heading(title, info, 24))
	if strings.Contains(short, "openai") || lipgloss.Width(short) != 24 {
		t.Errorf("heading = %q, want the info dropped and the row 24 cells wide", short)
	}
	if got := plain(r.heading(title, nil, 3)); lipgloss.Width(got) != 3 {
		t.Errorf("heading in 3 cells = %q", got)
	}
}

func TestScrollViewKeepsTheHighlightAwayFromTheNotes(t *testing.T) {
	r := NewRenderer(theme.Default())
	var rows []string
	for i := 0; i < 30; i++ {
		rows = append(rows, fmt.Sprintf("row%02d", i))
	}
	for _, at := range []int{0, 1, 5, 14, 28, 29} {
		for _, n := range []int{3, 5, 8} {
			shown := r.scrollView(rows, at, n, 20)
			if len(shown) != n {
				t.Fatalf("at %d, n %d: %d rows, want %d", at, n, len(shown), n)
			}
			found := -1
			for i, row := range shown {
				if strings.Contains(plain(row), fmt.Sprintf("row%02d", at)) {
					found = i
				}
			}
			if found < 0 {
				t.Errorf("at %d, n %d: the highlighted row is not shown: %q", at, n, plainRows(shown))
			}
		}
	}
	shown := plainRows(r.scrollView(rows, 14, 8, 20))
	if !strings.Contains(shown[0], "↑") || !strings.Contains(shown[7], "↓") {
		t.Errorf("a list cut at both ends has no notes: %q", shown)
	}
	top := plainRows(r.scrollView(rows, 0, 8, 20))
	if strings.Contains(top[0], "↑") || !strings.Contains(top[7], "↓ 23 more") {
		t.Errorf("at the top: %q, want only a note below that counts what is cut", top)
	}
	if got := r.scrollView(rows[:2], 0, 5, 20); len(got) != 5 || got[2] != "" {
		t.Errorf("a short list = %q, want it padded to 5 rows", got)
	}
	if got := r.scrollView(rows, 0, 0, 20); got != nil {
		t.Errorf("zero rows = %v, want none", got)
	}
}

func TestBarPaintsTheBackgroundOnEverySegmentAndFillsTheRow(t *testing.T) {
	r := NewRenderer(theme.Default())
	segs := []segment{r.marker(true), {text: " "}, {text: "name", style: r.styles.Output}, {text: "  hint", style: r.styles.Dim}}
	row := r.bar(segs, 30, true)
	if lipgloss.Width(row) != 30 {
		t.Fatalf("the bar is %d cells wide, want 30", lipgloss.Width(row))
	}
	if _, bg := rowColours(row); len(bg) == 0 {
		t.Errorf("the bar paints no background: %q", row)
	}
	if strings.Contains(row, r.styles.Dim.Render("  hint")) {
		t.Errorf("muted text keeps its grey on the bar, where it cannot be read: %q", row)
	}
	plainRow := r.bar(segs, 30, false)
	if _, bg := rowColours(plainRow); len(bg) > 0 {
		t.Errorf("a row that is not highlighted paints a background: %q", plainRow)
	}
}
