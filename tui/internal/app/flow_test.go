package app

import (
	"errors"
	"reflect"
	"testing"

	"phycode/tui/internal/backend"
	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func TestGreetingTurnStreamsOneAnswer(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("hola"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "Hola"},
		&protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "!"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
	)
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	wantBlocks := []state.Block{
		{Kind: state.KindUser, Text: "hola"},
		{Kind: state.KindAssistant, Agent: "build", Text: "Hola!"},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
	if m.Busy {
		t.Error("Busy = true after turn_end, want false")
	}
}

func TestApprovedToolCallRunsBeforeTheAnswer(t *testing.T) {
	m, cmds := feed(testModel(),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "build", Tool: "bash", Summary: "go test ./..."},
		&protocol.Confirm{Type: protocol.TypeConfirm, ID: "c1", Tool: "bash", Category: "exec", Agent: "build", Summary: "go test ./..."},
	)
	if len(cmds) != 0 {
		t.Fatalf("commands = %v before the answer, want none", cmds)
	}
	if m.Confirm == nil || m.Confirm.ID != "c1" {
		t.Fatalf("Confirm = %+v, want the prompt for c1", m.Confirm)
	}

	m, cmds = feed(m, typed("y"))
	if want := []any{protocol.NewConfirmReply("c1", protocol.DecisionOnce)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Confirm != nil {
		t.Errorf("Confirm = %+v after the answer, want it cleared", m.Confirm)
	}

	m, cmds = feed(m,
		&protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t1", OK: true, Summary: "3 packages ok"},
		&protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "Tests pass."},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v after the answer, want none", cmds)
	}
	wantBlocks := []state.Block{
		{Kind: state.KindTool, ID: "t1", Agent: "build", Tool: "bash", Summary: "go test ./...", Text: "3 packages ok", Status: "ok"},
		{Kind: state.KindAssistant, Agent: "build", Text: "Tests pass."},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
}

func TestEscDenialLetsTheTurnGoOn(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("clean the build"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "build", Tool: "bash", Summary: "rm -rf build"},
		&protocol.Confirm{Type: protocol.TypeConfirm, ID: "c1", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"},
		press("esc"),
	)
	wantCmds := []any{protocol.NewInput("clean the build"), protocol.NewConfirmReply("c1", protocol.DecisionDeny)}
	if !reflect.DeepEqual(cmds, wantCmds) {
		t.Errorf("commands = %v, want %v: esc denies this call and does not interrupt", cmds, wantCmds)
	}
	if m.Confirm != nil || !m.Busy {
		t.Errorf("Confirm = %+v, Busy = %v after esc; want the prompt cleared and the turn running", m.Confirm, m.Busy)
	}

	m, cmds = feed(m,
		&protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t1", OK: false, Summary: "the user denied this action"},
		&protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "Skipping the build."},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v after the turn ended, want none", cmds)
	}
	wantBlocks := []state.Block{
		{Kind: state.KindUser, Text: "clean the build"},
		{Kind: state.KindTool, ID: "t1", Agent: "build", Tool: "bash", Summary: "rm -rf build", Text: "the user denied this action", Status: "error"},
		{Kind: state.KindAssistant, Agent: "build", Text: "Skipping the build."},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
	if m.Busy {
		t.Error("Busy = true after turn_end, want false")
	}
}

func TestCtrlCDenialStopsTheTurn(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("clean the build"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "build", Tool: "bash", Summary: "rm -rf build"},
		&protocol.Confirm{Type: protocol.TypeConfirm, ID: "c1", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"},
		press("ctrl+c"),
	)
	wantCmds := []any{protocol.NewInput("clean the build"), protocol.NewConfirmReply("c1", protocol.DecisionDeny), protocol.NewInterrupt()}
	if !reflect.DeepEqual(cmds, wantCmds) {
		t.Errorf("commands = %v, want %v: ctrl+c denies and interrupts", cmds, wantCmds)
	}
	if m.Confirm != nil || m.Quit || !m.Busy || m.Flash != "stopping…" {
		t.Errorf("Confirm = %+v, Quit = %v, Busy = %v, Flash = %q; want the prompt cleared and the turn stopping",
			m.Confirm, m.Quit, m.Busy, m.Flash)
	}

	m, cmds = feed(m,
		&protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t1", OK: false, Summary: "the user denied this action"},
		&protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "turn ended (cancelled): interrupted by the user"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "cancelled", Reason: ptr("interrupted by the user")},
	)
	if len(cmds) != 0 || m.Busy {
		t.Errorf("commands = %v, Busy = %v after the turn ended; want no command and an idle session", cmds, m.Busy)
	}
}

func TestPathLineDuringATurnWaitsForTheTurnToEnd(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("hola"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		typed("/tmp/a.py is broken"), press("enter"),
	)
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the path line is queued", cmds, want)
	}
	if want := []string{"/tmp/a.py is broken"}; !reflect.DeepEqual(m.Queue, want) {
		t.Errorf("Queue = %v, want %v", m.Queue, want)
	}

	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("/tmp/a.py is broken")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("after the turn: commands = %v, want %v", cmds, want)
	}
	if !m.Busy || m.Queue != nil {
		t.Errorf("Busy = %v, Queue = %v; want the path sent as a turn and the queue empty", m.Busy, m.Queue)
	}
}

func TestQueuedTurnGoesOutAfterAQueuedCommand(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("hola"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		typed("/clear"), press("enter"),
		typed("next"), press("enter"),
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
	)
	if want := []any{protocol.NewInput("hola"), protocol.NewInput("/clear"), protocol.NewInput("next")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the command goes out, then the turn after it", cmds, want)
	}
	if !m.Busy || m.Queue != nil {
		t.Errorf("Busy = %v, Queue = %v; want the turn after the command running", m.Busy, m.Queue)
	}
}

func TestSubagentToolCallNestsBetweenItsStartAndEnd(t *testing.T) {
	m, cmds := feed(testModel(),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s1", Parent: "build", Agent: "explore", Task: "find x"},
		&protocol.ToolStart{Type: protocol.TypeToolStart, ID: "t1", Agent: "explore", Depth: 1, Tool: "grep", Summary: "x", Parent: "build"},
		&protocol.ToolEnd{Type: protocol.TypeToolEnd, ID: "t1", OK: true, Summary: "2 matches"},
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	wantBlocks := []state.Block{
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find x", Status: "running"},
		{Kind: state.KindTool, ID: "t1", Agent: "explore", Depth: 1, Parent: "s1", Tool: "grep", Summary: "x", Text: "2 matches", Status: "ok"},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Fatalf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}

	m, cmds = feed(m,
		&protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Agent: "explore", Status: "completed", Summary: "x is in lexer.php"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	wantBlocks = []state.Block{
		{Kind: state.KindSubagent, ID: "s1", Agent: "explore", Text: "find x", Status: "completed", Summary: "x is in lexer.php"},
		{Kind: state.KindTool, ID: "t1", Agent: "explore", Depth: 1, Parent: "s1", Tool: "grep", Summary: "x", Text: "2 matches", Status: "ok"},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
}

func TestAgentsCommandPrintsItsListWithoutStartingATurn(t *testing.T) {
	const list = "build  primary  writes code\nexplore  subagent  reads the code"
	m, cmds := feed(testModel(),
		typed("/agents"), press("enter"),
		&protocol.Output{Type: protocol.TypeOutput, Text: list},
	)
	if want := []any{protocol.NewInput("/agents")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Busy {
		t.Error("Busy = true after a command, want false")
	}
	wantBlocks := []state.Block{
		{Kind: state.KindUser, Text: "/agents"},
		{Kind: state.KindOutput, Text: list},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
}

func TestBackendClosingMidTurnEndsTheSession(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("hola"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "Ho"},
		&backend.Closed{Stderr: "PHP Fatal error: out of memory\n"},
	)
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if want := "the backend stopped: PHP Fatal error: out of memory"; m.Error != want {
		t.Errorf("Error = %q, want %q", m.Error, want)
	}
	if !m.Quit || m.Busy {
		t.Errorf("Quit = %v, Busy = %v; want the session ended with no turn running", m.Quit, m.Busy)
	}
}

func TestCtrlCDuringATurnSendsInterrupt(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("hola"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		press("ctrl+c"),
	)
	if want := []any{protocol.NewInput("hola"), protocol.NewInterrupt()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Flash != "stopping…" || !m.Busy || m.Quit {
		t.Errorf("Flash = %q, Busy = %v, Quit = %v; want \"stopping…\" while the turn still runs", m.Flash, m.Busy, m.Quit)
	}

	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "cancelled", Reason: ptr("interrupted by the user")})
	if len(cmds) != 0 || m.Busy || m.Flash != "" {
		t.Errorf("after turn_end: commands = %v, Busy = %v, Flash = %q; want idle with no flash", cmds, m.Busy, m.Flash)
	}
}

func TestQueuedMessagesRunOneTurnAfterAnother(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("first"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		typed("second"), press("enter"),
		typed("third"), press("enter"),
		&protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "one"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "two"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
	)
	wantCmds := []any{protocol.NewInput("first"), protocol.NewInput("second"), protocol.NewInput("third")}
	if !reflect.DeepEqual(cmds, wantCmds) {
		t.Errorf("commands = %v, want %v", cmds, wantCmds)
	}
	wantBlocks := []state.Block{
		{Kind: state.KindUser, Text: "first"},
		{Kind: state.KindAssistant, Agent: "build", Text: "one"},
		{Kind: state.KindUser, Text: "second"},
		{Kind: state.KindAssistant, Agent: "build", Text: "two"},
		{Kind: state.KindUser, Text: "third"},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
	if m.Busy || m.Queue != nil {
		t.Errorf("Busy = %v, Queue = %v; want an idle session with an empty queue", m.Busy, m.Queue)
	}
}

func TestInterruptPausesTheQueueUntilEnter(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("first"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		typed("second"), press("enter"),
		press("esc"),
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "cancelled", Reason: ptr("interrupted by the user")},
	)
	if want := []any{protocol.NewInput("first"), protocol.NewInterrupt()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Busy || !m.QueuePaused || !reflect.DeepEqual(m.Queue, []string{"second"}) {
		t.Errorf("Busy = %v, QueuePaused = %v, Queue = %v; want the queue paused after the interrupt", m.Busy, m.QueuePaused, m.Queue)
	}

	m, cmds = feed(m, press("enter"))
	if want := []any{protocol.NewInput("second")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v after Enter, want %v", cmds, want)
	}
	if !m.Busy || m.QueuePaused || m.Queue != nil {
		t.Errorf("Busy = %v, QueuePaused = %v, Queue = %v; want the queued message running", m.Busy, m.QueuePaused, m.Queue)
	}
}

func TestFatalErrorClearsAPendingApproval(t *testing.T) {
	m, cmds := feed(testModel(),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.Confirm{Type: protocol.TypeConfirm, ID: "c1", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build"},
		&protocol.Fatal{Type: protocol.TypeError, Message: "model server refused the connection"},
	)
	if len(cmds) != 0 {
		t.Errorf("commands = %v, want none", cmds)
	}
	if !m.Quit || m.Error == "" {
		t.Errorf("Quit = %v, Error = %q; want the session ended with an error", m.Quit, m.Error)
	}
	if m.Confirm != nil {
		t.Errorf("Confirm = %+v after the fatal error, want it cleared", m.Confirm)
	}
}

func TestFatalErrorWithoutAMessageStillFailsTheSession(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Fatal{Type: protocol.TypeError})
	if !m.Quit || m.Error == "" {
		t.Errorf("Quit = %v, Error = %q; want the session ended with an error even when the backend gives none", m.Quit, m.Error)
	}
}

func TestBadAndUnknownLinesDoNotBreakATurn(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("hola"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.Unknown{Type: "future_event", Raw: []byte(`{"type":"future_event"}`)},
		&backend.BadLine{Line: []byte("oops"), Err: errors.New("invalid character 'o'")},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"},
	)
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	wantBlocks := []state.Block{
		{Kind: state.KindUser, Text: "hola"},
		{Kind: state.KindNotice, Level: "warn", Text: "the backend sent a line that is not JSON"},
	}
	if !reflect.DeepEqual(untimed(m.Blocks), wantBlocks) {
		t.Errorf("Blocks = %+v, want %+v", m.Blocks, wantBlocks)
	}
	if m.Busy || m.Quit || m.Error != "" {
		t.Errorf("Busy = %v, Quit = %v, Error = %q; want an idle session", m.Busy, m.Quit, m.Error)
	}
}

func TestOpenSubagentKeepsTheUIBusyAndCtrlCInterrupts(t *testing.T) {
	m, cmds := feed(testModel(),
		&protocol.SubagentStart{Type: protocol.TypeSubagentStart, ID: "s1", Parent: "main", Agent: "reviewer", Task: "review"},
	)
	if len(cmds) != 0 || m.Busy {
		t.Fatalf("Busy = %v, commands = %v: a subagent is not a turn", m.Busy, cmds)
	}
	if !m.Working() {
		t.Fatal("Working = false while a subagent runs, want the UI busy")
	}

	m, cmds = feed(m, press("ctrl+c"))
	if m.Quit {
		t.Error("Quit = true after Ctrl+C during a subagent, want the backend left running")
	}
	if want := []any{protocol.NewInterrupt()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}

	m, _ = feed(m, &protocol.SubagentEnd{Type: protocol.TypeSubagentEnd, ID: "s1", Agent: "reviewer", Status: "completed"})
	if m.Working() {
		t.Error("Working = true after the subagent ended, want idle")
	}
}

func TestAnsweredConfirmationFreesTheKeys(t *testing.T) {
	m, _ := feed(testModel(),
		&protocol.Panel{Type: protocol.TypePanel, Title: "/persona off", Kind: "text", Text: "persona: none"},
		&protocol.Confirm{Type: protocol.TypeConfirm, ID: "c1", Tool: "phycode", Category: "exec", Summary: "set the persona"},
	)
	m, cmds := feed(m, typed("y"))
	if want := []any{protocol.NewConfirmReply("c1", protocol.DecisionOnce)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if m.Panel.Open {
		t.Error("Panel stayed open after the answer, so it would keep the keys")
	}
	m, _ = feed(m, typed("x"))
	if m.Input != "x" {
		t.Errorf("Input = %q after typing x, want x", m.Input)
	}
}

func TestEnterSendsTheArgumentAsTyped(t *testing.T) {
	m := testModel()
	m.Commands = append(m.Commands, protocol.Command{Name: "/persona [gentleman|neutral|off]"})
	m.Input = "/persona off"
	m.Suggestions = []state.Suggestion{{Insert: "/persona ", Label: "/persona [gentleman|neutral|off]"}}
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewInput("/persona off")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Input != "" {
		t.Errorf("Input = %q after the line was sent, want it cleared", m.Input)
	}
}
