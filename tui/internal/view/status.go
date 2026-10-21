package view

import (
	"fmt"
	"math"
	"slices"
	"strings"
	"time"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

var spinnerFrames = []string{"⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"}

func statusHintSets(m state.Model) []string {
	if m.ShellMode {
		return []string{"enter run · esc leave shell mode", "enter run · esc leave"}
	}
	agents := "@ subagents"
	if len(m.Subagents()) > 0 {
		agents = "ctrl+g subagents"
	}
	return []string{
		"/ commands · " + agents + " · ctrl+p palette · ctrl+c stop",
		"/ commands · ctrl+p palette",
		"/ commands · ctrl+c",
	}
}

func (r *Renderer) status(m state.Model, w int) string {
	inner := max(w-2, 0)
	flash := oneLine(m.Flash)
	var left []segment
	add := func(segs ...segment) {
		if len(left) > 0 {
			left = append(left, segment{text: " · ", style: r.styles.Dim})
		}
		left = append(left, segs...)
	}
	item := func(text string, style lipgloss.Style) {
		add(segment{text: text, style: style})
	}
	if m.Working() {
		add(segment{text: spinnerFrame(m.Frame), style: r.styles.Run},
			segment{text: " working " + elapsed(m.TurnStarted), style: r.styles.Dim})
	}
	if m.Confirm != nil {
		add(segment{text: "approval needed", style: r.styles.Warn})
	}
	if m.Ask != nil {
		add(segment{text: "question", style: r.styles.Warn})
	}
	if m.Exec != nil {
		add(segment{text: "run confirmation", style: r.styles.Warn})
	}
	if m.Compacting {
		add(segment{text: "compacting…", style: r.styles.Run})
	}
	if m.Autocompacting {
		add(segment{text: "autocompacting…", style: r.styles.Run})
	}
	if n := m.RunningSubagents(); n > 0 {
		add(segment{text: fmt.Sprintf("◆ %d %s", n, plural(n, "subagent", "subagents")), style: r.styles.Run})
	}
	if !m.Working() && m.Confirm == nil && m.Ask == nil && m.Exec == nil && !m.Compacting && !m.Autocompacting {
		switch {
		case !m.Ready:
			add(segment{text: "connecting…", style: r.styles.Dim})
		case flash == "":
			add(segment{text: "●", style: r.styles.OK}, segment{text: " ready", style: r.styles.Dim})
		}
	}
	if title := ellipsize(oneLine(m.Session.Title), 30); title != "" {
		item(title, r.styles.Dim)
	}
	flashAt := len(left)
	if model := shortModel(m.App.Model); model != "" {
		item(ellipsize(model, 24), r.styles.Dim)
	}
	if level := protocol.CanonicalLevel(oneLine(m.Effort.Level)); level != "" && level != "none" {
		item("effort "+level, r.styles.Dim)
	}
	if pct, ok := contextUsed(m); ok {
		item(fmt.Sprintf("ctx %d%%", pct), r.styles.Dim)
	}
	if u := m.Usage; u != nil && u.TokensPerSecond != nil {
		item(fmt.Sprintf("%.0f tok/s", *u.TokensPerSecond), r.styles.Dim)
	}
	if mode := oneLine(m.App.PermissionMode); mode != "" {
		item(mode, r.styles.Dim)
	}
	if n := len(m.Queue); n > 0 {
		if m.QueuePaused {
			item("paused", r.styles.Warn)
		}
		item(fmt.Sprintf("%d queued", n), r.styles.Dim)
	}
	if n := len(m.Changes); n > 0 {
		item(fmt.Sprintf("%d %s", n, plural(n, "change", "changes")), r.styles.Dim)
	}

	rest := segmentsWidth(left)
	var right []segment
	for _, hints := range statusHintSets(m) {
		if rest+2+lipgloss.Width(hints) <= inner {
			right = r.hintSegments(hints, lipgloss.Width(hints))
			break
		}
	}
	room := inner - rest - 3
	if right != nil {
		room -= 2 + segmentsWidth(right)
	}
	if flash != "" && room >= 4 {
		seg := segment{text: ellipsize(flash, room), style: r.styles.Dim}
		sep := segment{text: " · ", style: r.styles.Dim}
		switch {
		case flashAt > 0:
			left = slices.Insert(left, flashAt, sep, seg)
		case len(left) > 0:
			left = slices.Insert(left, 0, seg, sep)
		default:
			left = []segment{seg}
		}
	}
	if segmentsWidth(left) > inner {
		left = cutSegments(left, inner)
		right = nil
	}
	gap := inner - segmentsWidth(left) - segmentsWidth(right)
	return " " + renderSegments(left) + spaces(gap) + renderSegments(right) + " "
}

func shortModel(id string) string {
	id = strings.TrimRight(oneLine(id), "/")
	return id[strings.LastIndex(id, "/")+1:]
}

func contextPercent(prompt, window int) int {
	return (prompt*100 + window/2) / window
}

func contextPercentOf(c protocol.Context) (int, bool) {
	switch {
	case c.Percent != nil:
		return int(math.Round(*c.Percent)), true
	case c.Used != nil && c.Limit != nil && *c.Limit > 0:
		return contextPercent(*c.Used, *c.Limit), true
	}
	return 0, false
}

func contextUsed(m state.Model) (int, bool) {
	if pct, ok := contextPercentOf(m.Context); ok {
		return pct, true
	}
	if u := m.Usage; u != nil && u.PromptTokens != nil && u.ContextWindow != nil && *u.ContextWindow > 0 {
		return contextPercent(*u.PromptTokens, *u.ContextWindow), true
	}
	return 0, false
}

func spinnerFrame(frame int) string {
	n := len(spinnerFrames)
	return spinnerFrames[((frame%n)+n)%n]
}

func elapsed(t time.Time) string {
	if t.IsZero() {
		return "0s"
	}
	return fmt.Sprintf("%ds", max(int(time.Since(t)/time.Second), 0))
}
