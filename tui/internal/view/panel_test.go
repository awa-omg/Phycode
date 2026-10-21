package view

import (
	"fmt"
	"strings"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func panelSkills() *protocol.Panel {
	return &protocol.Panel{
		Type: protocol.TypePanel, Title: "Skills", Kind: "list", Items: []protocol.PanelItem{
			{Category: "Project", Title: "tdd", Detail: "write the test first", Badge: "project", Status: "ok", Path: "/work/skills/tdd/SKILL.md"},
			{Category: "Global", Title: "review", Detail: "read a diff and say what is wrong", Badge: "global", Status: "ok"},
			{Category: "Project", Title: "docs", Detail: "keep the README in step", Badge: "project", Status: "error"},
			{Category: "gentle-ai", Title: "sdd-init", Detail: "start a spec-driven change", Badge: "registry", Status: "off"},
		},
	}
}

func panelModel(w, h int, p *protocol.Panel) state.Model {
	m := newModel(w, h)
	m.Panel = state.NewPanel(p)
	return m
}

func numberedText(n int) *protocol.Panel {
	var lines []string
	for i := 1; i <= n; i++ {
		lines = append(lines, fmt.Sprintf("line %02d of the help text", i))
	}
	return &protocol.Panel{Title: "Help", Kind: "text", Text: strings.Join(lines, "\n")}
}

func TestPanelShowsItsTitleAndARowOnTheSmallestScreen(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, panelModel(20, 8, panelSkills()))
	if rowOf(rows, "Skills") < 0 {
		t.Errorf("the title is missing on 20x8: %q", plainRows(rows))
	}
	if rowOf(rows, "tdd") < 0 {
		t.Errorf("no item row is visible on 20x8: %q", plainRows(rows))
	}
}

func TestPanelGroupsItemsUnderTheirCategory(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, panelModel(80, 24, panelSkills())))
	project, tdd, global := rowOf(rows, "Project ─"), rowOf(rows, "tdd"), rowOf(rows, "Global ─")
	review, gentle := rowOf(rows, "review"), rowOf(rows, "gentle-ai ─")
	if !(project >= 0 && project < tdd && tdd < global && global < review && review < gentle) {
		t.Errorf("the groups are out of order: project %d, tdd %d, global %d, review %d, gentle-ai %d: %q",
			project, tdd, global, review, gentle, rows)
	}
}

func TestPanelRowsAreCutAndKeepTheBadge(t *testing.T) {
	r := NewRenderer(theme.Default())
	p := &protocol.Panel{Title: "Skills", Kind: "list", Items: []protocol.PanelItem{{
		Category: "Project", Title: "a-very-long-skill-name-that-goes-on", Detail: strings.Repeat("detail ", 30),
		Badge: "project", Status: "ok",
	}}}
	rows := screen(t, r, panelModel(80, 24, p))
	i := rowOf(rows, "a-very-long")
	if i < 0 {
		t.Fatalf("the item is missing: %q", plainRows(rows))
	}
	row := strings.TrimRight(plain(rows[i]), " ")
	if !strings.HasSuffix(row, "project") || !strings.Contains(row, "…") {
		t.Errorf("row %q: want the detail cut with an ellipsis and the badge at the right", row)
	}
}

func TestPanelStatusMarksSayWhatEachItemIs(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, panelModel(80, 24, panelSkills()))
	for _, tc := range []struct{ title, mark string }{{"tdd", "●"}, {"docs", "✗"}, {"sdd-init", "○"}} {
		if i := rowOf(rows, tc.title); i < 0 || !strings.Contains(plain(rows[i]), tc.mark) {
			t.Errorf("the row of %s lacks its mark %s: %q", tc.title, tc.mark, plainRows(rows))
		}
	}
}

func TestPanelHighlightsOnlyTheSelectedItem(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := panelModel(80, 24, panelSkills())
	m.Panel.Selected = 2
	rows := screen(t, r, m)
	if got := highlightedRows(rows); len(got) != 1 {
		t.Errorf("%d highlighted rows, want 1: %q", len(got), plainRows(rows))
	}
	if i := rowOf(rows, "review"); i < 0 || !onBar(plain(rows[i])) {
		t.Errorf("the highlight is not on review: %q", plainRows(rows))
	}
}

func TestPanelFilterShowsInTheTitleAndNarrowsTheList(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := panelModel(80, 24, panelSkills())
	m.Panel.Query = "DOCS"
	rows := screen(t, r, m)
	if rowOf(rows, "Skills  ❯ DOCS█") < 0 {
		t.Errorf("the title does not show the filter: %q", plainRows(rows))
	}
	if rowOf(rows, "docs") < 0 || rowOf(rows, "tdd") >= 0 || rowOf(rows, "Global") >= 0 {
		t.Errorf("the filter does not keep only the matching item and its category: %q", plainRows(rows))
	}
	m.Panel.Query = "zzz"
	if rowOf(screen(t, r, m), "no item matches the filter") < 0 {
		t.Error("an empty result does not say so")
	}
}

func TestPanelDetailShowsStatusPathAndDetail(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := panelModel(80, 24, panelSkills())
	m.Panel.Detail = true
	rows := screen(t, r, m)
	for _, want := range []string{"Skills › tdd", "● ok", "category", "Project", "path", "SKILL.md", "write the test first", "↑↓ PgUp PgDn scroll · esc back"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the detail lacks %q: %q", want, plainRows(rows))
		}
	}
	if rowOf(rows, "Project ─") >= 0 {
		t.Error("the detail still shows the list")
	}
}

func TestPanelHintIsTheEventsOrTheDefault(t *testing.T) {
	r := NewRenderer(theme.Default())
	if rowOf(screen(t, r, panelModel(80, 24, panelSkills())), "type to filter · enter details · esc close") < 0 {
		t.Error("a list without a hint does not show the default hint")
	}
	p := panelSkills()
	p.Hint = "pick a skill"
	rows := screen(t, r, panelModel(80, 24, p))
	note, keys := rowOf(rows, "pick a skill"), rowOf(rows, "type to filter · enter details · esc close")
	if note < 0 || keys <= note {
		t.Errorf("the event hint is not a note above the default keys: %q", plainRows(rows))
	}
	if rowOf(screen(t, r, panelModel(80, 24, numberedText(3))), "↑↓ PgUp PgDn scroll · esc close") < 0 {
		t.Error("a text panel does not show its scroll hint")
	}
}

func TestPanelHintKeepsEnterAndEscWithAnEventHint(t *testing.T) {
	ev := panelSkills()
	ev.Hint = "/skill NAME shows one skill."
	if hint := panelHint(state.NewPanel(ev)); !strings.Contains(hint, "enter") || !strings.Contains(hint, "esc") {
		t.Errorf("panelHint = %q, want Enter and Esc", hint)
	}
}

func TestPanelTextWrapsAndScrolls(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := panelModel(80, 24, numberedText(30))
	rows := screen(t, r, m)
	if rowOf(rows, "1–10 of 30") < 0 || rowOf(rows, "line 01") < 0 || rowOf(rows, "line 11") >= 0 {
		t.Errorf("the first page is wrong: %q", plainRows(rows))
	}

	top := PanelMaxScroll(m)
	if top != 20 {
		t.Fatalf("PanelMaxScroll = %d, want 20: 30 lines, and 10 rows of body in a dock of 16", top)
	}
	m.Panel.Scroll = top
	rows = screen(t, r, m)
	if rowOf(rows, "21–30 of 30") < 0 || rowOf(rows, "line 30") < 0 || rowOf(rows, "line 20") >= 0 {
		t.Errorf("the last page is wrong: %q", plainRows(rows))
	}
	m.Panel.Scroll = 1 << 20
	if beyond := screen(t, r, m); strings.Join(plainRows(beyond), "\n") != strings.Join(plainRows(rows), "\n") {
		t.Error("a scroll past the end changes the screen")
	}
}

func TestPanelTextThatFitsHasNoCounter(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := panelModel(80, 24, &protocol.Panel{Title: "Help", Kind: "text", Text: "one\ntwo"})
	rows := screen(t, r, m)
	if rowOf(rows, " of ") >= 0 || PanelMaxScroll(m) != 0 {
		t.Errorf("a short text shows a counter or scrolls: %q", plainRows(rows))
	}
}

func TestHelpRowsAreCutAndOtherTextWraps(t *testing.T) {
	r := NewRenderer(theme.Default())
	const status = "/status                  show the version, profile, model, sandbox, permissions and transcript"
	help := &protocol.Panel{Title: "Help", Kind: "text", Text: "/help                    show the commands\n" + status}
	for _, size := range [][2]int{{80, 24}, {40, 12}} {
		w, h := size[0], size[1]
		rows := plainRows(screen(t, r, panelModel(w, h, help)))
		row := rowOf(rows, "/status")
		if row < 0 || !strings.Contains(rows[row], "…") || rowOf(rows, "transcript") >= 0 {
			t.Errorf("%dx%d: the /status row is not cut: %q", w, h, rows)
		}
	}
	rows := plainRows(screen(t, r, panelModel(80, 24, &protocol.Panel{Title: "Status", Kind: "text", Text: status})))
	if rowOf(rows, "transcript") < 0 {
		t.Errorf("a text panel that is not the help does not wrap its long row: %q", rows)
	}
}

func TestPanelLeavesTheRowsAroundItAlone(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, panelModel(80, 24, panelSkills()))
	if !strings.Contains(plain(rows[0]), "phycode") {
		t.Errorf("the header is covered: %q", plain(rows[0]))
	}
	if !strings.Contains(plain(rows[len(rows)-1]), "ready") {
		t.Errorf("the status line is covered: %q", plain(rows[len(rows)-1]))
	}
}

func TestPanelTakesTheWholeInputBandOnAShortScreen(t *testing.T) {
	r := NewRenderer(theme.Default())
	models := map[string]func(int, int) state.Model{
		"effort": effortPanelModel, "context": contextPanelModel, "compact": compactKeepModel, "config": configPanelModel,
	}
	for name, model := range models {
		rows := plainRows(screen(t, r, model(40, 12)))
		if joined := strings.Join(rows, "\n"); strings.Contains(joined, "Message phycode") {
			t.Errorf("%s: the input band shows under the panel: %q", name, rows)
		}
		if !strings.Contains(rows[len(rows)-1], "ready") && !strings.Contains(rows[len(rows)-1], "compacting") {
			t.Errorf("%s: the status line is covered: %q", name, rows[len(rows)-1])
		}
	}
}

func TestPanelFitsEverySmallSize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{1, 2, 3, 5, 8, 12, 16, 20, 24, 30, 40} {
		for _, h := range []int{1, 2, 3, 4, 5, 6, 8, 10, 12, 14} {
			m := panelModel(w, h, panelSkills())
			screen(t, r, m)
			m.Panel.Detail = true
			screen(t, r, m)
			m = panelModel(w, h, numberedText(20))
			m.Panel.Scroll = 3
			screen(t, r, m)
		}
	}
}

func TestTheThemeListAlignsWhatEachThemeIsInOneColumnAndMarksTheOneInUse(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(100, 30)
	m.Panel = state.Panel{Open: true, Title: "Theme", Kind: "themes", Hint: "↑↓ preview · enter keep · esc cancel", Items: []protocol.PanelItem{
		{Title: "phycode", Detail: "dark, the default", Badge: "in use", Status: "ok"},
		{Title: "phycode-light", Detail: "light"},
		{Title: "mono", Detail: "greys only"},
	}}
	rows := inDock(t, r, m)
	cell := func(row, note string) int { return len([]rune(row[:strings.LastIndex(row, note)])) }
	want := cell(rowWith(rows, "phycode  "), "dark")
	for _, pair := range [][2]string{{"phycode-light", "light"}, {"mono", "greys"}} {
		if got := cell(rowWith(rows, pair[0]), pair[1]); got != want {
			t.Errorf("the note of %s starts at cell %d, want %d, where the others start: %q", pair[0], got, want, rows)
		}
	}
	if row := rowWith(rows, "phycode  "); !strings.HasSuffix(row, "in use") {
		t.Errorf("row = %q, want in use at the right", row)
	}
}
