package app

import (
	"slices"
	"strconv"
	"strings"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

const (
	settingTheme  = "ui.theme"
	settingEditor = "ui.editor"
)

func configArrived(m state.Model, ev *protocol.Config) state.Model {
	for _, c := range ev.Items {
		switch c.Key {
		case settingTheme:
			m.Theme = c.Text()
		case settingEditor:
			m.Editor = c.Text()
		}
	}
	if m.Panel.Open && m.Panel.Kind == "config" {
		m.Panel = m.Panel.WithConfig(ev.Items)
	}
	return m
}

func sessionsArrived(m state.Model, ev *protocol.Sessions) state.Model {
	if m.Panel.Open && m.Panel.Kind == "sessions" {
		m.Panel = m.Panel.WithSessions(ev.Items)
	}
	i := slices.IndexFunc(ev.Items, func(s protocol.Session) bool { return s.Current })
	if i < 0 {
		return m
	}
	current := ev.Items[i]
	if m.Session.ID != "" && current.ID != m.Session.ID {
		m = sessionSwitched(m, current)
	}
	m.Session = current
	return m
}

func sessionSwitched(m state.Model, current protocol.Session) state.Model {
	m.Blocks = []state.Block{{Kind: state.KindNotice, Level: "info", Text: "session switched: " + state.SessionName(current)}}
	m.Scroll = 0
	m.Changes = nil
	m.Usage = nil
	m.Research = ""
	return m
}

func contextArrived(m state.Model, ev *protocol.Context) state.Model {
	m.Context = *ev
	m.Autocompacting = false
	if m.Panel.Open && m.Panel.Kind == "context" {
		m.Panel.Loading = false
	}
	return m
}

func effortArrived(m state.Model, ev *protocol.Effort) state.Model {
	m.Effort = *ev
	if m.Panel.Open && m.Panel.Kind == "effort" {
		m.Panel = m.Panel.WithEffort(*ev)
	}
	return m
}

func progressArrived(m state.Model, ev *protocol.Progress) state.Model {
	fresh := ev.ID != m.Research
	m.Research = ev.ID
	switch {
	case m.Panel.Open && m.Panel.Run == ev.ID:
		m.Panel = m.Panel.WithStep(ev)
	case fresh:
		m.Panel = state.ResearchPanel(ev)
	}
	return m
}

func researchFinished(m state.Model) state.Model {
	if m.Panel.Run != "" {
		m.Panel = state.Panel{}
	}
	return m
}

func panelEnter(m state.Model) (state.Model, []any) {
	switch m.Panel.Kind {
	case "config":
		return configEnter(m)
	case "sessions":
		return switchSession(m)
	case "effort":
		return setEffort(m)
	case "commands":
		return chooseCommand(m)
	case "tasks":
		return showSubagent(m)
	}
	if _, ok := m.Panel.Highlighted(); ok && m.Panel.Kind == "list" && !m.Panel.Detail {
		m.Panel.Detail = true
		m.Panel.Scroll = 0
	}
	return m, nil
}

func configEnter(m state.Model) (state.Model, []any) {
	it, ok := m.Panel.Highlighted()
	if !ok {
		return m, nil
	}
	c, ok := m.Panel.ConfigSetting(it.Key)
	switch {
	case !ok:
		return m, nil
	case m.Busy:
		m.Flash = busyText
		return m, nil
	}
	switch c.Kind {
	case protocol.ConfigBool:
		return m, []any{protocol.NewConfigSet(c.Key, !c.Bool())}
	case protocol.ConfigChoice:
		return m, []any{protocol.NewConfigSet(c.Key, nextChoice(c.Choices, c.Text()))}
	}
	m.Panel.Editing = true
	m.Panel.Input = c.Text()
	return m, nil
}

func nextChoice(choices []string, current string) string {
	if len(choices) == 0 {
		return current
	}
	return choices[(slices.Index(choices, current)+1)%len(choices)]
}

func editSettingKey(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	switch name {
	case "":
		m.Panel.Input += printable(msg.Runes)
	case "backspace", "ctrl+h":
		m.Panel.Input = state.DropLastRune(m.Panel.Input)
	case "esc":
		m.Panel.Editing = false
		m.Panel.Input = ""
	case "ctrl+c":
		m.Panel = state.Panel{}
	case "enter":
		return saveSetting(m)
	}
	return m, nil
}

func saveSetting(m state.Model) (state.Model, []any) {
	it, ok := m.Panel.Highlighted()
	if !ok {
		m.Panel.Editing = false
		return m, nil
	}
	if m.Busy {
		m.Flash = busyText
		return m, nil
	}
	value := strings.TrimSpace(m.Panel.Input)
	m.Panel.Editing = false
	m.Panel.Input = ""
	return m, []any{protocol.NewConfigSet(it.Key, value)}
}

func switchSession(m state.Model) (state.Model, []any) {
	it, ok := m.Panel.Highlighted()
	switch {
	case !ok:
		return m, nil
	case m.Busy:
		m.Flash = busyText
		return m, nil
	}
	return m, []any{protocol.NewSessionSwitch(it.Key)}
}

func newSession(m state.Model) (state.Model, []any) {
	if m.Busy {
		m.Flash = busyText
		return m, nil
	}
	return m, []any{protocol.NewSessionNew()}
}

func setEffort(m state.Model) (state.Model, []any) {
	it, ok := m.Panel.Highlighted()
	switch {
	case !ok:
		return m, nil
	case m.Busy:
		m.Flash = busyText
		return m, nil
	}
	return m, []any{protocol.NewSetEffort(it.Key)}
}

func contextKey(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	switch name {
	case "":
		m.Panel.Input += printable(msg.Runes)
	case "backspace", "ctrl+h":
		m.Panel.Input = state.DropLastRune(m.Panel.Input)
	case "up":
		m = movePanel(m, -1)
	case "down":
		m = movePanel(m, 1)
	case "pgup":
		m = movePanel(m, -pageSize(m))
	case "pgdown":
		m = movePanel(m, pageSize(m))
	case "esc", "ctrl+c":
		m.Panel = state.Panel{}
	case "enter":
		return applyContext(m)
	}
	return m, nil
}

const maxContextTokens = 1 << 40

func applyContext(m state.Model) (state.Model, []any) {
	if strings.TrimSpace(m.Panel.Input) == "" {
		return m, nil
	}
	limit, ok := parseMax(m.Panel.Input)
	if !ok {
		m.Flash = "type max N, Nk or off"
		return m, nil
	}
	if m.Busy {
		m.Flash = busyText
		return m, nil
	}
	m.Panel.Input = ""
	return m, []any{protocol.NewSetContext(limit)}
}

func parseMax(line string) (limit *int, ok bool) {
	rest, found := strings.CutPrefix(strings.ToLower(strings.TrimSpace(line)), "max ")
	if !found {
		return nil, false
	}
	rest = strings.TrimSpace(rest)
	if rest == "off" {
		return nil, true
	}
	digits, thousands := strings.CutSuffix(rest, "k")
	n, err := strconv.Atoi(digits)
	if err != nil || n < 1 || n > maxContextTokens || strings.Trim(digits, "0123456789") != "" {
		return nil, false
	}
	if thousands {
		n *= 1000
	}
	return &n, true
}

const compactTitle = "compact"

func compactKey(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	switch name {
	case "":
		if !m.Compacting {
			m.Panel.Input += printable(msg.Runes)
		}
	case "backspace", "ctrl+h":
		if !m.Compacting {
			m.Panel.Input = state.DropLastRune(m.Panel.Input)
		}
	case "enter":
		return sendCompact(m)
	case "esc", "ctrl+c":
		m.Panel = state.Panel{}
	}
	return m, nil
}

func sendCompact(m state.Model) (state.Model, []any) {
	switch {
	case m.Compacting:
		return m, nil
	case m.Busy:
		m.Flash = busyText
		return m, nil
	}
	text := strings.TrimSpace(m.Panel.Input)
	m.Compacting = true
	m.Panel = m.Panel.CompactSent()
	return m, []any{protocol.NewCompact(text)}
}

func compactionEnded(m state.Model) state.Model {
	m.Compacting = false
	if m.Panel.Kind == "compact" {
		m.Panel = m.Panel.CompactEnded()
	}
	return m
}

func panelArrived(m state.Model, ev *protocol.Panel) state.Model {
	if ev.Title == compactTitle {
		m.Compacting = false
	}
	m.Panel = state.NewPanel(ev)
	return m
}
