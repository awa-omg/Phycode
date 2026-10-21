package view

import (
	"fmt"
	"math"
	"strings"
	"sync"
	"time"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

const (
	maxScroll      = 1 << 30
	maxErrorLines  = 4
	maxTaskLines   = 3
	maxReportLines = 6
	maxTreeRows    = 4
	maxHint        = 32
)

const (
	margin      = 1
	textInset   = 3
	rightMargin = 2
	nestIndent  = 2
)

func (r *Renderer) transcript(m state.Model, w, h int) []string {
	if h <= 0 {
		return nil
	}
	if len(transcriptBlocks(m)) == 0 {
		if overlayOpen(m) {
			return make([]string, h)
		}
		return r.welcome(m, w, h)
	}

	scroll := clamp(m.Scroll, 0, maxScroll)
	lines := r.tailLines(m, w, scroll+h)
	scroll = min(scroll, max(0, len(lines)-h))
	end := len(lines) - scroll
	out := make([]string, 0, h)
	for i := end - h; i < end; i++ {
		if i < 0 {
			out = append(out, "")
		} else {
			out = append(out, lines[i])
		}
	}
	return out
}

func overlayOpen(m state.Model) bool {
	return m.Picker.Open || m.Panel.Open || m.Ask != nil || m.Exec != nil || m.Confirm != nil || len(m.Suggestions) > 0 || m.NoMatch != ""
}

func transcriptBlocks(m state.Model) []state.Block {
	blocks := m.Blocks
	if m.Error != "" {
		blocks = append(blocks[:len(blocks):len(blocks)], state.Block{Kind: state.KindNotice, Level: "error", Text: m.Error})
	}
	return blocks
}

func tight(a state.Block, aRows int, b state.Block, bRows int) bool {
	return a.Kind == state.KindTool && b.Kind == state.KindTool && a.Depth == b.Depth && aRows == 1 && bRows == 1
}

func (r *Renderer) tailLines(m state.Model, w, need int) []string {
	blocks := transcriptBlocks(m)
	hidden := hiddenBlocks(blocks)
	var lines []string
	var below state.Block
	belowRows := 0
	for i := len(blocks) - 1; i >= 0 && len(lines) < need; i-- {
		if hidden[i] || compacted(blocks[i], m) {
			continue
		}
		rows := r.block(blocks[i], max(w, 1), m)
		if len(rows) == 0 {
			continue
		}
		count := len(rows)
		if len(lines) > 0 && !tight(blocks[i], count, below, belowRows) {
			rows = append(rows, spaces(w))
		}
		lines = append(rows, lines...)
		below, belowRows = blocks[i], count
	}
	return lines
}

func hiddenBlocks(blocks []state.Block) []bool {
	hidden := make([]bool, len(blocks))
	closed := map[string]bool{}
	for i, b := range blocks {
		hidden[i] = b.Parent != "" && closed[b.Parent]
		if b.Kind == state.KindSubagent && (hidden[i] || b.Folded) {
			closed[b.ID] = true
		}
	}
	return hidden
}

var (
	measureMu sync.Mutex
	measurers = map[string]*Renderer{}
)

func measurerOf(m state.Model) *Renderer {
	p := theme.Lookup(m.Theme)
	r, ok := measurers[p.Name]
	if !ok {
		r = NewRenderer(theme.Build(p))
		measurers[p.Name] = r
	}
	return r
}

func MaxScroll(m state.Model) int {
	if m.Width <= 0 || m.Height <= 0 {
		return 0
	}
	measureMu.Lock()
	defer measureMu.Unlock()
	r := measurerOf(m)
	rows := len(r.tailLines(m, m.Width, math.MaxInt))
	return max(0, rows-r.layout(m).view)
}

func (r *Renderer) block(b state.Block, w int, m state.Model) []string {
	switch b.Kind {
	case state.KindUser:
		return r.userBlock(b, w)
	case state.KindAssistant:
		return r.assistantBlock(b, w)
	case state.KindTool:
		return r.toolBlock(b, w, m)
	case state.KindSubagent:
		return r.subagentBlock(b, w, m)
	case state.KindNotice:
		return r.noticeBlock(b, w)
	case state.KindOutput:
		return r.outputBlock(b, w)
	}
	return nil
}

func (r *Renderer) bandRow(ind int, bar, content string, w int) string {
	left := margin + ind
	inner := max(w-left-margin, 0)
	return fit(spaces(left)+paint(fit(bar+" "+content, inner), r.bg.raised), w)
}

func textWidth(w, ind int) int {
	return max(w-textInset-ind-rightMargin, 1)
}

func bandWidth(w, ind int) int {
	return max(w-margin-ind-margin-3, 1)
}

func (r *Renderer) userBlock(b state.Block, w int) []string {
	text := trimBlank(sanitize(b.Text))
	if text == "" {
		return nil
	}
	ind := nestIndent * b.Depth
	bar := r.styles.Emphasis.Render("▌")
	var out []string
	for _, row := range wrapText(text, bandWidth(w, ind)) {
		out = append(out, r.bandRow(ind, bar, r.styles.Heading.Render(row), w))
	}
	return out
}

func (r *Renderer) assistantBlock(b state.Block, w int) []string {
	ind := nestIndent * b.Depth
	lines := r.markdown(sanitize(b.Text), textWidth(w, ind))
	out := make([]string, len(lines))
	for i, line := range lines {
		mark := " "
		if i == 0 {
			mark = r.styles.Accent.Render("●")
		}
		out[i] = fit(spaces(margin+ind)+mark+" "+line, w)
	}
	return out
}

func (r *Renderer) toolBlock(b state.Block, w int, m state.Model) []string {
	ind := nestIndent * b.Depth
	icon, style := r.toolIcon(b.Status, m.Frame)
	tool := oneLine(b.Tool)
	left := []segment{{text: icon, style: style}, {text: " "}, {text: tool, style: r.styles.Heading}}
	summary := withoutToolName(tool, oneLine(b.Summary))
	if b.Diff != nil && b.Diff.Path != "" {
		summary = oneLine(b.Diff.Path)
	}
	if summary != "" {
		left = append(left, segment{text: "  " + summary, style: r.styles.Dim})
	}
	var right []segment
	if d := b.Diff; d != nil {
		right = []segment{
			{text: fmt.Sprintf("+%d", d.Added), style: r.styles.OK}, {text: " "},
			{text: fmt.Sprintf("−%d", d.Removed), style: r.styles.Fail},
		}
	} else if b.Status == "ok" {
		if hint := resultHint(b.Text); hint != "" {
			right = []segment{{text: hint, style: r.styles.Dim}}
		}
	}
	out := []string{r.toolLine(ind, left, right, w)}

	if d := b.Diff; d != nil {
		dw := max(w-margin-ind-nestIndent-rightMargin, 1)
		for _, row := range r.cardDiff(*d, dw, splitView(m.DiffView, m.Width, dw), m.ToolsExpanded) {
			out = append(out, fit(spaces(margin+ind+nestIndent)+row, w))
		}
		return out
	}
	text := trimBlank(sanitize(b.Text))
	switch {
	case text == "":
	case m.ToolsExpanded:
		out = append(out, r.outputRows(text, ind+nestIndent, w, math.MaxInt, r.styles.Faint.Render("▎"))...)
	case b.Status == "error":
		out = append(out, r.outputRows(text, ind+nestIndent, w, maxErrorLines, r.styles.Fail.Render("▎"))...)
	}
	return out
}

func (r *Renderer) toolLine(ind int, left, right []segment, w int) string {
	room := max(w-margin-ind-rightMargin, 0)
	if segmentsWidth(right) > 0 && room-segmentsWidth(right)-2 < room/3 {
		right = nil
	}
	if segmentsWidth(right) > 0 {
		left = cutSegments(left, room-segmentsWidth(right)-2)
	} else {
		left = cutSegments(left, room)
	}
	gap := room - segmentsWidth(left) - segmentsWidth(right)
	return fit(spaces(margin+ind)+renderSegments(left)+spaces(gap)+renderSegments(right), w)
}

func resultHint(text string) string {
	text = trimBlank(sanitize(text))
	if text == "" {
		return ""
	}
	lines := strings.Split(text, "\n")
	first := strings.TrimSpace(lines[0])
	if len(lines) == 1 {
		return ellipsize(first, maxHint)
	}
	return ellipsize(first, maxHint-4) + fmt.Sprintf(" +%d", len(lines)-1)
}

func (r *Renderer) outputRows(text string, ind, w, limit int, bar string) []string {
	var out []string
	for _, row := range r.resultLines(text, bandWidth(w, ind), limit, r.styles.Output) {
		out = append(out, r.bandRow(ind, bar, row, w))
	}
	return out
}

func withoutToolName(tool, summary string) string {
	switch {
	case tool == "":
		return summary
	case summary == tool:
		return ""
	}
	if rest, ok := strings.CutPrefix(summary, tool+" "); ok {
		return rest
	}
	return summary
}

func (r *Renderer) toolIcon(status string, frame int) (string, lipgloss.Style) {
	switch status {
	case "ok":
		return "✓", r.styles.OK
	case "error":
		return "✗", r.styles.Fail
	case "", "running":
		return spinnerFrame(frame), r.styles.Run
	}
	return "•", r.styles.Dim
}

func compacted(b state.Block, m state.Model) bool {
	return b.Kind == state.KindTool && b.Parent != "" && b.Diff == nil && !m.ToolsExpanded
}

func (r *Renderer) subagentBlock(b state.Block, w int, m state.Model) []string {
	ind := nestIndent * b.Depth
	cw := bandWidth(w, ind)
	agent := oneLine(b.Agent)
	tone := r.styles.Tone(agent)
	mark := "▾"
	if b.Folded {
		mark = "▸"
	}
	head := r.styles.Dim.Render(mark+" ") + tone.Render("◆ "+agent) + "  " + r.subagentState(b, m)
	calls := state.ToolCalls(m.Blocks, b.ID)
	if n := len(calls); n > 0 {
		head += r.styles.Dim.Render(fmt.Sprintf(" · %d %s", n, plural(n, "tool", "tools")))
	}
	if n := childCount(m.Blocks, b.ID); b.Folded && n > 0 {
		head += "  " + r.styles.Dim.Render(fmt.Sprintf("%d hidden", n))
	}
	lines := []string{cut(head, cw)}

	task := trimBlank(sanitize(b.Text))
	if task != "" {
		rows := wrapText(task, cw)
		if len(rows) > maxTaskLines {
			rows = rows[:maxTaskLines]
			last := rows[maxTaskLines-1]
			if lipgloss.Width(last) >= cw {
				last = cut(last, cw-1)
			}
			rows[maxTaskLines-1] = last + "…"
		}
		for _, row := range rows {
			lines = append(lines, r.styles.Output.Render(row))
		}
	}
	if !b.Folded {
		lines = append(lines, r.toolTree(calls, m, cw)...)
	}
	if report := trimBlank(sanitize(b.Summary)); report != "" {
		if task != "" {
			lines = append(lines, "")
		}
		lines = append(lines, r.resultLines(report, cw, rowLimit(m.ToolsExpanded, maxReportLines), r.styles.Output)...)
	}
	bar := tone.Render("▌")
	out := make([]string, len(lines))
	for i, line := range lines {
		out[i] = r.bandRow(ind, bar, line, w)
	}
	return out
}

func (r *Renderer) subagentState(b state.Block, m state.Model) string {
	took := ""
	if d := b.Elapsed(time.Now()); !b.Started.IsZero() {
		took = " " + state.FormatElapsed(d)
	}
	switch {
	case m.Waiting(b):
		return r.styles.Warn.Render("⚠ needs your approval" + took)
	case b.Status == "running":
		return r.styles.Run.Render(spinnerFrame(m.Frame) + " working" + took)
	case b.Status == "completed":
		return r.styles.OK.Render("✓ done" + took)
	}
	return r.styles.Fail.Render("✗ " + oneLine(b.Status) + took)
}

func (r *Renderer) toolTree(calls []state.Block, m state.Model, inner int) []string {
	var tree []state.Block
	for _, c := range calls {
		if compacted(c, m) {
			tree = append(tree, c)
		}
	}
	if len(tree) == 0 {
		return nil
	}
	var out []string
	if extra := len(tree) - maxTreeRows; extra > 0 {
		out = append(out, r.styles.Dim.Render(fmt.Sprintf("… %d earlier", extra)))
		tree = tree[extra:]
	}
	for i, c := range tree {
		glyph := "├─"
		if i == len(tree)-1 {
			glyph = "╰─"
		}
		out = append(out, r.treeLine(c, glyph, m.Frame, inner))
	}
	return out
}

func (r *Renderer) treeLine(b state.Block, glyph string, frame, inner int) string {
	icon, style := r.toolIcon(b.Status, frame)
	tool := oneLine(b.Tool)
	segs := []segment{
		{text: glyph + " ", style: r.styles.Faint},
		{text: icon + " ", style: style},
		{text: tool, style: r.styles.Heading},
	}
	if summary := withoutToolName(tool, oneLine(b.Summary)); summary != "" {
		segs = append(segs, segment{text: " " + summary, style: r.styles.Dim})
	}
	if b.Status == "error" {
		if first, _, _ := strings.Cut(trimBlank(sanitize(b.Text)), "\n"); first != "" {
			segs = append(segs, segment{text: "  " + first, style: r.styles.Fail})
		}
	}
	return renderSegments(cutSegments(segs, inner))
}

func BlockScroll(m state.Model, id string) int {
	if m.Width <= 0 || m.Height <= 0 {
		return 0
	}
	measureMu.Lock()
	defer measureMu.Unlock()
	r := measurerOf(m)
	total, start, ok := r.spanOf(m, m.Width, id)
	if !ok {
		return 0
	}
	return max(total-start-r.layout(m).view, 0)
}

func (r *Renderer) spanOf(m state.Model, w int, id string) (total, start int, ok bool) {
	blocks := transcriptBlocks(m)
	hidden := hiddenBlocks(blocks)
	var prev state.Block
	prevRows := 0
	for i, b := range blocks {
		if hidden[i] || compacted(b, m) {
			continue
		}
		n := len(r.block(b, max(w, 1), m))
		if n == 0 {
			continue
		}
		if prevRows > 0 && !tight(prev, prevRows, b, n) {
			total++
		}
		if b.Kind == state.KindSubagent && b.ID == id {
			start, ok = total, true
		}
		total += n
		prev, prevRows = b, n
	}
	return total, start, ok
}

func childCount(blocks []state.Block, id string) int {
	n := 0
	for _, b := range blocks {
		if id != "" && b.Parent == id {
			n++
		}
	}
	return n
}

func (r *Renderer) noticeBlock(b state.Block, w int) []string {
	ind := nestIndent * b.Depth
	icon, style := "ℹ", r.styles.Notice
	switch b.Level {
	case "warn":
		icon, style = "⚠", r.styles.Warn
	case "error":
		icon, style = "✖", r.styles.Fail.Bold(true)
	}
	var out []string
	for i, row := range wrapText(trimBlank(sanitize(b.Text)), textWidth(w, ind)) {
		mark := " "
		if i == 0 {
			mark = style.Render(icon)
		}
		out = append(out, fit(spaces(margin+ind)+mark+" "+style.Render(row), w))
	}
	return out
}

func (r *Renderer) outputBlock(b state.Block, w int) []string {
	text := trimBlank(sanitize(b.Text))
	if text == "" {
		return nil
	}
	ind := nestIndent * b.Depth
	bar := r.styles.Faint.Render("▎")
	var out []string
	for i, row := range wrapText(text, bandWidth(w, ind)) {
		styled := r.styles.Output.Render(row)
		if i == 0 && strings.HasPrefix(row, "$ ") {
			styled = r.styles.Info.Render("$ ") + r.styles.Heading.Render(row[2:])
		}
		out = append(out, r.bandRow(ind, bar, styled, w))
	}
	return out
}

func (r *Renderer) resultLines(text string, width, limit int, style lipgloss.Style) []string {
	text = trimBlank(text)
	if text == "" {
		return nil
	}
	rows := wrapText(text, width)
	var out []string
	for _, row := range rows[:min(len(rows), limit)] {
		out = append(out, style.Render(row))
	}
	if hidden := len(rows) - limit; hidden > 0 {
		note := fmt.Sprintf("… %d more %s (ctrl+o to expand)", hidden, plural(hidden, "line", "lines"))
		out = append(out, r.styles.Dim.Render(note))
	}
	return out
}

func rowLimit(expanded bool, maxRows int) int {
	if expanded {
		return math.MaxInt
	}
	return maxRows
}
