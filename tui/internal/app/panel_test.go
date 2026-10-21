package app

import (
	"reflect"
	"strings"
	"testing"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/view"
)

func panelEvent() *protocol.Panel {
	return &protocol.Panel{
		Type: protocol.TypePanel, Title: "Skills", Kind: "list", Items: []protocol.PanelItem{
			{Category: "Project", Title: "tdd", Detail: "write the test first", Badge: "project", Status: "ok", Path: "/work/skills/tdd/SKILL.md"},
			{Category: "Global", Title: "review", Detail: "read a diff", Badge: "global", Status: "ok"},
			{Category: "Project", Title: "docs", Detail: "keep the README in step", Badge: "project", Status: "error"},
			{Category: "gentle-ai", Title: "sdd-init", Detail: "start a spec", Badge: "registry", Status: "off"},
		},
	}
}

func textEvent(n int) *protocol.Panel {
	lines := make([]string, n)
	for i := range lines {
		lines[i] = "line " + strings.Repeat("x", i%3+1)
	}
	return &protocol.Panel{Type: protocol.TypePanel, Title: "Help", Kind: "text", Text: strings.Join(lines, "\n")}
}

func openPanel(t *testing.T, p *protocol.Panel) state.Model {
	t.Helper()
	m := testModel()
	m.Width, m.Height = 80, 24
	m, cmds := feed(m, p)
	if len(cmds) != 0 || !m.Panel.Open {
		t.Fatalf("the panel event gave panel %+v and commands %v", m.Panel, cmds)
	}
	return m
}

func shownTitles(m state.Model) []string {
	var out []string
	for _, it := range m.Panel.Visible() {
		out = append(out, it.Title)
	}
	return out
}

func TestPanelEventOpensAListWithoutCommands(t *testing.T) {
	m := openPanel(t, panelEvent())
	if m.Panel.Kind != "list" || m.Panel.Title != "Skills" {
		t.Errorf("panel = %+v, want the skills list", m.Panel)
	}
	if want := []string{"tdd", "docs", "review", "sdd-init"}; !reflect.DeepEqual(shownTitles(m), want) {
		t.Errorf("shown = %v, want %v", shownTitles(m), want)
	}
}

func TestTypingFiltersTheListAndLeavesTheInputAlone(t *testing.T) {
	m := openPanel(t, panelEvent())
	m, cmds := feed(m, typed("DOC"))
	if len(cmds) != 0 || m.Input != "" || m.Panel.Query != "DOC" {
		t.Errorf("commands %v, input %q, query %q; want the typing in the filter", cmds, m.Input, m.Panel.Query)
	}
	if want := []string{"docs"}; !reflect.DeepEqual(shownTitles(m), want) {
		t.Errorf("shown = %v, want %v", shownTitles(m), want)
	}

	m, _ = feed(m, press("backspace"), press("backspace"), press("backspace"))
	if m.Panel.Query != "" || len(shownTitles(m)) != 4 {
		t.Errorf("query %q, shown %v; want the filter cleared", m.Panel.Query, shownTitles(m))
	}
	m, _ = feed(m, press("backspace"))
	if m.Panel.Query != "" {
		t.Errorf("backspace on an empty filter gave %q", m.Panel.Query)
	}
}

func TestFilterMatchesTheCategoryAndTheDetail(t *testing.T) {
	m := openPanel(t, panelEvent())
	m, _ = feed(m, typed("gentle"))
	if want := []string{"sdd-init"}; !reflect.DeepEqual(shownTitles(m), want) {
		t.Errorf("category filter shows %v, want %v", shownTitles(m), want)
	}
	m, _ = feed(m, press("backspace"), press("backspace"), press("backspace"), press("backspace"), press("backspace"), press("backspace"))
	m, _ = feed(m, typed("diff"))
	if want := []string{"review"}; !reflect.DeepEqual(shownTitles(m), want) {
		t.Errorf("detail filter shows %v, want %v", shownTitles(m), want)
	}
}

func TestArrowsSelectAndStopAtTheEnds(t *testing.T) {
	m := openPanel(t, panelEvent())
	steps := []struct {
		key  string
		want int
	}{
		{"down", 1}, {"down", 2}, {"down", 3}, {"down", 3}, {"up", 2}, {"pgup", 0}, {"pgdown", 3},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if m.Panel.Selected != s.want {
			t.Fatalf("after %s: Selected = %d, want %d", s.key, m.Panel.Selected, s.want)
		}
	}
}

func TestEnterOpensTheDetailAndEscGoesBackToTheList(t *testing.T) {
	m := openPanel(t, panelEvent())
	m, _ = feed(m, press("down"), press("enter"))
	if !m.Panel.Detail || !m.Panel.Open {
		t.Fatalf("enter gave Detail %v, Open %v; want the detail of the second item", m.Panel.Detail, m.Panel.Open)
	}
	if it, _ := m.Panel.Highlighted(); it.Title != "docs" {
		t.Errorf("detail shows %q, want docs", it.Title)
	}

	m, cmds := feed(m, press("esc"))
	if len(cmds) != 0 || m.Panel.Detail || !m.Panel.Open || m.Panel.Selected != 1 {
		t.Errorf("esc from the detail gave Detail %v, Open %v, Selected %d; want the list back with the same item",
			m.Panel.Detail, m.Panel.Open, m.Panel.Selected)
	}

	m, _ = feed(m, press("esc"))
	if m.Panel.Open {
		t.Error("esc from the list did not close the panel")
	}
}

func TestEscClosesAListEvenWithAFilter(t *testing.T) {
	m := openPanel(t, panelEvent())
	m, _ = feed(m, typed("doc"), press("esc"))
	if m.Panel.Open {
		t.Errorf("esc with a filter typed kept the panel open: %+v", m.Panel)
	}
}

func TestEscClosesATextPanel(t *testing.T) {
	m := openPanel(t, textEvent(3))
	m, _ = feed(m, press("esc"))
	if m.Panel.Open {
		t.Error("esc did not close the text panel")
	}
}

func TestCtrlCClosesThePanelAndNothingElse(t *testing.T) {
	m := openPanel(t, panelEvent())
	m.Busy = true
	m, cmds := feed(m, press("ctrl+c"))
	if len(cmds) != 0 || m.Panel.Open || m.Quit || m.Flash != "" {
		t.Errorf("ctrl+c gave commands %v, Open %v, Quit %v, Flash %q; want only the panel closed",
			cmds, m.Panel.Open, m.Quit, m.Flash)
	}
}

func TestPanelTakesEveryOtherKey(t *testing.T) {
	m := openPanel(t, panelEvent())
	m.Input = "draft"
	m.Cursor = 5
	m, cmds := feed(m,
		press("ctrl+d"), tea.KeyMsg{Type: tea.KeyCtrlO}, press("alt+up"), press("tab"), press("left"),
		press("ctrl+s"), press("ctrl+u"), typed("y"),
	)
	if len(cmds) != 0 || m.Quit || m.ToolsExpanded || m.Input != "draft" || !m.Panel.Open {
		t.Errorf("commands %v, Quit %v, ToolsExpanded %v, Input %q, Open %v; want the panel to take the keys",
			cmds, m.Quit, m.ToolsExpanded, m.Input, m.Panel.Open)
	}
	if m.Panel.Query != "y" {
		t.Errorf("query = %q, want the typed letter in the filter", m.Panel.Query)
	}
}

func TestEnterOnAnEmptyListDoesNothing(t *testing.T) {
	m := openPanel(t, &protocol.Panel{Title: "Skills", Kind: "list"})
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Panel.Detail || !m.Panel.Open {
		t.Errorf("enter on an empty list gave Detail %v, Open %v, commands %v", m.Panel.Detail, m.Panel.Open, cmds)
	}
}

func TestTextPanelScrollsAndStopsAtBothEnds(t *testing.T) {
	m := openPanel(t, textEvent(40))
	top := view.PanelMaxScroll(m)
	if top <= 0 {
		t.Fatalf("PanelMaxScroll = %d, want a text that scrolls", top)
	}
	page := pageSize(m)
	steps := []struct {
		key   string
		delta int
	}{
		{"down", 1}, {"pgdown", page}, {"pgdown", page}, {"pgdown", page}, {"down", 1}, {"up", -1}, {"pgup", -page}, {"pgup", -page}, {"pgup", -page}, {"up", -1},
	}
	want := 0
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		want = min(max(want+s.delta, 0), top)
		if m.Panel.Scroll != want {
			t.Fatalf("after %s: Scroll = %d, want %d", s.key, m.Panel.Scroll, want)
		}
	}
	if want != 0 {
		t.Fatalf("the steps end at %d, want them to reach the top of the text again", want)
	}
}

func TestTextPanelIgnoresTyping(t *testing.T) {
	m := openPanel(t, textEvent(3))
	m, _ = feed(m, typed("abc"), press("enter"))
	if m.Panel.Query != "" || m.Input != "" || !m.Panel.Open {
		t.Errorf("query %q, input %q, Open %v; want the text to ignore the keys", m.Panel.Query, m.Input, m.Panel.Open)
	}
}

func TestDetailScrollsItsText(t *testing.T) {
	p := panelEvent()
	p.Items[0].Detail = strings.Repeat("a long line of detail ", 80)
	m := openPanel(t, p)
	m, _ = feed(m, press("enter"), press("down"), press("down"))
	if m.Panel.Scroll != 2 {
		t.Errorf("Scroll = %d in the detail, want 2", m.Panel.Scroll)
	}
	m, _ = feed(m, press("esc"))
	if m.Panel.Scroll != 0 {
		t.Errorf("Scroll = %d after leaving the detail, want 0", m.Panel.Scroll)
	}
}

func TestClosedPanelHandsTheKeysBackToTheInput(t *testing.T) {
	m := openPanel(t, panelEvent())
	m, _ = feed(m, press("esc"), typed("hi"))
	if m.Input != "hi" {
		t.Errorf("input = %q after the panel closed, want hi", m.Input)
	}
}

func TestPanelEventReplacesTheOpenOne(t *testing.T) {
	m := openPanel(t, panelEvent())
	m, _ = feed(m, typed("doc"), &protocol.Panel{Title: "Help", Kind: "text", Text: "hello"})
	if m.Panel.Title != "Help" || m.Panel.Query != "" || m.Panel.Kind != "text" {
		t.Errorf("panel = %+v, want the new text panel with no filter", m.Panel)
	}
}
