package view

import (
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
)

const maxInputRows = 6

var shellExamples = []string{"ls -la", "git status", "go test ./...", "rg TODO", "git diff --stat"}

func (r *Renderer) inputLines(m state.Model, w int) ([]string, int) {
	contentW := max(w-5, 1)
	textW := max(contentW-2, 1)
	wrapW := max(textW-1, 1)
	prompt := r.styles.Emphasis.Render("❯ ")
	if m.ShellMode {
		prompt = r.styles.Info.Bold(true).Render("$ ")
	}
	if m.Input == "" {
		placeholder := []rune("Message phycode…")
		if m.ShellMode {
			placeholder = []rune(`Run a command… "` + shellExamples[len(m.History)%len(shellExamples)] + `"`)
		}
		row := prompt + r.styles.Cursor.Render(string(placeholder[0])) + r.styles.Faint.Render(string(placeholder[1:]))
		return []string{fit(row, contentW)}, 0
	}

	runes := []rune(m.Input)
	cursor := clamp(m.Cursor, 0, len(runes))
	var rows []string
	cursorRow := -1
	lineStart := 0
	for {
		lineEnd := lineStart
		for lineEnd < len(runes) && runes[lineEnd] != '\n' {
			lineEnd++
		}
		starts := wrapSpans(runes[lineStart:lineEnd], wrapW)
		for i, s := range starts {
			start, end := lineStart+s, lineEnd
			if i+1 < len(starts) {
				end = lineStart + starts[i+1]
			}
			prefix := "  "
			if len(rows) == 0 {
				prefix = prompt
			}
			text := cut(strings.TrimRight(string(runes[start:end]), " "), textW)
			if cursorRow < 0 && cursor <= end {
				cursorRow = len(rows)
				text = r.cursorText(runes, start, end, cursor, wrapW, textW)
			}
			rows = append(rows, fit(prefix+text, contentW))
		}
		if lineEnd == len(runes) {
			break
		}
		lineStart = lineEnd + 1
	}
	return rows, cursorRow
}

func (r *Renderer) cursorText(runes []rune, start, end, cursor, wrapW, textW int) string {
	left := cut(string(runes[start:cursor]), wrapW)
	cell, right := " ", ""
	if cursor < end {
		cell = string(runes[cursor])
		room := textW - lipgloss.Width(left) - lipgloss.Width(cell)
		right = cut(strings.TrimRight(string(runes[cursor+1:end]), " "), room)
	}
	return left + r.styles.Cursor.Render(cell) + right
}

func (r *Renderer) composerBar(m state.Model) string {
	style := r.styles.Emphasis
	switch {
	case m.Confirm != nil || m.Ask != nil || m.Exec != nil:
		style = r.styles.Warn
	case m.ShellMode:
		style = r.styles.Info
	case m.Working():
		style = r.styles.Faint
	}
	return style.Render("▌")
}

func (r *Renderer) composer(m state.Model, w int, rows []string, cursorRow, boxRows, pad int) []string {
	if boxRows <= 0 {
		return nil
	}
	n := min(clamp(boxRows-2*pad, 1, maxInputRows), len(rows))
	start := clamp(cursorRow-n+1, 0, len(rows)-n)
	bar := r.composerBar(m)
	inner := max(w-2, 0)
	draw := func(content string) string {
		return fit(" "+band(bar+" "+content, inner, r.bg.raised), w)
	}
	var out []string
	for i := 0; i < pad; i++ {
		out = append(out, draw(""))
	}
	for _, row := range rows[start : start+n] {
		out = append(out, draw(row))
	}
	for i := 0; i < pad; i++ {
		out = append(out, draw(""))
	}
	return out[max(len(out)-boxRows, 0):]
}
