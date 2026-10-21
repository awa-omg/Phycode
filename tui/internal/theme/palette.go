package theme

import "github.com/charmbracelet/lipgloss"

type Palette struct {
	Name string
	Dark bool

	Bg, Raised, Overlay, Select lipgloss.Color

	Text, Muted, Faint lipgloss.Color
	Border             lipgloss.Color

	Accent                        lipgloss.Color
	Success, Warning, Error, Info lipgloss.Color

	AddBg, DelBg, AddWord, DelWord lipgloss.Color

	Comment, Keyword, Function, String, Number, Type, Operator, Link lipgloss.Color

	Tones []lipgloss.Color
}

type seed struct {
	name                                          string
	dark                                          bool
	bg, text, accent                              string
	success, warning, err, info                   string
	comment, keyword, function, str, num, typ, op string
	link                                          string
	tones                                         []string
}

const (
	NamePhycode      = "phycode"
	NamePhycodeLight = "phycode-light"
	NameTokyoNight   = "tokyo-night"
	NameCatppuccin   = "catppuccin"
	NameGruvbox      = "gruvbox"
	NameRosePine     = "rose-pine"
	NameNord         = "nord"
	NameSolarized    = "solarized-dark"
	NamePaper        = "paper"
	NameMono         = "mono"
)

var seeds = []seed{
	{
		name: NamePhycode, dark: true,
		bg: "#0e1218", text: "#d9dee7", accent: "#58d6b8",
		success: "#7bd88f", warning: "#f2c36b", err: "#ff6b81", info: "#6cb6ff",
		comment: "#6b7588", keyword: "#c792ea", function: "#82aaff", str: "#a5e075", num: "#f78c6c", typ: "#ffcb6b", op: "#89ddff",
		link:  "#6cb6ff",
		tones: []string{"#82aaff", "#c792ea", "#ff9e64", "#ff79c6", "#8be9fd"},
	},
	{
		name: NamePhycodeLight, dark: false,
		bg: "#fafaf8", text: "#1d2330", accent: "#007a65",
		success: "#1c6e30", warning: "#9a5b00", err: "#b8212a", info: "#1559c4",
		comment: "#7a8496", keyword: "#7a3fd1", function: "#1559c4", str: "#25702e", num: "#b4470c", typ: "#8a5a00", op: "#0b6e82",
		link:  "#1559c4",
		tones: []string{"#1559c4", "#7a3fd1", "#b4470c", "#b02a77", "#0b6e82"},
	},
	{
		name: NameTokyoNight, dark: true,
		bg: "#1a1b26", text: "#c0caf5", accent: "#7aa2f7",
		success: "#9ece6a", warning: "#e0af68", err: "#f7768e", info: "#7dcfff",
		comment: "#565f89", keyword: "#bb9af7", function: "#7aa2f7", str: "#9ece6a", num: "#ff9e64", typ: "#2ac3de", op: "#89ddff",
		link:  "#7dcfff",
		tones: []string{"#7dcfff", "#bb9af7", "#ff9e64", "#73daca", "#f7768e"},
	},
	{
		name: NameCatppuccin, dark: true,
		bg: "#1e1e2e", text: "#cdd6f4", accent: "#cba6f7",
		success: "#a6e3a1", warning: "#f9e2af", err: "#f38ba8", info: "#89dceb",
		comment: "#6c7086", keyword: "#cba6f7", function: "#89b4fa", str: "#a6e3a1", num: "#fab387", typ: "#f9e2af", op: "#89dceb",
		link:  "#89b4fa",
		tones: []string{"#89b4fa", "#cba6f7", "#fab387", "#94e2d5", "#f5c2e7"},
	},
	{
		name: NameGruvbox, dark: true,
		bg: "#282828", text: "#ebdbb2", accent: "#fabd2f",
		success: "#b8bb26", warning: "#fe8019", err: "#ff7a66", info: "#83a598",
		comment: "#928374", keyword: "#ff7a66", function: "#b8bb26", str: "#b8bb26", num: "#d3869b", typ: "#fabd2f", op: "#8ec07c",
		link:  "#83a598",
		tones: []string{"#83a598", "#d3869b", "#fe8019", "#8ec07c", "#fabd2f"},
	},
	{
		name: NameRosePine, dark: true,
		bg: "#191724", text: "#e0def4", accent: "#c4a7e7",
		success: "#9ccfd8", warning: "#f6c177", err: "#eb6f92", info: "#8fb8d8",
		comment: "#6e6a86", keyword: "#9ccfd8", function: "#ebbcba", str: "#f6c177", num: "#eb6f92", typ: "#c4a7e7", op: "#908caa",
		link:  "#9ccfd8",
		tones: []string{"#9ccfd8", "#c4a7e7", "#f6c177", "#ebbcba", "#eb6f92"},
	},
	{
		name: NameNord, dark: true,
		bg: "#2e3440", text: "#e5e9f0", accent: "#88c0d0",
		success: "#aec797", warning: "#ebcb8b", err: "#ee9aa2", info: "#8fb0d0",
		comment: "#616e88", keyword: "#8fb0d0", function: "#88c0d0", str: "#aec797", num: "#c39bbd", typ: "#8fbcbb", op: "#81a1c1",
		link:  "#88c0d0",
		tones: []string{"#88c0d0", "#b48ead", "#d08770", "#a3be8c", "#ebcb8b"},
	},
	{
		name: NameSolarized, dark: true,
		bg: "#002b36", text: "#c0cdcd", accent: "#4aa9ee",
		success: "#a6bf1a", warning: "#dcab26", err: "#fa7a77", info: "#35b3a8",
		comment: "#586e75", keyword: "#9bb300", function: "#4aa9ee", str: "#35b3a8", num: "#ea62a9", typ: "#d3a017", op: "#c0cdcd",
		link:  "#4aa9ee",
		tones: []string{"#4aa9ee", "#8a90e0", "#ec7338", "#35b3a8", "#ea62a9"},
	},
	{
		name: NamePaper, dark: false,
		bg: "#fbf7ee", text: "#2a2823", accent: "#2f5fa7",
		success: "#26702a", warning: "#8a5a00", err: "#b3261e", info: "#1a6f8f",
		comment: "#7d776a", keyword: "#7a3e9d", function: "#2f5fa7", str: "#26702a", num: "#b3541e", typ: "#8a5a00", op: "#1a6f8f",
		link:  "#2f5fa7",
		tones: []string{"#2f5fa7", "#7a3e9d", "#b3541e", "#1a6f8f", "#a23b72"},
	},
	{
		name: NameMono, dark: true,
		bg: "#0c0c0c", text: "#d7d7d7", accent: "#ffffff",
		success: "#e4e4e4", warning: "#cfcfcf", err: "#f5f5f5", info: "#bdbdbd",
		comment: "#7a7a7a", keyword: "#ffffff", function: "#e4e4e4", str: "#bdbdbd", num: "#cfcfcf", typ: "#e4e4e4", op: "#9e9e9e",
		link:  "#ffffff",
		tones: []string{"#bcbcbc", "#a8a8a8", "#e0e0e0", "#9e9e9e"},
	},
}

func Names() []string {
	out := make([]string, len(seeds))
	for i, s := range seeds {
		out[i] = s.name
	}
	return out
}

func Lookup(name string) Palette {
	for _, s := range seeds {
		if s.name == name {
			return s.palette()
		}
	}
	return seeds[0].palette()
}

func (s seed) palette() Palette {
	step := func(t float64) string { return mix(s.bg, s.text, t) }
	raised, overlay, sel, border, faint, muted := 0.055, 0.09, 0.26, 0.22, 0.42, 0.70
	tint, word := 0.14, 0.28
	if !s.dark {
		raised, overlay, sel, border, faint, muted = 0.045, 0.08, 0.20, 0.24, 0.50, 0.72
		tint, word = 0.12, 0.26
	}
	c := func(hex string) lipgloss.Color { return lipgloss.Color(hex) }
	p := Palette{
		Name: s.name, Dark: s.dark,
		Bg: c(s.bg), Raised: c(step(raised)), Overlay: c(step(overlay)), Select: c(mix(s.bg, s.accent, sel)),
		Text: c(s.text), Muted: c(step(muted)), Faint: c(step(faint)), Border: c(step(border)),
		Accent:  c(s.accent),
		Success: c(s.success), Warning: c(s.warning), Error: c(s.err), Info: c(s.info),
		AddBg: c(mix(s.bg, s.success, tint)), DelBg: c(mix(s.bg, s.err, tint)),
		AddWord: c(mix(s.bg, s.success, word)), DelWord: c(mix(s.bg, s.err, word)),
		Comment: c(s.comment), Keyword: c(s.keyword), Function: c(s.function), String: c(s.str),
		Number: c(s.num), Type: c(s.typ), Operator: c(s.op), Link: c(s.link),
	}
	for _, t := range s.tones {
		p.Tones = append(p.Tones, c(t))
	}
	return p
}
