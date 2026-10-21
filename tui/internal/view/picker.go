package view

import (
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

const (
	hintsCustom = "enter use · esc cancel · type a model id"
	hintsForm   = "enter add · esc cancel · tab next field · shift+tab previous"
)

var (
	hintsListHead = []string{"↑↓ move", "enter use", "esc close", "←→ thinking", "ctrl+f favourite", "ctrl+s save default", "ctrl+a connect", "ctrl+k key", "ctrl+n custom id", "ctrl+r refresh"}
	hintsRemove   = "ctrl+x remove custom"
)

const (
	rowHead  = 4
	chipGap  = 2
	minName  = 14
	addLabel = "+ Connect a provider…"

	maxNameWithProvider = 28
)

var (
	formLabels = [state.FieldCount]string{
		state.FieldName:      "name",
		state.FieldProtocol:  "api",
		state.FieldBaseURL:   "base_url",
		state.FieldAPIKey:    "api_key",
		state.FieldAPIKeyEnv: "api_key_env",
		state.FieldModel:     "model",
	}
	formHelp = [state.FieldCount]string{
		state.FieldName:      "how the provider is named here: lowercase letters, digits, - and _",
		state.FieldProtocol:  "←→ the API the server speaks: OpenAI-compatible, Anthropic-compatible, Ollama or OpenAI Responses",
		state.FieldBaseURL:   "where it listens, e.g. https://api.example.com or http://127.0.0.1:9931; a trailing /v1 is dropped",
		state.FieldAPIKey:    "the key itself, saved encrypted and never shown; a local server needs none",
		state.FieldAPIKeyEnv: "the NAME of an environment variable that holds the key, never the key",
		state.FieldModel:     "optional: leave it empty and choose one from the list the server gives",
	}
	protocolLabels = map[string]string{
		"openai": "OpenAI-compatible", "anthropic": "Anthropic-compatible", "ollama": "Ollama", "openai-responses": "OpenAI Responses",
	}
	formSections = map[int]string{state.FieldName: "Endpoint", state.FieldAPIKeyEnv: "Optional"}
)

func (r *Renderer) pickerLines(p state.Picker, busy bool, inner, rows int, now time.Time, frame int) []string {
	if p.Connect != nil {
		return r.connectLines(p, inner, rows, frame)
	}
	head := []string{r.pickerHead(p, busy, inner, rows, now)}
	if p.Error != "" && rows >= 6 {
		head = append(head, r.styles.Fail.Render(ellipsize(oneLine(p.Error), inner)))
	}
	foot := r.hints(pickerHints(p), inner)
	switch {
	case rows < 12:
		foot = foot[:1]
	case rows < 18 && len(foot) > 2:
		foot = foot[:2]
	}

	room := rows - len(head)
	foot = foot[:min(len(foot), max(room-1, 0))]
	room -= len(foot)
	gap := 0
	if room >= 6 {
		gap = 1
	}
	room -= 2 * gap

	var body []string
	var at int
	switch {
	case p.Form != nil:
		body, at = r.formBody(p, inner)
	case p.Loading && busy:
		body, at = []string{r.styles.Dim.Render("models load when the turn is over")}, -1
	case p.Loading:
		body, at = []string{r.styles.Dim.Render("loading models…")}, -1
	default:
		body, at = r.pickerList(p, inner, now)
	}
	n := min(len(body), max(room, 0))
	body = r.scrollView(body, at, n, inner)[:n]

	out := append([]string(nil), head...)
	out = append(out, make([]string, gap)...)
	out = append(out, body...)
	out = append(out, make([]string, gap)...)
	out = append(out, foot...)
	for i := range out {
		out[i] = fit(out[i], inner)
	}
	return out
}

func (r *Renderer) pickerHead(p state.Picker, busy bool, inner, rows int, now time.Time) string {
	title := "Models"
	if p.Form != nil {
		title = "Custom endpoint"
	}
	left := []segment{{text: title, style: r.styles.Heading}}
	meta := pickerMeta(p, busy, now)
	if lipgloss.Width(title)+2+lipgloss.Width(meta) > inner {
		meta = ""
	}
	metaW := lipgloss.Width(meta)
	if p.Form == nil && (rows >= 5 || p.Query != "" || p.CustomOpen) {
		input, hint := p.Query, "search models or providers"
		if p.CustomOpen {
			input, hint = p.CustomID, "model id for "+profileLabel(p.CustomFor)
		}
		room := inner - lipgloss.Width(title) - 2 - metaW
		if metaW > 0 {
			room -= 2
		}
		if room >= 8 {
			left = append(left, segment{text: "  "})
			left = append(left, cutSegments(r.typeSegments(input, hint, room), room)...)
		}
	}
	if metaW > 0 {
		left = append(left, segment{text: spaces(inner - segmentsWidth(left) - metaW)}, segment{text: meta, style: r.styles.Dim})
	}
	return fit(renderSegments(left), inner)
}

func pickerMeta(p state.Picker, busy bool, now time.Time) string {
	switch {
	case p.Form != nil:
		return ""
	case p.Applying:
		return "applying…"
	case p.Loading && busy:
		return "waiting for the turn…"
	case p.Loading:
		return "loading…"
	}
	t, ok := p.Updated()
	if !ok {
		return ""
	}
	meta := "updated " + relativeAge(now.Sub(t))
	for _, pr := range p.Providers {
		if pr.Refreshing {
			return meta + " · refreshing…"
		}
	}
	return meta
}

func pickerHints(p state.Picker) string {
	switch {
	case p.Form != nil:
		return hintsForm
	case p.CustomOpen:
		return hintsCustom
	}
	hints := append([]string(nil), hintsListHead...)
	if e, ok := p.Highlighted(); ok && e.Custom {
		hints = append(hints, hintsRemove)
	}
	return strings.Join(hints, " · ")
}

type chipCols struct {
	loaded, ctx, tools, think int
}

func pickerChips(p state.Picker, visible []protocol.ModelEntry) chipCols {
	var c chipCols
	for _, e := range visible {
		if e.Loaded != nil && *e.Loaded {
			c.loaded = lipgloss.Width("loaded")
		}
		if label := contextLabel(e.ContextWindow); label != "" {
			c.ctx = max(c.ctx, lipgloss.Width(label))
		}
		if label := toolsLabel(e.Tools); label != "" {
			c.tools = max(c.tools, lipgloss.Width(label))
		}
		for _, level := range e.Thinking {
			c.think = max(c.think, lipgloss.Width("‹ "+oneLine(level)+" ›"))
		}
	}
	return c
}

type chip struct {
	name  string
	text  string
	width int
	style lipgloss.Style
}

func (r *Renderer) chipsOf(p state.Picker, e protocol.ModelEntry, at bool, c chipCols) []chip {
	var out []chip
	add := func(name string, width int, text string, style lipgloss.Style) {
		if width > 0 {
			out = append(out, chip{name: name, text: text, width: width, style: style})
		}
	}
	loaded := ""
	if e.Loaded != nil && *e.Loaded {
		loaded = "loaded"
	}
	add("loaded", c.loaded, loaded, r.styles.OK)
	add("ctx", c.ctx, contextLabel(e.ContextWindow), r.styles.Output)
	tools, toolsStyle := toolsLabel(e.Tools), r.styles.Output
	if e.Tools != nil && !*e.Tools {
		toolsStyle = r.styles.Warn
	}
	add("tools", c.tools, tools, toolsStyle)

	level, style := levelOf(p, e, at), r.styles.Output
	text := oneLine(level)
	if at && len(e.Thinking) > 1 {
		text, style = "‹ "+text+" ›", r.styles.Emphasis
	}
	if level == "" {
		text = ""
	}
	add("think", c.think, text, style)
	return out
}

var dropOrder = []string{"think", "tools", "loaded", "ctx"}

func (r *Renderer) modelRow(p state.Picker, row state.Row, at bool, c chipCols, inner int) string {
	e := row.Entry
	cur := " "
	if e.Profile == p.Current.Profile && e.Model == p.Current.Model {
		cur = "●"
	}
	segs := []segment{r.marker(at), {text: " "}, {text: cur, style: r.styles.Emphasis}, {text: " "}}

	name := r.styles.Output
	if at {
		name = r.styles.Heading
	}
	left := matched(modelLabel(e), row.Match, name, r.styles.Match)
	extra := []segment{}
	if row.Group != "" {
		extra = append(extra, segment{text: "  " + profileLabel(e.Profile), style: r.styles.Dim})
	}
	if e.Custom {
		extra = append(extra, segment{text: "  custom", style: r.styles.Dim})
	}
	if e.Kind != "" {
		extra = append(extra, segment{text: "  " + oneLine(e.Kind), style: r.styles.Faint})
	}

	var right []segment
	if msg := valueOf(e.Error); msg != "" {
		avail := inner - rowHead - min(segmentsWidth(left)+segmentsWidth(extra), maxNameWithProvider) - 2
		text := " ✖"
		switch {
		case sharedWithNote(p, e):
			if avail >= 14 {
				text = " ✖ unavailable"
			}
		case avail >= 12:
			text = "  " + ellipsize(oneLine(msg), avail)
		}
		right = []segment{{text: text, style: r.styles.Fail}}
	} else {
		chips := r.chipsOf(p, e, at, c)
		for width(chips) > inner-rowHead-minName && len(chips) > 0 {
			chips = without(chips, dropOrder)
		}
		for _, ch := range chips {
			right = append(right, segment{text: spaces(chipGap) + padRight(ch.text, ch.width), style: ch.style})
		}
	}

	room := max(inner-rowHead-segmentsWidth(right), 0)
	if segmentsWidth(left)+segmentsWidth(extra) > room {
		extra = nil
	}
	left = append(cutSegments(left, room), extra...)
	segs = append(segs, left...)
	segs = append(segs, segment{text: spaces(room - segmentsWidth(left))})
	return r.bar(append(segs, right...), inner, at)
}

func sharedWithNote(p state.Picker, e protocol.ModelEntry) bool {
	pr, ok := p.Provider(e.Profile)
	return ok && pr.Error != nil && strings.TrimSpace(p.Query) == "" && *pr.Error == valueOf(e.Error)
}

func width(chips []chip) int {
	total := 0
	for _, ch := range chips {
		total += chipGap + ch.width
	}
	return total
}

func without(chips []chip, drop []string) []chip {
	for _, name := range drop {
		for i, ch := range chips {
			if ch.name == name {
				return append(append([]chip(nil), chips[:i]...), chips[i+1:]...)
			}
		}
	}
	return nil
}

func (r *Renderer) addRow(at bool, inner int) string {
	segs := []segment{r.marker(at), {text: "   "}, {text: addLabel, style: r.styles.Output}}
	return r.bar(segs, inner, at)
}

func (r *Renderer) pickerList(p state.Picker, inner int, now time.Time) ([]string, int) {
	rows := p.Rows()
	visible := p.Visible()
	cols := pickerChips(p, visible)
	selected := min(max(p.Selected, 0), len(visible))

	var lines []string
	if note := listNote(p, len(visible)); note != "" {
		lines = append(lines, r.styles.Dim.Render(note), "")
	}
	bar := selected
	if p.CustomOpen {
		bar = -1
	}
	at, index := 0, 0
	for _, row := range rows {
		switch row.Kind {
		case state.RowSection:
			lines = append(lines, r.sectionRow(p, row, inner, now))
		case state.RowNote:
			lines = append(lines, r.noteRows(row, inner)...)
		case state.RowModel:
			if index == selected {
				at = len(lines)
			}
			lines = append(lines, r.modelRow(p, row, index == bar, cols, inner))
			index++
		case state.RowAdd:
			if index == selected {
				at = len(lines)
			}
			lines = append(lines, r.addRow(index == bar, inner))
		}
	}
	return lines, at
}

func listNote(p state.Picker, models int) string {
	switch {
	case models > 0:
		return ""
	case p.Query != "":
		return "no model or provider matches “" + oneLine(p.Query) + "”"
	case len(p.Providers) == 0 && len(p.Entries) == 0:
		return "no providers yet"
	}
	return ""
}

func (r *Renderer) sectionRow(p state.Picker, row state.Row, inner int, now time.Time) string {
	if row.Provider == nil {
		title := []segment{{text: row.Title, style: r.styles.Heading}}
		return r.heading(title, nil, inner)
	}
	pr := *row.Provider
	title := []segment{{text: profileLabel(pr.Profile), style: r.styles.Heading}}
	room := inner - segmentsWidth(title) - 5
	for _, variant := range r.providerInfo(pr, now) {
		if segmentsWidth(variant) <= room {
			return r.heading(title, variant, inner)
		}
	}
	return r.heading(title, nil, inner)
}

func (r *Renderer) providerInfo(pr protocol.Provider, now time.Time) [][]segment {
	dim := r.styles.Dim
	dot := segment{text: " · ", style: dim}
	var lead []segment
	if pr.Error != nil {
		lead = []segment{{text: "✖ failed", style: r.styles.Fail}, dot}
	}
	proto := segment{text: orDash(oneLine(pr.Protocol)), style: dim}
	host := segment{text: hostOf(pr.Endpoint), style: dim}
	count := segment{text: modelCount(pr.Count), style: dim}
	age := segment{text: queryAge(pr, now), style: dim}
	if pr.Refreshing {
		age.style = r.styles.Run
	}
	join := func(parts ...segment) []segment {
		out := append([]segment(nil), lead...)
		for i, part := range parts {
			if part.text == "" {
				continue
			}
			if i > 0 {
				out = append(out, dot)
			}
			out = append(out, part)
		}
		return out
	}
	variants := [][]segment{
		join(proto, host, count, age),
		join(proto, host, count),
		join(proto, count),
		join(proto),
	}
	if pr.Error != nil {
		variants = append(variants, []segment{{text: "✖ failed", style: r.styles.Fail}})
	}
	return variants
}

func (r *Renderer) noteRows(row state.Row, inner int) []string {
	text := oneLine(row.Title)
	style, mark := r.styles.Dim, "  "
	if row.Bad {
		style, mark = r.styles.Fail, "✖ "
	}
	wrapped := wrapText(text, max(inner-rowHead-2, 1))
	if len(wrapped) > 2 {
		wrapped = []string{wrapped[0], ellipsize(strings.Join(wrapped[1:], " "), max(inner-rowHead-2, 1))}
	}
	out := make([]string, len(wrapped))
	for i, line := range wrapped {
		prefix := spaces(rowHead - 2)
		if i == 0 {
			prefix += style.Render(mark)
		} else {
			prefix += "  "
		}
		out[i] = fit(prefix+style.Render(line), inner)
	}
	return out
}

func (r *Renderer) formBody(p state.Picker, inner int) ([]string, int) {
	f := *p.Form
	var rows []string
	at := 0
	for i := 0; i < state.FieldCount; i++ {
		if name, ok := formSections[i]; ok {
			if len(rows) > 0 {
				rows = append(rows, "")
			}
			rows = append(rows, r.heading([]segment{{text: name, style: r.styles.Heading}}, nil, inner))
		}
		if i == f.Focus {
			at = len(rows)
		}
		rows = append(rows, r.formRow(f, i, inner))
	}
	rows = append(rows, "", r.styles.Dim.Render(ellipsize(formHelp[f.Focus], inner)))
	return rows, at
}

func (r *Renderer) formRow(f state.ProviderForm, i, inner int) string {
	const labelW = 12
	at := i == f.Focus
	label := r.styles.Dim
	if at {
		label = r.styles.Emphasis
	}
	segs := []segment{r.marker(at), {text: " "}, {text: padRight(formLabels[i], labelW), style: label}}
	value := oneLine(f.Values[i])
	if i == state.FieldAPIKey {
		value = maskKey(f.Values[i])
	}
	switch {
	case i == state.FieldProtocol:
		label := protocolLabels[value]
		if label == "" {
			label = value
		}
		segs = append(segs, segment{text: "‹ " + label + " ›", style: r.styles.Output})
	case at:
		room := max(inner-labelW-3, 0)
		segs = append(segs, segment{text: tailCut(value, room), style: r.styles.Output}, segment{text: "█", style: r.styles.Emphasis})
	default:
		segs = append(segs, segment{text: value, style: r.styles.Output})
	}
	return r.bar(segs, inner, at)
}

func maskKey(key string) string {
	return strings.Repeat("•", utf8.RuneCountInString(key))
}

func levelOf(p state.Picker, e protocol.ModelEntry, at bool) string {
	if at {
		return p.Level
	}
	return p.DefaultLevel(e)
}

func contextLabel(tokens *int) string {
	if tokens == nil || *tokens <= 0 {
		return ""
	}
	k := (*tokens + 512) >> 10
	switch {
	case k == 0:
		return "<1k"
	case k < 1000:
		return fmt.Sprintf("%dk", k)
	}
	return fmt.Sprintf("%dM", (*tokens+(1<<19))>>20)
}

func toolsLabel(tools *bool) string {
	switch {
	case tools == nil:
		return ""
	case *tools:
		return "tools"
	}
	return "no tools"
}

func orDash(s string) string {
	if s == "" {
		return "–"
	}
	return s
}

func valueOf(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func profileLabel(profile string) string {
	return orDash(oneLine(profile))
}

func modelLabel(e protocol.ModelEntry) string {
	return orDash(oneLine(e.Model))
}

func padRows(rows []string, n int) []string {
	for len(rows) < n {
		rows = append(rows, "")
	}
	return rows
}
