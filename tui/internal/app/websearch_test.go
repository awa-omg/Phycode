package app

import (
	"reflect"
	"testing"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func webEvent() *protocol.SearchProviders {
	return &protocol.SearchProviders{
		Type:   protocol.TypeSearchProviders,
		Active: ptr("main"),
		Items: []protocol.SearchProvider{
			{Name: "main", Backend: "brave", Key: "stored", Active: true},
			{Name: "lan", Backend: "searxng", URL: ptr("http://192.168.1.20:8888"), Key: "none"},
		},
		Presets: []protocol.SearchPreset{
			{Backend: "brave", URL: ptr("https://api.search.brave.com/res/v1/web/search"), KeyRequired: true, URLMeans: "endpoint"},
			{Backend: "exa", URL: ptr("https://api.exa.ai/search"), KeyRequired: true, URLMeans: "endpoint"},
			{Backend: "searxng", URLRequired: true, URLMeans: "base"},
		},
	}
}

func webOpen(t *testing.T) state.Model {
	t.Helper()
	m, _ := feed(testModel(), typed("/web"), press("enter"))
	if m.Panel.Kind != "web" {
		t.Fatalf("Panel.Kind = %q, want the web search panel", m.Panel.Kind)
	}
	m, _ = feed(m, webEvent())

	return m
}

func ctrlX() tea.KeyMsg {
	return tea.KeyMsg{Type: tea.KeyCtrlX}
}

func TestWebOpensThePanelAndAsksTheBackendForTheProviders(t *testing.T) {
	m, cmds := feed(testModel(), typed("/web"), press("enter"))

	if want := []any{protocol.NewListSearchProviders()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Panel.Web.Pending {
		t.Error("the panel waits for the list")
	}
	m, _ = feed(m, webEvent())
	if m.Panel.Web.Pending || len(m.Panel.Web.Items) != 2 || m.Panel.Web.Active != "main" {
		t.Errorf("web = %+v; want the list of the answer", m.Panel.Web)
	}
}

func TestEnterMakesTheHighlightedProviderTheOneInUseAndTheOffRowTurnsTheSearchOff(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"))
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewSetSearchProvider(ptr("lan"))}; !reflect.DeepEqual(cmds, want) || !m.Panel.Web.Pending {
		t.Errorf("commands = %v, pending = %v; want set lan and the wait", cmds, m.Panel.Web.Pending)
	}

	m = webOpen(t)
	if _, cmds = feed(m, press("enter")); !reflect.DeepEqual(cmds, []any{protocol.NewSetSearchProvider(nil)}) {
		t.Errorf("the off row sent %v", cmds)
	}
}

func TestAChangeWhileATurnRunsIsRefusedWithoutACommand(t *testing.T) {
	m := webOpen(t)
	m.Busy = true
	m, _ = feed(m, press("down"))
	m, cmds := feed(m, press("enter"))

	if len(cmds) != 0 || m.Panel.Web.Error != busyText {
		t.Errorf("commands = %v, error = %q; want none and the busy text", cmds, m.Panel.Web.Error)
	}
}

func TestTheAddRowOpensTheFormAndSendsTheProviderWithItsKeyThatItForgets(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"), press("down"))
	m, _ = feed(m, press("enter"))
	if m.Panel.Web.Form == nil || m.Panel.Web.Form.Focus != state.WebFieldName {
		t.Fatalf("form = %+v; want the form of a new provider", m.Panel.Web.Form)
	}
	m, _ = feed(m, typed("exa-main"), press("tab"), press("right"), press("tab"), press("tab"), typed("exa-secret-7"))
	m, cmds := feed(m, press("enter"))

	if want := []any{protocol.NewAddSearchProvider("exa-main", "exa", "https://api.exa.ai/search", "exa-secret-7")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Panel.Web.Form == nil || m.Panel.Web.Form.Key != "" || !m.Panel.Web.Pending {
		t.Errorf("form = %+v; the key leaves the form when it is sent", m.Panel.Web.Form)
	}
}

func TestTheFormAsksForWhatTheBackendNeedsAndShowsTheProblemUnderIt(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"), press("down"), press("enter"))
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Panel.Web.Form.Error != "type a name" {
		t.Errorf("error = %q, commands = %v; want the name asked for", m.Panel.Web.Form.Error, cmds)
	}
	m, _ = feed(m, typed("brave-2"), press("enter"))
	if m.Panel.Web.Form.Error != "type the key" {
		t.Errorf("error = %q; brave takes a key", m.Panel.Web.Form.Error)
	}
}

func TestCtrlEEditsTheURLAndTheKeyOfAProviderAndKeepsItsName(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"))
	m, _ = feed(m, tea.KeyMsg{Type: tea.KeyCtrlE})
	if f := m.Panel.Web.Form; f == nil || f.Edit != "lan" || f.Name != "lan" || f.URL != "http://192.168.1.20:8888" || f.Focus != state.WebFieldURL {
		t.Fatalf("form = %+v; want the edit of lan with its URL", f)
	}
	m, _ = feed(m, press("up"), press("up"), typed("renamed"))
	m, cmds := feed(m, press("enter"))

	if m.Panel.Web.Form.Name != "lan" {
		t.Errorf("the name of an edited provider is fixed, it is %q", m.Panel.Web.Form.Name)
	}
	if want := []any{protocol.NewUpdateSearchProvider("lan", "http://192.168.1.20:8888", "")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: an empty key keeps the key", cmds, want)
	}
}

func TestCtrlXAsksOnceAndRemovesOnTheSecondPress(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"))
	m, cmds := feed(m, ctrlX())
	if len(cmds) != 0 || m.Panel.Web.Confirm != "main" {
		t.Fatalf("first press: commands = %v, confirm = %q; want the question only", cmds, m.Panel.Web.Confirm)
	}
	m, cmds = feed(m, ctrlX())
	if want := []any{protocol.NewRemoveSearchProvider("main")}; !reflect.DeepEqual(cmds, want) || !m.Panel.Web.Pending {
		t.Errorf("second press: commands = %v; want %v", cmds, want)
	}
}

func TestTheAnswerClosesTheFormThatWasSentAndAnyOtherKeepsIt(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"), press("down"), press("enter"), typed("exa-two"), press("tab"), press("right"), press("tab"), press("tab"), typed("k"))
	m, _ = feed(m, press("enter"))
	if m.Panel.Web.Form == nil || !m.Panel.Web.Pending {
		t.Fatal("the form waits for its answer")
	}

	added := webEvent()
	added.Items = append(added.Items, protocol.SearchProvider{Name: "exa-two", Backend: "exa", Key: "stored"})
	added.Active = ptr("exa-two")
	m, _ = feed(m, added)
	if m.Panel.Web.Form != nil || m.Panel.Web.Pending || len(m.Panel.Web.Items) != 3 {
		t.Errorf("web = %+v; want the form closed by the answer", m.Panel.Web)
	}
	if m.Flash != "web search on: exa-two · from the next turn" {
		t.Errorf("flash = %q", m.Flash)
	}
}

func TestARefusalIsShownUnderTheFormWhenOneIsOpenAndUnderTheListOtherwise(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("enter"))
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "no such provider"})
	if m.Panel.Web.Error != "no such provider" || m.Panel.Web.Pending {
		t.Errorf("web = %+v; want the refusal under the list, and no wait", m.Panel.Web)
	}

	m, _ = feed(m, press("down"), press("down"), press("enter"))
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "not taken"})
	if m.Panel.Web.Form == nil || m.Panel.Web.Form.Error != "not taken" {
		t.Errorf("form = %+v; want the refusal under the form", m.Panel.Web.Form)
	}
}

func TestAFormKeepsGoingWhenItsBackendLeavesThePresets(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"), press("down"), press("enter"), press("tab"), press("right"), press("right"))
	shrunk := webEvent()
	shrunk.Presets = shrunk.Presets[:1]
	m, _ = feed(m, shrunk)
	m, cmds := feed(m, press("tab"), press("enter"))

	if len(cmds) != 0 || m.Panel.Web.Form == nil || m.Panel.Web.Form.Backend != 0 {
		t.Errorf("form = %+v, commands = %v; want the form open on the backend that is left", m.Panel.Web.Form, cmds)
	}
}

func TestAFormSurvivesTheLossOfEveryPreset(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"), press("down"), press("enter"))
	none := webEvent()
	none.Presets = nil
	m, _ = feed(m, none)
	m, cmds := feed(m, press("tab"), press("tab"), press("enter"))

	if len(cmds) != 0 || m.Panel.Web.Form == nil || m.Panel.Web.Form.Error != "the backend has no search presets yet" {
		t.Errorf("form = %+v, commands = %v; want the form kept with the reason", m.Panel.Web.Form, cmds)
	}
}

func TestAFormKeepsItsBackendWhenThePresetsChangeOrder(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"), press("down"), press("enter"), press("tab"), press("right"))
	presets := webEvent().Presets
	reordered := webEvent()
	reordered.Presets = []protocol.SearchPreset{presets[1], presets[2], presets[0]}
	m, _ = feed(m, reordered)

	if f := m.Panel.Web.Form; f == nil || f.Backend != 0 {
		t.Errorf("form = %+v; want the backend exa, now first, still chosen", f)
	}
}

func TestEscBacksOutOfTheFormThenClosesThePanel(t *testing.T) {
	m := webOpen(t)
	m, _ = feed(m, press("down"), press("down"), press("down"), press("enter"))
	m, _ = feed(m, press("esc"))
	if m.Panel.Web.Form != nil || !m.Panel.Open {
		t.Errorf("after one esc the list is back: form = %+v, open = %v", m.Panel.Web.Form, m.Panel.Open)
	}
	m, _ = feed(m, press("esc"))
	if m.Panel.Open {
		t.Error("esc from the list closes the panel")
	}
}
