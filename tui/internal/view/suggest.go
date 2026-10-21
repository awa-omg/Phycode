package view

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
)

const maxSuggestions = 8

const (
	minMenuRows  = 2
	minMenuWidth = 16
)

const maxMenuName = 26

var menuHints = []string{
	"↑↓ select · tab complete · enter run · esc close",
	"↑↓ select · tab complete · esc",
	"tab complete · esc",
}

func (r *Renderer) overlaySuggestions(rows []string, m state.Model, w int) []string {
	block := r.suggestionBlock(m, w, len(rows))
	if len(block) == 0 {
		return rows
	}
	out := append([]string(nil), rows...)
	copy(out[len(out)-len(block):], block)
	return out
}

func (r *Renderer) suggestionBlock(m state.Model, w, avail int) []string {
	n := len(m.Suggestions)
	inner := dockInner(w)
	if (n == 0 && m.NoMatch == "") || avail < minMenuRows || w < minMenuWidth {
		return nil
	}
	if n == 0 {
		note := r.styles.Dim.Render(ellipsize("no command matches “"+oneLine(m.NoMatch)+"”", inner))
		head := r.menuHead([]segment{{text: "commands", style: r.styles.Heading}}, "esc close", inner)
		return r.railBlock([]string{head, note}, r.styles.Emphasis, w, false, false)
	}

	vis := min(maxSuggestions, n, avail-1)
	sel := clamp(m.Selected, 0, n-1)
	start := clamp(sel-vis/2, 0, n-vis)
	shown := m.Suggestions[start : start+vis]

	nameW := 0
	for _, s := range shown {
		nameW = max(nameW, labelWidth(s))
	}
	nameW = min(nameW, maxMenuName, max(inner/2, 12))

	title := []segment{{text: menuTitle(m.Suggestions[0]), style: r.styles.Heading}, {text: "  " + positionLabel(sel+1, n), style: r.styles.Dim}}
	if pickHints(inner-segmentsWidth(title)-2) == "" {
		title = title[:1]
	}
	lines := []string{r.menuHead(title, pickHints(inner-segmentsWidth(title)-2), inner)}
	for i, s := range shown {
		lines = append(lines, r.suggestionRow(s, start+i == sel, nameW, inner))
	}
	return r.railBlock(lines, r.styles.Emphasis, w, false, false)
}

func (r *Renderer) menuHead(title []segment, hints string, inner int) string {
	keys := r.hintSegments(hints, inner-segmentsWidth(title)-2)
	gap := inner - segmentsWidth(title) - segmentsWidth(keys)
	return fit(renderSegments(append(append(title, segment{text: spaces(gap)}), keys...)), inner)
}

func (r *Renderer) suggestionRow(s state.Suggestion, at bool, nameW, inner int) string {
	word, args, _ := strings.Cut(oneLine(s.Label), " ")
	name := r.styles.Output
	if at {
		name = r.styles.Heading
	}
	segs := []segment{r.marker(at), {text: " "}}
	label := matched(word, s.Match, name, r.styles.Match)
	if args != "" {
		label = append(label, segment{text: " " + args, style: r.styles.Dim})
	}
	segs = append(segs, cutSegments(label, nameW)...)
	segs = append(segs, segment{text: spaces(nameW - min(segmentsWidth(label), nameW))})
	if desc := oneLine(s.Description); desc != "" {
		room := inner - 2 - nameW - 2
		if room >= 4 {
			segs = append(segs, segment{text: "  " + ellipsize(desc, room), style: r.styles.Dim})
		}
	}
	return r.bar(segs, inner, at)
}

func (r *Renderer) hintSegments(text string, room int) []segment {
	if text == "" || len([]rune(text)) > room {
		return nil
	}
	var segs []segment
	for i, hint := range strings.Split(text, " · ") {
		if i > 0 {
			segs = append(segs, segment{text: " · ", style: r.styles.Dim})
		}
		if key, what := splitHint(hint); key != "" {
			segs = append(segs, segment{text: key, style: r.styles.Key})
			if what != "" {
				segs = append(segs, segment{text: " " + what, style: r.styles.Dim})
			}
		} else {
			segs = append(segs, segment{text: hint, style: r.styles.Dim})
		}
	}
	return segs
}

func pickHints(room int) string {
	for _, h := range menuHints {
		if len([]rune(h)) <= room {
			return h
		}
	}
	return ""
}

func labelWidth(s state.Suggestion) int {
	return lipgloss.Width(oneLine(s.Label))
}

func menuTitle(s state.Suggestion) string {
	switch s.Kind {
	case state.SuggestAgent:
		return "agents"
	case state.SuggestSubagent:
		return "subagents"
	case state.SuggestChoice:
		return "options"
	}
	return "commands"
}

func positionLabel(at, n int) string {
	return fmt.Sprintf("%d of %d", at, n)
}
