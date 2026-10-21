package view

import (
	"strings"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

var webLabels = [state.WebFieldCount]string{"name", "backend", "url", "api key"}

func (r *Renderer) webBody(p state.Panel, iw int) ([]string, int) {
	w := p.Web
	if w.Pending && w.Form == nil && len(w.Items) == 0 {
		return []string{r.styles.Dim.Render("loading…")}, -1
	}
	if w.Form != nil {
		return r.webFormBody(*w, iw)
	}
	var rows []string
	if w.Legacy != nil {
		rows = append(rows, r.styles.Dim.Render(ellipsize(oneLine("the older form of the config decides the search: "+w.Legacy.Backend), iw)), "")
	}
	at := 0
	for i, row := range w.Rows() {
		if i == w.Selected {
			at = len(rows)
		}
		rows = append(rows, r.webRow(*w, row, i == w.Selected, iw))
	}
	if w.Error != "" {
		rows = append(rows, "", r.styles.Warn.Render(ellipsize(oneLine(w.Error), iw)))
	}

	return rows, at
}

func (r *Renderer) webRow(w state.WebSearch, row state.WebRow, at bool, iw int) string {
	switch row.Kind {
	case state.WebRowOff:
		dot := r.webDot(w.Active == "" && w.Legacy == nil)

		return r.bar([]segment{dot, {text: " "}, {text: "Search off", style: r.styles.Heading}}, iw, at)
	case state.WebRowAdd:
		return r.bar([]segment{{text: "  "}, {text: "+ Add a search provider…", style: r.styles.Accent}}, iw, at)
	}
	p := row.Provider
	host := ""
	if p.URL != nil {
		host = webHost(*p.URL)
	} else if preset, ok := w.PresetOf(p.Backend); ok {
		host = webHost(state.DefaultURL(preset))
	}
	keyStyle := r.styles.Dim
	if p.Key == "missing" {
		keyStyle = r.styles.Warn
	}
	segs := []segment{
		r.webDot(p.Active),
		{text: " "},
		{text: padRight(oneLine(p.Name), 16), style: r.styles.Heading},
		{text: padRight(oneLine(p.Backend), 11), style: r.styles.Output},
		{text: ellipsize(host, 28), style: r.styles.Dim},
		{text: "  "},
		{text: webKeyText(p.Key), style: keyStyle},
	}

	return r.bar(segs, iw, at)
}

func (r *Renderer) webDot(on bool) segment {
	if on {
		return segment{text: "●", style: r.styles.Accent}
	}

	return segment{text: " "}
}

func (r *Renderer) webFormBody(w state.WebSearch, iw int) ([]string, int) {
	f := *w.Form
	title := "new search provider"
	if f.Edit != "" {
		title = "edit " + f.Edit
	}
	rows := []string{r.styles.Heading.Render(ellipsize(oneLine(title), iw)), ""}
	preset, _ := w.PresetOf(presetBackend(w, f))
	at := 0
	for i := 0; i < state.WebFieldCount; i++ {
		if i == state.WebFieldKey && !preset.KeyRequired {
			continue
		}
		if i == f.Focus {
			at = len(rows)
		}
		rows = append(rows, r.webField(w, f, i, iw))
	}
	if f.Error != "" {
		rows = append(rows, "", r.styles.Warn.Render(ellipsize(oneLine(f.Error), iw)))
	}

	return rows, at
}

func (r *Renderer) webField(w state.WebSearch, f state.WebForm, i, iw int) string {
	const labelW = 10
	at := i == f.Focus
	label := r.styles.Dim
	if at {
		label = r.styles.Emphasis
	}
	segs := []segment{r.marker(at), {text: " "}, {text: padRight(webLabels[i], labelW), style: label}}
	value := ""
	switch i {
	case state.WebFieldName:
		value = f.Name
	case state.WebFieldBackend:
		segs = append(segs, segment{text: "‹ " + oneLine(presetBackend(w, f)) + " ›", style: r.styles.Output})

		return r.bar(segs, iw, at)
	case state.WebFieldURL:
		value = f.URL
	case state.WebFieldKey:
		value = maskKey(f.Key)
	}
	switch {
	case at:
		room := max(iw-labelW-3, 0)
		segs = append(segs, segment{text: tailCut(oneLine(value), room), style: r.styles.Output}, segment{text: "█", style: r.styles.Emphasis})
	case value == "" && i == state.WebFieldURL:
		segs = append(segs, segment{text: "default " + ellipsize(oneLine(state.DefaultURL(webPreset(w, f))), max(iw-labelW-12, 8)), style: r.styles.Dim})
	default:
		segs = append(segs, segment{text: oneLine(value), style: r.styles.Output})
	}

	return r.bar(segs, iw, at)
}

func webHint(w *state.WebSearch) string {
	if w.Form != nil {
		return "tab next field · ←→ backend · enter save · esc back"
	}

	return "enter choose · ctrl+e edit · ctrl+x twice remove · esc close"
}

func presetBackend(w state.WebSearch, f state.WebForm) string {
	if f.Backend >= 0 && f.Backend < len(w.Presets) {
		return w.Presets[f.Backend].Backend
	}

	return ""
}

func webPreset(w state.WebSearch, f state.WebForm) protocol.SearchPreset {
	if f.Backend >= 0 && f.Backend < len(w.Presets) {
		return w.Presets[f.Backend]
	}

	return protocol.SearchPreset{}
}

func webKeyText(key string) string {
	switch key {
	case "stored":
		return "key stored"
	case "file":
		return "key from file"
	case "none":
		return "no key needed"
	}

	return "key missing"
}

func webHost(url string) string {
	rest := url
	if i := strings.Index(rest, "://"); i >= 0 {
		rest = rest[i+3:]
	}
	if i := strings.IndexAny(rest, "/?#"); i >= 0 {
		rest = rest[:i]
	}

	return oneLine(rest)
}
