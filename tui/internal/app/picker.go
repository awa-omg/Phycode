package app

import (
	"slices"
	"strings"
	"unicode"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

const (
	busyText        = "wait for the turn to finish"
	flashNoThinking = "thinking control is not available for this model"
)

func openPicker(m state.Model) (state.Model, []any) {
	m.Picker = closePicker(m.Picker)
	m.Picker.Open = true
	if m.Busy {
		m.Picker.Loading = !m.Picker.Known
		return m, nil
	}
	m.Picker.Loading = true
	m.Picker.Pending = true
	return m, []any{protocol.NewListModels(false)}
}

func catalogueAfterTurn(m *state.Model, commands []any) []any {
	if m.Busy || !m.Picker.Open || m.Picker.Known && !m.Picker.Loading {
		return commands
	}
	m.Picker.Loading, m.Picker.Pending = true, true
	return append(commands, protocol.NewListModels(false))
}

func closePicker(p state.Picker) state.Picker {
	p.Open = false
	p.Loading = false
	p.Pending = false
	p.Applying = false
	p.Query = ""
	p.Selected = 0
	p.Level = ""
	p.Error = ""
	p.Keep = nil
	p.CustomOpen = false
	p.CustomID = ""
	p.CustomFor = ""
	p.Form = nil
	p.Connect = nil
	p.Detecting = false
	return p
}

func keepHighlight(p state.Picker) *protocol.ModelRef {
	switch {
	case p.Loading:
		return p.Keep
	case p.OnSpecial():
		return &protocol.ModelRef{}
	}
	if e, ok := p.Highlighted(); ok {
		return &protocol.ModelRef{Profile: e.Profile, Model: e.Model}
	}
	return nil
}

func rowOf(p state.Picker, ref *protocol.ModelRef) (int, bool) {
	switch {
	case ref == nil:
		return 0, false
	case *ref == protocol.ModelRef{}:
		return p.Items(), true
	}
	for i, e := range p.Visible() {
		if e.Profile == ref.Profile && (ref.Model == "" || e.Model == ref.Model) {
			return i, true
		}
	}
	return 0, false
}

func restoreHighlight(p *state.Picker) {
	keep, level := p.Keep, p.Level
	p.Keep = nil
	idx, found := rowOf(*p, keep)
	if !found {
		idx = 0
	}
	p.Selected = idx
	syncPicker(p)
	e, ok := p.Highlighted()
	if kept, offered := state.OfferedLevel(e.Thinking, level); found && ok && offered {
		p.Level = kept
	}
}

func syncPicker(p *state.Picker) {
	p.Selected = min(max(p.Selected, 0), p.Items())
	p.Level = ""
	if e, ok := p.Highlighted(); ok {
		p.Level = p.DefaultLevel(e)
	}
}

func handlePickerKey(m state.Model, msg tea.KeyMsg) (state.Model, []any) {
	name := keyName(msg)
	if name == "ctrl+c" {
		m.Picker = closePicker(m.Picker)
		return m, nil
	}
	switch {
	case m.Picker.Connect != nil:
		return handleConnectKey(m, msg, name)
	case m.Picker.Form != nil:
		return handleFormKey(m, msg, name)
	case m.Picker.CustomOpen:
		return handleCustomKey(m, msg, name)
	}
	if name == "" {
		return filterPicker(m, msg.Runes), nil
	}
	switch name {
	case "esc":
		return back(m)
	case "enter":
		return pickerEnter(m)
	case "ctrl+s":
		return applyModel(m, true)
	case "ctrl+f":
		return toggleFavorite(m)
	case "ctrl+r":
		return refreshModels(m)
	case "ctrl+x":
		return removeCustom(m)
	case "ctrl+a":
		return openConnect(m)
	case "ctrl+k":
		return openKey(m)
	case "ctrl+n":
		return openCustom(m), nil
	case "up":
		m = moveHighlight(m, -1)
	case "down":
		m = moveHighlight(m, 1)
	case "pgup":
		m = jumpHighlight(m, -pageSize(m))
	case "pgdown":
		m = jumpHighlight(m, pageSize(m))
	case "left":
		return changeLevel(m, -1)
	case "right":
		return changeLevel(m, 1)
	case "backspace", "ctrl+h":
		if m.Picker.Query != "" {
			m.Picker.Query = state.DropLastRune(m.Picker.Query)
			m = refilter(m)
		}
	}
	return m, nil
}

func back(m state.Model) (state.Model, []any) {
	switch {
	case m.Picker.Connect != nil:
		c := m.Picker.Connect
		if c.Stage == state.ConnectKey && !c.Direct {
			m = connectBack(m)
		} else {
			m.Picker.Connect = nil
			m.Picker.Pending = false
		}
		m.Picker.Error = ""
	case m.Picker.Form != nil:
		m.Picker.Form = nil
		m.Picker.Pending = false
		m.Picker.Error = ""
	case m.Picker.CustomOpen:
		m.Picker.CustomOpen = false
		m.Picker.CustomID = ""
		m.Picker.CustomFor = ""
		m.Picker.Error = ""
	default:
		m.Picker = closePicker(m.Picker)
	}
	return m, nil
}

func pickerEnter(m state.Model) (state.Model, []any) {
	switch {
	case m.Picker.Loading:
		return m, nil
	case m.Picker.OnSpecial():
		return openConnect(m)
	}
	return applyModel(m, false)
}

func openForm(m state.Model) (state.Model, []any) {
	m.Picker.Error = ""
	return setForm(m, state.NewProviderForm()), nil
}

func setForm(m state.Model, f state.ProviderForm) state.Model {
	m.Picker.Form = &f
	return m
}

func handleFormKey(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	f := *m.Picker.Form
	switch name {
	case "esc":
		return back(m)
	case "enter":
		return submitForm(m)
	case "tab":
		return setForm(m, f.Moved(1)), nil
	case "shift+tab":
		return setForm(m, f.Moved(-1)), nil
	case "left":
		return setForm(m, f.Cycled(-1)), nil
	case "right":
		return setForm(m, f.Cycled(1)), nil
	case "backspace", "ctrl+h":
		return setForm(m, f.Erased()), nil
	case "":
		return setForm(m, f.Typed(printable(msg.Runes))), nil
	}
	return m, nil
}

func submitForm(m state.Model) (state.Model, []any) {
	f := *m.Picker.Form
	if m.Busy {
		m.Picker.Error = busyText
		return m, nil
	}
	if field, problem := f.Problem(); problem != "" {
		f.Focus = field
		m.Picker.Error = problem
		return setForm(m, f), nil
	}
	f.Sent = strings.TrimSpace(f.Values[state.FieldName])
	key := strings.TrimSpace(f.Values[state.FieldAPIKey])
	f.Values[state.FieldAPIKey] = ""
	m.Picker.Error = ""
	m.Picker.Pending = true
	command := protocol.NewAddProvider(f.Sent, f.Values[state.FieldProtocol],
		state.NormalizeBaseURL(f.Values[state.FieldBaseURL]),
		strings.TrimSpace(f.Values[state.FieldAPIKeyEnv]),
		strings.TrimSpace(f.Values[state.FieldModel])).WithAPIKey(key)
	return setForm(m, f), []any{command}
}

func openCustom(m state.Model) state.Model {
	target := m.Picker.CustomTarget()
	if target == "" {
		m.Picker.Error = "add a provider first"
		return m
	}
	m.Picker.CustomOpen = true
	m.Picker.CustomID = ""
	m.Picker.CustomFor = target
	m.Picker.Error = ""
	return m
}

func handleCustomKey(m state.Model, msg tea.KeyMsg, name string) (state.Model, []any) {
	switch name {
	case "esc":
		return back(m)
	case "enter":
		return submitCustom(m)
	case "backspace", "ctrl+h":
		m.Picker.CustomID = state.DropLastRune(m.Picker.CustomID)
	case "":
		m.Picker.CustomID += printable(msg.Runes)
	}
	return m, nil
}

func submitCustom(m state.Model) (state.Model, []any) {
	id := strings.TrimSpace(m.Picker.CustomID)
	switch {
	case m.Busy:
		m.Picker.Error = busyText
		return m, nil
	case id == "":
		m.Picker.Error = "type a model id"
		return m, nil
	}
	profile := m.Picker.CustomFor
	m.Picker.CustomOpen = false
	m.Picker.CustomID = ""
	m.Picker.CustomFor = ""
	m.Picker.Error = ""
	m.Picker.Pending = true
	m.Picker.Applying = true
	m.Picker.Keep = &protocol.ModelRef{Profile: profile, Model: id}
	return m, []any{
		protocol.NewCustomModel(profile, id, protocol.CustomAdd),
		protocol.NewSetModel(profile, id, "", false),
	}
}

func removeCustom(m state.Model) (state.Model, []any) {
	e, ok := m.Picker.Highlighted()
	switch {
	case !ok:
		return m, nil
	case !e.Custom:
		m.Flash = "only a custom model id can be removed"
		return m, nil
	case m.Busy:
		m.Picker.Error = busyText
		return m, nil
	}
	m.Picker.Error = ""
	m.Picker.Pending = true
	m.Picker.Keep = nil
	return m, []any{protocol.NewCustomModel(e.Profile, e.Model, protocol.CustomRemove)}
}

func printable(runes []rune) string {
	var b strings.Builder
	for _, r := range runes {
		if unicode.IsPrint(r) {
			b.WriteRune(r)
		}
	}
	return b.String()
}

func filterPicker(m state.Model, runes []rune) state.Model {
	text := printable(runes)
	if text == "" {
		return m
	}
	m.Picker.Query += text
	return refilter(m)
}

func refilter(m state.Model) state.Model {
	m.Picker.Selected = 0
	m.Picker.Keep = nil
	syncPicker(&m.Picker)
	return m
}

func moveHighlight(m state.Model, step int) state.Model {
	if m.Picker.Loading {
		return m
	}
	n := m.Picker.Items() + 1
	cur := min(max(m.Picker.Selected, 0), n-1)
	return setHighlight(m, (cur+step+n)%n)
}

func jumpHighlight(m state.Model, step int) state.Model {
	if m.Picker.Loading {
		return m
	}
	n := m.Picker.Items() + 1
	cur := min(max(m.Picker.Selected, 0), n-1)
	return setHighlight(m, min(max(cur+step, 0), n-1))
}

func setHighlight(m state.Model, idx int) state.Model {
	if idx != m.Picker.Selected {
		m.Picker.Selected = idx
		syncPicker(&m.Picker)
	}
	return m
}

func changeLevel(m state.Model, step int) (state.Model, []any) {
	e, ok := m.Picker.Highlighted()
	if !ok {
		return m, nil
	}
	if len(e.Thinking) == 0 {
		m.Flash = flashNoThinking
		return m, nil
	}
	at := max(slices.Index(e.Thinking, m.Picker.Level), 0)
	m.Picker.Level = e.Thinking[min(max(at+step, 0), len(e.Thinking)-1)]
	return m, nil
}

func applyModel(m state.Model, save bool) (state.Model, []any) {
	e, ok := m.Picker.Highlighted()
	switch {
	case !ok:
		return m, nil
	case valueOf(e.Error) != "":
		m.Flash = "cannot use: " + valueOf(e.Error)
		return m, nil
	case m.Busy:
		m.Flash = busyText
		return m, nil
	}
	m.Picker.Error = ""
	m.Picker.Pending = true
	m.Picker.Applying = true
	return m, []any{protocol.NewSetModel(e.Profile, e.Model, m.Picker.Level, save)}
}

func toggleFavorite(m state.Model) (state.Model, []any) {
	e, ok := m.Picker.Highlighted()
	switch {
	case !ok:
		return m, nil
	case m.Busy:
		m.Flash = busyText
		return m, nil
	}
	m.Picker.Error = ""
	m.Picker.Pending = true
	m.Picker.Keep = keepHighlight(m.Picker)
	return m, []any{protocol.NewFavorite(e.Profile, e.Model, !m.Picker.IsFavorite(e.Profile, e.Model))}
}

func refreshModels(m state.Model) (state.Model, []any) {
	if m.Busy {
		m.Flash = busyText
		return m, nil
	}
	m.Picker.Keep = keepHighlight(m.Picker)
	m.Picker.Loading = true
	m.Picker.Pending = true
	m.Picker.Error = ""
	return m, []any{protocol.NewListModels(true)}
}

func modelsArrived(m state.Model, ev *protocol.Models) state.Model {
	p := &m.Picker
	p.Entries = ev.Entries
	p.Providers = ev.Providers
	p.Favorites = ev.Favorites
	p.Recents = ev.Recents
	p.Presets = ev.Presets
	p.Current = ev.Current
	p.Known = true
	p.Loading = false
	m = formAnswered(m, ev)
	m = connectAnswered(m, ev)
	p = &m.Picker
	waiting := p.Applying || (p.Form != nil && p.Form.Sent != "") || (p.Connect != nil && p.Connect.Pending)
	if !waiting {
		p.Pending = false
		p.Error = ""
	}
	if p.Open {
		restoreHighlight(p)
	}
	return m
}

func hasProvider(providers []protocol.Provider, name string) bool {
	return slices.ContainsFunc(providers, func(pr protocol.Provider) bool {
		return pr.Profile == name
	})
}

func modelChanged(m state.Model, ev *protocol.ModelChanged) state.Model {
	asked := m.Picker.Applying
	cacheResets := ev.Model != m.App.Model
	m.App.Profile = ev.Profile
	m.App.Model = ev.Model
	m.App.Thinking = ev.Thinking
	m.Effort.Level = protocol.CanonicalLevel(ev.Thinking)
	m.Picker = closePicker(m.Picker)
	m.Picker.Current = protocol.ModelRef{Profile: ev.Profile, Model: ev.Model, Thinking: ev.Thinking}
	if !asked {
		return m
	}

	level := ev.Thinking
	if level == "" {
		level = "–"
	}
	m.Flash = "thinking " + level + " · applies from the next turn"
	if cacheResets {
		m.Flash += " · the prompt cache resets"
	}
	return m
}

func formAnswered(m state.Model, ev *protocol.Models) state.Model {
	p := &m.Picker
	f := p.Form
	if f == nil || f.Sent == "" {
		return m
	}
	done, problem := state.ConnectFinished(ev.Providers, f.Sent)
	switch {
	case !done:
	case problem == "":
		p.Keep = &protocol.ModelRef{Profile: f.Sent}
		m.Flash = "connected " + f.Sent + connectedModels(ev.Providers, f.Sent)
		p.Form, p.Error = nil, ""
	case state.KeyRefused(problem):
		p.Connect = &state.Connect{
			Stage: state.ConnectKey, Direct: true, Error: problem,
			Target: state.ConnectTarget{Name: f.Sent, Protocol: f.Values[state.FieldProtocol], BaseURL: ev.ProviderEndpoint(f.Sent), Existing: true},
		}
		p.Form, p.Pending = nil, false
	default:
		p.Connect = &state.Connect{Selected: choiceOf(*p, f.Sent)}
		p.Form, p.Pending = nil, false
	}
	return m
}

func choiceOf(p state.Picker, name string) int {
	i := slices.IndexFunc(p.ConnectChoices(), func(row state.ConnectRow) bool {
		return row.Kind == state.ConnectProvider && row.Title == name
	})
	return max(i, 0)
}
