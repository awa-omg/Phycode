package view

import (
	"os"
	"regexp"
	"strings"
	"testing"

	"github.com/charmbracelet/lipgloss"
	"github.com/muesli/termenv"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

var csi = regexp.MustCompile(`\x1b\[[0-9;?]*[ -/]*[@-~]`)

func TestMain(m *testing.M) {
	_ = os.Unsetenv("NO_COLOR")
	_ = os.Setenv("CLICOLOR_FORCE", "1")
	os.Exit(m.Run())
}

func plain(s string) string {
	return csi.ReplaceAllString(s, "")
}

func screen(t *testing.T, r *Renderer, m state.Model) []string {
	t.Helper()
	rows := strings.Split(r.View(m), "\n")
	if len(rows) != m.Height {
		t.Fatalf("screen has %d rows, want %d", len(rows), m.Height)
	}
	for i, row := range rows {
		if got := lipgloss.Width(row); got != m.Width {
			t.Fatalf("row %d is %d cells wide, want %d: %q", i, got, m.Width, plain(row))
		}
	}
	return rows
}

func rowOf(rows []string, s string) int {
	for i, row := range rows {
		if strings.Contains(plain(row), s) {
			return i
		}
	}
	return -1
}

func newModel(w, h int) state.Model {
	m := state.New()
	m.Width, m.Height = w, h
	m.Ready = true
	m.App = protocol.AppInfo{
		Version:        "0.1.0",
		Cwd:            "/work/phycode",
		Agent:          "build",
		Profile:        "default",
		Model:          "qwen",
		ToolMode:       "full",
		Sandbox:        "host",
		PermissionMode: "ask",
	}
	m.Agents = []protocol.Agent{{Name: "build", Mode: "primary"}, {Name: "explore", Mode: "subagent"}}
	return m
}

func plainRows(rows []string) []string {
	out := make([]string, len(rows))
	for i, row := range rows {
		out[i] = strings.TrimRight(plain(row), " ")
	}
	return out
}

func twoDigits(n int) string {
	return string(rune('0'+n/10)) + string(rune('0'+n%10))
}

func boxBounds(t *testing.T, rows []string) (int, int) {
	t.Helper()
	bottom := len(rows) - 2
	if bottom < 0 {
		t.Fatalf("screen too short for an input band: %q", plainRows(rows))
	}
	top := bottom
	for top > 0 && strings.HasPrefix(plain(rows[top-1]), " ▌") {
		top--
	}
	if !strings.HasPrefix(plain(rows[bottom]), " ▌") {
		t.Fatalf("the row above the status line is not the input band: %q", plainRows(rows))
	}
	return top, bottom
}

func highlightedRows(rows []string) []string {
	var out []string
	for _, row := range rows {
		if p := plain(row); onBar(p) {
			out = append(out, p)
		}
	}
	return out
}

func trueColour(t *testing.T) {
	t.Helper()
	lipgloss.SetColorProfile(termenv.TrueColor)
	t.Cleanup(func() { lipgloss.SetColorProfile(termenv.ANSI) })
}

func wearsBar(row string) bool {
	return strings.Contains(row, strings.TrimPrefix(bgSeq(theme.Default().P.Select), "\x1b["))
}

func onBar(row string) bool {
	return barInBox.MatchString(row)
}

var barInBox = regexp.MustCompile(`^ ▌\s+›`)

func dockRowsOf(rows []string) []string {
	lip := -1
	for i, row := range rows {
		if strings.HasPrefix(plain(row), " ▄▄") {
			lip = i
			break
		}
	}
	if lip < 0 {
		return nil
	}
	var out []string
	for _, row := range rows[lip+1 : len(rows)-1] {
		out = append(out, plain(row))
	}
	return out
}

func inDock(t *testing.T, r *Renderer, m state.Model) []string {
	t.Helper()
	rows := dockRowsOf(screen(t, r, m))
	if rows == nil {
		t.Fatalf("no dock on the screen")
	}
	for i, row := range rows {
		rows[i] = strings.TrimRight(strings.TrimPrefix(row, " ▌"), " ")
	}
	return rows
}

func styledAs(row, text string) string {
	i := strings.Index(row, text)
	if i < 0 {
		return ""
	}
	j := strings.LastIndex(row[:i], sgrReset)
	if j < 0 {
		j = 0
	}
	return row[j:i]
}

func onBarRow(row string) bool {
	return strings.HasPrefix(strings.TrimSpace(row), "›")
}

func plainRowsOfDock(t *testing.T, r *Renderer, m state.Model) []string {
	t.Helper()
	return dockRowsOf(screen(t, r, m))
}

func highlightIn(rows []string) string {
	for _, row := range rows {
		if onBarRow(row) {
			return row
		}
	}
	return ""
}

func rowStarting(rows []string, label string) int {
	for i, row := range rows {
		words := strings.Fields(row)
		if len(words) > 0 && words[0] == "›" {
			words = words[1:]
		}
		if len(words) > 0 && words[0] == label {
			return i
		}
	}
	return -1
}
