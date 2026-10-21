package theme

import (
	"github.com/charmbracelet/glamour/ansi"
	"github.com/charmbracelet/lipgloss"
)

type Styles struct {
	P Palette

	Heading  lipgloss.Style
	Output   lipgloss.Style
	Dim      lipgloss.Style
	Faint    lipgloss.Style
	Emphasis lipgloss.Style
	Accent   lipgloss.Style
	Key      lipgloss.Style
	Match    lipgloss.Style
	Rule     lipgloss.Style
	Cursor   lipgloss.Style
	Bar      lipgloss.Style

	OK     lipgloss.Style
	Run    lipgloss.Style
	Fail   lipgloss.Style
	Warn   lipgloss.Style
	Info   lipgloss.Style
	Notice lipgloss.Style

	DiffAdd     lipgloss.Style
	DiffDel     lipgloss.Style
	DiffAddWord lipgloss.Style
	DiffDelWord lipgloss.Style
	DiffNo      lipgloss.Style

	Tones []lipgloss.Style

	Markdown ansi.StyleConfig
}

func Default() Styles {
	return Build(Lookup(""))
}

func Build(p Palette) Styles {
	fg := func(c lipgloss.Color) lipgloss.Style { return lipgloss.NewStyle().Foreground(c) }
	return Styles{
		P: p,

		Heading:  fg(p.Text).Bold(true),
		Output:   fg(p.Text),
		Dim:      fg(p.Muted),
		Faint:    fg(p.Faint),
		Emphasis: fg(p.Accent).Bold(true),
		Accent:   fg(p.Accent),
		Key:      fg(p.Text).Bold(true),
		Match:    fg(p.Accent).Bold(true).Underline(true),
		Rule:     fg(p.Border),
		Cursor:   lipgloss.NewStyle().Reverse(true),
		Bar:      fg(p.Text).Background(p.Select),

		OK:     fg(p.Success),
		Run:    fg(p.Accent),
		Fail:   fg(p.Error),
		Warn:   fg(p.Warning),
		Info:   fg(p.Info),
		Notice: fg(p.Muted).Italic(true),

		DiffAdd:     fg(p.Success),
		DiffDel:     fg(p.Error),
		DiffAddWord: fg(p.Text).Background(p.AddWord),
		DiffDelWord: fg(p.Text).Background(p.DelWord),
		DiffNo:      fg(p.Faint),

		Tones:    tones(p),
		Markdown: markdownStyle(p),
	}
}

func tones(p Palette) []lipgloss.Style {
	out := make([]lipgloss.Style, len(p.Tones))
	for i, c := range p.Tones {
		out[i] = lipgloss.NewStyle().Foreground(c).Bold(true)
	}
	return out
}

func (s Styles) Tone(name string) lipgloss.Style {
	if len(s.Tones) == 0 {
		return s.Heading
	}
	sum := 0
	for _, r := range name {
		sum = sum*31 + int(r)
	}
	return s.Tones[(sum%len(s.Tones)+len(s.Tones))%len(s.Tones)]
}
