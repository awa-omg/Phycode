package view

import (
	"slices"
	"strconv"
	"strings"
	"testing"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func TestUserAndAssistantBlocks(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Blocks = []state.Block{
		{Kind: state.KindUser, Text: "hello there"},
		{Kind: state.KindAssistant, Text: "Some **bold** text."},
	}
	rows := screen(t, r, m)
	user := rowOf(rows, "▌ hello there")
	if user < 0 {
		t.Fatalf("the user message is missing: %q", plainRows(rows))
	}
	if _, bg := rowColours(rows[user]); len(bg) == 0 {
		t.Errorf("the message of the user is not a band: %q", rows[user])
	}
	answer := rowOf(rows, "Some bold text.")
	if answer < 0 {
		t.Fatalf("the answer is missing: %q", plainRows(rows))
	}
	if got := cellAt(plain(rows[answer]), "Some"); got != 3 {
		t.Errorf("the answer starts at column %d, want 3, after its mark", got)
	}
	if !strings.HasPrefix(plain(rows[answer]), " ● ") {
		t.Errorf("the first row of the answer has no mark: %q", plain(rows[answer]))
	}
}

func TestUserBlockWrapsUnderItsText(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(40, 24)
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: "one two three four five six seven eight nine ten"}}
	rows := screen(t, r, m)
	first := rowOf(rows, "▌ one")
	if first < 0 {
		t.Fatalf("the message is missing: %q", plainRows(rows))
	}
	if next := plain(rows[first+1]); !strings.HasPrefix(next, " ▌ ") || !strings.Contains(next, "eight") {
		t.Errorf("continuation row = %q, want the bar to go on and the text to stay under the text", next)
	}
}

func TestMarkdownHasNoRawFences(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{{Kind: state.KindAssistant, Text: "Here it is:\n\n```go\nfunc main() {}\n```\n\n1. first item\n2. second item\n"}}
	raw := strings.Join(screen(t, r, m), "\n")
	text := plain(raw)
	if strings.Contains(text, "```") {
		t.Errorf("raw fence markers left in the output:\n%s", text)
	}
	for _, want := range []string{"func main() {}", "first item", "second item"} {
		if !strings.Contains(text, want) {
			t.Errorf("the output lacks %q:\n%s", want, text)
		}
	}
}

func TestMarkdownStaysWithinTheWidth(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(50, 30)
	text := strings.Repeat("A paragraph with several words that must wrap at the edge. ", 6)
	m.Blocks = []state.Block{{Kind: state.KindAssistant, Text: text}}
	rows := screen(t, r, m)
	if got := strings.Count(strings.Join(plainRows(rows), " "), "paragraph"); got != 6 {
		t.Errorf("the paragraph shows %d times, want 6:\n%s", got, strings.Join(plainRows(rows), "\n"))
	}
}

func TestMarkdownCacheIsBounded(t *testing.T) {
	r := NewRenderer(theme.Default())
	for i := 0; i < maxCachedMarkdown+100; i++ {
		r.markdown("answer "+strconv.Itoa(i), 5)
	}
	if n := len(r.md); n > maxCachedMarkdown+1 {
		t.Errorf("the cache holds %d renders, want at most %d", n, maxCachedMarkdown+1)
	}

	r.markdown("again", 60)
	before := len(r.md)
	first := r.markdown("again", 60)
	if len(r.md) != before || len(first) == 0 {
		t.Errorf("a cached render was not reused: %q", first)
	}
}

func TestMarkdownCacheDropsTheOldestRendersPastItsSize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for i := range 40 {
		r.cacheMarkdown(mdKey{width: i}, []string{strings.Repeat("x", 1<<20)})
	}
	if r.mdBytes > maxCachedMarkdownBytes {
		t.Errorf("the cache holds %d bytes, want at most %d", r.mdBytes, maxCachedMarkdownBytes)
	}
	if _, ok := r.md[mdKey{width: 0}]; ok {
		t.Error("the oldest render is still cached")
	}
	if _, ok := r.md[mdKey{width: 39}]; !ok {
		t.Error("the newest render is not cached")
	}
}

func TestMarkdownOfTheSameTextIsTheSameRender(t *testing.T) {
	r := NewRenderer(theme.Default())
	const text = "# Title\n\nSome **bold** text and a list:\n\n- one\n- two\n"
	first := r.markdown(text, 60)
	if again := r.markdown(text, 60); !slices.Equal(first, again) {
		t.Errorf("a cached render differs from the first one:\n%q\n%q", first, again)
	}
	if fresh := renderMarkdown(text, 60, r.styles.Markdown); !slices.Equal(first, fresh) {
		t.Errorf("the cached render differs from a fresh one:\n%q\n%q", first, fresh)
	}
}

func TestToolLineIcons(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Frame = 0
	m.Blocks = []state.Block{
		{Kind: state.KindTool, ID: "1", Tool: "read", Summary: "a.go", Status: "running"},
		{Kind: state.KindTool, ID: "2", Tool: "read", Summary: "b.go", Status: "ok"},
		{Kind: state.KindTool, ID: "3", Tool: "read", Summary: "c.go", Status: "error"},
	}
	rows := screen(t, r, m)
	for _, want := range []string{"⠋ read  a.go", "✓ read  b.go", "✗ read  c.go"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the line %q is missing: %q", want, plainRows(rows))
		}
	}
	a, b, c := rowOf(rows, "a.go"), rowOf(rows, "b.go"), rowOf(rows, "c.go")
	if b != a+1 || c != b+1 {
		t.Errorf("a run of calls is not tight: rows %d, %d, %d", a, b, c)
	}
}

func TestAToolCallShowsItsResultOnlyWhenItFailsOrWhenExpanded(t *testing.T) {
	r := NewRenderer(theme.Default())
	var lines []string
	for i := 1; i <= 20; i++ {
		lines = append(lines, "line "+twoDigits(i))
	}
	text := strings.Join(lines, "\n")
	call := func(status string) state.Model {
		m := newModel(80, 40)
		m.Blocks = []state.Block{{Kind: state.KindTool, Tool: "bash", Summary: "ls", Status: status, Text: text}}
		return m
	}

	rows := screen(t, r, call("ok"))
	if rowOf(rows, "line 02") >= 0 {
		t.Errorf("a call that went well shows its output: %q", plainRows(rows))
	}
	if row := rowWith(plainRows(rows), "✓ bash"); !strings.Contains(row, "line 01 +19") {
		t.Errorf("the line of the call = %q, want its first line and how many more at the right edge", row)
	}

	rows = screen(t, r, call("error"))
	if rowOf(rows, "line 04") < 0 || rowOf(rows, "line 05") >= 0 {
		t.Errorf("a call that failed does not show its first %d lines: %q", maxErrorLines, plainRows(rows))
	}
	if rowOf(rows, "… 16 more lines") < 0 {
		t.Errorf("the clipped note is missing: %q", plainRows(rows))
	}
	if row := rows[rowOf(rows, "line 01")]; !strings.Contains(plain(row), "▎") {
		t.Errorf("the output of a failed call has no bar: %q", plain(row))
	}

	expanded := call("ok")
	expanded.ToolsExpanded = true
	rows = screen(t, r, expanded)
	if rowOf(rows, "line 20") < 0 || rowOf(rows, "more lines") >= 0 {
		t.Errorf("expanded tools do not show the whole output: %q", plainRows(rows))
	}
}

func TestToolLineDoesNotRepeatItsName(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{
		{Kind: state.KindTool, ID: "1", Tool: "list", Summary: "list path=. depth=2", Status: "ok"},
		{Kind: state.KindTool, ID: "2", Tool: "list", Summary: "list", Status: "ok"},
		{Kind: state.KindTool, ID: "3", Tool: "list", Summary: "listing.go", Status: "ok"},
	}
	rows := screen(t, r, m)
	if rowOf(rows, "list  list ") >= 0 || rowOf(rows, "list list") >= 0 {
		t.Errorf("the tool name is printed twice: %q", plainRows(rows))
	}
	for _, want := range []string{"✓ list  path=. depth=2", "✓ list  listing.go"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the line %q is missing: %q", want, plainRows(rows))
		}
	}
}

func TestAToolCallWithoutAResultIsOneLine(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{{Kind: state.KindTool, Tool: "write", Summary: "x.go", Status: "ok"}}
	rows := plainRows(screen(t, r, m))
	line := rowOf(rows, "✓ write  x.go")
	if line < 0 || rows[line+1] != "" || rows[line-1] != "" {
		t.Errorf("the call is not one line between blank rows: %q", rows)
	}
}

func TestToolLineHasItsMarginDepthAndResultAtTheRightEdge(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{
		{Kind: state.KindTool, Tool: "read", Summary: "top.go", Status: "ok", Text: "package a"},
		{Kind: state.KindTool, Tool: "grep", Summary: "nested", Status: "ok", Depth: 1},
	}
	rows := plainRows(screen(t, r, m))
	top, nested := rowOf(rows, "read  top.go"), rowOf(rows, "grep  nested")
	if got := cellAt(rows[top], "✓"); got != 1 {
		t.Errorf("a call at depth 0 starts at column %d, want 1", got)
	}
	if got := cellAt(rows[nested], "✓"); got != 3 {
		t.Errorf("a call at depth 1 starts at column %d, want 3", got)
	}
	if got := cellAt(rows[top], "package a"); got != lipgloss.Width(strings.TrimRight(rows[top], " "))-len("package a") || got < 60 {
		t.Errorf("the result is at column %d, want it at the right edge: %q", got, rows[top])
	}
}

func TestToolSummaryIsClipped(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(40, 24)
	m.Blocks = []state.Block{{Kind: state.KindTool, Tool: "bash", Summary: strings.Repeat("argument ", 20), Status: "ok"}}
	rows := screen(t, r, m)
	if rowOf(rows, "✓ bash  argument") < 0 || rowOf(rows, "…") < 0 {
		t.Errorf("the long summary is not clipped with an ellipsis: %q", plainRows(rows))
	}
}

func leadingCells(row string) int {
	text := plain(row)
	return len(text) - len(strings.TrimLeft(text, " "))
}

func TestBlocksUnderASubagentAreIndented(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.ToolsExpanded = true
	m.Blocks = []state.Block{
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find it", Status: "running"},
		{Kind: state.KindTool, ID: "t1", Agent: "explore", Depth: 1, Parent: "s1", Tool: "grep", Summary: "needle", Status: "ok", Text: "one match"},
		{Kind: state.KindNotice, Level: "info", Depth: 1, Parent: "s1", Text: "stray note"},
		{Kind: state.KindNotice, Level: "info", Text: "main note"},
	}
	rows := screen(t, r, m)
	subagent, tool, note, main := rowOf(rows, "◆ explore"), rowOf(rows, "grep"), rowOf(rows, "stray note"), rowOf(rows, "main note")
	if subagent < 0 || tool < 0 || note < 0 || main < 0 {
		t.Fatalf("a block is missing: %q", plainRows(rows))
	}
	col := func(row int, mark string) int { return cellAt(plain(rows[row]), mark) }
	if col(subagent, "▌") != 1 || col(tool, "✓") != 3 || col(note, "ℹ") != 3 || col(main, "ℹ") != 1 {
		t.Errorf("columns of the subagent, its tool, its notice and the main notice = %d, %d, %d, %d; want 1, 3, 3 and 1: %q",
			col(subagent, "▌"), col(tool, "✓"), col(note, "ℹ"), col(main, "ℹ"), plainRows(rows))
	}
}

func TestFoldedSubagentHidesItsCards(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find it", Summary: "found it", Status: "completed", Folded: true},
		{Kind: state.KindTool, ID: "t1", Agent: "explore", Depth: 1, Parent: "s1", Tool: "grep", Summary: "needle", Status: "ok"},
		{Kind: state.KindNotice, Level: "info", Depth: 1, Parent: "s1", Text: "stray note"},
		{Kind: state.KindNotice, Level: "info", Text: "main note"},
	}
	rows := screen(t, r, m)
	if title := rowOf(rows, "▸ ◆ explore"); title < 0 || !strings.Contains(plain(rows[title]), "2 hidden") {
		t.Errorf("the folded title lacks the mark or the count of hidden cards: %q", plainRows(rows))
	}
	if rowOf(rows, "grep") >= 0 || rowOf(rows, "stray note") >= 0 {
		t.Errorf("the cards of a folded subagent are drawn: %q", plainRows(rows))
	}
	if rowOf(rows, "found it") < 0 || rowOf(rows, "main note") < 0 {
		t.Errorf("the report or a card of the main agent is missing: %q", plainRows(rows))
	}

	m.Blocks[0].Folded = false
	rows = screen(t, r, m)
	if rowOf(rows, "▾ ◆ explore") < 0 || rowOf(rows, "grep") < 0 || rowOf(rows, "stray note") < 0 {
		t.Errorf("the unfolded subagent does not draw its cards: %q", plainRows(rows))
	}
	if rowOf(rows, "hidden") >= 0 {
		t.Errorf("the unfolded subagent still counts hidden cards: %q", plainRows(rows))
	}
}

func TestHiddenBlocksFollowTheFoldedSubagent(t *testing.T) {
	blocks := []state.Block{
		{Kind: state.KindSubagent, ID: "s1", Folded: true},
		{Kind: state.KindTool, ID: "t1", Parent: "s1"},
		{Kind: state.KindSubagent, ID: "s2", Parent: "s1"},
		{Kind: state.KindTool, ID: "t2", Parent: "s2"},
		{Kind: state.KindNotice, Text: "main"},
	}
	hidden := hiddenBlocks(blocks)
	for i, want := range []bool{false, true, true, true, false} {
		if hidden[i] != want {
			t.Errorf("block %d hidden = %v, want %v", i, hidden[i], want)
		}
	}
}

func TestSubagentCardShowsTaskAndReport(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{{
		Kind:    state.KindSubagent,
		ID:      "s1",
		Agent:   "explore",
		Text:    "find the callers of parse",
		Summary: "parse is called in three files",
		Status:  "completed",
	}}
	rows := screen(t, r, m)
	title := rowOf(rows, "◆ explore")
	if title < 0 {
		t.Fatalf("the title is missing: %q", plainRows(rows))
	}
	if !strings.Contains(plain(rows[title]), "✓ done") {
		t.Errorf("the title %q lacks the status", plain(rows[title]))
	}
	if rowOf(rows, "find the callers of parse") < 0 {
		t.Error("the task is missing")
	}
	if rowOf(rows, "parse is called in three files") < 0 {
		t.Error("the report is missing")
	}
}

func TestSubagentRunningStatus(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{{Kind: state.KindSubagent, Agent: "general", Text: "fix it", Status: "running"}}
	rows := screen(t, r, m)
	title := rowOf(rows, "◆ general")
	if title < 0 || !strings.Contains(plain(rows[title]), "working") {
		t.Errorf("the running subagent is not titled: %q", plainRows(rows))
	}
	if rowOf(rows, "fix it") < 0 {
		t.Error("the task is missing")
	}
}

func TestSubagentTaskAndReportAreClipped(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(60, 60)
	long := strings.Repeat("abcdefghij ", 40)
	var report []string
	for i := 1; i <= 10; i++ {
		report = append(report, "report "+twoDigits(i))
	}
	m.Blocks = []state.Block{{Kind: state.KindSubagent, Agent: "explore", Text: long, Summary: strings.Join(report, "\n"), Status: "completed"}}
	rows := screen(t, r, m)

	task := 0
	for _, row := range rows {
		if strings.Contains(plain(row), "abcdefghij") {
			task++
		}
	}
	if task != maxTaskLines {
		t.Errorf("the task takes %d rows, want %d", task, maxTaskLines)
	}
	if rowOf(rows, "report 06") < 0 || rowOf(rows, "report 07") >= 0 {
		t.Errorf("the report is not clipped to six lines: %q", plainRows(rows))
	}
	if rowOf(rows, "… 4 more lines") < 0 {
		t.Errorf("the report has no clipped note: %q", plainRows(rows))
	}
}

func TestNoticeIcons(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Blocks = []state.Block{
		{Kind: state.KindNotice, Level: "info", Text: "saved the file"},
		{Kind: state.KindNotice, Level: "warn", Text: "close to the limit"},
		{Kind: state.KindNotice, Level: "error", Text: "the request failed"},
	}
	rows := screen(t, r, m)
	for _, want := range []string{"ℹ saved the file", "⚠ close to the limit", "✖ the request failed"} {
		row := rowOf(rows, want)
		if row < 0 {
			t.Errorf("the notice %q is missing: %q", want, plainRows(rows))
			continue
		}
		if !strings.HasPrefix(plain(rows[row]), " "+want) {
			t.Errorf("the notice %q does not start at column 1: %q", want, plain(rows[row]))
		}
	}
}

func TestNoticeHangsItsContinuation(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(30, 24)
	m.Blocks = []state.Block{{Kind: state.KindNotice, Level: "warn", Text: "the context window is nearly full and older turns are dropped"}}
	rows := screen(t, r, m)
	first := rowOf(rows, "⚠ the")
	if first < 0 {
		t.Fatalf("the notice is missing: %q", plainRows(rows))
	}
	if next := plain(rows[first+1]); !strings.HasPrefix(next, "   ") || strings.HasPrefix(next, "    ") {
		t.Errorf("continuation %q does not hang under the text", next)
	}
}

func TestOutputKeepsItsLineBreaks(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Blocks = []state.Block{{Kind: state.KindOutput, Text: "alpha\nbeta\ngamma"}}
	rows := plainRows(screen(t, r, m))
	first := rowOf(rows, "alpha")
	if first < 0 || !strings.Contains(rows[first+1], "▎ beta") || !strings.Contains(rows[first+2], "▎ gamma") {
		t.Errorf("the output lost its line breaks or its bar: %q", rows)
	}
}

func TestLongTextWrapsWithinTheWidth(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(60, 30)
	words := strings.Fields(strings.Repeat("alpha beta gamma delta epsilon ", 20))
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: strings.Join(words, " ")}}
	rows := screen(t, r, m)
	text := strings.Join(plainRows(rows), " ")
	if got := strings.Count(text, "epsilon"); got != 20 {
		t.Errorf("the text shows %d epsilons, want 20", got)
	}
}

func TestSanitizeRemovesEscapes(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Blocks = []state.Block{{Kind: state.KindOutput, Text: "\x1b[31mred\x1b[0m\tend"}}
	raw := strings.Join(screen(t, r, m), "\n")
	if strings.Contains(raw, "\x1b[31m") {
		t.Error("the escape sequence of the text reached the screen")
	}
	if !strings.Contains(plain(raw), "red    end") {
		t.Errorf("the text is %q", plain(raw))
	}
}

func TestWrapText(t *testing.T) {
	cases := []struct {
		text  string
		width int
		want  []string
	}{
		{"hello big world", 8, []string{"hello", "big", "world"}},
		{"abcdefghij", 4, []string{"abcd", "efgh", "ij"}},
		{"a\n\nb", 10, []string{"a", "", "b"}},
		{"", 10, []string{""}},
		{"one    two", 4, []string{"one", "two"}},
	}
	for _, c := range cases {
		got := wrapText(c.text, c.width)
		if strings.Join(got, "|") != strings.Join(c.want, "|") {
			t.Errorf("wrapText(%q, %d) = %q, want %q", c.text, c.width, got, c.want)
		}
	}
}

func TestBlocksAreSeparatedByABlankRowButToolCallsStayTogether(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Blocks = []state.Block{
		{Kind: state.KindUser, Text: "first"},
		{Kind: state.KindAssistant, Text: "second"},
		{Kind: state.KindTool, ID: "a", Tool: "read", Summary: "one.go", Status: "ok"},
		{Kind: state.KindTool, ID: "b", Tool: "read", Summary: "two.go", Status: "ok"},
		{Kind: state.KindAssistant, Text: "third"},
	}
	rows := plainRows(screen(t, r, m))
	at := map[string]int{}
	for _, word := range []string{"first", "second", "one.go", "two.go", "third"} {
		if at[word] = rowOf(rows, word); at[word] < 0 {
			t.Fatalf("%q is not on the screen: %q", word, rows)
		}
	}
	for _, pair := range [][2]string{{"first", "second"}, {"second", "one.go"}, {"two.go", "third"}} {
		if gap := at[pair[1]] - at[pair[0]]; gap != 2 || rows[at[pair[0]]+1] != "" {
			t.Errorf("%q and %q are %d rows apart, want one blank row between them: %q", pair[0], pair[1], gap, rows)
		}
	}
	if gap := at["two.go"] - at["one.go"]; gap != 1 {
		t.Errorf("two tool calls in a row are %d rows apart, want 0 rows between them", gap)
	}
}
