package app

import (
	"io"
	"reflect"
	"strings"
	"testing"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func execEvent(title string, argv ...string) *protocol.Exec {
	return &protocol.Exec{Type: protocol.TypeExec, Title: title, Argv: argv, Confirm: true}
}

type runner struct {
	cmd  tea.Cmd
	done chan<- programDone
}

func (r *runner) Init() tea.Cmd { return r.cmd }

func (r *runner) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	if d, ok := msg.(programDone); ok {
		r.done <- d
		return r, tea.Quit
	}
	return r, nil
}

func (r *runner) View() string { return "" }

func runHeadless(t *testing.T, cmd tea.Cmd) programDone {
	t.Helper()
	done := make(chan programDone, 1)
	p := tea.NewProgram(&runner{cmd: cmd, done: done}, tea.WithInput(strings.NewReader("")), tea.WithOutput(io.Discard), tea.WithoutSignalHandler())
	go func() { _, _ = p.Run() }()
	select {
	case msg := <-done:
		return msg
	case <-time.After(10 * time.Second):
		p.Kill()
		t.Fatal("the program did not report back")
		return programDone{}
	}
}

func TestAnExecEventAsksBeforeAnythingRuns(t *testing.T) {
	m, cmds := feed(testModel(), execEvent("gentle-ai", "gentle-ai", "tui"))
	if len(cmds) != 0 || m.Exec == nil || m.Exec.Title != "gentle-ai" || !reflect.DeepEqual(m.Exec.Argv, []string{"gentle-ai", "tui"}) {
		t.Errorf("commands = %v, Exec = %+v; want the program waiting for an answer", cmds, m.Exec)
	}
	if len(m.Blocks) != 0 {
		t.Errorf("Blocks = %+v, want nothing in the transcript before the answer", m.Blocks)
	}
}

func TestAnExecEventAsksEvenWithoutConfirm(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Exec{Type: protocol.TypeExec, Title: "true", Argv: []string{"true"}})
	if m.Exec == nil {
		t.Error("a program was run without the user's yes")
	}
}

func TestAnExecWithoutAProgramIsRefused(t *testing.T) {
	m, cmds := feed(testModel(), &protocol.Exec{Type: protocol.TypeExec, Title: "nothing"})
	if len(cmds) != 0 || m.Exec != nil || len(m.Blocks) != 1 || m.Blocks[0].Level != "error" {
		t.Errorf("commands = %v, Exec = %+v, Blocks = %+v; want a refusal in the transcript", cmds, m.Exec, m.Blocks)
	}
}

func TestAnExecWithoutATitleNamesTheProgram(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Exec{Type: protocol.TypeExec, Argv: []string{"true", "--version"}, Confirm: true})
	if m.Exec == nil || m.Exec.Title != "true" {
		t.Errorf("Exec = %+v, want the program's name as its title", m.Exec)
	}
}

func TestYRunsTheProgramAndSendsNothingToTheBackend(t *testing.T) {
	m, _ := feed(testModel(), execEvent("true", "true"))
	m, cmds := feed(m, typed("y"))
	if want := []any{runProgram{Title: "true", Argv: []string{"true"}}}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Exec != nil {
		t.Error("the request is still open after the answer")
	}
}

func TestTheAppTurnsTheAnswerIntoACommandAndSendsNothingToTheBackend(t *testing.T) {
	fb := newFakeBackend()
	next, _ := New(fb).Update(eventMsg{value: execEvent("true", "true")})
	next, cmd := next.(App).Update(typed("y"))
	if cmd == nil || len(fb.sent) != 0 || next.(App).state.Exec != nil {
		t.Errorf("command = %v, sent = %v, Exec = %+v; want a command that runs the program and nothing sent",
			cmd != nil, fb.sent, next.(App).state.Exec)
	}
}

func TestNAndEscLeaveTheProgramUnrunAndCtrlCDoesNotQuit(t *testing.T) {
	tests := []struct {
		name string
		key  tea.KeyMsg
	}{
		{"n", typed("n")},
		{"esc", press("esc")},
		{"ctrl+c", press("ctrl+c")},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m, cmds := feed(testModel(), execEvent("true", "true"), tt.key)
			if len(cmds) != 0 || m.Exec != nil || m.Quit || m.Flash != "did not run true" {
				t.Errorf("commands = %v, Exec = %+v, Quit = %v, Flash = %q; want the program left unrun and the session open",
					cmds, m.Exec, m.Quit, m.Flash)
			}
		})
	}
}

func TestOtherKeysDoNotAnswerTheRequest(t *testing.T) {
	m, cmds := feed(testModel(), execEvent("true", "true"),
		press("enter"), typed("a"), typed("x"), press("ctrl+d"), pasted("y"), press("up"))
	if len(cmds) != 0 || m.Exec == nil || m.Quit {
		t.Errorf("commands = %v, Exec = %+v, Quit = %v; want the request still waiting", cmds, m.Exec, m.Quit)
	}
}

func TestTheProgramRunsWithItsArgumentsAndNoShell(t *testing.T) {
	run := runProgram{Title: "printf", Argv: []string{"printf", "%s|%s", "a b", "$HOME"}}
	cmd := programProcess(run)
	if !reflect.DeepEqual(cmd.Args, run.Argv) {
		t.Fatalf("Args = %q, want the argv as it is, with no shell in front of it", cmd.Args)
	}
	out, err := cmd.Output()
	if err != nil || string(out) != "a b|$HOME" {
		t.Errorf("output = %q, err = %v; want the words as separate arguments, with $HOME not expanded", out, err)
	}
}

func TestTheExitCodeOfAProgramIsReportedInTheTranscript(t *testing.T) {
	tests := []struct {
		name  string
		argv  []string
		level string
		text  string
	}{
		{"exit 0", []string{"true"}, "info", "true exited with code 0"},
		{"exit 1", []string{"false"}, "warn", "false exited with code 1"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			done := runHeadless(t, programCommand(runProgram{Title: tt.argv[0], Argv: tt.argv}))
			m := programFinished(testModel(), done)
			if want := []state.Block{{Kind: state.KindNotice, Level: tt.level, Text: tt.text}}; !reflect.DeepEqual(m.Blocks, want) {
				t.Errorf("Blocks = %+v, want %+v", m.Blocks, want)
			}
		})
	}
}

func TestAProgramThatCannotStartIsAnError(t *testing.T) {
	done := runHeadless(t, programCommand(runProgram{Title: "phycode-no-such-program", Argv: []string{"phycode-no-such-program"}}))
	m := programFinished(testModel(), done)
	if len(m.Blocks) != 1 || m.Blocks[0].Level != "error" || !strings.HasPrefix(m.Blocks[0].Text, "could not run phycode-no-such-program: ") {
		t.Errorf("Blocks = %+v, want an error notice that says the program could not run", m.Blocks)
	}
}

func TestAProgramEndingUnderAPanelSaysItInTheStatusLine(t *testing.T) {
	m := programFinished(openedConfig(t), programDone{Title: "true"})
	if m.Flash != "true exited with code 0" {
		t.Errorf("Flash = %q, want the outcome in the status line while the panel covers the transcript", m.Flash)
	}
}
