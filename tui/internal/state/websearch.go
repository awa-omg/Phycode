package state

import "phycode/tui/internal/protocol"

const (
	WebRowOff = iota
	WebRowProvider
	WebRowAdd
)

const (
	WebFieldName = iota
	WebFieldBackend
	WebFieldURL
	WebFieldKey
	WebFieldCount
)

type WebSearch struct {
	Active   string
	Legacy   *protocol.SearchLegacy
	Items    []protocol.SearchProvider
	Presets  []protocol.SearchPreset
	Selected int
	Confirm  string
	Form     *WebForm
	Pending  bool
	Error    string
}

type WebForm struct {
	Edit    string
	Name    string
	Backend int
	URL     string
	Key     string
	Focus   int
	Error   string
}

type WebRow struct {
	Kind     int
	Provider protocol.SearchProvider
}

func WebPanel() Panel {
	return Panel{Open: true, Title: "Web search", Kind: "web", Web: &WebSearch{Pending: true}}
}

func (w WebSearch) Rows() []WebRow {
	rows := []WebRow{{Kind: WebRowOff}}
	for _, p := range w.Items {
		rows = append(rows, WebRow{Kind: WebRowProvider, Provider: p})
	}

	return append(rows, WebRow{Kind: WebRowAdd})
}

func (w WebSearch) Highlighted() (WebRow, bool) {
	rows := w.Rows()
	if w.Selected < 0 || w.Selected >= len(rows) {
		return WebRow{}, false
	}

	return rows[w.Selected], true
}

func (w WebSearch) PresetOf(backend string) (protocol.SearchPreset, bool) {
	for _, p := range w.Presets {
		if p.Backend == backend {
			return p, true
		}
	}

	return protocol.SearchPreset{}, false
}

func (w WebSearch) Provider(name string) (protocol.SearchProvider, bool) {
	for _, p := range w.Items {
		if p.Name == name {
			return p, true
		}
	}

	return protocol.SearchProvider{}, false
}

func DefaultURL(p protocol.SearchPreset) string {
	if p.URL == nil {
		return ""
	}

	return *p.URL
}
