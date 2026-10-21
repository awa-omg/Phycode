package view

import (
	"strings"
	"testing"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func TestHueSetCountsOnlyTheColoursOfAnyRow(t *testing.T) {
	st := theme.Default()
	if got := len(hueSet(st.OK.Render("✓") + " " + st.Output.Render("read") + st.Fail.Render("✗"))); got != 2 {
		t.Errorf("a green and a red mark count %d colours, want 2", got)
	}
	if got := len(hueSet(st.Dim.Render("muted") + " " + st.Output.Render("text") + st.Heading.Render(" bold"))); got != 0 {
		t.Errorf("greys count %d colours, want 0", got)
	}
}

func TestCtrlOExpandsEveryToolOutput(t *testing.T) {
	r := NewRenderer(theme.Default())
	var lines []string
	for i := 1; i <= 20; i++ {
		lines = append(lines, "line "+twoDigits(i))
	}
	m := newModel(80, 40)
	m.Blocks = []state.Block{{Kind: state.KindTool, Tool: "bash", Summary: "ls", Status: "ok", Text: strings.Join(lines, "\n")}}
	rows := screen(t, r, m)
	if rowOf(rows, "line 02") >= 0 {
		t.Errorf("collapsed: the output of a call that went well is shown: %q", plainRows(rows))
	}

	m.ToolsExpanded = true
	rows = screen(t, r, m)
	if rowOf(rows, "line 20") < 0 || rowOf(rows, "more lines") >= 0 {
		t.Errorf("expanded: the output is still clipped: %q", plainRows(rows))
	}
}

func TestCtrlOExpandsEverySubagentReport(t *testing.T) {
	r := NewRenderer(theme.Default())
	var report []string
	for i := 1; i <= 10; i++ {
		report = append(report, "report "+twoDigits(i))
	}
	m := newModel(60, 60)
	m.Blocks = []state.Block{{Kind: state.KindSubagent, Agent: "explore", Text: "find it", Summary: strings.Join(report, "\n"), Status: "completed"}}
	if rowOf(screen(t, r, m), "report 07") >= 0 {
		t.Fatal("collapsed: the report shows more than six lines")
	}
	m.ToolsExpanded = true
	if rowOf(screen(t, r, m), "report 10") < 0 {
		t.Error("expanded: the report is still clipped")
	}
}
