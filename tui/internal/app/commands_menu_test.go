package app

import (
	"reflect"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func menuModel() state.Model {
	m := withV3Commands()
	m.Commands = append(m.Commands, protocol.Command{Name: "/compact [INSTRUCTIONS]", Description: "summarise"})
	return m
}

func highlightRow(t *testing.T, m state.Model, title string) state.Model {
	t.Helper()
	for i, it := range m.Panel.Visible() {
		if it.Title == title {
			m.Panel.Selected = i
			return m
		}
	}
	t.Fatalf("no row %q in the panel", title)
	return m
}

func TestCtrlPOpensTheCommandsPanel(t *testing.T) {
	m, cmds := feed(menuModel(), press("ctrl+p"))
	if len(cmds) != 0 || !m.Panel.Open || m.Panel.Kind != "commands" || m.Panel.Title != "Commands" {
		t.Errorf("commands = %v, Panel = %+v; want the commands panel and nothing sent", cmds, m.Panel)
	}
}

func TestHelpOpensTheCommandsPanelWithoutAskingTheBackend(t *testing.T) {
	m, cmds := feed(menuModel(), typed("/help"), press("enter"))
	if len(cmds) != 0 || !m.Panel.Open || m.Panel.Kind != "commands" {
		t.Errorf("commands = %v, Panel = %+v; want the commands panel and nothing sent", cmds, m.Panel)
	}
	if len(m.Blocks) != 0 || m.Busy {
		t.Errorf("Blocks = %+v, Busy = %v; want the panel only", m.Blocks, m.Busy)
	}
}

func TestHelpOpensWhileATurnRuns(t *testing.T) {
	m := menuModel()
	m.Busy = true
	m, cmds := feed(m, typed("/help"), press("enter"))
	if len(cmds) != 0 || !m.Panel.Open || len(m.Queue) != 0 {
		t.Errorf("commands = %v, Open = %v, Queue = %v; want the panel and nothing queued", cmds, m.Panel.Open, m.Queue)
	}
}

func TestTypingInTheCommandsPanelFiltersIt(t *testing.T) {
	m, _ := feed(menuModel(), press("ctrl+p"), typed("compact"))
	if got := m.Panel.Visible(); len(got) != 1 || got[0].Title != "/compact [INSTRUCTIONS]" {
		t.Errorf("Visible = %+v, want only /compact", got)
	}
}

func TestChoosingACommandThatNeedsAnArgumentPutsItInTheInput(t *testing.T) {
	m, _ := feed(menuModel(), press("ctrl+p"))
	m = highlightRow(t, m, "/btw <pregunta>")
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Panel.Open || m.Input != "/btw " || m.Cursor != len("/btw ") {
		t.Errorf("commands = %v, Open = %v, Input = %q, Cursor = %d; want the panel closed and \"/btw \" waiting for the question",
			cmds, m.Panel.Open, m.Input, m.Cursor)
	}
}

func TestChoosingACommandWithoutArgumentsRunsIt(t *testing.T) {
	m, _ := feed(menuModel(), press("ctrl+p"))
	m = highlightRow(t, m, "/sessions")
	m, cmds := feed(m, press("enter"))
	if m.Panel.Kind != "sessions" || !m.Panel.Open {
		t.Errorf("Panel = %+v, want the sessions panel the command opens", m.Panel)
	}
	if want := []any{protocol.NewSessionList()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestChoosingACommandWithOptionalArgumentsRunsItBare(t *testing.T) {
	m, _ := feed(menuModel(), press("ctrl+p"))
	m = highlightRow(t, m, "/compact [INSTRUCTIONS]")
	m, _ = feed(m, press("enter"))
	if m.Panel.Kind != "compact" || !m.Panel.Open {
		t.Errorf("Panel = %+v, want the compact line the bare command opens", m.Panel)
	}
}

func TestRunningACommandFromThePanelKeepsTheMessageBeingTyped(t *testing.T) {
	m, _ := feed(menuModel(), typed("a draft message"), press("ctrl+p"))
	m = highlightRow(t, m, "/sessions")
	m, _ = feed(m, press("enter"))
	if m.Input != "a draft message" || m.Cursor != len("a draft message") {
		t.Errorf("Input = %q, Cursor = %d; want the draft kept", m.Input, m.Cursor)
	}
}

func TestACommandThatNeedsTypingWaitsForTheDraftToBeCleared(t *testing.T) {
	m, _ := feed(menuModel(), typed("a draft message"), press("ctrl+p"))
	m = highlightRow(t, m, "/btw <pregunta>")
	m, _ = feed(m, press("enter"))
	if m.Input != "a draft message" || !m.Panel.Open || m.Flash == "" {
		t.Errorf("Input = %q, Open = %v, Flash = %q; want the draft kept, the panel open and a note", m.Input, m.Panel.Open, m.Flash)
	}
}

func TestChoosingSubagentSyntaxOpensTheSubagentMenu(t *testing.T) {
	m, _ := feed(menuModel(), press("ctrl+p"))
	m = highlightRow(t, m, "@NAME TASK")
	m, _ = feed(m, press("enter"))
	if m.Input != "@" || len(m.Suggestions) == 0 || m.Suggestions[0].Kind != state.SuggestSubagent {
		t.Errorf("Input = %q, Suggestions = %+v; want @ with the subagents offered", m.Input, m.Suggestions)
	}
}

func TestAKeysRowHasNothingToRun(t *testing.T) {
	m, _ := feed(menuModel(), press("ctrl+p"))
	m = highlightRow(t, m, "ctrl+o")
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || !m.Panel.Open || m.Input != "" {
		t.Errorf("commands = %v, Open = %v, Input = %q; want nothing to happen", cmds, m.Panel.Open, m.Input)
	}
}

func TestEscClosesTheCommandsPanel(t *testing.T) {
	m, _ := feed(menuModel(), press("ctrl+p"), press("esc"))
	if m.Panel.Open {
		t.Error("esc did not close the panel")
	}
}

func TestNoMatchIsSetWhenNoCommandMatchesAndClearedByEscOrANewMatch(t *testing.T) {
	m, _ := feed(menuModel(), typed("/zzz"))
	if m.NoMatch != "/zzz" || len(m.Suggestions) != 0 {
		t.Fatalf("NoMatch = %q, Suggestions = %d; want the note set", m.NoMatch, len(m.Suggestions))
	}
	m, _ = feed(m, press("esc"))
	if m.NoMatch != "" || m.Input != "/zzz" {
		t.Errorf("NoMatch = %q, Input = %q; want the note gone and the input kept", m.NoMatch, m.Input)
	}
	m, _ = feed(menuModel(), typed("/zzz"), press("backspace"), press("backspace"), press("backspace"), typed("h"))
	if m.NoMatch != "" || len(m.Suggestions) == 0 {
		t.Errorf("NoMatch = %q, Suggestions = %d; want matches again and no note", m.NoMatch, len(m.Suggestions))
	}
	m, _ = feed(menuModel(), typed("hello /zzz"))
	if m.NoMatch != "" {
		t.Errorf("NoMatch = %q for a message that is not a command", m.NoMatch)
	}
	m, _ = feed(menuModel(), typed("/zzz"), press("enter"))
	if m.NoMatch != "" {
		t.Errorf("NoMatch = %q after the line was sent", m.NoMatch)
	}
}
