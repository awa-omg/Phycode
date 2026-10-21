package view

import (
	"fmt"
	"strings"
	"testing"
	"time"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func workingModel(w, h int) state.Model {
	now := time.Now()
	m := newModel(w, h)
	m.Busy = true
	m.Blocks = []state.Block{
		{Kind: state.KindUser, Text: "refactor the loader"},
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find every caller", Status: "running", Started: now.Add(-23 * time.Second)},
		{Kind: state.KindTool, ID: "t1", Agent: "explore", Depth: 1, Parent: "s1", Tool: "grep", Summary: "grep Config::load", Status: "ok", Text: "12 matches"},
		{Kind: state.KindTool, ID: "t2", Agent: "explore", Depth: 1, Parent: "s1", Tool: "read", Summary: "src/Config.php", Status: "ok"},
		{Kind: state.KindTool, ID: "t3", Agent: "explore", Depth: 1, Parent: "s1", Tool: "bash", Summary: "phpunit", Status: "error", Text: "FAIL: 2 tests\nmore"},
		{Kind: state.KindTool, ID: "t4", Agent: "explore", Depth: 1, Parent: "s1", Tool: "read", Summary: "tests/ConfigTest.php", Status: "running"},
		{Kind: state.KindSubagent, ID: "s2", Agent: "general", Text: "write notes", Status: "completed", Summary: "Notes written", Started: now.Add(-40 * time.Second), Ended: now.Add(-9 * time.Second)},
	}
	return m
}

func TestASubagentCardNamesTheAgentWhatItDoesAndForHowLong(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, workingModel(100, 40)))
	if row := rowWith(rows, "◆ explore"); !strings.Contains(row, "working 23s") || !strings.Contains(row, "4 tools") {
		t.Errorf("explore title = %q, want that it works, for 23s, with 4 tools", row)
	}
	if row := rowWith(rows, "◆ general"); !strings.Contains(row, "✓ done 31s") || strings.Contains(row, "tool") {
		t.Errorf("general title = %q, want that it is done after 31s", row)
	}
}

func TestTheAgentNameInTheCardUsesItsTone(t *testing.T) {
	r := NewRenderer(theme.Default())
	s := theme.Default()
	rows := screen(t, r, workingModel(100, 40))
	if row := rows[rowOf(rows, "◆ explore")]; !strings.Contains(row, s.Tone("explore").Render("◆ explore")) {
		t.Errorf("explore title = %q, want the name in its tone", row)
	}
}

func TestToolCallsOfASubagentAreOneLineEachInATree(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, workingModel(100, 40)))
	for _, want := range []string{"├─ ✓ grep Config::load", "├─ ✓ read src/Config.php", "├─ ✗ bash phpunit", "╰─ "} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the tree has no %q: %q", want, rows)
		}
	}
	if row := rowWith(rows, "✗ bash phpunit"); !strings.Contains(row, "FAIL: 2 tests") || strings.Contains(row, "more") {
		t.Errorf("failed call = %q, want the first line of what went wrong only", row)
	}
	if last := rowWith(rows, "tests/ConfigTest.php"); !strings.Contains(last, "╰─") {
		t.Errorf("the last call = %q, want it to close the tree", last)
	}
	for _, card := range []string{"12 matches", "grep ───"} {
		if rowOf(rows, card) >= 0 {
			t.Errorf("a tool call that is in the tree also has a card of its own: %q", rows)
		}
	}
}

func TestTheTreeKeepsTheLastCallsAndCountsTheRest(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(100, 40)
	for i := 5; i <= 9; i++ {
		id := fmt.Sprintf("t%d", i)
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindTool, ID: id, Parent: "s1", Depth: 1, Tool: "read", Summary: "file" + id, Status: "ok"})
	}
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "… 5 earlier") < 0 {
		t.Errorf("the calls that do not fit are not counted: %q", rows)
	}
	if rowOf(rows, "filet9") < 0 || rowOf(rows, "filet6") < 0 || rowOf(rows, "filet5") >= 0 {
		t.Errorf("the tree does not keep the last %d calls: %q", maxTreeRows, rows)
	}
}

func TestToolCallsKeepTheirCardsWhileExpanded(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(100, 60)
	m.ToolsExpanded = true
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "12 matches") < 0 {
		t.Errorf("expanded tools lost their cards: %q", rows)
	}
	if rowOf(rows, "├─") >= 0 {
		t.Errorf("a tree is drawn over the cards while expanded: %q", rows)
	}
}

func TestAToolCallWithADiffKeepsItsCardAndStaysOutOfTheTree(t *testing.T) {
	r := NewRenderer(theme.Default())
	d := modifiedDiff()
	m := workingModel(100, 60)
	m.Blocks = append(m.Blocks, state.Block{Kind: state.KindTool, ID: "t9", Parent: "s1", Depth: 1, Tool: "write_file", Summary: "a.go", Status: "ok", Diff: &d})
	rows := plainRows(screen(t, r, m))
	if i := rowOf(rows, "write_file"); i < 0 || strings.Contains(rows[i], "├─") || strings.Contains(rows[i], "╰─") {
		t.Errorf("the edit is not a card of its own: %q", rows)
	}
}

func TestAFoldedSubagentDrawsNoTree(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(100, 40)
	m.Blocks[1].Folded = true
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "├─") >= 0 || rowOf(rows, "phpunit") >= 0 {
		t.Errorf("a folded subagent still draws its tree: %q", rows)
	}
	if row := rowWith(rows, "▸ ◆ explore"); !strings.Contains(row, "hidden") {
		t.Errorf("folded title = %q, want the count of the cards it hides", row)
	}
}

func TestASubagentThatAsksForApprovalSaysSoInItsTitle(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(100, 40)
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Agent: "explore", Depth: 1, Summary: "rm -rf build"}
	rows := plainRows(screen(t, r, m))
	if row := rowWith(rows, "◆ explore"); !strings.Contains(row, "⚠ needs your approval") {
		t.Errorf("explore title = %q, want that it waits for the approval", row)
	}
	if row := rowWith(rows, "◆ general"); strings.Contains(row, "approval") {
		t.Errorf("general title = %q, but it did not ask", row)
	}
}

func TestAFailedSubagentSaysHowItEnded(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(100, 40)
	m.Blocks[6].Status = "budget"
	if row := rowWith(plainRows(screen(t, r, m)), "◆ general"); !strings.Contains(row, "✗ budget") {
		t.Errorf("general title = %q, want ✗ budget", row)
	}
}

func TestTheStatusLineCountsTheSubagentsThatRun(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(120, 40)
	rows := screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "◆ 1 subagent ·") {
		t.Errorf("status = %q, want ◆ 1 subagent", status)
	}
	m.Blocks[6].Status = "running"
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "◆ 2 subagents") {
		t.Errorf("status = %q, want ◆ 2 subagents", status)
	}
	m.Blocks[1].Status, m.Blocks[6].Status = "completed", "completed"
	m.Busy = false
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); strings.Contains(status, "◆") {
		t.Errorf("status = %q, want no slot once no subagent runs", status)
	}
}

func TestOnceThereIsASubagentTheHintNamesItsKey(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, workingModel(120, 40)))
	status := rows[len(rows)-1]
	if !strings.Contains(status, "ctrl+g subagents") || strings.Contains(status, "@ subagents") {
		t.Errorf("status = %q, want ctrl+g subagents in place of @ subagents", status)
	}
	if status := plain(screen(t, r, newModel(120, 24))[23]); !strings.Contains(status, "@ subagents") {
		t.Errorf("status = %q, want @ subagents while the session has none", status)
	}
}

func TestTheTasksPanelListsGroupsWithTheirRows(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(100, 40)
	m.Panel = state.TasksPanel(m, time.Now())
	rows := inDock(t, r, m)
	order := []string{"Subagents", "Working", "explore", "Done", "general"}
	last := -1
	for _, want := range order {
		i := rowOf(rows, want)
		if i <= last {
			t.Fatalf("%q at row %d, want it after row %d: %q", want, i, last, rows)
		}
		last = i
	}
	if row := rowWith(rows, "explore  find"); !strings.Contains(row, "4 tools") || !strings.Contains(row, "23s") {
		t.Errorf("explore row = %q, want its time and its tools at the right", row)
	}
	if rowOf(rows, "show in the transcript") < 0 {
		t.Errorf("the hint is missing: %q", rows)
	}
}

func TestTheTasksPanelTellsWhatToDoWhenThereAreNoSubagents(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(100, 30)
	m.Panel = state.TasksPanel(m, time.Now())
	if rowOf(plainRows(screen(t, r, m)), "no subagents yet · @NAME TASK hands a task to one") < 0 {
		t.Error("the empty panel does not say how to start a subagent")
	}
}

func TestTheTasksPanelAnimatesTheSpinnerOfWhatWorks(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := workingModel(100, 40)
	m.Panel = state.TasksPanel(m, time.Now())
	m.Frame = 0
	a := plain(screen(t, r, m)[rowOf(screen(t, r, m), "explore  find")])
	m.Frame = 3
	b := plain(screen(t, r, m)[rowOf(screen(t, r, m), "explore  find")])
	if a == b {
		t.Errorf("the working row does not change from frame 0 to frame 3: %q", a)
	}
}

func TestTheTasksPanelFitsEverySize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{1, 8, 20, 40, 80, 120} {
		for _, h := range []int{1, 4, 8, 12, 24, 40} {
			m := workingModel(w, h)
			m.Panel = state.TasksPanel(m, time.Now())
			m.Confirm = &state.Confirm{Agent: "explore", Depth: 1, Tool: "bash"}
			screen(t, r, m)
			m.Confirm = nil
			m.Blocks = nil
			m.Panel = state.TasksPanel(m, time.Now())
			screen(t, r, m)
		}
	}
}

func TestBlockScrollBringsTheCardToTheTopOfTheTranscript(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 20)
	m.Blocks = append(m.Blocks, state.Block{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find it", Status: "completed", Summary: "found", Started: time.Now(), Ended: time.Now()})
	for i := 0; i < 20; i++ {
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindNotice, Level: "info", Text: fmt.Sprintf("note %02d", i)})
	}
	m.Scroll = BlockScroll(m, "s1")
	if m.Scroll <= 0 {
		t.Fatalf("Scroll = %d, want the transcript scrolled up to the card", m.Scroll)
	}
	rows := screen(t, r, m)
	if i := rowOf(rows, "◆ explore"); i < 0 || i > 3 {
		t.Errorf("the card title is at row %d, want it at the top of the transcript: %q", i, plainRows(rows))
	}
	if BlockScroll(m, "missing") != 0 {
		t.Error("BlockScroll of a card that is not there is not 0")
	}
}

func TestTheTreeNeedsFewerRowsThanTheCardsItReplaces(t *testing.T) {
	compact, expanded := workingModel(100, 14), workingModel(100, 14)
	expanded.ToolsExpanded = true
	if MaxScroll(compact) >= MaxScroll(expanded) {
		t.Errorf("MaxScroll compact = %d, expanded = %d: the tree must take fewer rows than the cards", MaxScroll(compact), MaxScroll(expanded))
	}
}
