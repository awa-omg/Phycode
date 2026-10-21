package app

import (
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

const (
	refreshEvery = 15 * time.Minute
	refreshCheck = 30 * time.Second
)

var clock = time.Now

type refreshTick struct{}

func catalogueTimer(msg any) tea.Cmd {
	switch msg.(type) {
	case *protocol.Ready, refreshTick:
		return tea.Tick(refreshCheck, func(time.Time) tea.Msg { return refreshTick{} })
	}
	return nil
}

func catalogueCommands(m *state.Model, msg any, commands []any) []any {
	due := false
	switch msg.(type) {
	case *protocol.Ready:
		due = !m.Quit
	case refreshTick, *protocol.TurnEnd:
		due = !m.Working() && !m.Picker.Asked.IsZero() && clock().Sub(m.Picker.Asked) >= refreshEvery
	}
	if due {
		if m.Picker.Open {
			m.Picker.Keep = keepHighlight(m.Picker)
			m.Picker.Pending = true
		}
		commands = append(commands, protocol.NewListModels(true))
	}
	for _, c := range commands {
		if list, ok := c.(protocol.ListModels); ok && list.Refresh {
			m.Picker.Asked = clock()
		}
	}
	return commands
}
