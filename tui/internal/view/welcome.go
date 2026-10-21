package view

import (
	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
)

var wordmark = []string{
	"█▀█ █ █ █ █ █▀▀ █▀█   █ █▀▀",
	"█▀▀ █▀█ ▀█▀ █   █ █ █▀█ █▀▀",
	"▀   ▀ ▀  ▀  ▀▀▀ ▀▀▀ ▀▀█ ▀▀▀",
}

const wordmarkPhy = 11

func (r *Renderer) welcome(m state.Model, w, h int) []string {
	out := make([]string, h)
	if !m.Ready {
		return out
	}
	agent := "explore"
	for _, a := range m.Agents {
		if (a.Mode == "subagent" || a.Mode == "all") && a.Name != "" {
			agent = oneLine(a.Name)
			break
		}
	}
	title := r.styles.Emphasis.Render("phycode")
	if v := oneLine(m.App.Version); v != "" {
		title += " " + r.styles.Dim.Render(v)
	}
	var mark []string
	if w >= len([]rune(wordmark[0]))+4 && h >= len(wordmark)+13 {
		for _, row := range wordmark {
			cells := []rune(row)
			mark = append(mark, r.styles.Accent.Render(string(cells[:wordmarkPhy]))+r.styles.Output.Render(string(cells[wordmarkPhy:])))
		}
	}
	tips := [][2]string{
		{"enter", "send the message"},
		{"/", "commands, settings and help"},
		{"@" + agent, "hand a task to a subagent"},
		{"!", "run a shell command (!! keeps its output from the model)"},
		{"ctrl+p", "the palette of commands and keys"},
		{"ctrl+g", "the subagents of the session"},
		{"ctrl+o", "show the tool outputs in full"},
		{"ctrl+t", "fold the last subagent"},
	}
	keyW, descW := 0, 0
	for _, tip := range tips {
		keyW, descW = max(keyW, lipgloss.Width(tip[0])), max(descW, lipgloss.Width(tip[1]))
	}
	avail := max(w-2, 1)
	blockW := min(keyW+2+descW, avail)

	var rows []string
	center := func(row string) string { return spaces((w-lipgloss.Width(row))/2) + row }
	if len(mark) > 0 {
		for _, row := range mark {
			rows = append(rows, center(row))
		}
		rows = append(rows, "")
		if v := oneLine(m.App.Version); v != "" {
			rows = append(rows, center(r.styles.Dim.Render(v)))
		}
	} else {
		rows = append(rows, center(title))
	}
	rows = append(rows, center(r.styles.Output.Render("a local coding agent for llama.cpp")), "")
	left := spaces((w - blockW) / 2)
	for _, tip := range tips {
		key := r.styles.Key.Render(padRight(tip[0], keyW))
		desc := r.styles.Dim.Render(ellipsize(tip[1], max(blockW-keyW-2, 0)))
		rows = append(rows, left+key+"  "+desc)
	}
	top := max((h-len(rows))/2, 0)
	for i, row := range rows {
		if top+i >= h {
			break
		}
		out[top+i] = row
	}
	return out
}
