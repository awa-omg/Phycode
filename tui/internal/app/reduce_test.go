package app

import (
	"errors"
	"reflect"
	"regexp"
	"strings"
	"testing"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/backend"
	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
	"phycode/tui/internal/view"
)

func testModel() state.Model {
	m := state.New()
	m.Commands = []protocol.Command{
		{Name: "/help", Description: "show the commands"},
		{Name: "/agent NAME", Description: "switch the primary agent"},
		{Name: "/agents", Description: "list the agents"},
		{Name: "/clear", Description: "start a new conversation"},
		{Name: "/exit", Description: "leave"},
	}
	m.Agents = []protocol.Agent{
		{Name: "build", Mode: "primary", Description: "writes code"},
		{Name: "plan", Mode: "primary", Description: "plans the work"},
		{Name: "explore", Mode: "subagent", Description: "reads the code"},
		{Name: "general", Mode: "subagent", Description: "does anything"},
	}
	return m
}

func untimed(blocks []state.Block) []state.Block {
	out := make([]state.Block, len(blocks))
	for i, b := range blocks {
		b.Started, b.Ended = time.Time{}, time.Time{}
		out[i] = b
	}
	return out
}

func feed(m state.Model, msgs ...any) (state.Model, []any) {
	var cmds []any
	for _, msg := range msgs {
		var out []any
		m, out = Update(m, msg)
		cmds = append(cmds, out...)
	}
	return m, cmds
}

func ptr(s string) *string { return &s }

var escapeSeq = regexp.MustCompile(`\x1b\[[0-9;?]*[ -/]*[@-~]`)

func statusOf(m state.Model) string {
	rows := strings.Split(view.NewRenderer(theme.Default()).View(m), "\n")
	return escapeSeq.ReplaceAllString(rows[len(rows)-1], "")
}

func TestReadyStoresTheSession(t *testing.T) {
	ready := &protocol.Ready{
		Type:     protocol.TypeReady,
		Protocol: protocol.Version,
		App:      protocol.AppInfo{Version: "1.0", Model: "qwen", Agent: "build"},
		Agents:   []protocol.Agent{{Name: "build", Mode: "primary", Description: "writes code"}},
		Commands: []protocol.Command{{Name: "/help", Description: "show the commands"}},
	}
	m, cmds := feed(state.New(), ready)
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	if !m.Ready {
		t.Error("Ready = false, want true")
	}
	if m.App != ready.App {
		t.Errorf("App = %+v, want %+v", m.App, ready.App)
	}
	if !reflect.DeepEqual(m.Agents, ready.Agents) {
		t.Errorf("Agents = %+v, want %+v", m.Agents, ready.Agents)
	}
	if !reflect.DeepEqual(m.Commands, ready.Commands) {
		t.Errorf("Commands = %+v, want %+v", m.Commands, ready.Commands)
	}
}

func TestReadySetsTheEffortFromTheProfileByItsCanonicalName(t *testing.T) {
	for _, tt := range []struct{ thinking, want string }{
		{"medium", "medium"},
		{"on", "medium"},
		{"off", "none"},
		{"high", "high"},
		{"", ""},
	} {
		m, _ := feed(state.New(), &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version, App: protocol.AppInfo{Thinking: tt.thinking}})
		if m.Effort.Level != tt.want {
			t.Errorf("profile thinking %q: Effort.Level = %q, want %q", tt.thinking, m.Effort.Level, tt.want)
		}
	}
}

func TestTheStatusShowsTheProfilesEffortWhenTheSessionStarts(t *testing.T) {
	ready := &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version, App: protocol.AppInfo{Model: "qwen", Thinking: "medium"}}
	for _, w := range []int{40, 80} {
		m, _ := feed(state.New(), tea.WindowSizeMsg{Width: w, Height: 10}, ready)
		if status := statusOf(m); !strings.Contains(status, "effort medium") {
			t.Errorf("%d columns: status = %q, want effort medium from the ready event", w, status)
		}
	}
}

func TestTurnStartMarksTheTurnBusy(t *testing.T) {
	m := state.New()
	m.Changes = []string{"old.go"}
	m, cmds := feed(m, &protocol.TurnStart{Type: protocol.TypeTurnStart})
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	if !m.Busy {
		t.Error("Busy = false, want true")
	}
	if m.TurnStarted.IsZero() {
		t.Error("TurnStarted is zero")
	}
	if m.Changes != nil {
		t.Errorf("Changes = %v, want nil", m.Changes)
	}
}

func TestTextStreamsIntoTheLastAnswer(t *testing.T) {
	chunk := func(agent, text string) *protocol.Text {
		return &protocol.Text{Type: protocol.TypeText, Agent: agent, Chunk: text}
	}
	tool := &protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "build", Tool: "read", Summary: "a.go"}
	tests := []struct {
		name string
		msgs []any
		want []state.Block
	}{
		{
			name: "chunks of one agent join into one answer",
			msgs: []any{chunk("build", "Hel"), chunk("build", "lo")},
			want: []state.Block{{Kind: state.KindAssistant, Agent: "build", Text: "Hello"}},
		},
		{
			name: "another agent starts a new answer",
			msgs: []any{chunk("build", "a"), chunk("plan", "b")},
			want: []state.Block{
				{Kind: state.KindAssistant, Agent: "build", Text: "a"},
				{Kind: state.KindAssistant, Agent: "plan", Text: "b"},
			},
		},
		{
			name: "a tool call between chunks starts a new answer",
			msgs: []any{chunk("build", "a"), tool, chunk("build", "b")},
			want: []state.Block{
				{Kind: state.KindAssistant, Agent: "build", Text: "a"},
				{Kind: state.KindTool, ID: "t1", Agent: "build", Tool: "read", Summary: "a.go", Status: "running"},
				{Kind: state.KindAssistant, Agent: "build", Text: "b"},
			},
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m, _ := feed(state.New(), tt.msgs...)
			if !reflect.DeepEqual(m.Blocks, tt.want) {
				t.Errorf("Blocks = %+v, want %+v", m.Blocks, tt.want)
			}
		})
	}
}

func TestToolEndMatchesItsStartById(t *testing.T) {
	starts := []any{
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "build", Tool: "read", Summary: "a.go"},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t2", Agent: "build", Tool: "grep", Summary: "TODO"},
	}
	first := state.Block{Kind: state.KindTool, ID: "t1", Agent: "build", Tool: "read", Summary: "a.go", Status: "running"}
	second := state.Block{Kind: state.KindTool, ID: "t2", Agent: "build", Tool: "grep", Summary: "TODO", Status: "running"}
	tests := []struct {
		name string
		end  *protocol.ToolEnd
		want []state.Block
	}{
		{
			name: "ok result goes to the first call",
			end:  &protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t1", OK: true, Summary: "12 lines"},
			want: []state.Block{
				{Kind: state.KindTool, ID: "t1", Agent: "build", Tool: "read", Summary: "a.go", Text: "12 lines", Status: "ok"},
				second,
			},
		},
		{
			name: "failed result goes to the second call",
			end:  &protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t2", OK: false, Summary: "no match"},
			want: []state.Block{
				first,
				{Kind: state.KindTool, ID: "t2", Agent: "build", Tool: "grep", Summary: "TODO", Text: "no match", Status: "error"},
			},
		},
		{
			name: "unknown id changes nothing",
			end:  &protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "zz", OK: true, Summary: "x"},
			want: []state.Block{first, second},
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m, _ := feed(state.New(), starts...)
			m, _ = feed(m, tt.end)
			if !reflect.DeepEqual(m.Blocks, tt.want) {
				t.Errorf("Blocks = %+v, want %+v", m.Blocks, tt.want)
			}
		})
	}
}

func TestCardsHangUnderTheSubagentThatIsOpen(t *testing.T) {
	m, cmds := feed(testModel(),
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s1", Parent: "build", Agent: "explore", Task: "find x"},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "explore", Depth: 1, Tool: "grep", Summary: "x", Parent: "build"},
		&protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Agent: "explore", Status: "completed", Summary: "done"},
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s2", Parent: "build", Agent: "general", Task: "write y"},
		&protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: "inner", Parent: "build"},
		&protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: "outer"},
		&protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s2", Agent: "general", Status: "completed", Summary: "done"},
		&protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: "late", Parent: "build"},
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	want := []state.Block{
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find x", Status: "completed", Summary: "done"},
		{Kind: state.KindTool, ID: "t1", Agent: "explore", Depth: 1, Parent: "s1", Tool: "grep", Summary: "x", Status: "running"},
		{Kind: state.KindSubagent, ID: "s2", Agent: "general", Text: "write y", Status: "completed", Summary: "done"},
		{Kind: state.KindNotice, Level: "info", Depth: 1, Parent: "s2", Text: "inner"},
		{Kind: state.KindNotice, Level: "info", Text: "outer"},
		{Kind: state.KindNotice, Level: "info", Text: "late"},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), want) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
	}
}

func TestSubagentStartHangsOnlyUnderTheSubagentThatNamesIt(t *testing.T) {
	start := func(id, parent, agent string) *protocol.SubagentStart {
		return &protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: id, Parent: parent, Agent: agent, Task: "task"}
	}
	nested, _ := feed(testModel(), start("s1", "build", "explore"), start("s2", "explore", "general"))
	if b := nested.Blocks[1]; b.Parent != "s1" || b.Depth != 1 {
		t.Errorf("a subagent that names s1: Parent = %q, Depth = %d; want s1 and 1", b.Parent, b.Depth)
	}
	stray, _ := feed(testModel(), start("s1", "build", "explore"), start("s2", "build", "general"))
	if b := stray.Blocks[1]; b.Parent != "" || b.Depth != 0 {
		t.Errorf("a subagent that names build: Parent = %q, Depth = %d; want none and 0", b.Parent, b.Depth)
	}
}

func TestSubagentEndUpdatesItsBlock(t *testing.T) {
	start := &protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s1", Parent: "build", Agent: "explore", Task: "find the parser"}
	running := state.Block{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find the parser", Status: "running"}
	tests := []struct {
		name string
		end  *protocol.SubagentEnd
		want state.Block
	}{
		{
			name: "matching id gets the status and report",
			end:  &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Agent: "explore", Status: "completed", Summary: "it is in lexer.php"},
			want: state.Block{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find the parser", Status: "completed", Summary: "it is in lexer.php"},
		},
		{
			name: "unknown id changes nothing",
			end:  &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "zz", Status: "completed", Summary: "x"},
			want: running,
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m, _ := feed(state.New(), start)
			m, _ = feed(m, tt.end)
			if len(m.Blocks) != 1 || !reflect.DeepEqual(untimed(m.Blocks)[0], tt.want) {
				t.Errorf("Blocks = %+v, want [%+v]", m.Blocks, tt.want)
			}
		})
	}
}

func TestNoticeOutputAndConfirmAreKept(t *testing.T) {
	m, cmds := feed(state.New(),
		&protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: "saved"},
		&protocol.Output{Type: protocol.TypeOutput, Text: "help text"},
		&protocol.Confirm{Type: protocol.TypeConfirm, ID: "c1", Tool: "write_file", Category: "write", Agent: "build", Depth: 0, Summary: "edit a.go"},
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	wantBlocks := []state.Block{
		{Kind: state.KindNotice, Level: "info", Text: "saved"},
		{Kind: state.KindOutput, Text: "help text"},
	}
	if !reflect.DeepEqual(m.Blocks, wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
	wantConfirm := &state.Confirm{ID: "c1", Tool: "write_file", Category: "write", Agent: "build", Summary: "edit a.go"}
	if !reflect.DeepEqual(m.Confirm, wantConfirm) {
		t.Errorf("Confirm = %+v, want %+v", m.Confirm, wantConfirm)
	}
}

func TestTurnEndClosesTheTurn(t *testing.T) {
	tests := []struct {
		name string
		end  *protocol.TurnEnd
	}{
		{
			name: "completed",
			end:  &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed", Changes: []string{"a.go"}},
		},
		{
			name: "budget",
			end:  &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "budget", Reason: ptr("too many tool calls")},
		},
		{
			name: "cancelled without a reason",
			end:  &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "cancelled"},
		},
		{
			name: "error",
			end:  &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "error", Reason: ptr("model unreachable")},
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m := state.New()
			m.Busy = true
			m.Confirm = &state.Confirm{ID: "c1"}
			m.Flash = "stopping…"
			m, cmds := feed(m, tt.end)
			if len(cmds) != 0 {
				t.Errorf("commands = %v, want none", cmds)
			}
			if m.Busy || m.Confirm != nil || m.Flash != "" {
				t.Errorf("Busy = %v, Confirm = %v, Flash = %q; want idle", m.Busy, m.Confirm, m.Flash)
			}
			if !reflect.DeepEqual(m.Changes, tt.end.Changes) {
				t.Errorf("Changes = %v, want %v", m.Changes, tt.end.Changes)
			}
			if len(m.Blocks) != 0 {
				t.Errorf("Blocks = %+v, want none: the backend sends its own notice before turn_end", m.Blocks)
			}
		})
	}
}

func TestTurnEndSendsTheNextQueuedMessage(t *testing.T) {
	for _, status := range []string{"completed", "budget", "stuck", "error"} {
		t.Run(status, func(t *testing.T) {
			m := state.New()
			m.Busy = true
			m.Queue = []string{"first", "second"}
			m, cmds := feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: status})
			if want := []any{protocol.NewInput("first")}; !reflect.DeepEqual(cmds, want) {
				t.Errorf("commands = %v, want %v", cmds, want)
			}
			if !m.Busy || m.TurnStarted.IsZero() || !reflect.DeepEqual(m.Queue, []string{"second"}) {
				t.Errorf("Busy = %v, TurnStarted = %v, Queue = %v; want the next turn running and second waiting",
					m.Busy, m.TurnStarted, m.Queue)
			}
			if want := []state.Block{{Kind: state.KindUser, Text: "first"}}; !reflect.DeepEqual(m.Blocks, want) {
				t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
			}
		})
	}
}

func TestQueuedCommandsStartNoTurn(t *testing.T) {
	m := testModel()
	m.Busy = true
	m.Queue = []string{"/agents", "/clear"}
	m, cmds := feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("/agents"), protocol.NewInput("/clear")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Busy || m.Queue != nil {
		t.Errorf("Busy = %v, Queue = %v; want no turn and an empty queue", m.Busy, m.Queue)
	}

	m = testModel()
	m.Busy = true
	m.Queue = []string{"/agents", "fix it", "then test"}
	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("/agents"), protocol.NewInput("fix it")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Busy || !reflect.DeepEqual(m.Queue, []string{"then test"}) {
		t.Errorf("Busy = %v, Queue = %v; want the turn of fix it running and then test waiting", m.Busy, m.Queue)
	}
}

func TestQueuedPathLineIsATurnAndHoldsTheRest(t *testing.T) {
	m := testModel()
	m.Busy = true
	m.Queue = []string{"/tmp/a.py is broken", "next"}
	m, cmds := feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("/tmp/a.py is broken")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the path starts a turn, so next waits", cmds, want)
	}
	if !m.Busy || !reflect.DeepEqual(m.Queue, []string{"next"}) {
		t.Errorf("Busy = %v, Queue = %v; want the path turn running and next waiting", m.Busy, m.Queue)
	}

	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("next")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("after the second turn: commands = %v, want %v", cmds, want)
	}
}

func TestCancelledTurnPausesTheQueue(t *testing.T) {
	m := state.New()
	m.Busy = true
	m.Queue = []string{"next"}
	m, cmds := feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "cancelled", Reason: ptr("interrupted by the user")})
	if len(cmds) != 0 || m.Busy {
		t.Errorf("commands = %v, Busy = %v; want no turn and nothing sent", cmds, m.Busy)
	}
	if !m.QueuePaused || !reflect.DeepEqual(m.Queue, []string{"next"}) {
		t.Errorf("QueuePaused = %v, Queue = %v; want the queue paused and kept", m.QueuePaused, m.Queue)
	}
	if want := "queue paused after interrupt · enter sends the next one"; m.Flash != want {
		t.Errorf("Flash = %q, want %q", m.Flash, want)
	}
	if len(m.Blocks) != 0 {
		t.Errorf("Blocks = %+v, want none", m.Blocks)
	}
}

func TestCancelledTurnWithoutAQueueDoesNotPause(t *testing.T) {
	m := state.New()
	m.Busy = true
	m, _ = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "cancelled"})
	if m.QueuePaused || m.Flash != "" {
		t.Errorf("QueuePaused = %v, Flash = %q; want nothing to pause", m.QueuePaused, m.Flash)
	}
}

func TestSessionEndsStopTheProgram(t *testing.T) {
	tests := []struct {
		name      string
		msgs      []any
		wantError string
	}{
		{
			name:      "bye ends the session cleanly",
			msgs:      []any{&protocol.Bye{Type: protocol.TypeBye, Reason: "exit"}},
			wantError: "",
		},
		{
			name:      "fatal error is kept for the screen",
			msgs:      []any{&protocol.Fatal{Type: protocol.TypeError, Message: "no model configured"}},
			wantError: "no model configured",
		},
		{
			name:      "closed without bye",
			msgs:      []any{&backend.Closed{}},
			wantError: "the backend stopped",
		},
		{
			name:      "closed with stderr names its last line",
			msgs:      []any{&backend.Closed{Stderr: "booting\npanic: out of memory\n\n"}},
			wantError: "the backend stopped: panic: out of memory",
		},
		{
			name:      "closed after bye is not an error",
			msgs:      []any{&protocol.Bye{Type: protocol.TypeBye}, &backend.Closed{Stderr: "late line"}},
			wantError: "",
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m := state.New()
			m.Busy = true
			m, cmds := feed(m, tt.msgs...)
			if len(cmds) != 0 {
				t.Errorf("commands = %v, want none", cmds)
			}
			if !m.Quit {
				t.Error("Quit = false, want true")
			}
			if m.Busy {
				t.Error("Busy = true, want false")
			}
			if m.Error != tt.wantError {
				t.Errorf("Error = %q, want %q", m.Error, tt.wantError)
			}
		})
	}
}

func TestClosedClearsAPendingConfirmation(t *testing.T) {
	m := state.New()
	m.Busy = true
	m.Confirm = &state.Confirm{ID: "c1"}
	m, _ = feed(m, &backend.Closed{})
	if m.Confirm != nil {
		t.Errorf("Confirm = %+v, want nil", m.Confirm)
	}
}

func TestBadLineIsNoticedAndUnknownEventsAreIgnored(t *testing.T) {
	m, cmds := feed(state.New(),
		&backend.BadLine{Line: []byte("oops"), Err: errors.New("invalid character")},
		&protocol.Unknown{Type: "future_event", Raw: []byte(`{"type":"future_event"}`)},
		"not a message the app knows",
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	want := []state.Block{{Kind: state.KindNotice, Level: "warn", Text: "the backend sent a line that is not JSON"}}
	if !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
	}
	if m.Quit || m.Busy || m.Error != "" {
		t.Errorf("Quit = %v, Busy = %v, Error = %q; want none set", m.Quit, m.Busy, m.Error)
	}
}

func TestTickAdvancesTheSpinnerOnlyWhileBusy(t *testing.T) {
	m, _ := feed(state.New(), Tick{})
	if m.Frame != 0 {
		t.Errorf("Frame = %d while idle, want 0", m.Frame)
	}
	m.Busy = true
	m, _ = feed(m, Tick{}, Tick{})
	if m.Frame != 2 {
		t.Errorf("Frame = %d after two ticks while busy, want 2", m.Frame)
	}
}

func TestWindowSizeIsStored(t *testing.T) {
	m, cmds := feed(state.New(), tea.WindowSizeMsg{Width: 120, Height: 40})
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	if m.Width != 120 || m.Height != 40 {
		t.Errorf("size = %dx%d, want 120x40", m.Width, m.Height)
	}
}
