package app

import (
	"strings"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func openWeb(m state.Model) (state.Model, []any) {
	m.Panel = state.WebPanel()

	return m, []any{protocol.NewListSearchProviders()}
}

func webArrived(m state.Model, ev *protocol.SearchProviders) state.Model {
	if !m.Panel.Open || m.Panel.Kind != "web" || m.Panel.Web == nil {
		return m
	}
	w := *m.Panel.Web
	if w.Form != nil {
		f := *w.Form
		f.Backend = reselect(f.Backend, w.Presets, ev.Presets)
		w.Form = &f
	}
	w.Items, w.Presets, w.Legacy, w.Active = ev.Items, ev.Presets, ev.Legacy, ""
	if ev.Active != nil {
		w.Active = *ev.Active
	}
	if w.Pending && w.Form != nil {
		m.Flash = "web search saved"
		if w.Form.Edit == "" && ev.Active != nil {
			m.Flash = "web search on: " + *ev.Active + " · from the next turn"
		}
		w.Form = nil
	}
	w.Pending, w.Error, w.Confirm = false, "", ""
	w.Selected = clamp(w.Selected, len(w.Rows())-1)

	return withWeb(m, w)
}

func reselect(index int, old, presets []protocol.SearchPreset) int {
	if index >= 0 && index < len(old) {
		for i, p := range presets {
			if p.Backend == old[index].Backend {
				return i
			}
		}
	}

	return 0
}

func webRefused(m state.Model, message string) state.Model {
	w := *m.Panel.Web
	w.Pending = false
	if w.Form != nil {
		f := *w.Form
		f.Error = message
		w.Form = &f
	} else {
		w.Error = message
	}

	return withWeb(m, w)
}

func withWeb(m state.Model, w state.WebSearch) state.Model {
	m.Panel.Web = &w

	return m
}

func webKey(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	if m.Panel.Web == nil {
		m.Panel = state.Panel{}

		return m, nil
	}
	w := *m.Panel.Web
	if w.Form != nil {
		return webFormKey(m, w, msg, name)
	}
	if name != "ctrl+x" {
		w.Confirm = ""
	}
	switch name {
	case "":
		return m, nil
	case "esc", "ctrl+c":
		m.Panel = state.Panel{}

		return m, nil
	case "up":
		w.Selected = max(w.Selected-1, 0)
	case "down":
		w.Selected = min(w.Selected+1, len(w.Rows())-1)
	case "enter":
		return webChoose(m, w)
	case "ctrl+e":
		return webEdit(m, w)
	case "ctrl+x":
		return webRemove(m, w)
	}

	return withWeb(m, w), nil
}

func webChoose(m state.Model, w state.WebSearch) (state.Model, []any) {
	row, ok := w.Highlighted()
	if !ok {
		return withWeb(m, w), nil
	}
	switch row.Kind {
	case state.WebRowAdd:
		f := &state.WebForm{Focus: state.WebFieldName}
		if len(w.Presets) > 0 {
			f.URL = state.DefaultURL(w.Presets[0])
		}
		w.Form, w.Error = f, ""

		return withWeb(m, w), nil
	case state.WebRowOff:
		return sendSearch(m, w, nil)
	default:
		name := row.Provider.Name

		return sendSearch(m, w, &name)
	}
}

func sendSearch(m state.Model, w state.WebSearch, name *string) (state.Model, []any) {
	if m.Busy {
		w.Error = busyText

		return withWeb(m, w), nil
	}
	w.Error, w.Pending = "", true

	return withWeb(m, w), []any{protocol.NewSetSearchProvider(name)}
}

func webEdit(m state.Model, w state.WebSearch) (state.Model, []any) {
	row, ok := w.Highlighted()
	if !ok || row.Kind != state.WebRowProvider {
		w.Error = "only a provider can be edited"

		return withWeb(m, w), nil
	}
	url := ""
	if row.Provider.URL != nil {
		url = *row.Provider.URL
	}
	w.Form = &state.WebForm{Edit: row.Provider.Name, Name: row.Provider.Name, Backend: backendIndex(w, row.Provider.Backend), URL: url, Focus: state.WebFieldURL}
	w.Error = ""

	return withWeb(m, w), nil
}

func webRemove(m state.Model, w state.WebSearch) (state.Model, []any) {
	row, ok := w.Highlighted()
	if !ok || row.Kind != state.WebRowProvider {
		w.Error = "only a provider can be removed"

		return withWeb(m, w), nil
	}
	if m.Busy {
		w.Error = busyText

		return withWeb(m, w), nil
	}
	if w.Confirm != row.Provider.Name {
		w.Confirm, w.Error = row.Provider.Name, ""
		m.Flash = "press ctrl+x again to remove " + row.Provider.Name

		return withWeb(m, w), nil
	}
	w.Confirm, w.Error, w.Pending = "", "", true

	return withWeb(m, w), []any{protocol.NewRemoveSearchProvider(row.Provider.Name)}
}

func webFormKey(m state.Model, w state.WebSearch, msg tea.KeyMsg, name string) (state.Model, []any) {
	f := *w.Form
	f.Error = ""
	switch name {
	case "":
		addText(&f, w, printable(msg.Runes))
	case "backspace", "ctrl+h":
		dropText(&f, w)
	case "up", "shift+tab":
		f.Focus = max(f.Focus-1, 0)
	case "down", "tab":
		f.Focus = min(f.Focus+1, webFieldCount(w, f)-1)
	case "left", "right":
		if f.Focus == state.WebFieldBackend && f.Edit == "" && len(w.Presets) > 0 {
			step := map[string]int{"left": -1, "right": 1}[name]
			f.Backend = (f.Backend + step + len(w.Presets)) % len(w.Presets)
			f.URL = state.DefaultURL(w.Presets[f.Backend])
		}
	case "esc":
		w.Form = nil

		return withWeb(m, w), nil
	case "ctrl+c":
		m.Panel = state.Panel{}

		return m, nil
	case "enter":
		return webSubmit(m, w, f)
	}
	w.Form = &f

	return withWeb(m, w), nil
}

func webSubmit(m state.Model, w state.WebSearch, f state.WebForm) (state.Model, []any) {
	if m.Busy {
		f.Error = busyText
		w.Form = &f

		return withWeb(m, w), nil
	}
	if len(w.Presets) == 0 {
		f.Error = "the backend has no search presets yet"
		w.Form = &f

		return withWeb(m, w), nil
	}
	preset := w.Presets[f.Backend]
	url, key := strings.TrimSpace(f.URL), strings.TrimSpace(f.Key)
	var cmd any
	if f.Edit == "" {
		name := strings.TrimSpace(f.Name)
		switch {
		case name == "":
			f.Error = "type a name"
		case preset.KeyRequired && key == "":
			f.Error = "type the key"
		case preset.URLRequired && url == "":
			f.Error = "type the URL of the instance"
		}
		if f.Error != "" {
			w.Form = &f

			return withWeb(m, w), nil
		}
		cmd = protocol.NewAddSearchProvider(name, preset.Backend, url, key)
	} else {
		cmd = protocol.NewUpdateSearchProvider(f.Edit, url, key)
	}
	f.Key = ""
	w.Form, w.Pending, w.Error = &f, true, ""

	return withWeb(m, w), []any{cmd}
}

func addText(f *state.WebForm, w state.WebSearch, text string) {
	if text == "" {
		return
	}
	switch f.Focus {
	case state.WebFieldName:
		if f.Edit == "" {
			f.Name += text
		}
	case state.WebFieldURL:
		f.URL += text
	case state.WebFieldKey:
		if webKeyNeeded(w, *f) {
			f.Key += text
		}
	}
}

func dropText(f *state.WebForm, w state.WebSearch) {
	switch f.Focus {
	case state.WebFieldName:
		if f.Edit == "" {
			f.Name = state.DropLastRune(f.Name)
		}
	case state.WebFieldURL:
		f.URL = state.DropLastRune(f.URL)
	case state.WebFieldKey:
		if webKeyNeeded(w, *f) {
			f.Key = state.DropLastRune(f.Key)
		}
	}
}

func webKeyNeeded(w state.WebSearch, f state.WebForm) bool {
	if f.Backend < 0 || f.Backend >= len(w.Presets) {
		return false
	}
	preset, _ := w.PresetOf(w.Presets[f.Backend].Backend)

	return preset.KeyRequired
}

func webFieldCount(w state.WebSearch, f state.WebForm) int {
	if webKeyNeeded(w, f) {
		return state.WebFieldCount
	}

	return state.WebFieldCount - 1
}

func backendIndex(w state.WebSearch, backend string) int {
	for i, p := range w.Presets {
		if p.Backend == backend {
			return i
		}
	}

	return 0
}
