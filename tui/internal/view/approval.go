package view

import (
	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
)

const (
	approvalBannerRows = 6
	maxApprovalSummary = 4
)

const (
	approvalQuestion = "approval needed: "
	approvalAnswers  = "y once · n deny · ctrl+c stop"
)

var approvalChoices = [][2]string{{"y", "once"}, {"a", "always this session"}, {"n", "deny"}, {"esc", "deny"}}

func (r *Renderer) overlayApproval(rows []string, c state.Confirm, w int) []string {
	t := len(rows)
	if t == 0 {
		return rows
	}
	out := append([]string(nil), rows...)
	if t >= 2 {
		out[t-1] = ""
	}
	var block []string
	if t > approvalBannerRows {
		block = r.approvalBlock(c, w, t-1)
	}
	if block == nil {
		out[max(t-2, 0)] = r.approvalBanner(w)
		return out
	}
	copy(out[t-1-len(block):], block)
	return out
}

func (r *Renderer) approvalBlock(c state.Confirm, w, avail int) []string {
	inner := dockInner(w)
	if inner < 4 {
		return nil
	}
	keys := r.keyRows(approvalChoices, inner)
	fixed := 1 + len(keys)
	lip, blank, meta := 1, 1, 1
	for _, drop := range []*int{&lip, &blank, &meta} {
		if avail >= fixed+lip+blank+meta {
			break
		}
		*drop = 0
	}
	if avail < fixed+lip+blank+meta {
		return nil
	}
	room := avail - fixed - lip - blank - meta

	lines := []string{r.styles.Warn.Render("⚠ ") + r.styles.Heading.Render(ellipsize("Approve "+oneLine(c.Tool)+"?", inner-2))}
	if meta == 1 {
		text := "category " + oneLine(c.Category) + " · agent " + oneLine(c.Agent)
		if c.Depth >= 1 {
			text += " · subagent"
		}
		lines = append(lines, r.styles.Dim.Render(ellipsize(text, inner)))
	}
	var summary []string
	if text := trimBlank(sanitize(c.Summary)); text != "" {
		summary = wrapText(text, inner)
	}
	shown := r.summaryRows(summary, min(maxApprovalSummary, room), inner)
	lines = append(lines, shown...)
	if c.Preview != nil {
		lines = append(lines, r.previewRows(*c.Preview, inner, room-len(shown))...)
	}
	if blank == 1 {
		lines = append(lines, "")
	}
	lines = append(lines, keys...)
	return r.railBlock(lines, r.styles.Warn, w, lip == 1, false)
}

func (r *Renderer) keyRows(choices [][2]string, width int) []string {
	var rows []string
	row, rowW := "", 0
	for _, ch := range choices {
		item := r.styles.Key.Render(ch[0]) + r.styles.Dim.Render(" "+ch[1])
		w := lipgloss.Width(ch[0]) + 1 + lipgloss.Width(ch[1])
		switch {
		case row == "":
			row, rowW = item, w
		case rowW+3+w <= width:
			row, rowW = row+"   "+item, rowW+3+w
		default:
			rows = append(rows, row)
			row, rowW = item, w
		}
	}
	if row != "" {
		rows = append(rows, row)
	}
	return rows
}

func (r *Renderer) summaryRows(summary []string, n, inner int) []string {
	shown := min(n, len(summary))
	rows := make([]string, shown)
	copy(rows, summary[:shown])
	if shown > 0 && shown < len(summary) {
		rows[shown-1] = cut(rows[shown-1], inner-1) + "…"
	}
	for i := range rows {
		rows[i] = r.styles.Output.Render(rows[i])
	}
	return rows
}

func (r *Renderer) approvalBanner(w int) string {
	text := approvalQuestion + approvalAnswers
	if lipgloss.Width(text) > w {
		text = approvalAnswers
	}
	text = ellipsize(text, max(w-2, 0))
	return " " + r.styles.Warn.Render(text)
}
