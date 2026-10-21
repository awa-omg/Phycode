package app

import (
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func enqueue(m state.Model, text string) state.Model {
	m = remember(m, text)
	m = clearInput(m)
	m.Queue = append(m.Queue, text)
	m.Flash = "queued · alt+up edits the queue"
	return m
}

func resume(m state.Model) (state.Model, []any) {
	if m.Working() || len(m.Queue) == 0 {
		return m, nil
	}
	m = clearInput(m)
	m.QueuePaused = false
	m.Flash = ""
	m.Scroll = 0
	return sendQueued(m)
}

func sendQueued(m state.Model) (state.Model, []any) {
	var cmds []any
	for len(m.Queue) > 0 && !m.Working() {
		var text string
		text, m.Queue = m.Queue[0], m.Queue[1:]
		var sent []any
		m, sent = sendLine(m, text)
		cmds = append(cmds, sent...)
	}
	if len(m.Queue) == 0 {
		m.Queue = nil
	}
	return m, cmds
}

func restoreQueue(m state.Model) state.Model {
	lines := slices.Clone(m.Queue)
	if m.Input != "" {
		lines = append(lines, m.Input)
	}
	m.Input = strings.Join(lines, "\n")
	m.Cursor = utf8.RuneCountInString(m.Input)
	m.HistoryIndex = -1
	m.Queue = nil
	m.QueuePaused = false
	m.Flash = ""
	return refresh(m)
}

func sendLine(m state.Model, text string) (state.Model, []any) {
	text = resolveAlias(text)
	if next, cmds, ok := localCommand(m, text); ok {
		return next, cmds
	}
	if !strings.HasPrefix(text, "!") {
		m.Blocks = append(m.Blocks, state.Block{Kind: state.KindUser, Text: text})
	}
	if startsTurn(m.Commands, text) {
		m.Busy = true
		m.TurnStarted = time.Now()
	}
	return m, []any{protocol.NewInput(text)}
}

var backendCommands = []protocol.Command{
	{Name: "/help"}, {Name: "/agents"}, {Name: "/agent NAME"}, {Name: "/models"},
	{Name: "/skills"}, {Name: "/clear"}, {Name: "/exit"},
	{Name: "/effort [level]"}, {Name: "/context [max N|off]"}, {Name: "/config"},
	{Name: "/sessions"}, {Name: "/btw <pregunta>"}, {Name: "/deepresearch <pregunta>"},
}

func isCommand(commands []protocol.Command, line string) bool {
	if line == "/quit" || line == "/models" || strings.HasPrefix(line, "/agent ") {
		return true
	}
	_, ok := matchCommand(commands, line)
	return ok
}

func startsTurn(commands []protocol.Command, line string) bool {
	if !isCommand(commands, line) {
		return true
	}
	c, _ := matchCommand(commands, line)
	return c.Turn
}

func matchCommand(commands []protocol.Command, line string) (protocol.Command, bool) {
	if len(commands) == 0 {
		commands = backendCommands
	}
	for _, c := range commands {
		word, _, hasArgs := strings.Cut(c.Name, " ")
		if line == word || hasArgs && strings.HasPrefix(line, word+" ") {
			return c, true
		}
	}
	return protocol.Command{}, false
}
