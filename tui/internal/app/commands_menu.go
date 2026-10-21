package app

import (
	"strings"
	"time"
	"unicode/utf8"

	"phycode/tui/internal/state"
	"phycode/tui/internal/view"
)

func openCommands(m state.Model) (state.Model, []any) {
	m.Suggestions = nil
	m.Selected = 0
	m.NoMatch = ""
	m.Panel = state.CommandsPanel(m.Commands)
	return m, nil
}

func chooseCommand(m state.Model) (state.Model, []any) {
	it, ok := m.Panel.Highlighted()
	if !ok || it.Key == "" {
		return m, nil
	}
	draft, cursor := m.Input, m.Cursor
	if strings.HasSuffix(it.Key, " ") || it.Key == "@" {
		if draft != "" && !strings.HasPrefix(draft, "/") {
			m.Flash = "clear the message first · ctrl+c"
			return m, nil
		}
		m.Panel = state.Panel{}
		m.Input = it.Key
		m.Cursor = utf8.RuneCountInString(it.Key)
		return refresh(m), nil
	}
	m.Panel = state.Panel{}
	m.Input = it.Key
	m, cmds := submit(m)
	m.Input, m.Cursor = draft, min(cursor, utf8.RuneCountInString(draft))
	return refresh(m), cmds
}

func openTasks(m state.Model) (state.Model, []any) {
	m.Suggestions = nil
	m.Selected = 0
	m.NoMatch = ""
	m.Panel = state.TasksPanel(m, time.Now())
	return m, nil
}

func showSubagent(m state.Model) (state.Model, []any) {
	id, ok := m.Panel.SubagentID()
	if !ok {
		return m, nil
	}
	m.Panel = state.Panel{}
	for i := range m.Blocks {
		if b := &m.Blocks[i]; b.Kind == state.KindSubagent && b.ID == id {
			b.Folded = false
		}
	}
	m.Scroll = clamp(view.BlockScroll(m, id), view.MaxScroll(m))
	return m, nil
}
