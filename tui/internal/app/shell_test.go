package app

import (
	"reflect"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func TestABangTypedFirstEntersTheShellModeAndIsNotKept(t *testing.T) {
	m, _ := feed(testModel(), typed("!"))
	if !m.ShellMode || m.Input != "" || m.Cursor != 0 {
		t.Fatalf("ShellMode = %v, Input = %q; want the mode on and the bang not in the text", m.ShellMode, m.Input)
	}
	m, _ = feed(m, typed("ls -la"))
	if m.Input != "ls -la" || len(m.Suggestions) != 0 {
		t.Errorf("Input = %q, Suggestions = %v; want the command, with no menu", m.Input, m.Suggestions)
	}
	m, _ = feed(m, typed("/etc"))
	if len(m.Suggestions) != 0 || m.NoMatch != "" {
		t.Errorf("a path in a shell command opened the slash menu: %v %q", m.Suggestions, m.NoMatch)
	}
}

func TestABangPastedIntoAnEmptyInputEntersTheShellModeToo(t *testing.T) {
	m, _ := feed(testModel(), pasted("!git status"))
	if !m.ShellMode || m.Input != "git status" {
		t.Errorf("ShellMode = %v, Input = %q; want the mode on with the command", m.ShellMode, m.Input)
	}
}

func TestABangInTheMiddleOfTextIsText(t *testing.T) {
	m, _ := feed(testModel(), typed("hello"), typed("!"))
	if m.ShellMode || m.Input != "hello!" {
		t.Errorf("ShellMode = %v, Input = %q; want an ordinary exclamation mark", m.ShellMode, m.Input)
	}
}

func TestEnterSendsTheShellCommandWithItsBangAndLeavesTheModeWithoutShowingAMessage(t *testing.T) {
	m, cmds := feed(testModel(), typed("!"), typed("git status"), press("enter"))
	if want := []any{protocol.NewInput("!git status")}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if m.ShellMode || m.Input != "" || len(m.Blocks) != 0 || !m.Busy {
		t.Errorf("ShellMode = %v, Input = %q, Blocks = %v, Busy = %v; want the mode left, no message shown, and the turn running", m.ShellMode, m.Input, m.Blocks, m.Busy)
	}
	if want := []string{"!git status"}; !reflect.DeepEqual(m.History, want) {
		t.Errorf("History = %v, want %v", m.History, want)
	}
}

func TestASecondBangMakesTheCommandPrivate(t *testing.T) {
	_, cmds := feed(testModel(), typed("!"), typed("!echo hi"), press("enter"))
	if want := []any{protocol.NewInput("!!echo hi")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the bang typed in the shell mode is the second one", cmds, want)
	}
}

func TestBackspaceAtTheStartEscAndCtrlCLeaveTheShellMode(t *testing.T) {
	m, _ := feed(testModel(), typed("!"), press("backspace"))
	if m.ShellMode {
		t.Error("backspace on the empty shell line did not leave the mode")
	}
	m, _ = feed(testModel(), typed("!"), typed("ls"), press("backspace"), press("backspace"))
	if !m.ShellMode || m.Input != "" {
		t.Errorf("ShellMode = %v, Input = %q; want the text deleted first and the mode kept", m.ShellMode, m.Input)
	}
	m, _ = feed(m, press("backspace"))
	if m.ShellMode {
		t.Error("a second backspace on the empty line did not leave the mode")
	}
	m, _ = feed(testModel(), typed("!"), typed("ls"), press("esc"))
	if m.ShellMode || m.Input != "ls" {
		t.Errorf("ShellMode = %v, Input = %q; want esc to leave the mode and keep the text", m.ShellMode, m.Input)
	}
	m, _ = feed(testModel(), typed("!"), typed("ls"), press("ctrl+c"))
	if !m.ShellMode || m.Input != "" {
		t.Errorf("ShellMode = %v, Input = %q; want ctrl+c to clear the text first", m.ShellMode, m.Input)
	}
	m, _ = feed(m, press("ctrl+c"))
	if m.ShellMode || m.Quit {
		t.Errorf("ShellMode = %v, Quit = %v; want the second ctrl+c to leave the mode without quitting", m.ShellMode, m.Quit)
	}
}

func TestAnEmptyShellLineSentLeavesTheModeAndSendsNothing(t *testing.T) {
	m, cmds := feed(testModel(), typed("!"), press("enter"))
	if m.ShellMode || len(cmds) != 0 {
		t.Errorf("ShellMode = %v, commands = %v; want the mode left and nothing sent", m.ShellMode, cmds)
	}
}

func TestAShellCommandTypedDuringATurnWaitsInTheQueueWithItsBang(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, cmds := feed(m, typed("!"), typed("ls"), press("enter"))
	if len(cmds) != 0 || !reflect.DeepEqual(m.Queue, []string{"!ls"}) || m.ShellMode {
		t.Errorf("commands = %v, Queue = %v, ShellMode = %v; want !ls queued and the mode left", cmds, m.Queue, m.ShellMode)
	}
	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("!ls")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v after the turn, want %v", cmds, want)
	}
}

func TestUpRecallsAShellCommandIntoTheShellMode(t *testing.T) {
	m := testModel()
	m.History = []string{"hello", "!git log"}
	m, _ = feed(m, press("up"))
	if !m.ShellMode || m.Input != "git log" {
		t.Errorf("ShellMode = %v, Input = %q; want the command back in the shell mode", m.ShellMode, m.Input)
	}
	m, _ = feed(m, press("up"))
	if m.ShellMode || m.Input != "hello" {
		t.Errorf("ShellMode = %v, Input = %q; want an ordinary message to leave the mode", m.ShellMode, m.Input)
	}
	m, _ = feed(m, press("down"), press("down"))
	if m.ShellMode || m.Input != "" {
		t.Errorf("ShellMode = %v, Input = %q; want the empty line after the newest entry", m.ShellMode, m.Input)
	}
}

func TestAShellCommandBackFromTheQueueIsStillAShellCommand(t *testing.T) {
	m := testModel()
	m.Queue = []string{"!ls"}
	m, _ = feed(m, press("alt+up"))
	if m.Input != "!ls" {
		t.Fatalf("Input = %q, want the queued line back as it was", m.Input)
	}
	_, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewInput("!ls")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: a line that starts with a bang is a shell command either way", cmds, want)
	}
}

func TestTheOutputOfTheShellDoesNotAddAMessageBlockOfYours(t *testing.T) {
	m, _ := feed(testModel(), typed("!"), typed("ls"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.Output{Type: protocol.TypeOutput, Text: "$ ls\nfile.go"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if len(m.Blocks) != 1 || m.Blocks[0].Kind != state.KindOutput || m.Blocks[0].Text != "$ ls\nfile.go" || m.Busy {
		t.Errorf("Blocks = %+v, Busy = %v; want the output alone and the turn over", m.Blocks, m.Busy)
	}
}
