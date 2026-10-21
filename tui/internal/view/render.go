package view

import (
	"regexp"
	"strings"
	"unicode"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

var ansiPattern = regexp.MustCompile(`\x1b\[[0-9;?]*[ -/]*[@-~]`)

type Renderer struct {
	styles  theme.Styles
	bg      surfaces
	md      map[mdKey]mdRender
	mdOrder []mdKey
	mdBytes int
}

func NewRenderer(styles theme.Styles) *Renderer {
	return &Renderer{styles: styles, bg: surfacesOf(styles.P), md: make(map[mdKey]mdRender)}
}

type screenLayout struct {
	transcript int
	view       int
	box        int
	dock       []string
	pad        int
	input      []string
	cursorRow  int
	status     bool
}

func (r *Renderer) layout(m state.Model) screenLayout {
	var lay screenLayout
	lay.status = m.Height >= 2
	room := m.Height - 1
	if lay.status {
		room--
	}
	if dock := r.dock(m, m.Width, m.Height); dock != nil && room >= 2 {
		lay.box = min(len(dock), room-1)
		lay.dock = dock[:lay.box]
		lay.transcript = room - lay.box
		lay.view = lay.transcript
		return lay
	}
	lay.input, lay.cursorRow = r.inputLines(m, m.Width)
	if m.Height >= 14 {
		lay.pad = 1
	}
	lay.box = min(len(lay.input), maxInputRows) + 2*lay.pad
	if room-1 < lay.box {
		lay.box, lay.pad = min(lay.box, max(room-1, min(1, room))), 0
	}
	lay.transcript = room - lay.box
	lay.view = lay.transcript
	if lay.transcript >= 4 {
		lay.view--
	}
	return lay
}

func (r *Renderer) View(m state.Model) string {
	if m.Width <= 0 || m.Height <= 0 {
		return ""
	}
	w, h := m.Width, m.Height
	lay := r.layout(m)

	transcript := r.transcript(m, w, lay.view)
	transcript = append(transcript, make([]string, lay.transcript-lay.view)...)
	switch {
	case m.Confirm != nil:
		transcript = r.overlayApproval(transcript, *m.Confirm, w)
	case lay.dock == nil:
		transcript = r.overlaySuggestions(transcript, m, w)
	}

	lines := []string{r.header(m, w)}
	lines = append(lines, transcript...)
	if lay.dock != nil {
		lines = append(lines, lay.dock...)
	} else {
		lines = append(lines, r.composer(m, w, lay.input, lay.cursorRow, lay.box, lay.pad)...)
	}
	if lay.status {
		lines = append(lines, r.status(m, w))
	}
	lines = lines[:min(len(lines), h)]
	for len(lines) < h {
		lines = append(lines, "")
	}
	for i := range lines {
		lines[i] = band(lines[i], w, r.bg.page)
	}
	return strings.Join(lines, "\n")
}

func sanitize(s string) string {
	s = ansiPattern.ReplaceAllString(s, "")
	s = strings.ReplaceAll(s, "\t", "    ")
	return strings.Map(func(r rune) rune {
		if r == '\n' {
			return r
		}
		if unicode.IsControl(r) || (unicode.Is(unicode.Cf, r) && r != '\u200c' && r != '\u200d') {
			return -1
		}
		return r
	}, s)
}

func Sanitize(s string) string {
	return sanitize(s)
}

func oneLine(s string) string {
	return strings.ReplaceAll(sanitize(s), "\n", " ")
}

func cellWidth(r rune) int {
	if r < 0x7f {
		if r < 0x20 {
			return 0
		}
		return 1
	}
	return lipgloss.Width(string(r))
}

func wrapSpans(rs []rune, width int) []int {
	starts := []int{0}
	start := 0
	for {
		cells, stop := 0, start
		for stop < len(rs) {
			cw := cellWidth(rs[stop])
			if cells+cw > width && stop > start {
				break
			}
			cells += cw
			stop++
		}
		if stop == len(rs) {
			return starts
		}
		next := stop
		for i := stop; i > start; i-- {
			if rs[i] == ' ' {
				next = i
				for next < len(rs) && rs[next] == ' ' {
					next++
				}
				if next == len(rs) {
					return starts
				}
				break
			}
		}
		starts = append(starts, next)
		start = next
	}
}

func wrapText(s string, width int) []string {
	width = max(width, 1)
	var rows []string
	for _, line := range strings.Split(s, "\n") {
		rs := []rune(line)
		starts := wrapSpans(rs, width)
		for i, start := range starts {
			end := len(rs)
			if i+1 < len(starts) {
				end = starts[i+1]
			}
			rows = append(rows, strings.TrimRight(string(rs[start:end]), " "))
		}
	}
	return rows
}

func cut(s string, n int) string {
	if n <= 0 {
		return ""
	}
	if lipgloss.Width(s) <= n {
		return s
	}
	out := lipgloss.NewStyle().MaxWidth(n).Render(s)
	if strings.Contains(s, "\x1b") {
		out += "\x1b[0m"
	}
	return out
}

func ellipsize(s string, n int) string {
	if n <= 0 {
		return ""
	}
	if lipgloss.Width(s) <= n {
		return s
	}
	return cut(s, n-1) + "…"
}

func fit(s string, n int) string {
	if n <= 0 {
		return ""
	}
	s = cut(s, n)
	return s + spaces(n-lipgloss.Width(s))
}

func padRight(s string, n int) string {
	return s + spaces(n-lipgloss.Width(s))
}

func spaces(n int) string {
	if n <= 0 {
		return ""
	}
	return strings.Repeat(" ", n)
}

func clamp(v, lo, hi int) int {
	return min(max(v, lo), hi)
}

func plural(n int, one, many string) string {
	if n == 1 {
		return one
	}
	return many
}

func isBlank(line string) bool {
	return strings.TrimSpace(ansiPattern.ReplaceAllString(line, "")) == ""
}

func trimBlank(s string) string {
	return strings.Join(trimBlankLines(strings.Split(s, "\n")), "\n")
}

func trimBlankLines(lines []string) []string {
	for len(lines) > 0 && isBlank(lines[0]) {
		lines = lines[1:]
	}
	for len(lines) > 0 && isBlank(lines[len(lines)-1]) {
		lines = lines[:len(lines)-1]
	}
	return lines
}

type segment struct {
	text  string
	style lipgloss.Style
}

func segmentsWidth(segs []segment) int {
	total := 0
	for _, s := range segs {
		total += lipgloss.Width(s.text)
	}
	return total
}

func renderSegments(segs []segment) string {
	var b strings.Builder
	for _, s := range segs {
		if s.text != "" {
			b.WriteString(s.style.Render(s.text))
		}
	}
	return b.String()
}

func cutSegments(segs []segment, n int) []segment {
	if n <= 0 {
		return nil
	}
	if segmentsWidth(segs) <= n {
		return segs
	}
	budget := n - 1
	var out []segment
	for _, s := range segs {
		if w := lipgloss.Width(s.text); w <= budget {
			out = append(out, s)
			budget -= w
		} else {
			return append(out, segment{text: cut(s.text, budget) + "…", style: s.style})
		}
	}
	return out
}
