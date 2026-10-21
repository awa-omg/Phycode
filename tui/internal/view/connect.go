package view

import (
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
)

const (
	hintsChoose         = "↑↓ move · enter connect · esc back · ctrl+x remove · ctrl+r look again"
	hintsChooseExisting = "↑↓ move · enter test again · esc back · ctrl+x remove · ctrl+r test again"
	hintsKey            = "enter connect · esc back · ctrl+u clear"
	hintsKeyExisting    = "enter connect · esc back · ctrl+u clear · ctrl+r test again · ctrl+x remove"
	hintsKeyPending     = "esc cancel"
)

const customNote = "an OpenAI, Anthropic or Ollama compatible server of your own"

func (r *Renderer) connectLines(p state.Picker, inner, rows, frame int) []string {
	c := p.Connect
	head := []string{r.connectHead(p, inner, frame)}
	var body []string
	var at int
	var hints string
	switch {
	case p.Loading && c.Stage == state.ConnectChoose:
		body, at, hints = []string{r.styles.Dim.Render("loading…")}, -1, hintsChoose
	case c.Stage == state.ConnectChoose:
		body, at = r.connectList(p, inner)
		hints = hintsChoose
		if row, ok := p.ConnectHighlighted(); ok && row.Target.Existing {
			hints = hintsChooseExisting
		}
		if note := r.listNote(p, inner); note != "" && rows >= 6 {
			head = append(head, note)
		}
	default:
		body, at = r.keyBody(p, inner, frame)
		hints = hintsKey
		switch {
		case c.Pending:
			hints = hintsKeyPending
		case c.Target.Existing:
			hints = hintsKeyExisting
		}
	}
	foot := r.hints(hints, inner)
	air := 0
	if rows >= 10 {
		air = 1
	}
	foot = foot[:min(len(foot), max(rows-len(head)-1-2*air, 1), 2)]
	if rows < 7 {
		foot = foot[:1]
	}
	n := min(len(body), max(rows-len(head)-len(foot)-2*air, 1))
	out := append([]string(nil), head...)
	out = append(out, make([]string, air)...)
	out = append(out, r.scrollView(body, at, n, inner)[:n]...)
	out = append(out, make([]string, air)...)
	out = append(out, foot...)
	for i := range out {
		out[i] = fit(out[i], inner)
	}
	return out
}

func (r *Renderer) listNote(p state.Picker, inner int) string {
	switch {
	case p.Connect.Confirm != "":
		return r.styles.Warn.Render(ellipsize("press ctrl+x again to remove "+oneLine(p.Connect.Confirm)+" and its key", inner))
	case p.Connect.Error != "":
		return r.styles.Fail.Render("✖ " + ellipsize(oneLine(p.Connect.Error), max(inner-2, 1)))
	case p.Error != "":
		return r.styles.Fail.Render("✖ " + ellipsize(oneLine(p.Error), max(inner-2, 1)))
	}
	return ""
}

func (r *Renderer) connectHead(p state.Picker, inner, frame int) string {
	c := p.Connect
	if c.Stage == state.ConnectKey {
		title := "Connect " + oneLine(c.Target.Name)
		left := []segment{{text: title, style: r.styles.Heading}}
		if host := hostOf(c.Target.BaseURL); host != "" && lipgloss.Width(title)+2+lipgloss.Width(host) <= inner {
			left = append(left, segment{text: spaces(inner - lipgloss.Width(title) - lipgloss.Width(host))}, segment{text: host, style: r.styles.Dim})
		}
		return fit(renderSegments(cutSegments(left, inner)), inner)
	}
	title := "Connect a provider"
	left := []segment{{text: title, style: r.styles.Heading}}
	meta := ""
	switch {
	case c.Pending && c.Removing != "":
		meta = spinnerFrame(frame) + " removing " + oneLine(c.Removing) + "…"
	case c.Pending:
		meta = spinnerFrame(frame) + " asking " + oneLine(c.Target.Name) + " for its models…"
	case p.Detecting:
		meta = spinnerFrame(frame) + " looking for local servers"
	}
	if lipgloss.Width(title)+2+lipgloss.Width(meta) > inner {
		meta = ""
	}
	room := inner - lipgloss.Width(title) - 2 - lipgloss.Width(meta)
	if meta != "" {
		room -= 2
	}
	if room >= 8 {
		left = append(left, segment{text: "  "})
		left = append(left, cutSegments(r.typeSegments(c.Query, "search providers", room), room)...)
	}
	if meta != "" {
		left = append(left, segment{text: spaces(inner - segmentsWidth(left) - lipgloss.Width(meta))}, segment{text: meta, style: r.styles.Run})
	}
	return fit(renderSegments(left), inner)
}

func (r *Renderer) connectList(p state.Picker, inner int) ([]string, int) {
	rows := p.ConnectRows()
	nameW, hostW := 0, 0
	for _, row := range rows {
		if row.Kind == state.ConnectProvider {
			nameW = max(nameW, lipgloss.Width(oneLine(row.Title)))
			hostW = max(hostW, lipgloss.Width(row.Host))
		}
	}
	nameW = min(nameW, max(inner/4, 10))
	var lines []string
	at, index := 0, 0
	for _, row := range rows {
		switch row.Kind {
		case state.ConnectSection:
			lines = append(lines, r.heading([]segment{{text: row.Title, style: r.styles.Heading}}, nil, inner))
		case state.ConnectProvider:
			if index == p.Connect.Selected {
				at = len(lines)
			}
			lines = append(lines, r.connectProviderRow(row, index == p.Connect.Selected, nameW, hostW, inner))
			index++
		case state.ConnectCustom:
			if index == p.Connect.Selected {
				at = len(lines)
			}
			segs := []segment{r.marker(index == p.Connect.Selected), {text: " "}, {text: "+ " + row.Title, style: r.styles.Output}, {text: "  " + customNote, style: r.styles.Dim}}
			lines = append(lines, r.bar(segs, inner, index == p.Connect.Selected))
			index++
		}
	}
	providers := 0
	for _, row := range rows {
		if row.Kind == state.ConnectProvider {
			providers++
		}
	}
	if providers == 0 && p.Connect.Query != "" {
		lines = append([]string{r.styles.Dim.Render("no provider matches “" + oneLine(p.Connect.Query) + "”")}, lines...)
		at++
	}
	return lines, at
}

func (r *Renderer) connectProviderRow(row state.ConnectRow, at bool, nameW, hostW, inner int) string {
	name := r.styles.Output
	if at {
		name = r.styles.Heading
	}
	status, style := oneLine(row.Status), r.styles.Dim
	switch {
	case row.Bad:
		style = r.styles.Fail
		status = "✖ " + status
	case strings.HasPrefix(status, "connected"):
		style = r.styles.OK
		status = "✓ " + status
	}
	statusW := lipgloss.Width(status)
	left := matched(oneLine(row.Title), row.Match, name, r.styles.Match)
	left = cutSegments(left, nameW)
	segs := []segment{r.marker(at), {text: " "}}
	segs = append(segs, left...)
	segs = append(segs, segment{text: spaces(nameW - segmentsWidth(left))})
	room := inner - segmentsWidth(segs) - statusW - 2
	if room >= 6 && row.Host != "" {
		segs = append(segs, segment{text: "  " + ellipsize(row.Host, min(hostW, room-2)), style: r.styles.Dim})
	}
	segs = cutSegments(segs, max(inner-statusW-1, 1))
	segs = append(segs, segment{text: spaces(inner - segmentsWidth(segs) - statusW)}, segment{text: status, style: style})
	return r.bar(segs, inner, at)
}

func (r *Renderer) keyBody(p state.Picker, inner, frame int) ([]string, int) {
	c := p.Connect
	t := c.Target
	var rows []string
	note := "Paste the API key of " + oneLine(t.Name) + ". It is saved encrypted and never shown."
	switch {
	case t.Existing:
		note = "Paste a new key for " + oneLine(t.Name) + ". It replaces the one it has and is saved encrypted."
	case t.KeyInEnv:
		note = "$" + t.EnvVar + " is set: press enter to use it, or paste a key to store it encrypted instead."
	case t.NoKey:
		note = oneLine(t.Name) + " needs no key."
	}
	for _, row := range wrapText(note, inner) {
		rows = append(rows, r.styles.Dim.Render(row))
	}
	rows = append(rows, "")
	at := len(rows)
	switch {
	case c.Pending && c.Removing != "":
		rows = append(rows, r.styles.Run.Render(spinnerFrame(frame))+" "+r.styles.Output.Render("removing "+oneLine(c.Removing)+"…"))
	case c.Pending:
		rows = append(rows, r.styles.Run.Render(spinnerFrame(frame))+" "+r.styles.Output.Render("asking "+hostOf(t.BaseURL)+" for its models…"))
	default:
		rows = append(rows, r.keyLine(c.Key, inner))
	}
	rows = append(rows, r.keyProblem(c.Error, t, inner)...)
	if c.Confirm != "" {
		rows = append(rows, r.styles.Warn.Render(ellipsize("press ctrl+x again to remove "+oneLine(c.Confirm)+" and its key", inner)))
	}
	return rows, at
}

func (r *Renderer) keyLine(key string, inner int) string {
	dots := maskKey(key)
	segs := []segment{
		{text: "❯ ", style: r.styles.Emphasis},
		{text: tailCut(dots, max(inner-3, 0)), style: r.styles.Output},
		{text: "█", style: r.styles.Emphasis},
	}
	if key == "" {
		segs = append(segs, segment{text: " API key", style: r.styles.Dim})
	}
	return fit(renderSegments(cutSegments(segs, inner)), inner)
}

func (r *Renderer) keyProblem(problem string, t state.ConnectTarget, inner int) []string {
	if problem == "" {
		return nil
	}
	var rows []string
	switch state.KeyProblem(problem) {
	case "key refused":
		rows = append(rows, r.styles.Fail.Render("✖ "+hostOf(t.BaseURL)+" refused the key"))
		rows = append(rows, r.detail(problem, inner)...)
		rows = append(rows, r.styles.Dim.Render("Paste another key and press enter."))
	case "not answering":
		rows = append(rows, r.styles.Fail.Render("✖ "+hostOf(t.BaseURL)+" did not answer"))
		rows = append(rows, r.detail(problem, inner)...)
		rows = append(rows, r.styles.Dim.Render("Check that it runs and that the address is right, then press ctrl+r."))
	default:
		rows = append(rows, r.styles.Fail.Render("✖ "+ellipsize(oneLine(problem), max(inner-2, 1))))
	}
	return rows
}

func (r *Renderer) detail(problem string, inner int) []string {
	wrapped := wrapText(oneLine(problem), max(inner-2, 1))
	if len(wrapped) > 2 {
		wrapped = []string{wrapped[0], ellipsize(strings.Join(wrapped[1:], " "), max(inner-2, 1))}
	}
	out := make([]string, len(wrapped))
	for i, row := range wrapped {
		out[i] = "  " + r.styles.Dim.Render(row)
	}
	return out
}
