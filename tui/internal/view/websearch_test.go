package view

import (
	"strings"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func webList(w, h int) state.Model {
	m := newModel(w, h)
	web := &state.WebSearch{
		Active: "main",
		Items: []protocol.SearchProvider{
			{Name: "main", Backend: "brave", Key: "stored", Active: true},
			{Name: "lan", Backend: "searxng", URL: ptrString("http://192.168.1.20:8888"), Key: "none"},
			{Name: "gone", Backend: "exa", Key: "missing"},
		},
		Presets: []protocol.SearchPreset{
			{Backend: "brave", URL: ptrString("https://api.search.brave.com/res/v1/web/search"), KeyRequired: true, URLMeans: "endpoint"},
			{Backend: "exa", URL: ptrString("https://api.exa.ai/search"), KeyRequired: true, URLMeans: "endpoint"},
			{Backend: "searxng", URLRequired: true, URLMeans: "base"},
		},
	}
	m.Panel = state.WebPanel()
	m.Panel.Web = web
	return m
}

func ptrString(s string) *string { return &s }

func TestTheListShowsTheSearchOffTheProvidersTheirHostsAndWhereTheirKeysAre(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := webList(90, 24)
	text := strings.Join(plainRows(screen(t, r, m)), "\n")

	for _, want := range []string{"Search off", "main", "brave", "api.search.brave.com", "key stored", "lan", "searxng", "192.168.1.20:8888", "no key needed", "gone", "exa", "key missing", "+ Add a search provider…"} {
		if !strings.Contains(text, want) {
			t.Errorf("the list has no %q:\n%s", want, text)
		}
	}
	rows := plainRows(screen(t, r, m))
	if !strings.Contains(rows[rowOf(screen(t, r, m), "main")], "●") {
		t.Error("the dot is on the provider in use")
	}
	for _, name := range []string{"lan", "Search off"} {
		if strings.Contains(rows[rowOf(screen(t, r, m), name)], "●") {
			t.Errorf("the row %q has a dot, and only the provider in use has one", name)
		}
	}
	if !strings.Contains(text, "enter choose · ctrl+e edit · ctrl+x twice remove · esc close") {
		t.Errorf("the hint of the list is missing:\n%s", text)
	}
}

func TestTheOlderFormIsSaidWhenItDecidesTheSearch(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := webList(90, 24)
	m.Panel.Web.Active = ""
	m.Panel.Web.Legacy = &protocol.SearchLegacy{Backend: "searxng", Key: "none"}
	text := strings.Join(plainRows(screen(t, r, m)), "\n")

	if !strings.Contains(text, "the older form of the config decides the search: searxng") {
		t.Errorf("the older form is not said:\n%s", text)
	}
}

func TestTheFormShowsTheKeyAsDotsAndTheEndpointOfTheBackendWhenTheURLIsEmpty(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := webList(90, 24)
	m.Panel.Web.Form = &state.WebForm{Name: "exa-main", Backend: 1, Key: "exa-secret-1234", Focus: state.WebFieldKey}
	text := strings.Join(plainRows(screen(t, r, m)), "\n")

	for _, want := range []string{"new search provider", "‹ exa ›", "default https://api.exa.ai"} {
		if !strings.Contains(text, want) {
			t.Errorf("the form has no %q:\n%s", want, text)
		}
	}
	if !strings.Contains(text, "•••••••••••••••") || strings.Contains(text, "exa-secret") {
		t.Errorf("the key shows as dots and never as text:\n%s", text)
	}
}

func TestTheRowsOfAFormDoNotCarryTheKeyWhenTheBackendTakesNone(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := webList(90, 24)
	m.Panel.Web.Form = &state.WebForm{Name: "lan-2", Backend: 2, URL: "http://192.168.1.21:8888", Focus: state.WebFieldURL, Error: "type the URL of the instance"}
	text := strings.Join(plainRows(screen(t, r, m)), "\n")

	if strings.Contains(text, "api key") {
		t.Errorf("searxng takes no key, so the form has no key field:\n%s", text)
	}
	if !strings.Contains(text, "type the URL of the instance") {
		t.Errorf("the error of the form is not under it:\n%s", text)
	}
}

func TestTheSearchPanelDrawsTheBackendsTextWithoutEscapes(t *testing.T) {
	const hostile = "\x1b]52;c;SGVsbG8=\x07\x1b[2J"
	r := NewRenderer(theme.Default())

	list := webList(90, 24)
	list.Panel.Web.Active = ""
	list.Panel.Web.Legacy = &protocol.SearchLegacy{Backend: "searxng" + hostile}
	list.Panel.Web.Items[0].Name += hostile
	list.Panel.Web.Items[0].Backend += hostile
	list.Panel.Web.Items[1].URL = ptrString("http://192.168.1.20" + hostile + ":8888")

	edit := webList(90, 24)
	edit.Panel.Web.Form = &state.WebForm{Edit: "lan" + hostile, Name: "lan", Backend: 2, URL: "http://x" + hostile, Focus: state.WebFieldURL}

	add := webList(90, 24)
	add.Panel.Web.Presets[0].Backend += hostile
	add.Panel.Web.Form = &state.WebForm{Name: "new" + hostile, Backend: 0, Focus: state.WebFieldName}

	for name, m := range map[string]state.Model{"list": list, "edit": edit, "add": add} {
		for _, row := range screen(t, r, m) {
			if strings.Contains(sgrPattern.ReplaceAllString(row, ""), "\x1b") {
				t.Errorf("%s: an escape sequence of the backend reached the screen in the row %q", name, plain(row))
				break
			}
		}
	}
}
