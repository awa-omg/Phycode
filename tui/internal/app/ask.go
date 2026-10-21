package app

import (
	"strings"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func handleAskKey(m state.Model, msg tea.KeyMsg) (state.Model, []any) {
	name := keyName(msg)
	ask := *m.Ask
	switch name {
	case "":
		if ask.FreeText {
			ask.Input += printable(msg.Runes)
		}
	case "backspace", "ctrl+h":
		ask.Input = state.DropLastRune(ask.Input)
	case "up":
		ask.Selected = max(ask.Selected-1, 0)
	case "down":
		ask.Selected = min(ask.Selected+1, max(len(ask.Options)-1, 0))
	case "enter":
		return answerAsk(m)
	case "esc":
		return skipAsk(m, false)
	case "ctrl+c":
		return skipAsk(m, true)
	}
	m.Ask = &ask
	return m, nil
}

func answerAsk(m state.Model) (state.Model, []any) {
	ask := *m.Ask
	answer := strings.TrimSpace(ask.Input)
	if option, ok := ask.Option(); answer == "" && ok {
		answer = option
	}
	if answer == "" {
		return m, nil
	}
	m.Ask = nil
	return m, []any{protocol.NewAskReply(ask.ID, &answer)}
}

func skipAsk(m state.Model, stop bool) (state.Model, []any) {
	cmds := []any{protocol.NewAskReply(m.Ask.ID, nil)}
	m.Ask = nil
	if stop {
		var halt []any
		m, halt = interrupt(m)
		cmds = append(cmds, halt...)
	}
	return m, cmds
}
