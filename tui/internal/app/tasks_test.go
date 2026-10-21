package app

import (
	"strings"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func delegated() state.Model {
	m, _ := feed(testModel(), &protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s1", Agent: "explore", Task: "find the parser", Parent: "build"},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "explore", Tool: "grep", Summary: "parse", Parent: "build", Depth: 1},
	)
	return m
}

func TestSubagentsStartAndEndWithTheClock(t *testing.T) {
	m := delegated()
	if m.Blocks[0].Started.IsZero() || !m.Blocks[0].Ended.IsZero() {
		t.Fatalf("running subagent: Started = %v, Ended = %v; want a start and no end", m.Blocks[0].Started, m.Blocks[0].Ended)
	}
	m, _ = feed(m, &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Status: "completed", Summary: "found"})
	if m.Blocks[0].Ended.IsZero() || m.Blocks[0].Ended.Before(m.Blocks[0].Started) {
		t.Errorf("ended subagent: Started = %v, Ended = %v; want an end after the start", m.Blocks[0].Started, m.Blocks[0].Ended)
	}
}

func TestCtrlGAndTheCommandsOpenTheTasksPanelWithoutTheBackend(t *testing.T) {
	for name, steps := range map[string][]any{
		"ctrl+g":     {press("ctrl+g")},
		"/tasks":     {typed("/tasks"), press("enter")},
		"/subagents": {typed("/subagents"), press("enter")},
	} {
		m, cmds := feed(delegated(), steps...)
		if len(cmds) != 0 || !m.Panel.Open || m.Panel.Kind != "tasks" || len(m.Panel.Items) != 1 {
			t.Errorf("%s: commands = %v, Panel = %+v; want the tasks panel with explore and nothing sent", name, cmds, m.Panel)
		}
		if len(m.Queue) != 0 {
			t.Errorf("%s: Queue = %v, want the command to run while the turn runs", name, m.Queue)
		}
	}
}

func TestTheTasksPanelFollowsTheSubagentsWhileItIsOpen(t *testing.T) {
	m, _ := feed(delegated(), press("ctrl+g"))
	if it, _ := m.Panel.Highlighted(); it.Category != state.TaskWorking {
		t.Fatalf("highlighted = %+v, want explore under Working", it)
	}
	m, _ = feed(m, &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Status: "completed", Summary: "found"})
	if it, _ := m.Panel.Highlighted(); it.Category != state.TaskDone || it.Status != "ok" {
		t.Errorf("highlighted = %+v after the end, want explore under Done", it)
	}
	m, _ = feed(m, &protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s2", Agent: "general", Task: "write", Parent: "build"})
	if len(m.Panel.Items) != 2 {
		t.Errorf("Items = %d after a second subagent started, want 2", len(m.Panel.Items))
	}
	if id, _ := m.Panel.SubagentID(); id != "s1" {
		t.Errorf("highlighted = %q, want the highlight to stay on explore", id)
	}
}

func TestEnterOnASubagentClosesThePanelAndShowsItsCard(t *testing.T) {
	m := delegated()
	m.Width, m.Height = 80, 14
	for i := 0; i < 12; i++ {
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindNotice, Level: "info", Text: strings.Repeat("later ", 3)})
	}
	m.Blocks[0].Folded = true
	m.Blocks[0].Status = "completed"
	m, _ = feed(m, press("ctrl+g"), press("enter"))
	if m.Panel.Open {
		t.Error("the panel is still open")
	}
	if m.Blocks[0].Folded {
		t.Error("the card of the subagent is still folded")
	}
	if m.Scroll <= 0 {
		t.Errorf("Scroll = %d, want the transcript scrolled up to the card", m.Scroll)
	}
}

func TestEnterOnAnEmptyTasksPanelDoesNothing(t *testing.T) {
	m, cmds := feed(testModel(), press("ctrl+g"), press("enter"))
	if len(cmds) != 0 || !m.Panel.Open {
		t.Errorf("commands = %v, Open = %v; want the panel to stay", cmds, m.Panel.Open)
	}
	m, _ = feed(m, press("esc"))
	if m.Panel.Open {
		t.Error("esc did not close the panel")
	}
}

func TestTheEndOfASubagentIsFlashedWhenTheUserLooksAtOlderOutput(t *testing.T) {
	m := delegated()
	m.Scroll = 5
	m, _ = feed(m, &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Status: "completed", Summary: "found"})
	if !strings.HasPrefix(m.Flash, "◆ explore completed · ") {
		t.Errorf("Flash = %q, want the end of explore", m.Flash)
	}
	m = delegated()
	m, _ = feed(m, &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Status: "completed", Summary: "found"})
	if m.Flash != "" {
		t.Errorf("Flash = %q while the card is in view, want none", m.Flash)
	}
}
