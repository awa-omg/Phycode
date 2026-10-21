package state

import (
	"fmt"
	"slices"
	"time"

	"phycode/tui/internal/protocol"
)

const (
	configHint         = "↑↓ move · enter change · esc close"
	sessionsHint       = "↑↓ move · enter switch · ctrl+n new · esc close"
	effortHint         = "←→ change · enter set · esc close"
	contextHint        = "type max N, Nk or off · enter sets it · esc close"
	compactHint        = "type instructions · enter compacts · esc cancel"
	compactRunningHint = "compacting… · esc close"
	commandsHint       = "type to filter · ↑↓ move · enter use · esc close"
)

const (
	CategoryKeys   = "Keys"
	CategorySyntax = "Syntax"
)

var shortcuts = []protocol.PanelItem{
	{Title: "enter", Detail: "send the message; with a menu open, complete the highlighted row"},
	{Title: "alt+enter", Detail: "add a line to the message (ctrl+j does the same)"},
	{Title: "tab", Detail: "complete the highlighted row of the menu; with none open, switch the primary agent"},
	{Title: "shift+tab", Detail: "cycle the thinking level (/effort sets one)"},
	{Title: "ctrl+l", Detail: "open the model picker, like /models"},
	{Title: "ctrl+p", Detail: "open this list of commands"},
	{Title: "ctrl+g", Detail: "list the subagents of the session"},
	{Title: "esc", Detail: "close the menu, or stop the turn that runs"},
	{Title: "ctrl+c", Detail: "stop the turn, clear the message, or quit"},
	{Title: "ctrl+o", Detail: "show the tool outputs in full, or fold them"},
	{Title: "ctrl+t", Detail: "fold or unfold the last subagent"},
	{Title: "!", Detail: "run a shell command in the project; what it prints joins the conversation, and !! keeps it out"},
	{Title: "ctrl+e", Detail: "write the message in your editor"},
	{Title: "↑ ↓", Detail: "browse what you sent before"},
	{Title: "alt+↑ alt+↓", Detail: "scroll the transcript (pgup and pgdn too)"},
	{Title: "ctrl+d", Detail: "quit, from an empty message"},
}

func CommandsPanel(listed []protocol.Command) Panel {
	var items []protocol.PanelItem
	for _, c := range Catalog(listed) {
		it := protocol.PanelItem{Category: c.Group, Title: c.Name, Detail: c.Description, Key: Insert(c)}
		if c.Group == GroupCustom {
			it.Badge = "custom"
		}
		items = append(items, it)
	}
	items = append(items, protocol.PanelItem{
		Category: CategorySyntax, Title: "@NAME TASK", Detail: "hand a task to a subagent; its report joins the conversation", Key: "@",
	})
	for _, k := range shortcuts {
		k.Category = CategoryKeys
		items = append(items, k)
	}
	return Panel{Open: true, Title: "Commands", Kind: "commands", Hint: commandsHint, Items: items}
}

func Insert(c Command) string {
	if c.Optional() {
		return c.Word
	}
	return c.Word + " "
}

func ConfigPanel() Panel {
	return Panel{Open: true, Title: "Config", Kind: "config", Hint: configHint, Loading: true}
}

func SessionsPanel() Panel {
	return Panel{Open: true, Title: "Sessions", Kind: "sessions", Hint: sessionsHint, Loading: true}
}

func EffortPanel() Panel {
	return Panel{Open: true, Title: "Effort", Kind: "effort", Hint: effortHint, Loading: true}
}

func ContextPanel() Panel {
	return Panel{Open: true, Title: "Context", Kind: "context", Hint: contextHint, Loading: true}
}

func CompactPanel(text string) Panel {
	return Panel{Open: true, Title: "Compact", Kind: "compact", Hint: compactHint, Input: text}
}

func (p Panel) CompactSent() Panel {
	p.Hint = compactRunningHint
	return p
}

func (p Panel) CompactEnded() Panel {
	p.Hint = compactHint
	return p
}

func (p Panel) IsList() bool {
	switch p.Kind {
	case "list", "config", "sessions", "effort", "commands", "tasks", "themes":
		return true
	}
	return false
}

func (p Panel) ConfigSetting(key string) (protocol.ConfigItem, bool) {
	for _, c := range p.Config {
		if c.Key == key {
			return c, true
		}
	}
	return protocol.ConfigItem{}, false
}

func (p Panel) WithConfig(items []protocol.ConfigItem) Panel {
	p.Config = slices.Clone(items)
	p.Loading = false
	rows := make([]protocol.PanelItem, 0, len(items))
	for _, c := range items {
		rows = append(rows, configRow(c))
	}
	return p.withItems(rows, "")
}

func configRow(c protocol.ConfigItem) protocol.PanelItem {
	row := protocol.PanelItem{Category: c.Category, Title: c.Label, Detail: c.Key, Key: c.Key}
	if row.Title == "" {
		row.Title = c.Key
	}
	switch c.Kind {
	case protocol.ConfigBool:
		row.Badge, row.Status = "off", "off"
		if c.Bool() {
			row.Badge, row.Status = "on", "ok"
		}
	default:
		row.Badge = c.Text()
		if row.Badge == "" {
			row.Badge = "–"
		}
	}
	return row
}

func (p Panel) WithSessions(items []protocol.Session) Panel {
	p.Loading = false
	rows := make([]protocol.PanelItem, 0, len(items))
	current := ""
	for _, s := range items {
		row := protocol.PanelItem{Title: SessionName(s), Detail: sessionDetail(s), Key: s.ID}
		if s.Current {
			row.Badge, row.Status = "current", "ok"
			current = s.ID
		}
		rows = append(rows, row)
	}
	return p.withItems(rows, current)
}

func SessionName(s protocol.Session) string {
	if s.Title != "" {
		return s.Title
	}
	return s.ID
}

func sessionDetail(s protocol.Session) string {
	detail := fmt.Sprintf("%d %s", s.Messages, plural(s.Messages, "message", "messages"))
	if t, err := time.Parse(time.RFC3339, s.UpdatedAt); err == nil {
		detail += " · " + t.Format("2006-01-02 15:04")
	}
	return detail
}

func (p Panel) WithEffort(ev protocol.Effort) Panel {
	p.Loading = false
	p.Title = "Effort"
	if ev.Model != "" {
		p.Title += " · " + ev.Model
	}
	p.Note = ev.Note
	names := make([]string, len(ev.Levels))
	for i, l := range ev.Levels {
		names[i] = l.Name
	}
	level := protocol.CanonicalLevel(ev.Level)
	rows := make([]protocol.PanelItem, 0, len(names))
	for _, name := range names {
		row := protocol.PanelItem{Title: name, Key: name}
		if name == level {
			row.Badge, row.Status = "current", "ok"
		}
		if ev.Accepted != nil && !slices.Contains(ev.Accepted, name) {
			row.Detail = "→ " + sentAs(name, names, ev.Accepted)
			if row.Status == "" {
				row.Status = "off"
			}
		}
		rows = append(rows, row)
	}
	return p.withItems(rows, level)
}

func sentAs(level string, levels, accepted []string) string {
	var named []string
	for _, l := range levels {
		if l != "none" && slices.Contains(accepted, l) {
			named = append(named, l)
		}
	}
	if len(named) == 0 {
		return "none"
	}
	target, at := named[0], slices.Index(levels, level)
	for _, l := range named {
		if slices.Index(levels, l) < at {
			target = l
		}
	}
	return target
}

func (p Panel) withItems(rows []protocol.PanelItem, fallback string) Panel {
	key := ""
	if it, ok := p.Highlighted(); ok {
		key = it.Key
	}
	p.Items = rows
	p.Selected = 0
	for _, want := range []string{key, fallback} {
		if want == "" {
			continue
		}
		if i := indexOfKey(p.Visible(), want); i >= 0 {
			p.Selected = i
			break
		}
	}
	return p
}

func indexOfKey(items []protocol.PanelItem, key string) int {
	for i, it := range items {
		if it.Key == key {
			return i
		}
	}
	return -1
}

func ResearchPanel(ev *protocol.Progress) Panel {
	p := Panel{Open: true, Title: "Deep research", Kind: "list", Run: ev.ID}
	return p.WithStep(ev)
}

func (p Panel) WithStep(ev *protocol.Progress) Panel {
	item := protocol.PanelItem{Title: ev.Step, Detail: ev.Detail}
	if ev.Done {
		item.Status = "ok"
	}
	p.Items = append(slices.Clip(p.Items), item)
	return p
}

func NewAsk(ev *protocol.Ask) *Ask {
	return &Ask{ID: ev.ID, Question: ev.Question, Options: slices.Clone(ev.Options), FreeText: ev.AllowFreeText}
}

func (a Ask) Option() (string, bool) {
	if a.Selected < 0 || a.Selected >= len(a.Options) {
		return "", false
	}
	return a.Options[a.Selected], true
}

func plural(n int, one, many string) string {
	if n == 1 {
		return one
	}
	return many
}
