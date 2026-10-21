package app

import (
	"fmt"
	"reflect"
	"testing"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/view"
)

var keyMsgs = map[string]tea.KeyMsg{
	"enter":         {Type: tea.KeyEnter},
	"alt+enter":     {Type: tea.KeyEnter, Alt: true},
	"ctrl+j":        {Type: tea.KeyCtrlJ},
	"tab":           {Type: tea.KeyTab},
	"esc":           {Type: tea.KeyEsc},
	"ctrl+c":        {Type: tea.KeyCtrlC},
	"ctrl+d":        {Type: tea.KeyCtrlD},
	"up":            {Type: tea.KeyUp},
	"down":          {Type: tea.KeyDown},
	"alt+up":        {Type: tea.KeyUp, Alt: true},
	"alt+down":      {Type: tea.KeyDown, Alt: true},
	"pgup":          {Type: tea.KeyPgUp},
	"pgdown":        {Type: tea.KeyPgDown},
	"left":          {Type: tea.KeyLeft},
	"right":         {Type: tea.KeyRight},
	"alt+left":      {Type: tea.KeyLeft, Alt: true},
	"alt+right":     {Type: tea.KeyRight, Alt: true},
	"alt+b":         {Type: tea.KeyRunes, Runes: []rune{'b'}, Alt: true},
	"alt+f":         {Type: tea.KeyRunes, Runes: []rune{'f'}, Alt: true},
	"ctrl+b":        {Type: tea.KeyCtrlB},
	"ctrl+f":        {Type: tea.KeyCtrlF},
	"home":          {Type: tea.KeyHome},
	"end":           {Type: tea.KeyEnd},
	"ctrl+a":        {Type: tea.KeyCtrlA},
	"ctrl+e":        {Type: tea.KeyCtrlE},
	"backspace":     {Type: tea.KeyBackspace},
	"ctrl+h":        {Type: tea.KeyCtrlH},
	"delete":        {Type: tea.KeyDelete},
	"ctrl+w":        {Type: tea.KeyCtrlW},
	"alt+backspace": {Type: tea.KeyBackspace, Alt: true},
	"ctrl+u":        {Type: tea.KeyCtrlU},
	"ctrl+k":        {Type: tea.KeyCtrlK},
	"ctrl+r":        {Type: tea.KeyCtrlR},
	"ctrl+s":        {Type: tea.KeyCtrlS},
	"ctrl+n":        {Type: tea.KeyCtrlN},
	"ctrl+t":        {Type: tea.KeyCtrlT},
	"ctrl+p":        {Type: tea.KeyCtrlP},
	"ctrl+g":        {Type: tea.KeyCtrlG},
}

func press(name string) tea.KeyMsg {
	msg, ok := keyMsgs[name]
	if !ok {
		panic("no key named " + name)
	}
	return msg
}

func typed(text string) tea.KeyMsg {
	return tea.KeyMsg{Type: tea.KeyRunes, Runes: []rune(text)}
}

func pasted(text string) tea.KeyMsg {
	return tea.KeyMsg{Type: tea.KeyRunes, Runes: []rune(text), Paste: true}
}

func space() tea.KeyMsg {
	return tea.KeyMsg{Type: tea.KeySpace, Runes: []rune{' '}}
}

func TestKeyNamesMatchBubbletea(t *testing.T) {
	for name, msg := range keyMsgs {
		if got := msg.String(); got != name {
			t.Errorf("key %q has String() %q", name, got)
		}
	}
}

func TestEditing(t *testing.T) {
	tests := []struct {
		name   string
		msgs   []any
		input  string
		cursor int
	}{
		{
			name:  "typing inserts text",
			msgs:  []any{typed("hola")},
			input: "hola", cursor: 4,
		},
		{
			name:  "space inserts a space",
			msgs:  []any{typed("a"), space(), typed("b")},
			input: "a b", cursor: 3,
		},
		{
			name:  "backspace deletes before the cursor",
			msgs:  []any{typed("hola"), press("backspace")},
			input: "hol", cursor: 3,
		},
		{
			name:  "ctrl+h deletes before the cursor",
			msgs:  []any{typed("ab"), press("ctrl+h")},
			input: "a", cursor: 1,
		},
		{
			name:  "backspace at the start does nothing",
			msgs:  []any{typed("hi"), press("home"), press("backspace")},
			input: "hi", cursor: 0,
		},
		{
			name:  "left and right move by rune",
			msgs:  []any{typed("héy"), press("left"), press("left"), typed("X")},
			input: "hXéy", cursor: 2,
		},
		{
			name:  "ctrl+b and ctrl+f move by rune",
			msgs:  []any{typed("abc"), press("ctrl+b"), press("ctrl+b"), press("ctrl+f"), typed("X")},
			input: "abXc", cursor: 3,
		},
		{
			name:  "home and end move to the ends of the input",
			msgs:  []any{typed("abc"), press("home"), typed("X"), press("end"), typed("Y")},
			input: "XabcY", cursor: 5,
		},
		{
			name:  "ctrl+a moves to the start of the input",
			msgs:  []any{typed("abc"), press("ctrl+a"), typed("X")},
			input: "Xabc", cursor: 1,
		},
		{
			name:  "alt+left moves back a word",
			msgs:  []any{typed("fix the bug"), press("alt+left"), typed("X")},
			input: "fix the Xbug", cursor: 9,
		},
		{
			name:  "alt+b moves back a word",
			msgs:  []any{typed("fix the bug"), press("alt+b"), typed("X")},
			input: "fix the Xbug", cursor: 9,
		},
		{
			name:  "alt+right moves past a word",
			msgs:  []any{typed("fix the bug"), press("home"), press("alt+right"), typed("X")},
			input: "fixX the bug", cursor: 4,
		},
		{
			name:  "alt+f moves past a word",
			msgs:  []any{typed("fix the bug"), press("home"), press("alt+f"), typed("X")},
			input: "fixX the bug", cursor: 4,
		},
		{
			name:  "word moves skip separators",
			msgs:  []any{typed("src/app.go"), press("alt+left"), typed("X")},
			input: "src/app.Xgo", cursor: 9,
		},
		{
			name:  "ctrl+u deletes from the start to the cursor",
			msgs:  []any{typed("mundo"), press("home"), typed("hola "), press("ctrl+u")},
			input: "mundo", cursor: 0,
		},
		{
			name:  "ctrl+k deletes from the cursor to the end",
			msgs:  []any{typed(" mundo"), press("home"), typed("hola"), press("ctrl+k")},
			input: "hola", cursor: 4,
		},
		{
			name:  "ctrl+w deletes the previous word",
			msgs:  []any{typed("hola mundo"), press("ctrl+w")},
			input: "hola ", cursor: 5,
		},
		{
			name:  "alt+backspace deletes the previous word",
			msgs:  []any{typed("hola mundo"), press("alt+backspace")},
			input: "hola ", cursor: 5,
		},
		{
			name:  "ctrl+w skips separators before the word",
			msgs:  []any{typed("src/app.go"), press("ctrl+w")},
			input: "src/app.", cursor: 8,
		},
		{
			name:  "delete removes the rune under the cursor",
			msgs:  []any{typed("ab"), press("home"), press("delete")},
			input: "b", cursor: 0,
		},
		{
			name:  "ctrl+d deletes under the cursor when text remains",
			msgs:  []any{typed("ab"), press("home"), press("ctrl+d")},
			input: "b", cursor: 0,
		},
		{
			name:  "alt+enter inserts a newline",
			msgs:  []any{typed("a"), press("alt+enter"), typed("b")},
			input: "a\nb", cursor: 3,
		},
		{
			name:  "ctrl+j inserts a newline",
			msgs:  []any{typed("a"), press("ctrl+j"), typed("b")},
			input: "a\nb", cursor: 3,
		},
		{
			name:  "paste keeps every line",
			msgs:  []any{pasted("x\r\ny\rz")},
			input: "x\ny\nz", cursor: 5,
		},
		{
			name:  "paste lands at the cursor",
			msgs:  []any{typed("ad"), press("left"), pasted("bc")},
			input: "abcd", cursor: 3,
		},
		{
			name:  "a pasted tab becomes four spaces",
			msgs:  []any{pasted("a\tb")},
			input: "a    b", cursor: 6,
		},
		{
			name:  "a pasted escape loses its escape character",
			msgs:  []any{pasted("a\x1b[31m")},
			input: "a[31m", cursor: 5,
		},
		{
			name:  "pasted control characters are dropped",
			msgs:  []any{pasted("\x00o\x07k")},
			input: "ok", cursor: 2,
		},
		{
			name:  "typed control characters are dropped",
			msgs:  []any{typed("a\x7fb")},
			input: "ab", cursor: 2,
		},
		{
			name:  "text typed with alt is inserted, since AltGr sends @ this way on Windows",
			msgs:  []any{tea.KeyMsg{Type: tea.KeyRunes, Runes: []rune("@"), Alt: true}},
			input: "@", cursor: 1,
		},
		{
			name:  "typed letters that spell a key name are text",
			msgs:  []any{typed("up")},
			input: "up", cursor: 2,
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m, cmds := feed(testModel(), tt.msgs...)
			if len(cmds) != 0 {
				t.Errorf("commands = %v, want none", cmds)
			}
			if m.Input != tt.input || m.Cursor != tt.cursor {
				t.Errorf("Input = %q, Cursor = %d; want %q, %d", m.Input, m.Cursor, tt.input, tt.cursor)
			}
		})
	}
}

func TestTypingResetsHistoryBrowsing(t *testing.T) {
	m := testModel()
	m.History = []string{"one"}
	m, _ = feed(m, press("up"))
	if m.HistoryIndex != 0 || m.Input != "one" {
		t.Fatalf("after up: HistoryIndex = %d, Input = %q", m.HistoryIndex, m.Input)
	}
	m, _ = feed(m, typed("x"))
	if m.HistoryIndex != -1 || m.Input != "onex" {
		t.Errorf("after typing: HistoryIndex = %d, Input = %q; want -1, \"onex\"", m.HistoryIndex, m.Input)
	}
}

func TestTypedKeyNamesDoNotPressTheKey(t *testing.T) {
	m := testModel()
	m.History = []string{"one"}
	m, cmds := feed(m, typed("up"))
	if m.Input != "up" || m.HistoryIndex != -1 || len(cmds) != 0 {
		t.Errorf("typing \"up\": Input = %q, HistoryIndex = %d, commands = %v; want the text only", m.Input, m.HistoryIndex, cmds)
	}
	m, cmds = feed(testModel(), typed("enter"))
	if len(cmds) != 0 || m.Busy || m.Input != "enter" {
		t.Errorf("typing \"enter\": commands = %v, Busy = %v, Input = %q; want the text only", cmds, m.Busy, m.Input)
	}
}

func TestPlainTextStartsATurn(t *testing.T) {
	m := testModel()
	m.Scroll = 3
	m, cmds := feed(m, typed("hola"), press("enter"))
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Busy || m.TurnStarted.IsZero() {
		t.Errorf("Busy = %v, TurnStarted = %v; want a running turn", m.Busy, m.TurnStarted)
	}
	if m.Input != "" || m.Cursor != 0 || m.Scroll != 0 || m.Flash != "" {
		t.Errorf("Input = %q, Cursor = %d, Scroll = %d, Flash = %q; want all cleared", m.Input, m.Cursor, m.Scroll, m.Flash)
	}
	if want := []state.Block{{Kind: state.KindUser, Text: "hola"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
	}
	if want := []string{"hola"}; !reflect.DeepEqual(m.History, want) {
		t.Errorf("History = %v, want %v", m.History, want)
	}
}

func TestSubagentTaskStartsATurn(t *testing.T) {
	m, cmds := feed(testModel(), typed("@explore find the parser"), press("enter"))
	if want := []any{protocol.NewInput("@explore find the parser")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Busy {
		t.Error("Busy = false, want true")
	}
}

func TestCommandsDoNotStartATurn(t *testing.T) {
	m, cmds := feed(testModel(), typed("/clear"), press("enter"))
	if want := []any{protocol.NewInput("/clear")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Busy {
		t.Error("Busy = true after a command, want false")
	}
}

func TestOnlyTheBackendsCommandsAreNotTurns(t *testing.T) {
	for _, line := range []string{"/agents", "/agent build", "/clear", "/exit", "/quit"} {
		m, cmds := feed(testModel(), typed(line), press("enter"))
		if want := []any{protocol.NewInput(line)}; !reflect.DeepEqual(cmds, want) || m.Busy {
			t.Errorf("%s: commands = %v, Busy = %v; want the command sent and no turn", line, cmds, m.Busy)
		}
	}
	for _, line := range []string{"/tmp/a.py is broken", "/help me", "/agentx", "/exists"} {
		m, cmds := feed(testModel(), typed(line), press("enter"))
		if want := []any{protocol.NewInput(line)}; !reflect.DeepEqual(cmds, want) || !m.Busy {
			t.Errorf("%s: commands = %v, Busy = %v; want the line sent as a turn", line, cmds, m.Busy)
		}
	}
}

func TestCommandsAreKnownBeforeTheReadyEvent(t *testing.T) {
	m, cmds := feed(state.New(), typed("/clear"), press("enter"))
	if want := []any{protocol.NewInput("/clear")}; !reflect.DeepEqual(cmds, want) || m.Busy {
		t.Errorf("commands = %v, Busy = %v; want /clear sent without a turn before ready", cmds, m.Busy)
	}
	m, cmds = feed(state.New(), typed("/tmp/a.py is broken"), press("enter"))
	if want := []any{protocol.NewInput("/tmp/a.py is broken")}; !reflect.DeepEqual(cmds, want) || !m.Busy {
		t.Errorf("commands = %v, Busy = %v; want the path sent as a turn before ready", cmds, m.Busy)
	}
}

func TestEnterWhileBusyQueuesTheInput(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, cmds := feed(m, typed("  next question "), press("enter"))
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none: the queued line is sent when the turn ends", cmds)
	}
	if want := []string{"next question"}; !reflect.DeepEqual(m.Queue, want) {
		t.Errorf("Queue = %v, want %v", m.Queue, want)
	}
	if m.Input != "" || m.Flash != "queued · alt+up edits the queue" {
		t.Errorf("Input = %q, Flash = %q; want the input cleared and the queue flashed", m.Input, m.Flash)
	}
	if len(m.Blocks) != 0 || !m.Busy {
		t.Errorf("Blocks = %+v, Busy = %v; want no block and the turn still running", m.Blocks, m.Busy)
	}
	if want := []string{"next question"}; !reflect.DeepEqual(m.History, want) {
		t.Errorf("History = %v, want %v", m.History, want)
	}
}

func TestQueueFlashLeavesTheCountToTheStatusLine(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, _ = feed(m, typed("first"), press("enter"), typed("second"), press("enter"))
	if len(m.Queue) != 2 || m.Flash != "queued · alt+up edits the queue" {
		t.Errorf("Queue = %v, Flash = %q; want two lines and a flash without a count", m.Queue, m.Flash)
	}
}

func TestEnterWhileBusyWithNothingTypedDoesNothing(t *testing.T) {
	m := testModel()
	m.Busy = true
	m.Queue = []string{"later"}
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || !reflect.DeepEqual(m.Queue, []string{"later"}) || m.Flash != "" {
		t.Errorf("commands = %v, Queue = %v, Flash = %q; want nothing changed", cmds, m.Queue, m.Flash)
	}
}

func TestEscWhileBusyKeepsTheQueue(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, cmds := feed(m, typed("later"), press("enter"), press("esc"))
	if want := []any{protocol.NewInterrupt()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if want := []string{"later"}; !reflect.DeepEqual(m.Queue, want) || m.Flash != "stopping…" {
		t.Errorf("Queue = %v, Flash = %q; want the queue kept and the stop flashed", m.Queue, m.Flash)
	}
}

func TestAltUpMovesTheQueueBackIntoTheInput(t *testing.T) {
	tests := []struct {
		name  string
		draft string
		want  string
	}{
		{name: "with an empty input", want: "first\nsecond"},
		{name: "ahead of a typed draft", draft: "draft", want: "first\nsecond\ndraft"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m := testModel()
			m.Busy = true
			m, _ = feed(m, typed("first"), press("enter"), typed("second"), press("enter"))
			if tt.draft != "" {
				m, _ = feed(m, typed(tt.draft))
			}
			m, cmds := feed(m, press("alt+up"))
			if len(cmds) != 0 || m.Queue != nil || m.Input != tt.want {
				t.Errorf("commands = %v, Queue = %v, Input = %q; want nothing sent, the queue empty and Input %q",
					cmds, m.Queue, m.Input, tt.want)
			}
			if m.Cursor != len([]rune(tt.want)) || m.Flash != "" {
				t.Errorf("Cursor = %d, Flash = %q; want the cursor at the end and no flash", m.Cursor, m.Flash)
			}
		})
	}
}

func TestEnterWhilePausedSendsTheNextQueuedMessage(t *testing.T) {
	m := testModel()
	m.Queue = []string{"first", "second"}
	m.QueuePaused = true
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewInput("first")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.QueuePaused || !m.Busy || !reflect.DeepEqual(m.Queue, []string{"second"}) {
		t.Errorf("QueuePaused = %v, Busy = %v, Queue = %v; want the queue resumed with second waiting",
			m.QueuePaused, m.Busy, m.Queue)
	}
	if want := []state.Block{{Kind: state.KindUser, Text: "first"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
	}
}

func TestEnterWhilePausedSendsCommandsAtOnce(t *testing.T) {
	m := testModel()
	m.Queue = []string{"/agents", "fix the bug", "then test"}
	m.QueuePaused = true
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewInput("/agents"), protocol.NewInput("fix the bug")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Busy || !reflect.DeepEqual(m.Queue, []string{"then test"}) {
		t.Errorf("Busy = %v, Queue = %v; want the turn of fix the bug running and then test waiting", m.Busy, m.Queue)
	}
}

func TestTypedTextWhilePausedIsSentAndUnpausesTheQueue(t *testing.T) {
	m := testModel()
	m.Queue = []string{"queued"}
	m.QueuePaused = true
	m, cmds := feed(m, typed("hola"), press("enter"))
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.QueuePaused || !m.Busy || !reflect.DeepEqual(m.Queue, []string{"queued"}) {
		t.Errorf("QueuePaused = %v, Busy = %v, Queue = %v; want the queue unpaused and kept", m.QueuePaused, m.Busy, m.Queue)
	}
	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("queued")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("after the turn: commands = %v, want %v", cmds, want)
	}
}

func TestCommandWhilePausedUnpausesAndGoesOnWithTheQueue(t *testing.T) {
	m := testModel()
	m.Queue = []string{"queued"}
	m.QueuePaused = true
	m, cmds := feed(m, typed("/agents"), press("enter"))
	if want := []any{protocol.NewInput("/agents"), protocol.NewInput("queued")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.QueuePaused || !m.Busy || m.Queue != nil {
		t.Errorf("QueuePaused = %v, Busy = %v, Queue = %v; want the queue sent after the command", m.QueuePaused, m.Busy, m.Queue)
	}
}

func TestBlankInputWhilePausedSendsTheQueueAndClearsTheBlank(t *testing.T) {
	m := testModel()
	m.Queue = []string{"queued"}
	m.QueuePaused = true
	m, cmds := feed(m, typed("   "), press("enter"))
	if want := []any{protocol.NewInput("queued")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Input != "" || m.QueuePaused || !m.Busy {
		t.Errorf("Input = %q, QueuePaused = %v, Busy = %v; want the blank cleared and the queue running", m.Input, m.QueuePaused, m.Busy)
	}
}

func TestBlankInputIsNotSubmitted(t *testing.T) {
	m, cmds := feed(testModel(), space(), space(), press("enter"))
	if len(cmds) != 0 || m.Busy {
		t.Errorf("commands = %v, Busy = %v; want nothing", cmds, m.Busy)
	}
	if m.Input != "  " || len(m.History) != 0 {
		t.Errorf("Input = %q, History = %v; want the input kept and no history", m.Input, m.History)
	}
}

func TestPastedControlCharactersAreNotSent(t *testing.T) {
	m, cmds := feed(testModel(), pasted("\x00\x00"), press("enter"))
	if len(cmds) != 0 || m.Busy || m.Input != "" {
		t.Errorf("commands = %v, Busy = %v, Input = %q; want nothing sent", cmds, m.Busy, m.Input)
	}
}

func TestSubmittedTextIsTrimmedLikePHP(t *testing.T) {
	m, cmds := feed(testModel(), pasted(" \x00 hola \x0B\n"), press("enter"))
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if want := []state.Block{{Kind: state.KindUser, Text: "hola"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
	}
}

func TestHistorySkipsARepeatOfTheLastEntry(t *testing.T) {
	m, _ := feed(testModel(),
		typed("/clear"), press("enter"),
		typed("/clear"), press("enter"),
		typed("/agents"), press("enter"),
		typed("/clear"), press("enter"),
	)
	if want := []string{"/clear", "/agents", "/clear"}; !reflect.DeepEqual(m.History, want) {
		t.Errorf("History = %v, want %v", m.History, want)
	}
}

func TestConfirmAnswers(t *testing.T) {
	tests := []struct {
		name     string
		key      any
		decision string
		stop     bool
	}{
		{name: "y allows once", key: typed("y"), decision: protocol.DecisionOnce},
		{name: "a allows for the session", key: typed("a"), decision: protocol.DecisionSession},
		{name: "n denies", key: typed("n"), decision: protocol.DecisionDeny},
		{name: "esc denies this call only", key: press("esc"), decision: protocol.DecisionDeny},
		{name: "ctrl+c denies and stops the turn", key: press("ctrl+c"), decision: protocol.DecisionDeny, stop: true},
		{name: "other letters are ignored", key: typed("x"), decision: ""},
		{name: "enter is ignored", key: press("enter"), decision: ""},
		{name: "tab is ignored", key: press("tab"), decision: ""},
		{name: "pasted y is ignored", key: pasted("y"), decision: ""},
		{name: "typed word esc is ignored", key: typed("esc"), decision: ""},
		{name: "alt+y is ignored", key: tea.KeyMsg{Type: tea.KeyRunes, Runes: []rune("y"), Alt: true}, decision: ""},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m := testModel()
			m.Busy = true
			m.Confirm = &state.Confirm{ID: "c1", Tool: "write_file"}
			m, cmds := feed(m, tt.key)
			if tt.decision == "" {
				if len(cmds) != 0 || m.Confirm == nil || m.Quit {
					t.Errorf("commands = %v, Confirm = %v, Quit = %v; want the prompt kept", cmds, m.Confirm, m.Quit)
				}
				return
			}
			want := []any{protocol.NewConfirmReply("c1", tt.decision)}
			if tt.stop {
				want = append(want, protocol.NewInterrupt())
			}
			if !reflect.DeepEqual(cmds, want) {
				t.Errorf("commands = %v, want %v", cmds, want)
			}
			if m.Confirm != nil {
				t.Errorf("Confirm = %+v, want it cleared", m.Confirm)
			}
			if m.Quit {
				t.Error("Quit = true, want false")
			}
			if (m.Flash == "stopping…") != tt.stop {
				t.Errorf("Flash = %q, want \"stopping…\" only when the turn stops", m.Flash)
			}
		})
	}
}

func TestConfirmIgnoresEditingKeys(t *testing.T) {
	m := testModel()
	m.Busy = true
	m.Confirm = &state.Confirm{ID: "c1"}
	m, cmds := feed(m, typed("hola"), press("backspace"), press("enter"), press("up"))
	if len(cmds) != 0 || m.Input != "" || m.Confirm == nil {
		t.Errorf("commands = %v, Input = %q, Confirm = %v; want the keys ignored", cmds, m.Input, m.Confirm)
	}
}

func TestCtrlTFoldsTheLastSubagentOnceItEnds(t *testing.T) {
	m, _ := feed(testModel(),
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s1", Parent: "build", Agent: "explore", Task: "find x"},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "explore", Depth: 1, Tool: "grep", Summary: "x", Parent: "build"},
	)
	m, _ = feed(m, press("ctrl+t"))
	if m.Blocks[0].Folded || m.Input != "" {
		t.Errorf("a subagent that still runs: Folded = %v, Input = %q; want it open and no input", m.Blocks[0].Folded, m.Input)
	}

	m, _ = feed(m, &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Agent: "explore", Status: "completed", Summary: "done"})
	m, _ = feed(m, press("ctrl+t"))
	if !m.Blocks[0].Folded {
		t.Fatal("ctrl+t did not fold the ended subagent")
	}
	m, _ = feed(m, press("ctrl+t"))
	if m.Blocks[0].Folded {
		t.Error("a second ctrl+t did not unfold it")
	}
}

func TestCtrlTFoldsOnlyTheLastSubagent(t *testing.T) {
	m, _ := feed(testModel(), press("ctrl+t"))
	if len(m.Blocks) != 0 {
		t.Errorf("ctrl+t with no subagent added blocks: %+v", m.Blocks)
	}

	m, _ = feed(testModel(),
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s1", Parent: "build", Agent: "explore", Task: "find x"},
		&protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Agent: "explore", Status: "completed", Summary: "done"},
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s2", Parent: "build", Agent: "general", Task: "write y"},
		&protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s2", Agent: "general", Status: "completed", Summary: "done"},
	)
	m, _ = feed(m, press("ctrl+t"))
	if m.Blocks[0].Folded || !m.Blocks[1].Folded {
		t.Errorf("Folded = %v, %v; want only the last subagent folded", m.Blocks[0].Folded, m.Blocks[1].Folded)
	}
}

func TestCtrlCInterruptsClearsOrQuits(t *testing.T) {
	tests := []struct {
		name      string
		busy      bool
		input     string
		wantInput string
		wantFlash string
		wantCmds  []any
		wantQuit  bool
	}{
		{
			name: "interrupts a running turn", busy: true,
			input: "draft", wantInput: "draft", wantFlash: "stopping…",
			wantCmds: []any{protocol.NewInterrupt()},
		},
		{
			name:  "clears a non-empty input",
			input: "draft", wantInput: "",
		},
		{
			name:     "quits when idle and empty",
			wantCmds: []any{protocol.NewQuit()}, wantQuit: true,
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m := testModel()
			m.Busy = tt.busy
			m.Input = tt.input
			m.Cursor = len([]rune(tt.input))
			m, cmds := feed(m, press("ctrl+c"))
			if !reflect.DeepEqual(cmds, tt.wantCmds) {
				t.Errorf("commands = %v, want %v", cmds, tt.wantCmds)
			}
			if m.Input != tt.wantInput || m.Flash != tt.wantFlash || m.Quit != tt.wantQuit {
				t.Errorf("Input = %q, Flash = %q, Quit = %v; want %q, %q, %v",
					m.Input, m.Flash, m.Quit, tt.wantInput, tt.wantFlash, tt.wantQuit)
			}
		})
	}
}

func TestCtrlDQuitsOnlyWhenIdleAndEmpty(t *testing.T) {
	tests := []struct {
		name      string
		busy      bool
		input     string
		wantInput string
		wantQuit  bool
	}{
		{name: "quits when idle and empty", wantQuit: true},
		{name: "deletes under the cursor when text remains", input: "x", wantInput: ""},
		{name: "does not quit while a turn runs", busy: true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m := testModel()
			m.Busy = tt.busy
			m.Input = tt.input
			m.Cursor = 0
			m, _ = feed(m, press("ctrl+d"))
			if m.Quit != tt.wantQuit || m.Input != tt.wantInput {
				t.Errorf("Quit = %v, Input = %q; want %v, %q", m.Quit, m.Input, tt.wantQuit, tt.wantInput)
			}
		})
	}
}

func TestEscClearsSuggestionsThenInterrupts(t *testing.T) {
	m, _ := feed(testModel(), typed("/"))
	m.Busy = true
	m, cmds := feed(m, press("esc"))
	if len(cmds) != 0 || m.Suggestions != nil || m.Flash != "" {
		t.Errorf("first esc: commands = %v, Suggestions = %v, Flash = %q; want only the list cleared",
			cmds, m.Suggestions, m.Flash)
	}
	m, cmds = feed(m, press("esc"))
	if want := []any{protocol.NewInterrupt()}; !reflect.DeepEqual(cmds, want) || m.Flash != "stopping…" {
		t.Errorf("second esc: commands = %v, Flash = %q; want interrupt and \"stopping…\"", cmds, m.Flash)
	}
}

func TestEscWhenIdleDoesNothing(t *testing.T) {
	m, cmds := feed(testModel(), typed("draft"), press("esc"))
	if len(cmds) != 0 || m.Input != "draft" || m.Quit {
		t.Errorf("commands = %v, Input = %q, Quit = %v; want nothing", cmds, m.Input, m.Quit)
	}
}

func TestUpAndDownMoveThroughSuggestions(t *testing.T) {
	m, _ := feed(testModel(), typed("/"))
	last := len(m.Suggestions) - 1
	steps := []struct {
		key  string
		want int
	}{
		{"down", 1},
		{"up", 0},
		{"up", last},
		{"down", 0},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if m.Selected != s.want {
			t.Fatalf("after %s: Selected = %d, want %d", s.key, m.Selected, s.want)
		}
	}
}

func TestUpAndDownBrowseHistoryWithoutSuggestions(t *testing.T) {
	m := testModel()
	m.History = []string{"one", "two"}
	steps := []struct {
		key   string
		input string
		index int
	}{
		{"up", "two", 1},
		{"up", "one", 0},
		{"up", "one", 0},
		{"down", "two", 1},
		{"down", "", -1},
		{"down", "", -1},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if m.Input != s.input || m.HistoryIndex != s.index {
			t.Fatalf("after %s: Input = %q, HistoryIndex = %d; want %q, %d", s.key, m.Input, m.HistoryIndex, s.input, s.index)
		}
	}
}

func TestHistoryKeepsUpAndDownWalkingForCommands(t *testing.T) {
	m := testModel()
	m.History = []string{"/agents", "/help"}
	m, _ = feed(m, press("up"))
	if m.Input != "/help" || len(m.Suggestions) != 0 {
		t.Fatalf("after up: Input = %q, Suggestions = %v; want the recalled command and no list", m.Input, m.Suggestions)
	}
	m, _ = feed(m, press("up"))
	if m.Input != "/agents" || len(m.Suggestions) != 0 {
		t.Errorf("after second up: Input = %q, Suggestions = %v; want \"/agents\" and no list", m.Input, m.Suggestions)
	}
}

func TestUpAndDownMoveTheCursorInMultilineInput(t *testing.T) {
	m, _ := feed(testModel(), typed("a"), press("alt+enter"), typed("bc"))
	steps := []struct {
		key    string
		cursor int
	}{
		{"up", 1},
		{"up", 0},
		{"up", 0},
		{"down", 2},
		{"down", 4},
		{"down", 4},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if m.Cursor != s.cursor {
			t.Fatalf("after %s: Cursor = %d, want %d", s.key, m.Cursor, s.cursor)
		}
	}
	if m.Input != "a\nbc" {
		t.Errorf("Input = %q, want it unchanged", m.Input)
	}
}

func TestMultilineCursorKeepsTheColumn(t *testing.T) {
	m, _ := feed(testModel(), typed("abcdef"), press("alt+enter"), typed("xyz"))
	m, _ = feed(m, press("up"))
	if m.Cursor != 3 {
		t.Errorf("Cursor = %d after up from column 3, want 3", m.Cursor)
	}
}

func pagedModel(w, h int) state.Model {
	m := testModel()
	m.Width, m.Height = w, h
	for i := 1; i <= 60; i++ {
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindOutput, Text: fmt.Sprintf("line %d", i)})
	}
	return m
}

func TestScrollKeys(t *testing.T) {
	m := pagedModel(80, 40)
	steps := []struct {
		key  string
		want int
	}{
		{"alt+up", 1},
		{"alt+up", 2},
		{"alt+down", 1},
		{"alt+down", 0},
		{"alt+down", 0},
		{"pgup", 20},
		{"pgdown", 0},
		{"pgdown", 0},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if m.Scroll != s.want {
			t.Fatalf("after %s: Scroll = %d, want %d", s.key, m.Scroll, s.want)
		}
	}

	m.Height = 1
	m, _ = feed(m, press("pgup"))
	if m.Scroll != 1 {
		t.Errorf("pgup with height 1: Scroll = %d, want 1", m.Scroll)
	}
}

func TestPageKeysStayInsideTheTranscript(t *testing.T) {
	m := pagedModel(80, 40)
	page := pageSize(m)
	if top := view.MaxScroll(m); top <= 3*page {
		t.Fatalf("MaxScroll = %d, too short for three pages of %d", top, page)
	}

	for i := 0; i < 10; i++ {
		m, _ = feed(m, press("pgup"))
		if top := view.MaxScroll(m); m.Scroll > top {
			t.Fatalf("pgup %d: Scroll = %d, above MaxScroll %d", i+1, m.Scroll, top)
		}
	}
	top := view.MaxScroll(m)
	if m.Scroll != top {
		t.Fatalf("at the top: Scroll = %d, want MaxScroll %d", m.Scroll, top)
	}

	m, _ = feed(m, press("pgup"), press("pgup"), press("pgup"))
	if m.Scroll != top {
		t.Errorf("three more pgup at the top: Scroll = %d, want it to stay at %d", m.Scroll, top)
	}

	m, _ = feed(m, press("pgdown"))
	if want := top - page; m.Scroll != want {
		t.Errorf("pgdown from the top: Scroll = %d, want %d, one page down", m.Scroll, want)
	}
}
