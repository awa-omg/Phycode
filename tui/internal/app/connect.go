package app

import (
	"strconv"
	"strings"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func openConnect(m state.Model) (state.Model, []any) {
	if !m.Picker.Open {
		m.Picker = closePicker(m.Picker)
		m.Picker.Open = true
	}
	m.Picker.Form = nil
	m.Picker.Error = ""
	m.Picker.Connect = &state.Connect{}
	if m.Busy {
		return m, nil
	}
	var cmds []any
	if !m.Picker.Known {
		m.Picker.Loading = true
		m.Picker.Pending = true
		cmds = append(cmds, protocol.NewListModels(false))
	}
	m.Picker.Detecting = true
	return m, append(cmds, protocol.NewDetectEndpoints())
}

func openKey(m state.Model) (state.Model, []any) {
	profile := m.Picker.CustomTarget()
	pr, ok := m.Picker.Provider(profile)
	if profile == "" || !ok {
		m.Picker.Error = "add a provider first"
		return m, nil
	}
	m.Picker.Form = nil
	m.Picker.Error = ""
	m.Picker.Connect = &state.Connect{
		Stage:  state.ConnectKey,
		Direct: true,
		Target: state.ConnectTarget{Name: pr.Profile, Protocol: pr.Protocol, BaseURL: pr.Endpoint, Existing: true},
	}
	return m, nil
}

func ownConnect(m state.Model) (state.Model, *state.Connect) {
	c := *m.Picker.Connect
	m.Picker.Connect = &c
	return m, &c
}

func handleConnectKey(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	m, c := ownConnect(m)
	if c.Stage == state.ConnectKey {
		return handleKeyStage(m, msg, name)
	}
	if c.Pending && name != "esc" {
		return m, nil
	}
	if name != "ctrl+x" {
		c.Confirm = ""
	}
	switch name {
	case "":
		c.Query += printable(msg.Runes)
		c.Selected = 0
	case "esc":
		return back(m)
	case "enter":
		return chooseProvider(m)
	case "up":
		c.Selected = max(c.Selected-1, 0)
	case "down":
		c.Selected = min(c.Selected+1, max(len(m.Picker.ConnectChoices())-1, 0))
	case "pgup":
		c.Selected = max(c.Selected-pageSize(m), 0)
	case "pgdown":
		c.Selected = min(c.Selected+pageSize(m), max(len(m.Picker.ConnectChoices())-1, 0))
	case "backspace", "ctrl+h":
		c.Query = state.DropLastRune(c.Query)
		c.Selected = 0
	case "ctrl+u":
		c.Query, c.Selected = "", 0
	case "ctrl+r":
		if row, ok := m.Picker.ConnectHighlighted(); ok && row.Target.Existing {
			c.Target = row.Target
			return refreshProvider(m)
		}
		return redetect(m)
	case "ctrl+x":
		return removeFromList(m)
	}
	c.Selected = min(c.Selected, max(len(m.Picker.ConnectChoices())-1, 0))
	return m, nil
}

func redetect(m state.Model) (state.Model, []any) {
	if m.Busy {
		m.Picker.Error = busyText
		return m, nil
	}
	m.Picker.Error = ""
	m.Picker.Detecting = true
	return m, []any{protocol.NewDetectEndpoints()}
}

func chooseProvider(m state.Model) (state.Model, []any) {
	row, ok := m.Picker.ConnectHighlighted()
	if !ok {
		return m, nil
	}
	if row.Kind == state.ConnectCustom {
		m.Picker.Connect = nil
		return openForm(m)
	}
	c := m.Picker.Connect
	c.Target, c.Key, c.Error, c.Direct = row.Target, "", "", false
	if c.Target.Existing {
		return refreshProvider(m)
	}
	c.Stage = state.ConnectKey
	if c.Target.NoKey || c.Target.KeyInEnv {
		return sendKey(m, "")
	}
	return m, nil
}

func handleKeyStage(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	c := m.Picker.Connect
	if name != "ctrl+x" {
		c.Confirm = ""
	}
	if c.Pending && name != "esc" {
		return m, nil
	}
	switch name {
	case "":
		c.Key += printable(msg.Runes)
		c.Error = ""
	case "esc":
		return back(m)
	case "enter":
		return submitKey(m)
	case "backspace", "ctrl+h":
		c.Key = state.DropLastRune(c.Key)
	case "ctrl+u":
		c.Key = ""
	case "ctrl+r":
		if c.Target.Existing {
			return refreshProvider(m)
		}
	case "ctrl+x":
		if c.Target.Existing {
			return removeProvider(m, c.Target.Name)
		}
	}
	return m, nil
}

func submitKey(m state.Model) (state.Model, []any) {
	c := m.Picker.Connect
	key := strings.TrimSpace(c.Key)
	if key == "" && (c.Target.Existing || !c.Target.KeyInEnv) && !c.Target.NoKey {
		c.Error = "type the key"
		return m, nil
	}
	return sendKey(m, key)
}

func sendKey(m state.Model, key string) (state.Model, []any) {
	c := m.Picker.Connect
	if m.Busy {
		c.Error = busyText
		return m, nil
	}
	t := c.Target
	var cmd any
	switch {
	case t.Existing && key == "":
		return refreshProvider(m)
	case t.Existing:
		cmd = protocol.NewSetProviderKey(t.Name, key)
	case key == "" && t.KeyInEnv:
		cmd = protocol.NewAddProvider(t.Name, t.Protocol, t.BaseURL, t.EnvVar, "")
	default:
		cmd = protocol.NewAddProvider(t.Name, t.Protocol, t.BaseURL, "", "").WithAPIKey(key)
	}
	c.Key, c.Error, c.Pending = "", "", true
	m.Picker.Pending = true
	return m, []any{cmd}
}

func refreshProvider(m state.Model) (state.Model, []any) {
	c := m.Picker.Connect
	if m.Busy {
		c.Error = busyText
		return m, nil
	}
	c.Key, c.Error, c.Pending = "", "", true
	m.Picker.Pending = true
	return m, []any{protocol.NewListModels(true)}
}

func removeFromList(m state.Model) (state.Model, []any) {
	row, ok := m.Picker.ConnectHighlighted()
	if !ok || row.Kind != state.ConnectProvider || !row.Target.Existing {
		m.Picker.Error = "only a provider of the configuration can be removed"
		return m, nil
	}
	m.Picker.Error = ""
	return removeProvider(m, row.Target.Name)
}

func removeProvider(m state.Model, name string) (state.Model, []any) {
	c := m.Picker.Connect
	if m.Busy {
		c.Error = busyText
		return m, nil
	}
	if c.Confirm != name {
		c.Confirm = name
		return m, nil
	}
	c.Confirm, c.Removing = "", name
	c.Pending = true
	m.Picker.Pending = true
	return m, []any{protocol.NewRemoveProvider(name)}
}

func connectAnswered(m state.Model, ev *protocol.Models) state.Model {
	if m.Picker.Connect == nil || !m.Picker.Connect.Pending {
		return m
	}
	m, c := ownConnect(m)
	p := &m.Picker
	if c.Removing != "" {
		if !hasProvider(ev.Providers, c.Removing) {
			m.Flash = "removed " + c.Removing
			c.Removing = ""
			m = connectBack(m)
		}
		return m
	}
	done, problem := state.ConnectFinished(ev.Providers, c.Target.Name)
	switch {
	case !done:
	case problem == "":
		p.Keep = &protocol.ModelRef{Profile: c.Target.Name}
		m.Flash = "connected " + c.Target.Name + connectedModels(ev.Providers, c.Target.Name)
		p.Connect, p.Pending, p.Error = nil, false, ""
	case state.KeyRefused(problem):
		c.Stage, c.Pending, c.Error, c.Target.Existing = state.ConnectKey, false, problem, true
	default:
		m = connectBack(m)
	}
	return m
}

func connectBack(m state.Model) state.Model {
	c := m.Picker.Connect
	m.Picker.Connect = &state.Connect{Query: c.Query, Selected: c.Selected}
	m.Picker.Pending = false
	return m
}

func connectedModels(providers []protocol.Provider, name string) string {
	for _, pr := range providers {
		if pr.Profile == name && pr.Count > 0 {
			if pr.Count == 1 {
				return " · 1 model"
			}
			return " · " + strconv.Itoa(pr.Count) + " models"
		}
	}
	return ""
}
