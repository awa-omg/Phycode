package state

import (
	"strings"

	"phycode/tui/internal/protocol"
)

func NewPanel(ev *protocol.Panel) Panel {
	kind := "text"
	if ev.Kind == "list" {
		kind = "list"
	}
	return Panel{Open: true, Title: ev.Title, Kind: kind, Text: ev.Text, Hint: ev.Hint, Items: ev.Items}
}

func (p Panel) Visible() []protocol.PanelItem {
	words := strings.Fields(strings.ToLower(p.Query))
	var out []protocol.PanelItem
	for _, category := range panelCategories(p.Items) {
		for _, it := range p.Items {
			if it.Category == category && panelMatches(it, words) {
				out = append(out, it)
			}
		}
	}
	return out
}

func (p Panel) Highlighted() (protocol.PanelItem, bool) {
	visible := p.Visible()
	if p.Selected < 0 || p.Selected >= len(visible) {
		return protocol.PanelItem{}, false
	}
	return visible[p.Selected], true
}

func panelCategories(items []protocol.PanelItem) []string {
	var out []string
	seen := make(map[string]bool)
	for _, it := range items {
		if !seen[it.Category] {
			seen[it.Category] = true
			out = append(out, it.Category)
		}
	}
	return out
}

func panelMatches(it protocol.PanelItem, words []string) bool {
	text := strings.ToLower(it.Category + "\n" + it.Title + "\n" + it.Detail)
	for _, w := range words {
		if !strings.Contains(text, w) {
			return false
		}
	}
	return true
}
