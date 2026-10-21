package view

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/lipgloss"
)

func (r *Renderer) onBar(s lipgloss.Style) lipgloss.Style {
	if s.GetForeground() == r.styles.Dim.GetForeground() {
		s = s.Foreground(r.styles.Bar.GetForeground())
	}
	return s.Background(r.styles.Bar.GetBackground())
}

func (r *Renderer) bar(segs []segment, inner int, at bool) string {
	segs = append([]segment(nil), segs...)
	if at {
		for i := range segs {
			segs[i].style = r.onBar(segs[i].style)
		}
	}
	segs = cutSegments(segs, inner)
	if at {
		segs = append(segs, segment{text: spaces(inner - segmentsWidth(segs)), style: r.styles.Bar})
	}
	return fit(renderSegments(segs), inner)
}

func (r *Renderer) marker(at bool) segment {
	if at {
		return segment{text: "›", style: r.styles.Emphasis}
	}
	return segment{text: " "}
}

func matched(text string, positions []int, base, mark lipgloss.Style) []segment {
	if len(positions) == 0 {
		return []segment{{text: text, style: base}}
	}
	hit := make(map[int]bool, len(positions))
	for _, p := range positions {
		hit[p] = true
	}
	runes := []rune(text)
	var out []segment
	for i := 0; i < len(runes); {
		j := i
		for j < len(runes) && hit[j] == hit[i] {
			j++
		}
		style := base
		if hit[i] {
			style = mark
		}
		out = append(out, segment{text: string(runes[i:j]), style: style})
		i = j
	}
	return out
}

func (r *Renderer) heading(title, info []segment, inner int) string {
	title = cutSegments(title, inner)
	room := inner - segmentsWidth(title) - 1
	if segmentsWidth(info) > 0 && room-segmentsWidth(info)-1 < 3 {
		info = nil
	}
	if segmentsWidth(info) > 0 {
		room -= segmentsWidth(info) + 1
	}
	segs := title
	if room >= 1 {
		segs = append(segs, segment{text: " "}, segment{text: strings.Repeat("─", room), style: r.styles.Rule})
		if segmentsWidth(info) > 0 {
			segs = append(segs, segment{text: " "})
			segs = append(segs, info...)
		}
	}
	return fit(renderSegments(segs), inner)
}

func (r *Renderer) scrollView(rows []string, at, n, inner int) []string {
	if n <= 0 {
		return nil
	}
	if len(rows) <= n {
		return padRows(append([]string(nil), rows...), n)
	}
	start := clamp(at-n/2, 0, len(rows)-n)
	if n >= 3 {
		if start > 0 && at <= start {
			start = max(at-1, 0)
		}
		if start+n < len(rows) && at >= start+n-1 {
			start = min(at-n+2, len(rows)-n)
		}
	}
	out := append([]string(nil), rows[start:start+n]...)
	if n >= 3 {
		if start > 0 {
			out[0] = fit(r.styles.Dim.Render(fmt.Sprintf("  ↑ %d more", start+1)), inner)
		}
		if start+n < len(rows) {
			out[n-1] = fit(r.styles.Dim.Render(fmt.Sprintf("  ↓ %d more", len(rows)-start-(n-1))), inner)
		}
	}
	return out
}

func hintLines(text string, width int) []string {
	var out []string
	line := ""
	for _, hint := range strings.Split(text, " · ") {
		switch {
		case line == "":
			line = hint
		case lipgloss.Width(line)+lipgloss.Width(" · ")+lipgloss.Width(hint) <= width:
			line += " · " + hint
		default:
			out = append(out, line)
			line = hint
		}
	}
	if line != "" {
		out = append(out, line)
	}
	return out
}

func (r *Renderer) hints(text string, width int) []string {
	lines := hintLines(text, width)
	rows := make([]string, len(lines))
	for i, line := range lines {
		rows[i] = r.hintRow(line)
	}
	return rows
}

func (r *Renderer) hintRow(line string) string {
	var b strings.Builder
	for i, hint := range strings.Split(line, " · ") {
		if i > 0 {
			b.WriteString(r.styles.Dim.Render(" · "))
		}
		if key, what := splitHint(hint); key != "" {
			b.WriteString(r.styles.Key.Render(key))
			if what != "" {
				b.WriteString(r.styles.Dim.Render(" " + what))
			}
		} else {
			b.WriteString(r.styles.Dim.Render(hint))
		}
	}
	return b.String()
}

func splitHint(hint string) (key, what string) {
	words := strings.Fields(hint)
	n := 0
	for n < len(words) && isKey(words[n], len(words) > 1) {
		n++
	}
	if n == 0 {
		return "", hint
	}
	return strings.Join(words[:n], " "), strings.Join(words[n:], " ")
}

var namedKeys = map[string]bool{
	"enter": true, "esc": true, "tab": true, "space": true, "backspace": true, "delete": true,
	"pgup": true, "pgdn": true, "pageup": true, "pagedown": true, "home": true, "end": true, "ctrl": true,
}

func isKey(word string, more bool) bool {
	lower := strings.ToLower(word)
	switch {
	case namedKeys[lower], strings.Contains(word, "+") && len(word) > 1:
		return true
	case strings.Trim(word, "↑↓←→") == "":
		return true
	}
	runes := []rune(word)
	return more && len(runes) == 1 && !isLetterWord(runes[0])
}

func isLetterWord(r rune) bool {
	return r == 'a' || r == 'A' || r == 'I'
}
