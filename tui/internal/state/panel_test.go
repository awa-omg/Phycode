package state

import (
	"reflect"
	"testing"

	"phycode/tui/internal/protocol"
)

func skillItems() []protocol.PanelItem {
	return []protocol.PanelItem{
		{Category: "Project", Title: "tdd", Detail: "write the test first", Status: "ok"},
		{Category: "Global", Title: "review", Detail: "read a diff", Status: "off"},
		{Category: "Project", Title: "docs", Detail: "keep readme in step", Status: "error"},
	}
}

func panelTitles(items []protocol.PanelItem) []string {
	var out []string
	for _, it := range items {
		out = append(out, it.Title)
	}
	return out
}

func TestNewPanelOpensTheEventAsAListOrAText(t *testing.T) {
	list := NewPanel(&protocol.Panel{Title: "Skills", Kind: "list", Hint: "pick one", Items: skillItems()})
	if !list.Open || list.Kind != "list" || list.Title != "Skills" || list.Hint != "pick one" || len(list.Items) != 3 {
		t.Errorf("list panel = %+v", list)
	}
	text := NewPanel(&protocol.Panel{Title: "Help", Kind: "text", Text: "one line"})
	if !text.Open || text.Kind != "text" || text.Text != "one line" {
		t.Errorf("text panel = %+v", text)
	}
	if other := NewPanel(&protocol.Panel{Kind: "chart"}); other.Kind != "text" {
		t.Errorf("a kind the UI does not know shows as %q, want text", other.Kind)
	}
}

func TestVisibleGroupsItemsInOrderOfFirstAppearance(t *testing.T) {
	p := Panel{Items: skillItems()}
	want := []string{"tdd", "docs", "review"}
	if got := panelTitles(p.Visible()); !reflect.DeepEqual(got, want) {
		t.Errorf("Visible = %v, want %v", got, want)
	}
}

func TestFilterMatchesCategoryTitleAndDetailIgnoringCase(t *testing.T) {
	tests := map[string][]string{
		"TDD":          {"tdd"},
		"global":       {"review"},
		"diff":         {"review"},
		"readme":       {"docs"},
		"project tdd":  {"tdd"},
		"project diff": nil,
		"zzz":          nil,
		"":             {"tdd", "docs", "review"},
	}
	for query, want := range tests {
		p := Panel{Items: skillItems(), Query: query}
		if got := panelTitles(p.Visible()); !reflect.DeepEqual(got, want) {
			t.Errorf("filter %q: Visible = %v, want %v", query, got, want)
		}
	}
}

func TestHighlightedFollowsTheSelectionAmongTheVisibleItems(t *testing.T) {
	p := Panel{Items: skillItems(), Selected: 1}
	if it, ok := p.Highlighted(); !ok || it.Title != "docs" {
		t.Errorf("Highlighted = %+v, %v; want docs", it, ok)
	}
	for _, sel := range []int{-1, 3} {
		p.Selected = sel
		if _, ok := p.Highlighted(); ok {
			t.Errorf("selection %d highlights an item", sel)
		}
	}
	if _, ok := (Panel{Kind: "list"}).Highlighted(); ok {
		t.Error("an empty list highlights an item")
	}
}
