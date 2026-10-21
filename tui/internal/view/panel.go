package view

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

const (
	panelListHint   = "type to filter · enter details · esc close"
	panelTextHint   = "↑↓ PgUp PgDn scroll · esc close"
	panelDetailHint = "↑↓ PgUp PgDn scroll · esc back"
	editSettingHint = "type the value · enter save · esc cancel"
)

type panelDraw struct {
	iw    int
	air   int
	body  []string
	foot  []string
	hint  []string
	at    int
	shown int
}

func (r *Renderer) layoutPanel(m state.Model, iw, rows int) panelDraw {
	body, at := r.panelBody(m, iw)
	return r.drawOf(body, r.panelFoot(m, iw), at, panelHint(m.Panel), iw, rows)
}

func (r *Renderer) drawOf(body, foot []string, at int, hint string, iw, rows int) panelDraw {
	hints := r.hints(hint, iw)
	for len(foot) > 0 && rows < 10 && foot[0] == "" {
		foot = foot[1:]
	}
	air, keep := 0, 2
	if rows >= 10 {
		air = 1
	}
	if rows < 7 {
		keep = 1
	}
	hints = hints[:min(len(hints), keep, max(rows-2-2*air, 1))]
	foot = foot[:min(len(foot), max(rows-1-len(hints)-2*air-1, 0))]
	shown := min(len(body), max(rows-1-len(hints)-2*air-len(foot), 0))
	return panelDraw{iw: iw, air: air, body: body, foot: foot, hint: hints, at: at, shown: shown}
}

func (d panelDraw) window(scroll int) (int, int) {
	n := len(d.body)
	if n <= d.shown {
		return 0, n
	}
	start := clamp(scroll, 0, n-d.shown)
	if d.at >= 0 {
		start = clamp(d.at-d.shown/2, 0, n-d.shown)
	}
	return start, start + d.shown
}

func PanelMaxScroll(m state.Model) int {
	if !m.Panel.Open || m.Width <= 0 || m.Height <= 0 {
		return 0
	}
	measureMu.Lock()
	defer measureMu.Unlock()
	d := measurerOf(m).layoutPanel(m, dockInner(m.Width), dockRows(m.Height))
	return max(0, len(d.body)-d.shown)
}

func (r *Renderer) panelLines(m state.Model, iw, rows int) []string {
	p := m.Panel
	d := r.layoutPanel(m, iw, rows)
	first, last := d.window(p.Scroll)
	counter := ""
	if (p.Kind == "text" || p.Detail) && len(d.body) > d.shown {
		counter = fmt.Sprintf("%d–%d of %d", first+1, last, len(d.body))
	}
	return d.lines(r.panelTitleRow(p, iw, counter), p.Scroll)
}

func (d panelDraw) lines(title string, scroll int) []string {
	first, last := d.window(scroll)
	out := []string{title}
	if d.air > 0 {
		out = append(out, "")
	}
	out = append(out, d.body[first:last]...)
	out = append(out, d.foot...)
	if d.air > 0 {
		out = append(out, "")
	}
	return append(out, d.hint...)
}

func panelHint(p state.Panel) string {
	switch {
	case p.Editing:
		return editSettingHint
	case p.Kind == "web" && p.Web != nil:
		return webHint(p.Web)
	case p.Kind == "list" && p.Detail:
		return panelDetailHint
	case p.Hint != "" && p.Kind != "list":
		return oneLine(p.Hint)
	case p.IsList():
		return panelListHint
	}
	return panelTextHint
}

func (r *Renderer) panelTitleRow(p state.Panel, iw int, counter string) string {
	title := oneLine(p.Title)
	if p.Kind == "list" && p.Detail {
		if it, ok := p.Highlighted(); ok {
			title += " › " + oneLine(it.Title)
		}
	}
	if counter != "" && lipgloss.Width(title)+1+lipgloss.Width(counter) > iw {
		counter = ""
	}
	room := iw
	if counter != "" {
		room -= lipgloss.Width(counter) + 1
	}
	var left []segment
	if p.IsList() && !p.Detail && p.Kind != "effort" {
		left = r.panelFilter(title, p.Query, room)
	} else {
		left = []segment{{text: ellipsize(title, room), style: r.styles.Heading}}
	}
	gap := iw - segmentsWidth(left) - lipgloss.Width(counter)
	return fit(renderSegments(left)+spaces(gap)+r.styles.Dim.Render(counter), iw)
}

func (r *Renderer) panelFilter(title, query string, room int) []segment {
	head := ellipsize(title, room)
	left := room - lipgloss.Width(head) - 2
	if left < 6 {
		return []segment{{text: head, style: r.styles.Heading}}
	}
	return append([]segment{{text: head, style: r.styles.Heading}, {text: "  "}}, r.typeSegments(query, "filter", left)...)
}

func (r *Renderer) panelBody(m state.Model, iw int) ([]string, int) {
	p := m.Panel
	switch {
	case p.Loading:
		return []string{r.styles.Dim.Render("loading…")}, -1
	case p.Kind == "web" && p.Web != nil:
		return r.webBody(p, iw)
	case p.Kind == "context":
		return r.contextBody(m, iw)
	case p.Kind == "effort":
		return r.effortBody(p, iw)
	case p.Kind == "compact":
		return r.compactBody(m, iw)
	case p.Kind == "list" && p.Detail:
		if it, ok := p.Highlighted(); ok {
			return r.panelDetail(it, iw), -1
		}
		return nil, -1
	case p.IsList():
		return r.panelList(p, iw, m.Frame)
	}
	return r.panelText(p, iw), -1
}

func (r *Renderer) effortBody(p state.Panel, iw int) ([]string, int) {
	items := p.Visible()
	var segs []segment
	for i, it := range items {
		name := oneLine(it.Title)
		if it.Detail != "" {
			name += " " + oneLine(it.Detail)
		}
		label, style := " "+name+" ", r.styles.Output
		if it.Badge == "current" {
			label, style = " ● "+name+" ", r.styles.OK
		}
		if it.Detail != "" {
			style = r.styles.Dim
		}
		if i == p.Selected {
			style = r.onBar(r.styles.Heading)
			if it.Badge == "current" {
				style = r.onBar(r.styles.OK)
			}
		}
		if i > 0 {
			segs = append(segs, segment{text: " "})
		}
		segs = append(segs, segment{text: label, style: style})
	}
	if len(items) == 0 || segmentsWidth(segs) > iw {
		return r.panelList(p, iw, 0)
	}
	return []string{fit(renderSegments(segs), iw)}, -1
}

func (r *Renderer) panelFoot(m state.Model, iw int) []string {
	switch m.Panel.Kind {
	case "context":
		return []string{"", r.typeLine(m.Panel.Input, "max N, Nk or off", iw)}
	case "effort":
		return r.effortNote(m.Panel, iw)
	case "list":
		if m.Panel.Detail || m.Panel.Hint == "" {
			return nil
		}
		return []string{r.styles.Dim.Render(ellipsize(oneLine(m.Panel.Hint), iw))}
	}
	return nil
}

func (r *Renderer) effortNote(p state.Panel, iw int) []string {
	note := trimBlank(sanitize(p.Note))
	if note == "" {
		return nil
	}
	rows := wrapText(note, iw)
	if len(rows) > 2 {
		rows = []string{rows[0], ellipsize(strings.Join(rows[1:], " "), iw)}
	}
	out := []string{""}
	for _, row := range rows {
		out = append(out, r.styles.Dim.Render(row))
	}
	return out
}

func (r *Renderer) panelText(p state.Panel, iw int) []string {
	text := trimBlank(sanitize(p.Text))
	var rows []string
	if p.Title == "Help" {
		for _, line := range strings.Split(text, "\n") {
			rows = append(rows, ellipsize(line, iw))
		}
	} else {
		rows = wrapText(text, iw)
	}
	for i, row := range rows {
		rows[i] = r.styles.Output.Render(row)
	}
	return rows
}

func (r *Renderer) panelList(p state.Panel, iw, frame int) ([]string, int) {
	items := p.Visible()
	if len(items) == 0 {
		msg := "no item matches the filter"
		switch {
		case len(p.Items) == 0 && p.Kind == "tasks":
			msg = "no subagents yet · @NAME TASK hands a task to one"
		case len(p.Items) == 0:
			msg = "nothing to show"
		}
		return []string{r.styles.Dim.Render(msg)}, -1
	}
	var rows []string
	at := -1
	var nameW map[string]int
	switch p.Kind {
	case "commands", "themes":
		nameW = commandNameWidths(items, iw)
	case "config":
		w := 0
		for _, it := range items {
			w = max(w, lipgloss.Width(oneLine(it.Title)))
		}
		nameW = map[string]int{"": min(w, max(iw/3, 10))}
	}
	taskW := 0
	if p.Kind == "tasks" {
		for _, it := range items {
			taskW = max(taskW, lipgloss.Width(oneLine(it.Title)))
		}
		taskW = min(taskW, max(iw/4, 10))
	}
	for i, it := range items {
		if (i == 0 || items[i-1].Category != it.Category) && it.Category != "" {
			rows = append(rows, r.panelHeading(it.Category, iw))
		}
		if i == p.Selected {
			at = len(rows)
			if p.Editing {
				it.Badge = p.Input + "█"
			}
		}
		switch p.Kind {
		case "config":
			rows = append(rows, r.settingRow(it, i == p.Selected, nameW[""], iw))
			continue
		case "sessions":
			rows = append(rows, r.sessionRow(it, i == p.Selected, iw))
			continue
		case "commands", "themes":
			rows = append(rows, r.commandRow(it, i == p.Selected, nameW[it.Category], iw))
			continue
		case "tasks":
			rows = append(rows, r.taskRow(it, i == p.Selected, taskW, iw, frame))
			continue
		}
		rows = append(rows, r.panelRow(it, i == p.Selected, iw))
	}
	return rows, at
}

func (r *Renderer) panelHeading(name string, iw int) string {
	name = oneLine(name)
	rule := strings.Repeat("─", max(iw-lipgloss.Width(name)-1, 0))
	return fit(r.styles.Heading.Render(name)+" "+r.styles.Dim.Render(rule), iw)
}

func (r *Renderer) panelRow(it protocol.PanelItem, at bool, iw int) string {
	titleStyle := r.styles.Output
	if at {
		titleStyle = r.styles.Heading
	}
	sym, symStyle := r.panelStatus(it.Status)
	head := []segment{r.marker(at), {text: " "}, {text: sym, style: symStyle}, {text: " "}}

	room := iw - segmentsWidth(head)
	badge := oneLine(it.Badge)
	if badge != "" && room-lipgloss.Width(badge)-2 >= 4 {
		room -= lipgloss.Width(badge) + 2
	} else {
		badge = ""
	}
	body := []segment{{text: oneLine(it.Title), style: titleStyle}}
	if detail := oneLine(it.Detail); detail != "" {
		body = append(body, segment{text: "  "}, segment{text: detail, style: r.styles.Dim})
	}
	body = cutSegments(body, room)
	segs := append(head, body...)
	if badge != "" {
		segs = append(segs, segment{text: spaces(room + 2 - segmentsWidth(body))}, segment{text: badge, style: r.styles.Dim})
	}
	return r.bar(segs, iw, at)
}

func (r *Renderer) settingRow(it protocol.PanelItem, at bool, nameW, iw int) string {
	title := r.styles.Output
	if at {
		title = r.styles.Heading
	}
	value, style := oneLine(it.Badge), r.styles.Output
	switch it.Status {
	case "ok":
		value, style = "● "+value, r.styles.OK
	case "off":
		value, style = "○ "+value, r.styles.Dim
	}
	room := iw - 2 - lipgloss.Width(value) - 2
	segs := []segment{r.marker(at), {text: " "}, {text: padRight(ellipsize(oneLine(it.Title), nameW), nameW), style: title}}
	if key := oneLine(it.Detail); key != "" && room-nameW-2 >= 4 {
		segs = append(segs, segment{text: "  " + ellipsize(key, room-nameW-2), style: r.styles.Faint})
	}
	segs = cutSegments(segs, max(iw-lipgloss.Width(value)-1, 1))
	segs = append(segs, segment{text: spaces(iw - segmentsWidth(segs) - lipgloss.Width(value))}, segment{text: value, style: style})
	return r.bar(segs, iw, at)
}

func (r *Renderer) sessionRow(it protocol.PanelItem, at bool, iw int) string {
	title := r.styles.Output
	if at {
		title = r.styles.Heading
	}
	dot, dotStyle := " ", r.styles.Dim
	if it.Badge == "current" {
		dot, dotStyle = "●", r.styles.OK
	}
	detail := oneLine(it.Detail)
	room := iw - 4
	if lipgloss.Width(detail)+2+8 > room {
		detail = ""
	}
	segs := []segment{r.marker(at), {text: " "}, {text: dot, style: dotStyle}, {text: " "}}
	segs = append(segs, cutSegments([]segment{{text: oneLine(it.Title), style: title}}, max(room-lipgloss.Width(detail)-2, 1))...)
	segs = append(segs, segment{text: spaces(iw - segmentsWidth(segs) - lipgloss.Width(detail))}, segment{text: detail, style: r.styles.Dim})
	return r.bar(segs, iw, at)
}

func (r *Renderer) commandRow(it protocol.PanelItem, at bool, nameW, iw int) string {
	name := r.styles.Output
	switch {
	case at:
		name = r.styles.Heading
	case it.Category == state.CategoryKeys:
		name = r.styles.Key
	}
	title := ellipsize(oneLine(it.Title), nameW)
	segs := []segment{r.marker(at), {text: " "}, {text: padRight(title, nameW), style: name}}
	if room := iw - 2 - nameW - 2; room >= 4 {
		if detail := oneLine(it.Detail); detail != "" {
			segs = append(segs, segment{text: "  " + ellipsize(detail, room), style: r.styles.Dim})
		}
	}
	if badge := oneLine(it.Badge); badge != "" && iw-segmentsWidth(segs) > lipgloss.Width(badge)+2 {
		segs = cutSegments(segs, iw-lipgloss.Width(badge)-1)
		segs = append(segs, segment{text: spaces(iw - segmentsWidth(segs) - lipgloss.Width(badge)), style: r.styles.Dim}, segment{text: badge, style: r.styles.Dim})
	}
	return r.bar(segs, iw, at)
}

func (r *Renderer) taskRow(it protocol.PanelItem, at bool, nameW, iw, frame int) string {
	sym, symStyle := r.panelStatus(it.Status)
	if it.Status == "running" {
		sym, symStyle = spinnerFrame(frame), r.styles.Run
	}
	name := oneLine(it.Title)
	agent := strings.TrimLeft(name, " ")
	indent := name[:len(name)-len(agent)]
	segs := []segment{
		r.marker(at), {text: " "}, {text: sym, style: symStyle}, {text: " "},
		{text: indent}, {text: padRight(ellipsize(agent, max(nameW-len(indent), 1)), max(nameW-len(indent), 1)), style: r.styles.Tone(agent)},
	}
	badge := oneLine(it.Badge)
	room := iw - segmentsWidth(segs) - 2
	if badge != "" && room-lipgloss.Width(badge)-2 >= 8 {
		room -= lipgloss.Width(badge) + 2
	} else {
		badge = ""
	}
	if detail := oneLine(it.Detail); detail != "" && room >= 4 {
		segs = append(segs, segment{text: "  " + ellipsize(detail, room), style: r.styles.Dim})
	}
	if badge != "" {
		segs = cutSegments(segs, iw-lipgloss.Width(badge)-1)
		segs = append(segs, segment{text: spaces(iw - segmentsWidth(segs) - lipgloss.Width(badge)), style: r.styles.Dim}, segment{text: badge, style: r.styles.Dim})
	}
	return r.bar(segs, iw, at)
}

func commandNameWidths(items []protocol.PanelItem, iw int) map[string]int {
	widths := map[string]int{}
	shared := 0
	for _, it := range items {
		w := lipgloss.Width(oneLine(it.Title))
		widths[it.Category] = max(widths[it.Category], w)
		if it.Category != state.CategoryKeys {
			shared = max(shared, w)
		}
	}
	shared = min(shared, max(iw/3, 12))
	for category, w := range widths {
		if category == state.CategoryKeys {
			widths[category] = min(w, max(iw/3, 12))
			continue
		}
		widths[category] = shared
	}
	return widths
}

func (r *Renderer) panelStatus(status string) (string, lipgloss.Style) {
	switch status {
	case "ok":
		return "●", r.styles.OK
	case "error":
		return "✗", r.styles.Fail
	case "off":
		return "○", r.styles.Dim
	case "running":
		return "●", r.styles.Run
	case "waiting":
		return "!", r.styles.Warn
	}
	return " ", r.styles.Dim
}

func (r *Renderer) panelDetail(it protocol.PanelItem, iw int) []string {
	var head []string
	if sym, style := r.panelStatus(it.Status); sym != " " {
		head = append(head, fit(style.Render(sym)+" "+r.styles.Output.Render(oneLine(it.Status)), iw))
	}
	head = append(head, r.panelField("category", it.Category, iw)...)
	head = append(head, r.panelField("path", it.Path, iw)...)
	head = append(head, r.panelField("badge", it.Badge, iw)...)

	rows := head
	if detail := trimBlank(sanitize(it.Detail)); detail != "" {
		if len(head) > 0 {
			rows = append(rows, "")
		}
		for _, row := range wrapText(detail, iw) {
			rows = append(rows, r.styles.Output.Render(row))
		}
	}
	return rows
}

func (r *Renderer) panelField(label, value string, iw int) []string {
	const labelW = 10
	value = oneLine(value)
	if value == "" {
		return nil
	}
	vals := wrapText(value, max(iw-labelW, 1))
	rows := make([]string, 0, len(vals))
	for i, v := range vals {
		head := spaces(labelW)
		if i == 0 {
			head = r.styles.Dim.Render(padRight(label, labelW))
		}
		rows = append(rows, fit(head+r.styles.Output.Render(v), iw))
	}
	return rows
}
