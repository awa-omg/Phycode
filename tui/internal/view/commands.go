package view

import (
	"fmt"
	"math"
	"strconv"
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func (r *Renderer) contextBody(m state.Model, iw int) ([]string, int) {
	c := m.Context
	var rows []string
	known := c.Used != nil && c.Limit != nil && *c.Limit > 0
	rows = append(rows, r.contextFigure(c, iw))
	if known {
		rows = append(rows, r.contextBar(c, iw))
	}
	if len(c.Breakdown) > 0 {
		rows = append(rows, "")
		total := 0
		if known {
			total = *c.Limit
		}
		used := 0
		for i, part := range c.Breakdown {
			used += part.Tokens
			rows = append(rows, r.breakdownRow(part, r.partStyle(i), total, iw))
		}
		if known && *c.Limit > used {
			rows = append(rows, r.freeRow(*c.Limit-used, *c.Limit, iw))
		}
	}
	rows = append(rows, "", r.contextFacts(c, iw))
	return rows, -1
}

func (r *Renderer) contextFigure(c protocol.Context, iw int) string {
	pct, known := contextPercentOf(c)
	text := "–"
	switch {
	case c.Used != nil && c.Limit != nil && *c.Limit > 0:
		text = fmt.Sprintf("%s of %s tokens · %d%%", tokenLabel(*c.Used), tokenLabel(*c.Limit), pct)
	case c.Used != nil:
		text = tokenLabel(*c.Used) + " tokens used"
	case known:
		text = fmt.Sprintf("%d%%", pct)
	}
	left := r.styles.Heading.Render(ellipsize(text, iw))
	note := "autocompact " + autocompactLabel(c)
	if gap := iw - lipgloss.Width(text) - lipgloss.Width(note) - 2; gap >= 0 {
		return fit(left+spaces(gap+2)+r.styles.Dim.Render(note), iw)
	}
	return fit(left, iw)
}

func (r *Renderer) contextBar(c protocol.Context, iw int) string {
	limit := *c.Limit
	cells := make([]segment, 0, len(c.Breakdown)+1)
	used, filled := 0, 0
	for i, part := range c.Breakdown {
		used += part.Tokens
		n := min(int(math.Round(float64(used)/float64(limit)*float64(iw)))-filled, iw-filled)
		if n <= 0 && part.Tokens > 0 && filled < iw {
			n = 1
		}
		filled += max(n, 0)
		cells = append(cells, segment{text: strings.Repeat("█", max(n, 0)), style: r.partStyle(i)})
	}
	if len(c.Breakdown) == 0 {
		n := min(int(math.Round(float64(*c.Used)/float64(limit)*float64(iw))), iw)
		cells, filled = append(cells, segment{text: strings.Repeat("█", n), style: r.styles.Emphasis}), n
	}
	cells = append(cells, segment{text: strings.Repeat("░", max(iw-filled, 0)), style: r.styles.Faint})
	if c.AutoCompact && c.Threshold != nil && *c.Threshold > 0 && *c.Threshold < limit {
		at := min(int(math.Round(float64(*c.Threshold)/float64(limit)*float64(iw))), iw-1)
		return fit(renderSegments(withTick(cells, at, segment{text: "┃", style: r.styles.Warn})), iw)
	}
	return fit(renderSegments(cells), iw)
}

func (r *Renderer) partStyle(i int) lipgloss.Style {
	return r.styles.Tones[i%len(r.styles.Tones)]
}

func withTick(cells []segment, at int, tick segment) []segment {
	var out []segment
	pos := 0
	for _, seg := range cells {
		n := lipgloss.Width(seg.text)
		if at >= pos && at < pos+n {
			rs := []rune(seg.text)
			out = append(out, segment{text: string(rs[:at-pos]), style: seg.style}, tick, segment{text: string(rs[at-pos+1:]), style: seg.style})
		} else {
			out = append(out, seg)
		}
		pos += n
	}
	return out
}

func (r *Renderer) breakdownRow(part protocol.ContextPart, swatch lipgloss.Style, limit, iw int) string {
	share := ""
	if limit > 0 {
		share = fmt.Sprintf("%3d%%", int(math.Round(float64(part.Tokens)/float64(limit)*100)))
	}
	return r.legendRow("■", swatch, oneLine(part.Category), tokenLabel(part.Tokens), share, iw)
}

func (r *Renderer) freeRow(free, limit, iw int) string {
	return r.legendRow("░", r.styles.Faint, "free", tokenLabel(free), fmt.Sprintf("%3d%%", int(math.Round(float64(free)/float64(limit)*100))), iw)
}

func (r *Renderer) legendRow(mark string, swatch lipgloss.Style, name, tokens, share string, iw int) string {
	right := tokens
	if share != "" {
		right = padRight(tokens, 6) + share
	}
	name = ellipsize(name, max(iw-2-lipgloss.Width(right)-2, 1))
	gap := iw - 2 - lipgloss.Width(name) - lipgloss.Width(right)
	return fit(swatch.Render(mark)+" "+r.styles.Output.Render(name)+spaces(gap)+r.styles.Dim.Render(right), iw)
}

func (r *Renderer) contextFacts(c protocol.Context, iw int) string {
	text := "max " + maxLabel(c)
	if c.Messages != nil {
		text += " · " + strconv.Itoa(*c.Messages) + " " + plural(*c.Messages, "message", "messages")
	}
	return r.styles.Dim.Render(ellipsize(text, iw))
}

func (r *Renderer) compactBody(m state.Model, iw int) ([]string, int) {
	if m.Compacting {
		return []string{r.styles.Dim.Render("compacting…")}, -1
	}
	return []string{r.typeLine(m.Panel.Input, "optional instructions", iw)}, 0
}

func (r *Renderer) typeLine(input, placeholder string, iw int) string {
	return fit(renderSegments(cutSegments(r.typeSegments(input, placeholder, iw), iw)), iw)
}

func (r *Renderer) typeSegments(input, placeholder string, iw int) []segment {
	segs := []segment{
		{text: "❯ ", style: r.styles.Emphasis},
		{text: tailCut(input, max(iw-3, 0)), style: r.styles.Output},
		{text: "█", style: r.styles.Emphasis},
	}
	if input == "" {
		segs = append(segs, segment{text: " " + placeholder, style: r.styles.Dim})
	}
	return segs
}

func maxLabel(c protocol.Context) string {
	switch {
	case c.Max != nil:
		return tokenLabel(*c.Max)
	case c.Limit != nil:
		return "off · the effective window"
	}
	return "off"
}

func autocompactLabel(c protocol.Context) string {
	switch {
	case !c.AutoCompact && c.AutoCompactSwitch != nil && *c.AutoCompactSwitch:
		return "off until context.max is set"
	case !c.AutoCompact:
		return "off"
	case c.Threshold != nil:
		return "at " + tokenLabel(*c.Threshold)
	}
	return "–"
}

func tokenLabel(n int) string {
	switch {
	case n < 1000:
		return strconv.Itoa(n)
	case n < 10000:
		return strconv.FormatFloat(math.Round(float64(n)/100)/10, 'f', -1, 64) + "k"
	case n < 999500:
		return strconv.Itoa((n+500)/1000) + "k"
	}
	return strconv.FormatFloat(math.Round(float64(n)/100000)/10, 'f', -1, 64) + "M"
}
