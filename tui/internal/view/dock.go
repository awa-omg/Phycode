package view

import (
	"strings"
	"time"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
)

const dockRail = "▌"

func dockPad(w int) int {
	if w >= 100 {
		return 2
	}
	return 1
}

func dockInner(w int) int {
	return max(w-3-2*dockPad(w), 0)
}

func dockLimit(h int) int {
	room := h - 2
	return max(room-clamp(h/4, 2, 8), min(room, 3))
}

func dockLip(limit int) int {
	if limit >= 7 {
		return 1
	}
	return 0
}

func dockFoot(limit int) int {
	if limit >= 12 {
		return 1
	}
	return 0
}

func dockRows(h int) int {
	limit := dockLimit(h)
	return max(limit-dockLip(limit)-dockFoot(limit), 1)
}

func dockOn(m state.Model) bool {
	return m.Exec != nil || m.Ask != nil || m.Panel.Open || m.Picker.Open
}

func (r *Renderer) dock(m state.Model, w, h int) []string {
	if !dockOn(m) || w < 12 {
		return nil
	}
	limit := dockLimit(h)
	lip, foot := dockLip(limit), dockFoot(limit)
	inner, rows := dockInner(w), dockRows(h)
	rail := r.styles.Emphasis
	var body []string
	switch {
	case m.Exec != nil:
		body, rail = r.execLines(*m.Exec, inner, rows), r.styles.Warn
	case m.Ask != nil:
		body, rail = r.askLines(*m.Ask, inner, rows), r.styles.Warn
	case m.Panel.Open:
		body = r.panelLines(m, inner, rows)
	default:
		body = r.pickerLines(m.Picker, m.Busy, inner, rows, time.Now(), m.Frame)
	}
	return r.railBlock(body, rail, w, lip == 1, foot == 1)
}

func (r *Renderer) railBlock(lines []string, rail lipgloss.Style, w int, lip, foot bool) []string {
	pad, inner := dockPad(w), dockInner(w)
	bar := rail.Render(dockRail)
	row := func(content string) string {
		return " " + paint(bar+spaces(pad)+fit(content, inner)+spaces(pad), r.bg.overlay) + " "
	}
	var out []string
	if lip {
		out = append(out, " "+lipgloss.NewStyle().Foreground(r.styles.P.Overlay).Render(strings.Repeat("▄", max(w-2, 0)))+" ")
	}
	for _, line := range lines {
		out = append(out, row(line))
	}
	if foot {
		out = append(out, row(""))
	}
	return out
}
