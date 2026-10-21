package view

import (
	"math"
	"strings"
	"testing"
	"time"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func richModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Blocks = []state.Block{
		{Kind: state.KindUser, Text: "Look at the parser and tell me what happens to a long token that never ends because the input is cut off at the wrong place"},
		{Kind: state.KindAssistant, Text: "# Parser\n\nThe parser reads **tokens**. See `lex.go`.\n\n- one\n- two\n\n```go\nfunc main() {}\n```\n"},
		{Kind: state.KindTool, ID: "t1", Tool: "read", Summary: "internal/parser/parser.go lines 1-400 with a very long argument list", Status: "ok", Text: "package parser\n\nfunc Parse() {}"},
		{Kind: state.KindTool, ID: "t2", Depth: 1, Agent: "explore", Tool: "grep", Summary: "parse(", Status: "running"},
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "Find the callers of parse() in every package of the repository", Summary: "three callers", Status: "completed"},
		{Kind: state.KindNotice, Level: "warn", Text: "the context window is nearly full"},
		{Kind: state.KindOutput, Text: "/agents\n  build      primary\n  explore    subagent"},
	}
	m.Busy = true
	m.TurnStarted = time.Now().Add(-12500 * time.Millisecond)
	m.Frame = 3
	m.Input = "fix the parser\nthen run the tests"
	m.Cursor = 4
	m.Suggestions = []state.Suggestion{
		{Insert: "/agent ", Label: "/agent NAME", Description: "switch the agent"},
		{Insert: "/agents", Label: "/agents", Description: "list the agents"},
		{Insert: "/clear", Label: "/clear", Description: "clear the screen"},
	}
	m.Selected = 1
	m.Changes = []string{"parser.go"}
	m.Flash = "saved"
	return m
}

func TestColourIsOn(t *testing.T) {
	if theme.Default().Cursor.Render("x") == "x" {
		t.Fatal("colour codes are off; CLICOLOR_FORCE did not take effect")
	}
}

func TestViewIsEmptyUntilSized(t *testing.T) {
	r := NewRenderer(theme.Default())
	cases := []state.Model{
		{},
		{Width: 80},
		{Height: 24},
		newModel(0, 24),
		newModel(80, 0),
	}
	for _, m := range cases {
		if got := r.View(m); got != "" {
			t.Errorf("View(%dx%d) = %q, want empty", m.Width, m.Height, plain(got))
		}
	}
}

func TestViewFillsTheScreen(t *testing.T) {
	r := NewRenderer(theme.Default())
	sizes := [][2]int{{80, 24}, {120, 40}, {40, 12}, {20, 8}}
	for _, size := range sizes {
		w, h := size[0], size[1]
		screen(t, r, newModel(w, h))
		screen(t, r, richModel(w, h))

		many := richModel(w, h)
		for i := 0; i < 80; i++ {
			many.Blocks = append(many.Blocks, state.Block{Kind: state.KindUser, Text: "message"})
		}
		many.Scroll = 7
		screen(t, r, many)

		blocked := richModel(w, h)
		blocked.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "ls -la"}
		screen(t, r, blocked)
	}
}

func TestViewFitsEverySmallSize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for w := 1; w <= 24; w++ {
		for h := 1; h <= 14; h++ {
			m := richModel(w, h)
			screen(t, r, m)

			m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Depth: 1, Summary: strings.Repeat("summary ", 12)}
			screen(t, r, m)
		}
	}
}

func TestViewIsStableAcrossFrames(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := richModel(80, 40)
	m.Busy = false
	first := r.View(m)
	for i := 0; i < 3; i++ {
		if got := r.View(m); got != first {
			t.Fatalf("frame %d differs from the first frame:\n%s\n---\n%s", i+2, plain(got), plain(first))
		}
	}
}

func TestHeaderShowsTheSession(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(120, 24)
	rows := screen(t, r, m)
	header := plain(rows[0])
	for _, want := range []string{"phycode", "agent build", "default", "sandbox host", "permissions ask", "tools full", "/work/phycode"} {
		if !strings.Contains(header, want) {
			t.Errorf("header %q lacks %q", header, want)
		}
	}
}

func TestHeaderBeforeReady(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Ready = false
	rows := screen(t, r, m)
	if !strings.Contains(plain(rows[0]), "phycode · connecting…") {
		t.Errorf("header = %q, want the connecting text", plain(rows[0]))
	}
	if !strings.Contains(plain(rows[len(rows)-1]), "connecting…") {
		t.Errorf("status = %q, want connecting…", plain(rows[len(rows)-1]))
	}
}

func TestHeaderWritesHomeAsTilde(t *testing.T) {
	t.Setenv("HOME", "/home/dev")
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.App.Cwd = "/home/dev/src/app"
	rows := screen(t, r, m)
	if !strings.HasSuffix(strings.TrimRight(plain(rows[0]), " "), "~/src/app") {
		t.Errorf("header = %q, want the directory at the end written as ~/src/app", plain(rows[0]))
	}
}

func TestHeaderShortensWhenNarrow(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(40, 12)
	m.App.Cwd = "/a/very/deep/project/directory/phycode"
	rows := screen(t, r, m)
	header := plain(rows[0])
	if !strings.Contains(header, "…") {
		t.Errorf("header = %q, want an ellipsis", header)
	}
	if !strings.Contains(header, "phycode") {
		t.Errorf("header = %q, want the end of the directory", header)
	}
}

func TestWelcomeShowsOnlyForAnEmptySession(t *testing.T) {
	r := NewRenderer(theme.Default())
	const line = "a local coding agent for llama.cpp"

	empty := newModel(80, 24)
	if rowOf(screen(t, r, empty), line) < 0 {
		t.Error("the welcome text is missing from an empty ready session")
	}

	withBlocks := newModel(80, 24)
	withBlocks.Blocks = []state.Block{{Kind: state.KindNotice, Level: "info", Text: "hi"}}
	if rowOf(screen(t, r, withBlocks), line) >= 0 {
		t.Error("the welcome text shows with blocks in the transcript")
	}

	notReady := newModel(80, 24)
	notReady.Ready = false
	if rowOf(screen(t, r, notReady), line) >= 0 {
		t.Error("the welcome text shows before the backend is ready")
	}
}

func TestWelcomeNamesTheFoldKey(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, newModel(80, 24)))
	if row := rowWith(rows, "ctrl+t"); !strings.Contains(row, "fold the last subagent") {
		t.Error("the help does not name the key that folds a subagent")
	}
}

func TestWelcomeNamesTheSubagent(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Agents = []protocol.Agent{{Name: "build", Mode: "primary"}, {Name: "general", Mode: "all"}, {Name: "explore", Mode: "subagent"}}
	if rowOf(screen(t, r, m), "@general  hand a task to a subagent") < 0 {
		t.Error("the hint does not name the first subagent-capable agent")
	}

	m.Agents = []protocol.Agent{{Name: "build", Mode: "primary"}}
	if rowOf(screen(t, r, m), "@explore  hand a task to a subagent") < 0 {
		t.Error("the hint does not fall back to explore without a subagent")
	}
}

func TestTranscriptIsBottomAligned(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: "hello"}}
	rows := screen(t, r, m)
	box, _ := boxBounds(t, rows)
	if box < 3 || !strings.Contains(plain(rows[box-2]), "▌ hello") || strings.TrimSpace(plain(rows[box-1])) != "" {
		t.Fatalf("the last block is not above the input band with one blank row between them (band at row %d): %q", box, plainRows(rows))
	}
	for i := 1; i < box-2; i++ {
		if strings.TrimSpace(plain(rows[i])) != "" {
			t.Errorf("row %d = %q, want blank space above the content", i, plain(rows[i]))
		}
	}
}

func TestScrollShowsOlderContent(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 12)
	for i := 1; i <= 60; i++ {
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindUser, Text: "message " + twoDigits(i)})
	}

	rows := screen(t, r, m)
	if rowOf(rows, "message 60") < 0 || rowOf(rows, "message 01") >= 0 {
		t.Fatalf("scroll 0 must show the newest block only: %q", plainRows(rows))
	}

	m.Scroll = 30
	rows = screen(t, r, m)
	if rowOf(rows, "message 45") < 0 || rowOf(rows, "message 60") >= 0 {
		t.Errorf("scroll 30 must lift the window: %q", plainRows(rows))
	}

	m.Scroll = 1 << 20
	rows = screen(t, r, m)
	if rowOf(rows, "message 01") < 0 || rowOf(rows, "message 60") >= 0 {
		t.Errorf("a large scroll must show the oldest block: %q", plainRows(rows))
	}

	m.Scroll = math.MaxInt
	rows = screen(t, r, m)
	if rowOf(rows, "message 01") < 0 {
		t.Errorf("a huge scroll must clamp to the oldest block: %q", plainRows(rows))
	}

	m.Scroll = -5
	rows = screen(t, r, m)
	if rowOf(rows, "message 60") < 0 {
		t.Errorf("a negative scroll must follow the newest block: %q", plainRows(rows))
	}
}

func TestMaxScrollReachesTheOldestRow(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 12)
	for i := 1; i <= 60; i++ {
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindUser, Text: "message " + twoDigits(i)})
	}
	top := MaxScroll(m)
	if top != 111 {
		t.Fatalf("MaxScroll = %d, want 111", top)
	}

	m.Scroll = top
	atTop := plainRows(screen(t, r, m))
	if rowOf(atTop, "message 01") < 0 {
		t.Errorf("at MaxScroll the oldest block is not shown: %q", atTop)
	}
	m.Scroll = top + 1
	if beyond := plainRows(screen(t, r, m)); strings.Join(beyond, "\n") != strings.Join(atTop, "\n") {
		t.Errorf("a Scroll past MaxScroll changes the screen:\n%s\n---\n%s", strings.Join(beyond, "\n"), strings.Join(atTop, "\n"))
	}
}

func TestMaxScrollIsZeroWhenTheTranscriptFits(t *testing.T) {
	m := newModel(80, 24)
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: "hello"}}
	if got := MaxScroll(m); got != 0 {
		t.Errorf("MaxScroll = %d with one short block, want 0", got)
	}
	if got := MaxScroll(state.Model{}); got != 0 {
		t.Errorf("MaxScroll before the size is known = %d, want 0", got)
	}
}

func TestErrorIsShownInTheTranscript(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Error = "backend exited with status 2"
	if rowOf(screen(t, r, m), "✖ backend exited with status 2") < 0 {
		t.Error("the fatal error is not shown")
	}
}

func TestSanitizeDropsFormatCharactersExceptTheJoiners(t *testing.T) {
	for in, want := range map[string]string{
		"a\u202eb":        "ab",
		"a\u2066b\u2069c": "abc",
		"a\u200bb\ufeffc": "abc",
		"👨\u200d👩\u200d👧": "👨\u200d👩\u200d👧",
		"e\u200cx":        "e\u200cx",
	} {
		if got := sanitize(in); got != want {
			t.Errorf("sanitize(%q) = %q, want %q", in, got, want)
		}
	}
}
