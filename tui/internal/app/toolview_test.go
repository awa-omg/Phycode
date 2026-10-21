package app

import (
	"reflect"
	"testing"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func lineNumber(n int) *int { return &n }

func changeOfOneLine() *protocol.Diff {
	return &protocol.Diff{Path: "a.go", Added: 1, Removed: 1, Hunks: []protocol.Hunk{{
		OldStart: 10, OldLines: 1, NewStart: 10, NewLines: 1,
		Lines: []protocol.DiffLine{
			{Kind: protocol.DiffDel, Text: "return 1", OldNo: lineNumber(10)},
			{Kind: protocol.DiffAdd, Text: "return 2", NewNo: lineNumber(10)},
		},
	}}}
}

func TestToolEndWithADiffFillsTheToolBlock(t *testing.T) {
	d := changeOfOneLine()
	m, _ := feed(state.New(),
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "build", Tool: "write_file", Summary: "a.go"},
		&protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t1", OK: true, Summary: "edited a.go", Diff: d},
	)
	want := state.Block{Kind: state.KindTool, ID: "t1", Agent: "build", Tool: "write_file", Summary: "a.go",
		Text: "edited a.go", Status: "ok", Diff: d}
	if len(m.Blocks) != 1 || !reflect.DeepEqual(m.Blocks[0], want) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
	}
}

func TestToolEndWithoutADiffHasNone(t *testing.T) {
	m, _ := feed(state.New(),
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Tool: "read", Summary: "a.go"},
		&protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t1", OK: true, Summary: "package a"},
	)
	if len(m.Blocks) != 1 || m.Blocks[0].Diff != nil {
		t.Errorf("Blocks = %+v, want a block with no diff", m.Blocks)
	}
}

func TestConfirmKeepsItsPreview(t *testing.T) {
	d := changeOfOneLine()
	m, _ := feed(state.New(), &protocol.Confirm{Type: protocol.TypeConfirm, ID: "c1", Tool: "edit", Category: "write", Agent: "build", Summary: "a.go", Preview: d})
	if m.Confirm == nil || m.Confirm.Preview != d {
		t.Errorf("Confirm = %+v, want the preview of the change", m.Confirm)
	}
}

func TestCtrlOTogglesTheToolsExpanded(t *testing.T) {
	m := testModel()
	m, cmds := feed(m, tea.KeyMsg{Type: tea.KeyCtrlO})
	if len(cmds) != 0 || !m.ToolsExpanded {
		t.Fatalf("after one ctrl+o: ToolsExpanded %v, commands %v; want it expanded and no command", m.ToolsExpanded, cmds)
	}
	m, _ = feed(m, tea.KeyMsg{Type: tea.KeyCtrlO})
	if m.ToolsExpanded {
		t.Error("after the second ctrl+o the tools are still expanded")
	}
}

func TestCtrlOWaitsForAPendingApproval(t *testing.T) {
	m := testModel()
	m.Confirm = &state.Confirm{ID: "c1", Tool: "edit"}
	m, cmds := feed(m, tea.KeyMsg{Type: tea.KeyCtrlO})
	if m.ToolsExpanded || len(cmds) != 0 || m.Confirm == nil {
		t.Errorf("ctrl+o during an approval: ToolsExpanded %v, commands %v, Confirm %v; want nothing changed",
			m.ToolsExpanded, cmds, m.Confirm)
	}
}

func TestCtrlOWorksWhileATurnRuns(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, _ = feed(m, tea.KeyMsg{Type: tea.KeyCtrlO})
	if !m.ToolsExpanded {
		t.Error("ctrl+o does not expand the tools during a turn")
	}
}
