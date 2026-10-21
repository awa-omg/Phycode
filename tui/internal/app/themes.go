package app

import (
	"slices"
	"strings"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

var themeNotes = map[string]string{
	"phycode": "dark, the default", "phycode-light": "light", "tokyo-night": "dark, blue and violet", "catppuccin": "dark, soft pastels",
	"gruvbox": "dark, warm and earthy", "rose-pine": "dark, muted rose", "nord": "dark, cold blues", "solarized-dark": "dark, low contrast",
	"paper": "light, warm paper", "mono": "greys only, for any terminal",
}

func themesPanel(m state.Model) state.Model {
	current := m.Theme
	if current == "" {
		current = theme.Names()[0]
	}
	var items []protocol.PanelItem
	for _, name := range theme.Names() {
		it := protocol.PanelItem{Title: name, Key: name, Detail: themeNotes[name]}
		if name == current {
			it.Badge, it.Status = "in use", "ok"
		}
		items = append(items, it)
	}
	m.Panel = state.Panel{Open: true, Title: "Theme", Kind: "themes", Hint: themesHint, Items: items, Restore: m.Theme}
	m.Panel.Selected = max(slices.Index(theme.Names(), current), 0)
	return m
}

const themesHint = "↑↓ preview · enter keep · esc cancel"

func previewTheme(m state.Model) state.Model {
	if it, ok := m.Panel.Highlighted(); ok {
		m.Theme = it.Key
	}
	return m
}

func themesKey(m state.Model, name string) (state.Model, []any, bool) {
	switch name {
	case "esc", "ctrl+c":
		m.Theme = m.Panel.Restore
		m.Panel = state.Panel{}
		return m, nil, true
	case "enter":
		if it, ok := m.Panel.Highlighted(); ok {
			m.Theme, m.Panel = it.Key, state.Panel{}
			return m, []any{protocol.NewConfigSet(settingTheme, it.Key)}, true
		}
		return m, nil, true
	}
	return m, nil, false
}

func setTheme(m state.Model, name string) (state.Model, []any) {
	name = strings.ToLower(strings.TrimSpace(name))
	if !slices.Contains(theme.Names(), name) {
		m.Flash = "no theme " + name + ": " + strings.Join(theme.Names(), ", ")
		return m, nil
	}
	m.Theme = name
	m.Flash = "theme " + name
	return m, []any{protocol.NewConfigSet(settingTheme, name)}
}
