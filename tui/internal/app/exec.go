package app

import (
	"errors"
	"fmt"
	"os/exec"
	"slices"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

type runProgram struct {
	Title string
	Argv  []string
}

type programDone struct {
	Title string
	Err   error
}

func execRequested(m state.Model, ev *protocol.Exec) state.Model {
	if len(ev.Argv) == 0 {
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindNotice, Level: "error", Text: "the backend asked to run nothing"})
		return m
	}
	title := ev.Title
	if title == "" {
		title = ev.Argv[0]
	}
	m.Exec = &state.Exec{Title: title, Argv: slices.Clone(ev.Argv)}
	return m
}

func handleExecKey(m state.Model, msg tea.KeyMsg) (state.Model, []any) {
	ex := *m.Exec
	switch confirmDecision(msg) {
	case protocol.DecisionOnce:
		m.Exec = nil
		return m, []any{runProgram{Title: ex.Title, Argv: ex.Argv}}
	case protocol.DecisionDeny:
		m.Exec = nil
		m.Flash = "did not run " + ex.Title
	}
	return m, nil
}

func programCommand(run runProgram) tea.Cmd {
	return tea.ExecProcess(programProcess(run), func(err error) tea.Msg {
		return programDone{Title: run.Title, Err: err}
	})
}

func programProcess(run runProgram) *exec.Cmd {
	return exec.Command(run.Argv[0], run.Argv[1:]...)
}

func programFinished(m state.Model, ev programDone) state.Model {
	level, text := programOutcome(ev)
	m.Blocks = append(m.Blocks, state.Block{Kind: state.KindNotice, Level: level, Text: text})
	if m.Panel.Open || m.Picker.Open {
		m.Flash = text
	}
	return m
}

func programOutcome(ev programDone) (string, string) {
	var exitErr *exec.ExitError
	switch {
	case ev.Err == nil:
		return "info", ev.Title + " exited with code 0"
	case !errors.As(ev.Err, &exitErr):
		return "error", "could not run " + ev.Title + ": " + ev.Err.Error()
	case exitErr.ExitCode() < 0:
		return "warn", ev.Title + " was stopped by a signal"
	}
	return "warn", fmt.Sprintf("%s exited with code %d", ev.Title, exitErr.ExitCode())
}
