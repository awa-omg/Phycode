package app

import (
	"slices"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func cycleAgent(m state.Model) (state.Model, []any) {
	if m.Working() {
		m.Flash = busyText
		return m, nil
	}
	var primary []string
	for _, a := range m.Agents {
		if (a.Mode == "primary" || a.Mode == "all") && a.Name != "" {
			primary = append(primary, a.Name)
		}
	}
	if len(primary) < 2 {
		m.Flash = "there is only one primary agent"
		return m, nil
	}
	next := primary[(slices.Index(primary, m.App.Agent)+1)%len(primary)]
	m.Flash = "agent " + next
	return m, []any{protocol.NewInput("/agent " + next)}
}

func cycleEffort(m state.Model) (state.Model, []any) {
	if m.Working() {
		m.Flash = busyText
		return m, nil
	}
	levels := effortChoices(m)
	if len(levels) == 0 {
		return m, nil
	}
	current := m.Effort.Level
	if current == "" {
		current = "none"
	}
	next := levels[(slices.Index(levels, current)+1)%len(levels)]
	m.Flash = "effort " + next
	return m, []any{protocol.NewInput("/effort " + next)}
}
