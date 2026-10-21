package view

import (
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/theme"
)

const sgrReset = "\x1b[0m"

type surfaces struct {
	page    string
	raised  string
	overlay string
	add     string
	del     string
}

func surfacesOf(p theme.Palette) surfaces {
	on := func(bg lipgloss.Color) string { return bgSeq(bg) + seqOf(lipgloss.NewStyle().Foreground(p.Text)) }
	return surfaces{page: on(p.Bg), raised: on(p.Raised), overlay: on(p.Overlay), add: on(p.AddBg), del: on(p.DelBg)}
}

func bgSeq(c lipgloss.Color) string {
	return seqOf(lipgloss.NewStyle().Background(c))
}

func seqOf(s lipgloss.Style) string {
	out := s.Render("x")
	if i := strings.Index(out, "x"); i > 0 {
		return out[:i]
	}
	return ""
}

func paint(line, seq string) string {
	if seq == "" {
		return line
	}
	return seq + strings.ReplaceAll(line, sgrReset, sgrReset+seq) + sgrReset
}

func band(line string, w int, seq string) string {
	return paint(fit(line, w), seq)
}
