package app

import (
	"strings"
	"unicode"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/state"
	"phycode/tui/internal/view"
)

func handlePanelKey(m state.Model, msg tea.KeyMsg) (state.Model, []any) {
	name := keyName(msg)
	if m.Panel.Kind == "themes" {
		if next, cmds, done := themesKey(m, name); done {
			return next, cmds
		}
	}
	switch {
	case m.Panel.Kind == "web":
		return webKey(m, msg, name)
	case m.Panel.Kind == "context":
		return contextKey(m, msg, name)
	case m.Panel.Kind == "compact":
		return compactKey(m, msg, name)
	case m.Panel.Editing:
		return editSettingKey(m, msg, name)
	case name == "":
		if m.Panel.IsList() && !m.Panel.Detail {
			m = filterPanel(m, msg.Runes)
		}
		return m, nil
	}
	switch name {
	case "ctrl+c":
		m.Panel = state.Panel{}
	case "esc":
		if m.Panel.Detail {
			m.Panel.Detail = false
			m.Panel.Scroll = 0
		} else {
			m.Panel = state.Panel{}
		}
	case "enter":
		return panelEnter(m)
	case "ctrl+n":
		if m.Panel.Kind == "sessions" {
			return newSession(m)
		}
	case "up":
		m = movePanel(m, -1)
	case "down":
		m = movePanel(m, 1)
	case "left", "right":
		if m.Panel.Kind == "effort" {
			m = movePanel(m, map[string]int{"left": -1, "right": 1}[name])
		}
	case "pgup":
		m = movePanel(m, -pageSize(m))
	case "pgdown":
		m = movePanel(m, pageSize(m))
	case "backspace", "ctrl+h":
		if q := []rune(m.Panel.Query); m.Panel.IsList() && !m.Panel.Detail && len(q) > 0 {
			m.Panel.Query = string(q[:len(q)-1])
			m.Panel.Selected = 0
		}
	}
	return m, nil
}

func filterPanel(m state.Model, runes []rune) state.Model {
	var b strings.Builder
	for _, r := range runes {
		if unicode.IsPrint(r) {
			b.WriteRune(r)
		}
	}
	if b.Len() > 0 {
		m.Panel.Query += b.String()
		m.Panel.Selected = 0
	}
	return m
}

func movePanel(m state.Model, step int) state.Model {
	if m.Panel.IsList() && !m.Panel.Detail {
		m.Panel.Selected = clamp(m.Panel.Selected+step, len(m.Panel.Visible())-1)
		return m
	}
	m.Panel.Scroll = clamp(m.Panel.Scroll+step, view.PanelMaxScroll(m))
	return m
}
