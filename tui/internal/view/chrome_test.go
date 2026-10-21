package view

import (
	"strings"
	"testing"
	"time"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func TestInputShowsPlaceholderAndText(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	rows := screen(t, r, m)
	if rowOf(rows, "❯ Message phycode…") < 0 {
		t.Errorf("the placeholder is missing: %q", plainRows(rows))
	}

	m.Input = "hello there"
	m.Cursor = 11
	rows = screen(t, r, m)
	if rowOf(rows, "❯ hello there") < 0 {
		t.Errorf("the typed text is missing: %q", plainRows(rows))
	}
	if rowOf(rows, "Message phycode") >= 0 {
		t.Error("the placeholder shows next to typed text")
	}
}

func TestInputCursorIsReverseVideo(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Input = "abc"

	m.Cursor = 1
	raw := strings.Join(screen(t, r, m), "\n")
	if !strings.Contains(raw, theme.Default().Cursor.Render("b")) {
		t.Error("the cursor is not on the second rune")
	}

	m.Cursor = 3
	raw = strings.Join(screen(t, r, m), "\n")
	if !strings.Contains(raw, theme.Default().Cursor.Render(" ")) {
		t.Error("the cursor is not after the last rune")
	}
}

func TestInputPlaceholderCursorIsOnFirstCell(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, newModel(80, 24))
	row := rowOf(rows, "Message phycode")
	if row < 0 || !strings.Contains(rows[row], theme.Default().Cursor.Render("M")) {
		t.Errorf("the placeholder cursor is not on the M: %q", plainRows(rows))
	}
}

func TestInputGrowsWithNewlinesUpToSixRows(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)

	m.Input = "one\ntwo\nthree"
	m.Cursor = len([]rune(m.Input))
	rows := screen(t, r, m)
	top, bottom := boxBounds(t, rows)
	if got := bottom - top - 1; got != 3 {
		t.Errorf("3 lines give %d text rows, want 3", got)
	}

	var lines []string
	for i := 1; i <= 10; i++ {
		lines = append(lines, "line"+twoDigits(i))
	}
	m.Input = strings.Join(lines, "\n")
	m.Cursor = len([]rune(m.Input))
	rows = screen(t, r, m)
	top, bottom = boxBounds(t, rows)
	if got := bottom - top - 1; got != 6 {
		t.Errorf("10 lines give %d text rows, want 6", got)
	}
	if rowOf(rows, "line10") < 0 {
		t.Errorf("the cursor row is not visible: %q", plainRows(rows))
	}
	if rowOf(rows, "line01") >= 0 {
		t.Error("the first line stays visible while the cursor is at the end")
	}
}

func TestInputWrapsLongRows(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(40, 24)
	m.Input = strings.Repeat("x", 100)
	m.Cursor = 0
	rows := screen(t, r, m)
	top, bottom := boxBounds(t, rows)
	if got := bottom - top - 1; got != 4 {
		t.Errorf("100 cells in a 40-wide box give %d text rows, want 4", got)
	}
}

func TestInputRowsWrapAtSpacesAndFollowTheCursor(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(15, 24)
	m.Input = "hello big world"
	m.Cursor = 11
	rows, cursorRow := r.inputLines(m, m.Width)
	want := []string{"❯ hello", "  big", "  world"}
	if len(rows) != len(want) {
		t.Fatalf("got %d rows, want %d: %q", len(rows), len(want), rows)
	}
	for i, row := range rows {
		if got := strings.TrimRight(plain(row), " "); got != want[i] {
			t.Errorf("row %d = %q, want %q", i, got, want[i])
		}
	}
	if cursorRow != 2 {
		t.Errorf("cursor row = %d, want 2", cursorRow)
	}

	m.Cursor = 10
	if _, cursorRow = r.inputLines(m, m.Width); cursorRow != 1 {
		t.Errorf("a cursor just after a space belongs to the row before it, got row %d", cursorRow)
	}
}

func TestInputBoxIsBusyStyled(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	idle := strings.Join(screen(t, r, m), "\n")
	m.Busy = true
	busy := strings.Join(screen(t, r, m), "\n")
	if idle == busy {
		t.Error("the input box looks the same while a turn runs")
	}
}

func TestStatusLineStates(t *testing.T) {
	r := NewRenderer(theme.Default())
	hints := "/ commands · @ subagents · ctrl+p palette · ctrl+c stop"

	m := newModel(120, 24)
	rows := screen(t, r, m)
	status := plain(rows[len(rows)-1])
	if !strings.Contains(status, "● ready") || !strings.Contains(status, hints) {
		t.Errorf("idle status = %q", status)
	}

	m.Busy = true
	m.Frame = 0
	m.TurnStarted = time.Now().Add(-12500 * time.Millisecond)
	rows = screen(t, r, m)
	status = plain(rows[len(rows)-1])
	if !strings.Contains(status, "⠋ working 12s") {
		t.Errorf("busy status = %q, want the spinner and working 12s", status)
	}

	m.Busy = false
	m.Frame = 3
	m.Flash = "saved"
	m.Changes = []string{"a.go", "b.go"}
	rows = screen(t, r, m)
	status = plain(rows[len(rows)-1])
	if !strings.Contains(status, "saved") || !strings.Contains(status, "· 2 changes") {
		t.Errorf("status = %q, want the flash and the change count", status)
	}

	m.Changes = []string{"a.go"}
	rows = screen(t, r, m)
	if !strings.Contains(plain(rows[len(rows)-1]), "· 1 change") {
		t.Errorf("status = %q, want the singular change count", plain(rows[len(rows)-1]))
	}

	m.Changes = nil
	m.Flash = ""
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "ls"}
	rows = screen(t, r, m)
	if !strings.Contains(plain(rows[len(rows)-1]), "approval needed") {
		t.Errorf("status = %q, want approval needed", plain(rows[len(rows)-1]))
	}
}

func TestStatusShowsTheTitleOfTheConversation(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(120, 10)
	m.Session = protocol.Session{ID: "s1", Title: "fix parser"}
	rows := screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "● ready · fix parser · qwen") {
		t.Errorf("status = %q, want the title after ready", status)
	}

	m.Session = protocol.Session{ID: "s1"}
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "● ready · qwen") || strings.Contains(status, "s1") {
		t.Errorf("status = %q, want no title, and no id, for a conversation without a title", status)
	}

	m.Session = protocol.Session{ID: "s1", Title: strings.Repeat("a", 40)}
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, strings.Repeat("a", 29)+"…") {
		t.Errorf("status = %q, want the title cut to 30 cells", status)
	}
}

func TestStatusSaysACompactionRunsInsteadOfReady(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(120, 10)
	m.Compacting = true
	rows := screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "compacting…") || strings.Contains(status, "ready") {
		t.Errorf("status = %q, want compacting… in place of ready", status)
	}
}

func TestApprovalModalShowsTheRequest(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"}
	rows := screen(t, r, m)

	title := rowOf(rows, "Approve bash?")
	if title < 0 {
		t.Fatalf("the title is missing: %q", plainRows(rows))
	}
	if !strings.Contains(plain(rows[title+1]), "category exec · agent build") {
		t.Errorf("meta row = %q", plain(rows[title+1]))
	}
	if !strings.Contains(plain(rows[title+2]), "rm -rf build") {
		t.Errorf("summary row = %q", plain(rows[title+2]))
	}
	for _, hint := range []string{"y once", "a always this session", "n deny", "esc deny"} {
		if rowOf(rows, hint) < 0 {
			t.Errorf("the key hint %q is missing", hint)
		}
	}
	if rowOf(rows, "approval needed") < 0 {
		t.Error("the status line does not say approval is needed")
	}
}

func TestApprovalModalSitsAboveTheInputBox(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := richModel(80, 40)
	m.Suggestions = nil
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"}
	rows := screen(t, r, m)

	top, _ := boxBounds(t, rows)
	title := rowOf(rows, "Approve bash?")
	if title < 0 {
		t.Fatalf("the modal is missing: %q", plainRows(rows))
	}
	bottom := rowOf(rows[title:], "esc deny")
	if bottom < 0 {
		t.Fatalf("the modal has no key line: %q", plainRows(rows))
	}
	if title+bottom != top-2 {
		t.Errorf("the modal ends at row %d, want it to end at row %d, one blank row above the input box", title+bottom, top-2)
	}
}

func TestApprovalModalMarksSubagentRequests(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Confirm = &state.Confirm{ID: "c", Tool: "write", Category: "write", Agent: "explore", Depth: 1, Summary: "a.go"}
	rows := screen(t, r, m)
	if rowOf(rows, "category write · agent explore · subagent") < 0 {
		t.Errorf("the subagent note is missing: %q", plainRows(rows))
	}
}

func TestApprovalSummaryIsClipped(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 40)
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: strings.Repeat("word ", 200)}
	rows := screen(t, r, m)
	title := rowOf(rows, "Approve bash?")
	if title < 0 {
		t.Fatal("the modal is missing")
	}
	summary := 0
	for i := title + 2; i < title+8; i++ {
		if strings.Contains(plain(rows[i]), "word") {
			summary++
		}
	}
	if summary != 4 {
		t.Errorf("the summary takes %d rows, want 4", summary)
	}
	if rowOf(rows, "…") < 0 {
		t.Error("a clipped summary has no ellipsis")
	}
}

func TestApprovalModalFitsASmallTranscript(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(40, 11)
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: strings.Repeat("long ", 40)}
	rows := screen(t, r, m)
	if rowOf(rows, "Approve bash?") < 0 {
		t.Errorf("the title does not fit a small screen: %q", plainRows(rows))
	}
}

func TestStatusShowsZeroSecondsWithoutATurnStart(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Busy = true
	rows := screen(t, r, m)
	if rowOf(rows, "working 0s") < 0 {
		t.Errorf("status without a turn start: %q", plainRows(rows))
	}
}

func TestStatusLineDoesNotOverflow(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(30, 10)
	m.Busy = true
	m.Changes = []string{"a", "b", "c"}
	m.Flash = strings.Repeat("flash ", 10)
	rows := screen(t, r, m)
	if lipgloss.Width(rows[len(rows)-1]) != 30 {
		t.Errorf("status = %q", plain(rows[len(rows)-1]))
	}
}

var sessionItems = []string{"● ready", "· qwen", "· effort high", "· ctx 42%", "· 31 tok/s", "· ask", "· paused", "· 2 queued"}

func TestStatusDoesNotRepeatTheModelBeforeALongFlash(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 10)
	m.Flash = "thinking low · applies from the next turn · the prompt cache resets"
	rows := screen(t, r, m)
	status := plain(rows[len(rows)-1])
	if n := strings.Count(status, "qwen"); n != 1 {
		t.Errorf("status = %q: the model shows %d times, want once", status, n)
	}
	if !strings.Contains(status, "ctrl+c") {
		t.Errorf("status = %q, want the ctrl+c hint", status)
	}
}

func sessionStatus(w, h int) state.Model {
	m := newModel(w, h)
	m.Effort.Level = "high"
	prompt, window, speed := 4200, 10000, 31.4
	m.Usage = &protocol.Usage{PromptTokens: &prompt, ContextWindow: &window, TokensPerSecond: &speed}
	m.Queue = []string{"next", "later"}
	m.QueuePaused = true
	return m
}

func checkOrder(t *testing.T, s string, parts []string) {
	t.Helper()
	rest, cut := s, false
	for _, part := range parts {
		i := strings.Index(rest, part)
		switch {
		case i < 0:
			cut = true
		case cut:
			t.Errorf("%q shows after a part that was cut off in %q", part, s)
			return
		default:
			rest = rest[i+len(part):]
		}
	}
}

func TestStatusItemsShowInOrder(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, sessionStatus(200, 10))
	status := plain(rows[len(rows)-1])
	for _, part := range sessionItems {
		if !strings.Contains(status, part) {
			t.Errorf("status = %q, want %q", status, part)
		}
	}
	checkOrder(t, status, sessionItems)
}

func TestStatusCutsTheLeftSideAtItsEndAndDropsTheHintsFirst(t *testing.T) {
	r := NewRenderer(theme.Default())
	tests := []struct {
		width int
		shown []string
	}{
		{width: 80, shown: sessionItems[:7]},
		{width: 40, shown: sessionItems[:3]},
		{width: 20, shown: sessionItems[:2]},
	}
	for _, tt := range tests {
		rows := screen(t, r, sessionStatus(tt.width, 10))
		status := plain(rows[len(rows)-1])
		for _, part := range tt.shown {
			if !strings.Contains(status, part) {
				t.Errorf("width %d: status = %q, want %q", tt.width, status, part)
			}
		}
		checkOrder(t, status, sessionItems)
		if strings.Contains(status, "commands") {
			t.Errorf("width %d: status = %q, want the hints dropped", tt.width, status)
		}
	}
}

func TestStatusKeepsTheHintsWhileTheyFit(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, newModel(80, 10))
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "/ commands · @ subagents · ctrl+p palette · ctrl+c stop") {
		t.Errorf("status = %q, want the hints beside the short left side", status)
	}
	rows = screen(t, r, sessionStatus(80, 10))
	if status := plain(rows[len(rows)-1]); strings.Contains(status, "commands") {
		t.Errorf("status = %q, want the hints gone once the items need the room", status)
	}
}

func TestStatusKeepsTheHintsAndTheModelBeforeALongFlash(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{80, 40} {
		m := newModel(w, 10)
		m.Flash = strings.Repeat("x", 80)
		rows := screen(t, r, m)
		status := plain(rows[len(rows)-1])
		for _, part := range []string{"ctrl+c", "/ commands", "qwen"} {
			if !strings.Contains(status, part) {
				t.Errorf("width %d: status = %q, want %q", w, status, part)
			}
		}
	}
}

func TestStatusShowsAQueuedTurn(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(130, 10)
	m.Busy = true
	m.TurnStarted = time.Now().Add(-12500 * time.Millisecond)
	m.Queue = []string{"next"}
	m.Flash = "queued · alt+up edits the queue"
	rows := screen(t, r, m)
	status := plain(rows[len(rows)-1])
	checkOrder(t, status, []string{"⠋ working 12s", "queued · alt+up edits the queue", "· qwen", "· ask", "· 1 queued"})

	m = newModel(120, 10)
	m.Queue = []string{"next", "later"}
	m.QueuePaused = true
	m.Flash = "queue paused after interrupt · enter sends the next one"
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "· paused · 2 queued") {
		t.Errorf("status = %q, want the paused queue", status)
	}
}

func TestStatusHidesWhatIsUnknownOrOff(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(200, 10)
	m.Effort.Level = "off"
	prompt := 4200
	m.Usage = &protocol.Usage{PromptTokens: &prompt}
	rows := screen(t, r, m)
	status := plain(rows[len(rows)-1])
	for _, part := range []string{"effort", "ctx", "tok/s", "queued", "paused"} {
		if strings.Contains(status, part) {
			t.Errorf("status = %q, want no %q", status, part)
		}
	}
	m.Effort.Level = "none"
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); strings.Contains(status, "effort") {
		t.Errorf("status = %q, want no effort slot at the default none", status)
	}
}

func TestStatusHasOneEffortSlotAndNoThinkingSlot(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(120, 10)
	m.App.Thinking = "medium"
	m.Effort.Level = "medium"
	rows := screen(t, r, m)
	status := plain(rows[len(rows)-1])
	if !strings.Contains(status, "· effort medium") || strings.Contains(status, "thinking") || strings.Count(status, "medium") != 1 {
		t.Errorf("status = %q, want the level once, as the effort", status)
	}
	m.Effort.Level = "none"
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); strings.Contains(status, "thinking") || strings.Contains(status, "effort") {
		t.Errorf("status = %q, want neither a thinking slot nor an effort slot at none", status)
	}
}

func TestStatusShortensTheModelToItsLastPart(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(200, 10)
	m.App.Model = "models/qwen-coder.gguf"
	rows := screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "· qwen-coder.gguf") || strings.Contains(status, "models/") {
		t.Errorf("status = %q, want the file name of the model", status)
	}

	m.App.Model = "a-very-long-model-name-that-does-not-fit-the-status-line.gguf"
	rows = screen(t, r, m)
	status := plain(rows[len(rows)-1])
	if !strings.Contains(status, "· a-very-long-model-name-…") {
		t.Errorf("status = %q, want the model cut to 24 cells", status)
	}
}

func TestApprovalLeavesTheRowAboveTheInputBlank(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(40, 24)
	m.Blocks = []state.Block{{Kind: state.KindTool, ID: "t1", Tool: "read", Summary: "a.go", Status: "ok", Text: "package a"}}
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "ls"}
	rows := screen(t, r, m)
	top, _ := boxBounds(t, rows)
	if got := strings.TrimSpace(plain(rows[top-1])); got != "" {
		t.Errorf("the row above the input box is %q, want it blank", got)
	}
}

func TestApprovalKeepsTheKeysOnSmallScreens(t *testing.T) {
	r := NewRenderer(theme.Default())
	tests := []struct {
		w, h int
		keys []string
	}{
		{w: 80, h: 24, keys: []string{"y once", "a always this session", "n deny", "esc deny"}},
		{w: 40, h: 12, keys: []string{"y once", "n deny", "esc deny"}},
		{w: 20, h: 8, keys: []string{"y once", "n deny"}},
	}
	for _, tt := range tests {
		m := newModel(tt.w, tt.h)
		m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"}
		rows := screen(t, r, m)
		for _, key := range tt.keys {
			if rowOf(rows, key) < 0 {
				t.Errorf("%dx%d: %q is not visible: %q", tt.w, tt.h, key, plainRows(rows))
			}
		}
	}
}

func TestApprovalIsABannerInAShortTranscript(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 9)
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"}
	rows := screen(t, r, m)
	if rowOf(rows, "approval needed: y once · n deny · ctrl+c stop") < 0 {
		t.Errorf("the banner is missing: %q", plainRows(rows))
	}
	if rowOf(rows, "Approve") >= 0 {
		t.Errorf("a box is drawn in a transcript of six rows: %q", plainRows(rows))
	}
}

func TestApprovalSummaryShrinksToFit(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(40, 11)
	m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: strings.Repeat("long ", 40)}
	rows := screen(t, r, m)
	long := 0
	for _, row := range rows {
		if strings.Contains(plain(row), "long") {
			long++
		}
	}
	if long != 1 || rowOf(rows, "Approve bash?") < 0 || rowOf(rows, "esc deny") < 0 {
		t.Errorf("the summary takes %d rows, want one, and the title and the keys stay: %q", long, plainRows(rows))
	}
}
