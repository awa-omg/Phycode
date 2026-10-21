package view

import (
	"fmt"
	"strconv"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
)

const (
	diffHunkLines   = 40
	cardDiffRows    = 20
	previewDiffRows = 30
	splitMinWidth   = 120
	splitSideMin    = 24
)

const diffSeparator = " │ "

var blankSeg = segment{text: " ", style: lipgloss.NewStyle()}

type diffRow struct {
	text  string
	lines int
}

func splitView(mode string, screenW, w int) bool {
	split := mode == "split" || (mode != "unified" && screenW >= splitMinWidth)
	left, _ := sideWidths(w)
	return split && left >= splitSideMin
}

func sideWidths(w int) (left, right int) {
	left = (w - 3) / 2
	return left, w - 3 - left
}

func (r *Renderer) diffHead(prefix string, d protocol.Diff, w int) string {
	counts := r.styles.OK.Render(fmt.Sprintf("+%d", d.Added)) + " " + r.styles.Fail.Render(fmt.Sprintf("−%d", d.Removed))
	head := prefix
	if path, room := oneLine(d.Path), w-lipgloss.Width(prefix)-lipgloss.Width(counts)-3; path != "" && room > 0 {
		if head != "" {
			head += " "
		}
		head += r.styles.Output.Render(ellipsize(path, room))
	}
	if head != "" {
		head += "  "
	}
	return cut(head+counts, w)
}

func (r *Renderer) cardDiff(d protocol.Diff, w int, split, expanded bool) []string {
	if expanded {
		return r.diffBody(d, d.Hunks, w, split, 0, r.hiddenNote)
	}
	hunks := d.Hunks[:min(len(d.Hunks), 1)]
	if len(hunks) == 1 && len(hunks[0].Lines) > diffHunkLines {
		hunks = nil
	}
	return r.diffBody(d, hunks, w, split, cardDiffRows, r.hiddenNote)
}

func (r *Renderer) previewRows(d protocol.Diff, w, maxRows int) []string {
	limit := min(maxRows, previewDiffRows)
	if limit <= 0 {
		return nil
	}
	rows := []string{fit(r.diffHead("", d, w), w)}
	if limit > 1 {
		rows = append(rows, r.diffBody(d, d.Hunks, w, false, limit-1, r.moreNote)...)
	}
	return rows
}

func (r *Renderer) diffBody(d protocol.Diff, hunks []protocol.Hunk, w int, split bool, limit int, note func(int) string) []string {
	if d.Binary {
		return []string{fit(r.styles.Dim.Render("binary file changed"), w)}
	}
	out := clip(r.diffRows(d, hunks, w, split), diffLineCount(d.Hunks), limit, note)
	for i := range out {
		out[i] = fit(out[i], w)
	}
	return out
}

func (r *Renderer) diffRows(d protocol.Diff, hunks []protocol.Hunk, w int, split bool) []diffRow {
	numW := numberWidth(hunks)
	var rows []diffRow
	for _, h := range hunks {
		if len(d.Hunks) > 1 {
			rows = append(rows, diffRow{text: r.styles.Dim.Render(hunkHeader(h))})
		}
		rows = append(rows, r.lineRows(h.Lines, numW, w, split)...)
	}
	return rows
}

func (r *Renderer) lineRows(lines []protocol.DiffLine, numW, w int, split bool) []diffRow {
	partner := pairLines(lines)
	text := r.lineText(lines, partner)
	rows := make([]diffRow, 0, len(lines))
	if !split {
		for i, l := range lines {
			rows = append(rows, diffRow{text: r.unifiedRow(l, text[i], numW, w), lines: 1})
		}
		return rows
	}

	left, right := sideWidths(w)
	sep := r.styles.Dim.Render(diffSeparator)
	done := make([]bool, len(lines))
	for i, l := range lines {
		if done[i] {
			continue
		}
		switch {
		case l.Kind == protocol.DiffDel && partner[i] >= 0:
			j := partner[i]
			done[j] = true
			row := r.sideCell(l.OldNo, l.Kind, text[i], numW, left) + sep + r.sideCell(lines[j].NewNo, lines[j].Kind, text[j], numW, right)
			rows = append(rows, diffRow{text: row, lines: 2})
		case l.Kind == protocol.DiffDel:
			rows = append(rows, diffRow{text: r.sideCell(l.OldNo, l.Kind, text[i], numW, left) + sep + spaces(right), lines: 1})
		case l.Kind == protocol.DiffAdd:
			rows = append(rows, diffRow{text: spaces(left) + sep + r.sideCell(l.NewNo, l.Kind, text[i], numW, right), lines: 1})
		default:
			row := r.sideCell(l.OldNo, l.Kind, text[i], numW, left) + sep + r.sideCell(l.NewNo, l.Kind, text[i], numW, right)
			rows = append(rows, diffRow{text: row, lines: 1})
		}
	}
	return rows
}

func (r *Renderer) lineText(lines []protocol.DiffLine, partner []int) [][]segment {
	out := make([][]segment, len(lines))
	for i, l := range lines {
		out[i] = []segment{{text: oneLine(l.Text), style: r.lineStyle(l.Kind)}}
	}
	for i, l := range lines {
		if j := partner[i]; j >= 0 && l.Kind == protocol.DiffDel {
			out[i], out[j] = r.wordSegments(oneLine(l.Text), oneLine(lines[j].Text))
		}
	}
	return out
}

func (r *Renderer) unifiedRow(l protocol.DiffLine, text []segment, numW, w int) string {
	segs := append([]segment{
		r.numSegment(l.OldNo, numW), blankSeg, r.numSegment(l.NewNo, numW), blankSeg, r.signSegment(l.Kind), blankSeg,
	}, text...)
	return paint(fit(renderSegments(cutSegments(segs, w)), w), r.tint(l.Kind))
}

func (r *Renderer) sideCell(num *int, kind string, text []segment, numW, w int) string {
	segs := append([]segment{r.numSegment(num, numW), blankSeg, r.signSegment(kind), blankSeg}, text...)
	return paint(fit(renderSegments(cutSegments(segs, w)), w), r.tint(kind))
}

func (r *Renderer) tint(kind string) string {
	switch kind {
	case protocol.DiffAdd:
		return r.bg.add
	case protocol.DiffDel:
		return r.bg.del
	}
	return ""
}

func (r *Renderer) numSegment(num *int, numW int) segment {
	if num == nil {
		return segment{text: spaces(numW), style: r.styles.DiffNo}
	}
	return segment{text: fmt.Sprintf("%*d", numW, *num), style: r.styles.DiffNo}
}

func (r *Renderer) signSegment(kind string) segment {
	switch kind {
	case protocol.DiffAdd:
		return segment{text: "+", style: r.styles.DiffAdd}
	case protocol.DiffDel:
		return segment{text: "-", style: r.styles.DiffDel}
	}
	return blankSeg
}

func (r *Renderer) lineStyle(kind string) lipgloss.Style {
	switch kind {
	case protocol.DiffAdd:
		return r.styles.DiffAdd
	case protocol.DiffDel:
		return r.styles.DiffDel
	}
	return r.styles.Output
}

func (r *Renderer) hiddenNote(n int) string {
	return r.styles.Dim.Render(fmt.Sprintf("… %d %s hidden (ctrl+o to expand)", n, plural(n, "line", "lines")))
}

func (r *Renderer) moreNote(n int) string {
	return r.styles.Dim.Render(fmt.Sprintf("… %d more %s", n, plural(n, "line", "lines")))
}

func clip(rows []diffRow, total, limit int, note func(int) string) []string {
	if shown := rowLines(rows); shown == total && (limit <= 0 || len(rows) <= limit) {
		return rowTexts(rows)
	}
	if limit > 0 && len(rows) > limit-1 {
		rows = rows[:max(limit-1, 0)]
	}
	out := rowTexts(rows)
	if hidden := total - rowLines(rows); hidden > 0 {
		out = append(out, note(hidden))
	}
	return out
}

func rowLines(rows []diffRow) int {
	n := 0
	for _, row := range rows {
		n += row.lines
	}
	return n
}

func rowTexts(rows []diffRow) []string {
	out := make([]string, len(rows))
	for i, row := range rows {
		out[i] = row.text
	}
	return out
}

func diffLineCount(hunks []protocol.Hunk) int {
	n := 0
	for _, h := range hunks {
		n += len(h.Lines)
	}
	return n
}

func numberWidth(hunks []protocol.Hunk) int {
	most := 1
	for _, h := range hunks {
		for _, l := range h.Lines {
			for _, n := range []*int{l.OldNo, l.NewNo} {
				if n != nil {
					most = max(most, len(strconv.Itoa(*n)))
				}
			}
		}
	}
	return most
}

func hunkHeader(h protocol.Hunk) string {
	return fmt.Sprintf("@@ -%d,%d +%d,%d @@", h.OldStart, h.OldLines, h.NewStart, h.NewLines)
}

func pairLines(lines []protocol.DiffLine) []int {
	partner := make([]int, len(lines))
	for i := range partner {
		partner[i] = -1
	}
	for i := 0; i < len(lines); {
		if lines[i].Kind != protocol.DiffDel {
			i++
			continue
		}
		j := i
		for j < len(lines) && lines[j].Kind == protocol.DiffDel {
			j++
		}
		k := j
		for k < len(lines) && lines[k].Kind == protocol.DiffAdd {
			k++
		}
		for t := 0; t < min(j-i, k-j); t++ {
			partner[i+t], partner[j+t] = j+t, i+t
		}
		i = k
	}
	return partner
}
