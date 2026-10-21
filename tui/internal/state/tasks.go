package state

import (
	"fmt"
	"strings"
	"time"

	"phycode/tui/internal/protocol"
)

const (
	TaskNeedsYou = "Needs you"
	TaskWorking  = "Working"
	TaskDone     = "Done"
	TaskFailed   = "Failed"
)

const tasksHint = "type to filter · ↑↓ move · enter show in the transcript · esc close"

func (b Block) Elapsed(now time.Time) time.Duration {
	if b.Started.IsZero() {
		return 0
	}
	end := b.Ended
	if end.IsZero() {
		end = now
	}
	return max(end.Sub(b.Started), 0)
}

func FormatElapsed(d time.Duration) string {
	secs := int(d.Round(time.Second) / time.Second)
	switch {
	case secs < 60:
		return fmt.Sprintf("%ds", secs)
	case secs < 3600:
		return fmt.Sprintf("%dm %02ds", secs/60, secs%60)
	}
	return fmt.Sprintf("%dh %02dm", secs/3600, secs%3600/60)
}

func (m Model) Subagents() []Block {
	var out []Block
	for _, b := range m.Blocks {
		if b.Kind == KindSubagent {
			out = append(out, b)
		}
	}
	return out
}

func (m Model) RunningSubagents() int {
	n := 0
	for _, b := range m.Blocks {
		if b.Kind == KindSubagent && b.Status == "running" {
			n++
		}
	}
	return n
}

func (m Model) Waiting(b Block) bool {
	return m.Confirm != nil && m.Confirm.Depth > 0 && b.Kind == KindSubagent && b.Status == "running" && b.Agent == m.Confirm.Agent
}

func ToolCalls(blocks []Block, id string) []Block {
	var out []Block
	for _, b := range blocks {
		if id != "" && b.Parent == id && b.Kind == KindTool {
			out = append(out, b)
		}
	}
	return out
}

func TasksPanel(m Model, now time.Time) Panel {
	return Panel{Open: true, Title: "Subagents", Kind: "tasks", Hint: tasksHint}.WithTasks(m, now)
}

func (p Panel) WithTasks(m Model, now time.Time) Panel {
	key := ""
	if it, ok := p.Highlighted(); ok {
		key = it.Key
	}
	var rows []protocol.PanelItem
	for _, group := range []string{TaskNeedsYou, TaskWorking, TaskDone, TaskFailed} {
		for _, b := range m.Subagents() {
			if taskGroup(m, b) == group {
				rows = append(rows, taskItem(m, b, group, now))
			}
		}
	}
	p.Items = rows
	if i := indexOfKey(p.Visible(), key); i >= 0 {
		p.Selected = i
	} else {
		p.Selected = min(p.Selected, max(len(p.Visible())-1, 0))
	}
	return p
}

func taskGroup(m Model, b Block) string {
	switch {
	case m.Waiting(b):
		return TaskNeedsYou
	case b.Status == "running":
		return TaskWorking
	case b.Status == "completed":
		return TaskDone
	}
	return TaskFailed
}

func taskItem(m Model, b Block, group string, now time.Time) protocol.PanelItem {
	status := map[string]string{TaskNeedsYou: "waiting", TaskWorking: "running", TaskDone: "ok", TaskFailed: "error"}[group]
	tools := len(ToolCalls(m.Blocks, b.ID))
	badge := FormatElapsed(b.Elapsed(now))
	if tools > 0 {
		badge += fmt.Sprintf(" · %d %s", tools, plural(tools, "tool", "tools"))
	}
	if group == TaskFailed {
		badge = b.Status + " · " + badge
	}
	return protocol.PanelItem{
		Category: group,
		Title:    strings.Repeat("  ", b.Depth) + b.Agent,
		Detail:   strings.Join(strings.Fields(b.Text), " "),
		Badge:    badge,
		Status:   status,
		Key:      b.ID,
	}
}

func (p Panel) SubagentID() (string, bool) {
	it, ok := p.Highlighted()
	return it.Key, ok && it.Key != ""
}
