package state

import (
	"reflect"
	"testing"

	"phycode/tui/internal/protocol"
)

func TestTheRowsAreTheSearchOffTheProvidersAndTheRowThatAddsOne(t *testing.T) {
	w := WebSearch{Items: []protocol.SearchProvider{{Name: "main"}, {Name: "lan"}}}
	var kinds []int
	for _, row := range w.Rows() {
		kinds = append(kinds, row.Kind)
	}
	if want := []int{WebRowOff, WebRowProvider, WebRowProvider, WebRowAdd}; !reflect.DeepEqual(kinds, want) {
		t.Errorf("rows = %v, want %v", kinds, want)
	}
	w.Selected = 2
	if row, ok := w.Highlighted(); !ok || row.Provider.Name != "lan" {
		t.Errorf("highlighted = %+v, %v; want the provider lan", row, ok)
	}
	if _, ok := (WebSearch{Selected: 9}).Highlighted(); ok {
		t.Error("a highlight past the rows has no row")
	}
}

func TestTheWebPanelWaitsForTheListAndHasItsOwnKind(t *testing.T) {
	p := WebPanel()
	if !p.Open || p.Kind != "web" || p.Title != "Web search" || p.Web == nil || !p.Web.Pending {
		t.Errorf("panel = %+v; want an open web panel that waits for the backend", p)
	}
}

func TestThePresetOfABackendAndTheProviderOfAName(t *testing.T) {
	endpoint := "https://api.exa.ai/search"
	w := WebSearch{
		Items:   []protocol.SearchProvider{{Name: "main", Backend: "exa"}},
		Presets: []protocol.SearchPreset{{Backend: "exa", URL: &endpoint, KeyRequired: true}, {Backend: "searxng"}},
	}
	if p, ok := w.PresetOf("exa"); !ok || DefaultURL(p) != endpoint || !p.KeyRequired {
		t.Errorf("preset of exa = %+v, %v", p, ok)
	}
	if p, ok := w.PresetOf("searxng"); !ok || DefaultURL(p) != "" {
		t.Errorf("searxng has no endpoint, so its default URL is empty: %+v, %v", p, ok)
	}
	if _, ok := w.PresetOf("google"); ok {
		t.Error("an unknown backend has no preset")
	}
	if p, ok := w.Provider("main"); !ok || p.Backend != "exa" {
		t.Errorf("provider main = %+v, %v", p, ok)
	}
}
