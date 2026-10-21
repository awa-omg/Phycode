package view

import (
	"regexp"
	"strconv"
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

var sgrPattern = regexp.MustCompile(`\x1b\[([0-9;]*)m`)

func rowColours(row string) (fg, bg []string) {
	for _, m := range sgrPattern.FindAllStringSubmatch(row, -1) {
		parts := strings.Split(m[1], ";")
		for i := 0; i < len(parts); i++ {
			code, _ := strconv.Atoi(parts[i])
			var name string
			switch {
			case (code == 38 || code == 48) && i+2 < len(parts) && parts[i+1] == "5":
				name = strings.Join(parts[i:i+3], ";")
				i += 2
			case (code == 38 || code == 48) && i+4 < len(parts) && parts[i+1] == "2":
				name = strings.Join(parts[i:i+5], ";")
				i += 4
			case code >= 30 && code <= 37, code >= 90 && code <= 97:
				fg = append(fg, parts[i])
				continue
			case code >= 40 && code <= 47, code >= 100 && code <= 107:
				bg = append(bg, parts[i])
				continue
			default:
				continue
			}
			if code == 38 {
				fg = append(fg, name)
			} else {
				bg = append(bg, name)
			}
		}
	}
	return fg, bg
}

func hueSet(row string) map[string]bool {
	neutral := map[string]bool{}
	for _, s := range []lipgloss.Style{theme.Default().Output, theme.Default().Dim, theme.Default().Rule} {
		fg, _ := rowColours(s.Render("x"))
		for _, c := range fg {
			neutral[c] = true
		}
	}
	out := map[string]bool{}
	fg, _ := rowColours(row)
	for _, c := range fg {
		if !neutral[c] {
			out[c] = true
		}
	}
	return out
}

func coloredModel(w, h int) state.Model {
	d := modifiedDiff()
	m := newModel(w, h)
	m.Blocks = []state.Block{
		{Kind: state.KindUser, Text: "fix the return value in a.go"},
		{Kind: state.KindTool, ID: "t1", Tool: "read", Summary: "a.go", Status: "ok", Text: "package a"},
		{Kind: state.KindTool, ID: "t2", Tool: "write_file", Summary: "a.go", Status: "ok", Diff: &d},
		{Kind: state.KindTool, ID: "t3", Tool: "bash", Summary: "go test", Status: "error", Text: "FAIL"},
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find the callers", Summary: "three", Status: "completed"},
		{Kind: state.KindNotice, Level: "warn", Text: "close to the limit"},
		{Kind: state.KindOutput, Text: "/agents\n  build      primary"},
	}
	m.Input = "next"
	m.Cursor = 4
	m.Suggestions = []state.Suggestion{{Insert: "/agent ", Label: "/agent NAME", Description: "switch the agent"}, {Insert: "/help", Label: "/help", Description: "show help"}}
	m.Queue = []string{"later"}
	m.QueuePaused = true
	return m
}

func approvalOver(m state.Model) state.Model {
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"}
	return m
}

func topBorder(row string) string {
	i := strings.Index(row, "╭")
	j := strings.Index(row, "╮")
	if i < 0 || j < i {
		return ""
	}
	return row[i : j+len("╮")]
}
