package protocol

import (
	"encoding/json"
	"testing"
)

func TestSearchProvidersEventParsesTheProvidersAndThePresets(t *testing.T) {
	line := `{"type":"search_providers","active":"main","legacy":null,` +
		`"items":[{"name":"main","backend":"brave","url":null,"key":"stored","active":true}],` +
		`"presets":[{"backend":"exa","url":"https://api.exa.ai/search","keyRequired":true,"urlRequired":false,"urlMeans":"endpoint"}]}`
	got, err := Parse([]byte(line))
	if err != nil {
		t.Fatal(err)
	}
	ev, ok := got.(*SearchProviders)
	if !ok {
		t.Fatalf("Parse = %T, want *SearchProviders", got)
	}
	if ev.Active == nil || *ev.Active != "main" || ev.Legacy != nil {
		t.Errorf("active = %v, legacy = %v; want main and none", ev.Active, ev.Legacy)
	}
	if len(ev.Items) != 1 || ev.Items[0].Key != "stored" || !ev.Items[0].Active || ev.Items[0].URL != nil {
		t.Errorf("items = %+v", ev.Items)
	}
	if len(ev.Presets) != 1 || !ev.Presets[0].KeyRequired || ev.Presets[0].URLRequired || ev.Presets[0].URL == nil || *ev.Presets[0].URL != "https://api.exa.ai/search" {
		t.Errorf("presets = %+v", ev.Presets)
	}
}

func TestSearchProvidersEventCarriesTheOlderFormWhileItDecides(t *testing.T) {
	got, err := Parse([]byte(`{"type":"search_providers","active":null,"legacy":{"backend":"searxng","key":"none"},"items":[],"presets":[]}`))
	if err != nil {
		t.Fatal(err)
	}
	ev := got.(*SearchProviders)
	if ev.Active != nil || ev.Legacy == nil || ev.Legacy.Backend != "searxng" || ev.Legacy.Key != "none" {
		t.Errorf("active = %v, legacy = %+v; want no active provider and the older form", ev.Active, ev.Legacy)
	}
}

func TestTheSearchCommandsSendTheFieldsTheBackendReads(t *testing.T) {
	lan := "lan"
	cases := []struct {
		name string
		cmd  any
		want string
	}{
		{"list", NewListSearchProviders(), `{"type":"list_search_providers"}`},
		{"add with a key", NewAddSearchProvider("exa-main", "exa", "", "k"), `{"type":"add_search_provider","name":"exa-main","backend":"exa","url":null,"api_key":"k"}`},
		{"add with a URL", NewAddSearchProvider("lan", "searxng", "http://192.168.1.20:8888", ""), `{"type":"add_search_provider","name":"lan","backend":"searxng","url":"http://192.168.1.20:8888","api_key":null}`},
		{"update keeps what is empty", NewUpdateSearchProvider("main", "", ""), `{"type":"update_search_provider","name":"main","url":null,"api_key":null}`},
		{"update changes the key", NewUpdateSearchProvider("main", "", "k2"), `{"type":"update_search_provider","name":"main","url":null,"api_key":"k2"}`},
		{"remove", NewRemoveSearchProvider("main"), `{"type":"remove_search_provider","name":"main"}`},
		{"no search", NewSetSearchProvider(nil), `{"type":"set_search_provider","name":null}`},
		{"one provider", NewSetSearchProvider(&lan), `{"type":"set_search_provider","name":"lan"}`},
	}
	for _, c := range cases {
		data, err := json.Marshal(c.cmd)
		if err != nil {
			t.Fatal(err)
		}
		if string(data) != c.want {
			t.Errorf("%s: sent %s, want %s", c.name, data, c.want)
		}
	}
}
