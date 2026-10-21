package app

import (
	"reflect"
	"strings"
	"testing"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

var extraKeys = map[string]tea.KeyMsg{
	"ctrl+x":    {Type: tea.KeyCtrlX},
	"ctrl+l":    {Type: tea.KeyCtrlL},
	"shift+tab": {Type: tea.KeyShiftTab},
}

func pickerKey(name string) tea.KeyMsg {
	if msg, ok := extraKeys[name]; ok {
		return msg
	}
	return press(name)
}

func pickerModels() *protocol.Models {
	ctx, tools, loaded := 32768, true, true
	queried := "2026-10-19T12:00:00+00:00"
	problem := "protocol anthropic is not available yet"
	return &protocol.Models{
		Type: protocol.TypeModels,
		Entries: []protocol.ModelEntry{
			{Profile: "local", Model: "qwen-coder", Protocol: "openai", ContextWindow: &ctx, Tools: &tools, Thinking: []string{"off", "on"}, Loaded: &loaded},
			{Profile: "local", Model: "llama-3", Protocol: "openai"},
			{Profile: "local", Model: "deepseek-r1", Protocol: "openai", Thinking: []string{"off", "low", "high"}},
			{Profile: "local", Model: "my-model", Protocol: "openai", Custom: true},
			{Profile: "cloud", Model: "gpt-x", Protocol: "openai", Thinking: []string{"off", "low", "medium", "high"}},
			{Profile: "remote", Protocol: "anthropic", Error: &problem},
		},
		Providers: []protocol.Provider{
			{Profile: "local", Protocol: "openai", Endpoint: "http://127.0.0.1:9931", Count: 4, UpdatedAt: &queried},
			{Profile: "cloud", Protocol: "openai", Endpoint: "https://api.example.com", Count: 1, UpdatedAt: &queried},
			{Profile: "remote", Protocol: "anthropic", Endpoint: "https://api.anthropic.com", Error: &problem},
		},
		Current:   protocol.ModelRef{Profile: "local", Model: "qwen-coder", Thinking: "off"},
		Favorites: []protocol.ModelRef{{Profile: "local", Model: "llama-3"}},
		Recents:   []protocol.ModelRef{{Profile: "cloud", Model: "gpt-x"}},
	}
}

func loadedPicker(t *testing.T) state.Model {
	t.Helper()
	m, cmds := feed(testModel(), typed("/models"), press("enter"), pickerModels())
	if want := []any{protocol.NewListModels(false)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if !m.Picker.Open || m.Picker.Loading {
		t.Fatalf("Open = %v, Loading = %v; want a loaded picker", m.Picker.Open, m.Picker.Loading)
	}
	if got := rowName(m); got != "local/llama-3" {
		t.Fatalf("highlight = %s, want local/llama-3, the first row", got)
	}
	return m
}

func onModel(t *testing.T, name string) state.Model {
	t.Helper()
	return goTo(t, loadedPicker(t), name)
}

func goTo(t *testing.T, m state.Model, name string) state.Model {
	t.Helper()
	for range m.Picker.Items() + 2 {
		if rowName(m) == name {
			return m
		}
		m, _ = feed(m, press("down"))
	}
	t.Fatalf("no row %s in the list %v", name, visibleNames(m))
	return m
}

func formOpen(t *testing.T) state.Model {
	t.Helper()
	m, _ := feed(loadedPicker(t), press("ctrl+a"))
	m, cmds := feed(m, typed("custom"), press("enter"))
	if len(cmds) != 0 || m.Picker.Form == nil || m.Picker.Connect != nil {
		t.Fatalf("commands = %v, Form = %v, Connect = %v; want the form of a server of one's own open", cmds, m.Picker.Form, m.Picker.Connect)
	}
	return m
}

func withModel(m state.Model, id string) state.Model {
	f := *m.Picker.Form
	f.Values[state.FieldModel] = id
	m.Picker.Form = &f
	return m
}

func rowName(m state.Model) string {
	if m.Picker.OnSpecial() {
		return "add"
	}
	if e, ok := m.Picker.Highlighted(); ok {
		return e.Profile + "/" + e.Model
	}
	return "none"
}

func visibleNames(m state.Model) []string {
	out := []string{}
	for _, e := range m.Picker.Visible() {
		out = append(out, e.Profile+"/"+e.Model)
	}
	return out
}

func TestModelsCommandOpensThePickerAndAsksForTheList(t *testing.T) {
	m, cmds := feed(testModel(), typed("/models"), press("enter"))
	if want := []any{protocol.NewListModels(false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Picker.Open || !m.Picker.Loading {
		t.Errorf("Open = %v, Loading = %v; want an open picker that waits for the list", m.Picker.Open, m.Picker.Loading)
	}
	if len(m.Blocks) != 0 || m.Busy || m.Input != "" {
		t.Errorf("Blocks = %+v, Busy = %v, Input = %q; want no block, no turn and an empty input", m.Blocks, m.Busy, m.Input)
	}
	if want := []string{"/models"}; !reflect.DeepEqual(m.History, want) {
		t.Errorf("History = %v, want %v", m.History, want)
	}
}

func TestModelsCommandDuringATurnOpensThePickerWithoutAskingForTheList(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, cmds := feed(m, typed("/models"), press("enter"))
	if len(cmds) != 0 {
		t.Errorf("commands = %v during a turn, want none: the backend refuses list_models then", cmds)
	}
	if !m.Picker.Open || m.Input != "" || len(m.Queue) != 0 || !m.Busy {
		t.Errorf("Open = %v, Input = %q, Queue = %v, Busy = %v; want the picker open and nothing queued",
			m.Picker.Open, m.Input, m.Queue, m.Busy)
	}
}

func TestDuringATurnThePickerBrowsesTheCatalogueItHas(t *testing.T) {
	m, _ := feed(testModel(), pickerModels())
	m.Busy = true
	m, cmds := feed(m, typed("/models"), press("enter"))
	if len(cmds) != 0 || !m.Picker.Open || m.Picker.Loading {
		t.Fatalf("commands = %v, Open = %v, Loading = %v; want the catalogue browsed without a request", cmds, m.Picker.Open, m.Picker.Loading)
	}
	if got := rowName(m); got != "local/llama-3" {
		t.Errorf("highlight = %s, want local/llama-3", got)
	}
	m, cmds = feed(m, press("enter"))
	if len(cmds) != 0 || m.Flash != "wait for the turn to finish" || !m.Picker.Open {
		t.Errorf("commands = %v, Flash = %q, Open = %v; want the change refused and the picker kept", cmds, m.Flash, m.Picker.Open)
	}
}

func TestAPickerOpenedDuringATurnAsksForTheCatalogueWhenTheTurnEnds(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, cmds := feed(m, typed("/models"), press("enter"))
	if len(cmds) != 0 || !m.Picker.Loading {
		t.Fatalf("commands = %v, Loading = %v; want the picker waiting without a request", cmds, m.Picker.Loading)
	}
	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewListModels(false)}; !reflect.DeepEqual(cmds, want) || !m.Picker.Loading || !m.Picker.Pending {
		t.Fatalf("commands = %v, Loading = %v, Pending = %v; want %v and the picker waiting for it", cmds, m.Picker.Loading, m.Picker.Pending, want)
	}
	m, _ = feed(m, pickerModels())
	if m.Picker.Loading || m.Picker.Pending || rowName(m) != "local/llama-3" {
		t.Errorf("Loading = %v, Pending = %v, highlight = %s; want the catalogue shown on its first model", m.Picker.Loading, m.Picker.Pending, rowName(m))
	}
}

func TestThePickerAsksForTheCatalogueOnlyWhenNoTurnIsLeftAfterTheQueue(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, _ = feed(m, typed("next"), press("enter"), typed("/models"), press("enter"))
	m, cmds := feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("next")}; !reflect.DeepEqual(cmds, want) || !m.Busy {
		t.Fatalf("commands = %v, Busy = %v; want the queued message sent, which starts a turn, and no catalogue yet", cmds, m.Busy)
	}
	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewListModels(false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v after the queued turn, want %v", cmds, want)
	}
}

func TestModelsWhilePausedLeavesTheQueuePaused(t *testing.T) {
	m := testModel()
	m.Queue = []string{"queued"}
	m.QueuePaused = true
	m, cmds := feed(m, typed("/models"), press("enter"))
	if want := []any{protocol.NewListModels(false)}; !reflect.DeepEqual(cmds, want) || !m.Picker.Open {
		t.Errorf("commands = %v, Open = %v; want the picker opened and the list requested", cmds, m.Picker.Open)
	}
	if !m.QueuePaused || m.Busy || !reflect.DeepEqual(m.Queue, []string{"queued"}) {
		t.Errorf("QueuePaused = %v, Busy = %v, Queue = %v; want the queue still paused", m.QueuePaused, m.Busy, m.Queue)
	}
}

func TestModelsEventIsStoredWhetherOrNotThePickerIsOpen(t *testing.T) {
	m, cmds := feed(testModel(), pickerModels())
	if len(cmds) != 0 || m.Picker.Open || m.Picker.Loading || !m.Picker.Known {
		t.Errorf("commands = %v, Open = %v, Loading = %v, Known = %v; want the catalogue stored and no picker",
			cmds, m.Picker.Open, m.Picker.Loading, m.Picker.Known)
	}
	if len(m.Picker.Entries) != 6 || len(m.Picker.Providers) != 3 || len(m.Picker.Favorites) != 1 ||
		len(m.Picker.Recents) != 1 || m.Picker.Current.Model != "qwen-coder" {
		t.Errorf("Picker = %+v; want the catalogue", m.Picker)
	}

	m, _ = feed(m, typed("/models"), press("enter"))
	if !m.Picker.Loading || len(m.Picker.Entries) != 6 || len(m.Picker.Providers) != 3 {
		t.Errorf("reopened: Loading = %v, Entries = %d, Providers = %d; want loading with the stored catalogue",
			m.Picker.Loading, len(m.Picker.Entries), len(m.Picker.Providers))
	}
}

func TestModelsEventFillsTheProviders(t *testing.T) {
	m, _ := feed(testModel(), pickerModels())
	remote := m.Picker.Providers[2]
	if remote.Profile != "remote" || remote.Error == nil || *remote.Error != "protocol anthropic is not available yet" {
		t.Errorf("provider = %+v; want remote with its error", remote)
	}
	local := m.Picker.Providers[0]
	if local.Endpoint != "http://127.0.0.1:9931" || local.Count != 4 || local.UpdatedAt == nil {
		t.Errorf("provider = %+v; want the endpoint, the count and the query time of local", local)
	}
}

func TestTheListIsOneListOfFavoritesRecentsAndProviders(t *testing.T) {
	m := loadedPicker(t)
	want := []string{"local/llama-3", "cloud/gpt-x", "local/qwen-coder", "local/deepseek-r1", "local/my-model"}
	if got := visibleNames(m); !reflect.DeepEqual(got, want) {
		t.Errorf("models = %v, want %v", got, want)
	}
}

func TestModelsEventReplacesTheCatalogueAndClearsTheError(t *testing.T) {
	m := loadedPicker(t)
	m.Picker.Error = "an old problem"
	m, _ = feed(m, &protocol.Models{
		Type:      protocol.TypeModels,
		Entries:   []protocol.ModelEntry{{Profile: "p", Model: "only"}},
		Providers: []protocol.Provider{{Profile: "p", Protocol: "openai"}},
	})
	if len(m.Picker.Entries) != 1 || len(m.Picker.Providers) != 1 || m.Picker.Favorites != nil || m.Picker.Recents != nil ||
		m.Picker.Current != (protocol.ModelRef{}) {
		t.Errorf("Picker = %+v; want the new lists in place of the old ones", m.Picker)
	}
	if m.Picker.Error != "" || m.Picker.Loading {
		t.Errorf("Error = %q, Loading = %v; want no error and no loading", m.Picker.Error, m.Picker.Loading)
	}
	if got := rowName(m); got != "p/only" {
		t.Errorf("highlight = %s, want p/only", got)
	}
}

func TestTypingFiltersTheModelsAndTheFirstMatchIsHighlighted(t *testing.T) {
	m := loadedPicker(t)
	m, _ = feed(m, typed("deep"))
	if m.Picker.Query != "deep" || rowName(m) != "local/deepseek-r1" {
		t.Errorf("Query = %q, highlight = %s; want deep and local/deepseek-r1", m.Picker.Query, rowName(m))
	}
	if got := visibleNames(m); !reflect.DeepEqual(got, []string{"local/deepseek-r1"}) {
		t.Errorf("models = %v, want only deepseek-r1", got)
	}
	m, _ = feed(m, press("backspace"), press("backspace"), press("backspace"), press("backspace"))
	if m.Picker.Query != "" || len(m.Picker.Visible()) != 5 || rowName(m) != "local/llama-3" {
		t.Errorf("after backspace: Query = %q, models = %d, highlight = %s; want all five back and the top", m.Picker.Query, len(m.Picker.Visible()), rowName(m))
	}
}

func TestTypingAProviderNameListsItsModels(t *testing.T) {
	m, _ := feed(loadedPicker(t), typed("cloud"))
	if got := visibleNames(m); !reflect.DeepEqual(got, []string{"cloud/gpt-x"}) {
		t.Errorf("models = %v, want the models of cloud", got)
	}
}

func TestEscClosesThePicker(t *testing.T) {
	m, cmds := feed(loadedPicker(t), press("esc"))
	if len(cmds) != 0 || m.Picker.Open {
		t.Errorf("commands = %v, Open = %v; want the picker closed without a command", cmds, m.Picker.Open)
	}
}

func TestEnterAppliesTheHighlightedModelForTheSession(t *testing.T) {
	m := onModel(t, "local/qwen-coder")
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewSetModel("local", "qwen-coder", "off", false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Picker.Open {
		t.Error("the picker closed before the backend confirmed the change")
	}
}

func TestCtrlSSavesTheHighlightedModelAsTheDefault(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	_, cmds := feed(m, press("right"), press("ctrl+s"))
	if want := []any{protocol.NewSetModel("local", "deepseek-r1", "low", true)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestEnterRefusesAModelThatCannotBeUsed(t *testing.T) {
	down := "connection refused"
	catalogue := pickerModels()
	catalogue.Entries = append(catalogue.Entries, protocol.ModelEntry{Profile: "cloud", Model: "broken", Error: &down})
	m, _ := feed(testModel(), typed("/models"), press("enter"), catalogue)
	m = goTo(t, m, "cloud/broken")
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Flash != "cannot use: connection refused" || !m.Picker.Open {
		t.Errorf("commands = %v, Flash = %q, Open = %v; want nothing sent and the reason flashed", cmds, m.Flash, m.Picker.Open)
	}
}

func TestEnterWaitsForTheTurnToEnd(t *testing.T) {
	m := onModel(t, "local/qwen-coder")
	m.Busy = true
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Flash != "wait for the turn to finish" || !m.Picker.Open {
		t.Errorf("commands = %v, Flash = %q, Open = %v; want nothing sent, the wait flashed and the picker kept", cmds, m.Flash, m.Picker.Open)
	}
}

func TestCtrlFTogglesTheFavorite(t *testing.T) {
	m := loadedPicker(t)
	m, cmds := feed(m, press("ctrl+f"))
	if want := []any{protocol.NewFavorite("local", "llama-3", false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("on a favorite: commands = %v, want %v", cmds, want)
	}
	m = goTo(t, m, "local/qwen-coder")
	_, cmds = feed(m, press("ctrl+f"))
	if want := []any{protocol.NewFavorite("local", "qwen-coder", true)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("on another model: commands = %v, want %v", cmds, want)
	}
}

func TestLeftAndRightStepThroughTheThinkingLevels(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	if m.Picker.Level != "off" {
		t.Fatalf("Level = %q at the start, want off", m.Picker.Level)
	}
	steps := []struct {
		key  string
		want string
	}{
		{"right", "low"},
		{"right", "high"},
		{"right", "high"},
		{"left", "low"},
		{"left", "off"},
		{"left", "off"},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if m.Picker.Level != s.want {
			t.Fatalf("after %s: Level = %q, want %q", s.key, m.Picker.Level, s.want)
		}
	}
}

func TestLevelKeysNeedAModelWithThinkingControl(t *testing.T) {
	m, cmds := feed(loadedPicker(t), press("right"))
	if len(cmds) != 0 || m.Flash != "thinking control is not available for this model" || m.Picker.Level != "" {
		t.Errorf("commands = %v, Flash = %q, Level = %q; want the note and no level", cmds, m.Flash, m.Picker.Level)
	}
}

func TestModelKeysDoNothingOnTheAddRow(t *testing.T) {
	m := goTo(t, loadedPicker(t), "add")
	m, cmds := feed(m, press("right"), press("ctrl+s"), press("ctrl+f"), pickerKey("ctrl+x"))
	if len(cmds) != 0 || m.Flash != "" || !m.Picker.Open {
		t.Errorf("commands = %v, Flash = %q, Open = %v; want the keys ignored on the add row", cmds, m.Flash, m.Picker.Open)
	}
}

func TestAChosenLevelResetsWhenTheHighlightMoves(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	m, _ = feed(m, press("right"))
	m, _ = feed(m, press("up"))
	if m.Picker.Level != "off" {
		t.Errorf("on qwen-coder: Level = %q, want its current off", m.Picker.Level)
	}
	m, _ = feed(m, press("down"))
	if m.Picker.Level != "off" {
		t.Errorf("back on deepseek-r1: Level = %q, want its default off, not the low chosen before", m.Picker.Level)
	}
}

func TestAModelStartsAtItsProfilesThinkingLevel(t *testing.T) {
	catalogue := pickerModels()
	catalogue.Current = protocol.ModelRef{Profile: "cloud", Model: "gpt-x", Thinking: "low"}
	catalogue.Entries[0].ThinkingDefault = ptr("on")
	m, _ := feed(testModel(), typed("/models"), press("enter"), catalogue)
	m = goTo(t, m, "local/qwen-coder")
	if m.Picker.Level != "on" {
		t.Fatalf("Level = %q on local/qwen-coder; want on", m.Picker.Level)
	}
	_, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewSetModel("local", "qwen-coder", "on", false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestTheCurrentModelKeepsItsLevelOverTheProfilesLevel(t *testing.T) {
	catalogue := pickerModels()
	catalogue.Entries[0].ThinkingDefault = ptr("on")
	m, _ := feed(testModel(), typed("/models"), press("enter"), catalogue)
	m = goTo(t, m, "local/qwen-coder")
	if m.Picker.Level != "off" {
		t.Fatalf("Level = %q on local/qwen-coder; want its current off", m.Picker.Level)
	}
	_, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewSetModel("local", "qwen-coder", "off", false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestTheCurrentLevelTheBackendNamesIsTheOldOneOfTheList(t *testing.T) {
	catalogue := pickerModels()
	catalogue.Current = protocol.ModelRef{Profile: "local", Model: "qwen-coder", Thinking: "medium"}
	m, _ := feed(testModel(), typed("/models"), press("enter"), catalogue)
	m = goTo(t, m, "local/qwen-coder")
	if m.Picker.Level != "on" {
		t.Fatalf("Level = %q on local/qwen-coder; want on, the old name of medium", m.Picker.Level)
	}
	_, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewSetModel("local", "qwen-coder", "on", false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestAModelWithoutAProfileLevelStartsAtOff(t *testing.T) {
	m := onModel(t, "cloud/gpt-x")
	if m.Picker.Level != "off" {
		t.Fatalf("Level = %q on cloud/gpt-x; want off", m.Picker.Level)
	}
	_, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewSetModel("cloud", "gpt-x", "off", false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestFavoriteKeepsTheHighlightOnItsModel(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	m, cmds := feed(m, press("ctrl+f"))
	if want := []any{protocol.NewFavorite("local", "deepseek-r1", true)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	catalogue := pickerModels()
	catalogue.Favorites = append(catalogue.Favorites, protocol.ModelRef{Profile: "local", Model: "deepseek-r1"})
	m, _ = feed(m, catalogue)
	if got := rowName(m); got != "local/deepseek-r1" {
		t.Errorf("highlight = %s after the favorite, want local/deepseek-r1", got)
	}
	if got := m.Picker.Visible()[1].Model; got != "deepseek-r1" {
		t.Errorf("the second model is %s, want deepseek-r1 listed with the favorites", got)
	}
}

func TestRefreshKeepsTheHighlightAndItsLevel(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	m, _ = feed(m, press("right"))
	m, cmds := feed(m, press("ctrl+r"), press("ctrl+r"))
	if want := []any{protocol.NewListModels(true), protocol.NewListModels(true)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	m, _ = feed(m, pickerModels())
	if got := rowName(m); got != "local/deepseek-r1" || m.Picker.Level != "low" {
		t.Errorf("after the refresh: highlight = %s, Level = %q; want local/deepseek-r1 and low", got, m.Picker.Level)
	}
}

func TestRefreshStartsAtTheTopWhenTheModelIsGone(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	m, _ = feed(m, press("ctrl+r"))
	gone := pickerModels()
	gone.Entries = gone.Entries[:2]
	m, _ = feed(m, gone)
	if got := rowName(m); got != "local/llama-3" {
		t.Errorf("highlight = %s, want the top, local/llama-3", got)
	}
}

func TestRefreshKeepsTheLevelUnderItsCanonicalName(t *testing.T) {
	catalogue := pickerModels()
	catalogue.Entries[2].Thinking = []string{"off", "on", "high"}
	m, _ := feed(testModel(), typed("/models"), press("enter"), catalogue)
	m = goTo(t, m, "local/deepseek-r1")
	m, _ = feed(m, press("right"))
	if m.Picker.Level != "on" {
		t.Fatalf("Level = %q on local/deepseek-r1; want on", m.Picker.Level)
	}
	m, _ = feed(m, press("ctrl+r"))
	renamed := pickerModels()
	renamed.Entries[2].Thinking = []string{"none", "medium", "high"}
	m, _ = feed(m, renamed)
	if got := rowName(m); got != "local/deepseek-r1" || m.Picker.Level != "medium" {
		t.Errorf("after the refresh: highlight = %s, Level = %q; want local/deepseek-r1 at medium, the on it had", got, m.Picker.Level)
	}
}

func TestRefreshKeepsTheAddRow(t *testing.T) {
	m := goTo(t, loadedPicker(t), "add")
	m, _ = feed(m, press("ctrl+r"))
	m, _ = feed(m, pickerModels())
	if got := rowName(m); got != "add" {
		t.Errorf("highlight = %s after the refresh, want the add row", got)
	}
}

func TestOpeningThePickerStartsAtTheTop(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	m, _ = feed(m, press("esc"))
	m, _ = feed(m, typed("/models"), press("enter"), pickerModels())
	if got := rowName(m); got != "local/llama-3" {
		t.Errorf("highlight = %s after reopening, want the top, local/llama-3", got)
	}
}

func TestEscAndCtrlCCloseThePickerWithoutSending(t *testing.T) {
	for _, key := range []string{"esc", "ctrl+c"} {
		t.Run(key, func(t *testing.T) {
			m := loadedPicker(t)
			m, cmds := feed(m, typed("gp"), press(key))
			if len(cmds) != 0 || m.Picker.Open || m.Quit {
				t.Errorf("commands = %v, Open = %v, Quit = %v; want nothing sent and the picker closed", cmds, m.Picker.Open, m.Quit)
			}
			if m.Picker.Query != "" || m.Input != "" {
				t.Errorf("Query = %q, Input = %q; want both cleared", m.Picker.Query, m.Input)
			}
		})
	}
}

func TestCtrlCClosesThePickerFromTheFormAndTheCustomInput(t *testing.T) {
	if m, _ := feed(formOpen(t), press("ctrl+c")); m.Picker.Open || m.Picker.Form != nil {
		t.Error("ctrl+c did not close the picker from the form")
	}
	m, _ := feed(onModel(t, "local/qwen-coder"), pickerKey("ctrl+n"))
	if m, _ = feed(m, press("ctrl+c")); m.Picker.Open || m.Picker.CustomOpen {
		t.Error("ctrl+c did not close the picker from the custom model id input")
	}
}

func TestCtrlRAsksForAFreshCatalogue(t *testing.T) {
	m := loadedPicker(t)
	m, cmds := feed(m, press("ctrl+r"))
	if want := []any{protocol.NewListModels(true)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Picker.Loading {
		t.Error("Loading = false after a refresh, want true")
	}

	m = loadedPicker(t)
	m.Busy = true
	m, cmds = feed(m, press("ctrl+r"))
	if len(cmds) != 0 || m.Picker.Loading || m.Flash != "wait for the turn to finish" {
		t.Errorf("during a turn: commands = %v, Loading = %v, Flash = %q; want the refresh refused", cmds, m.Picker.Loading, m.Flash)
	}
}

func TestHighlightWrapsAroundTheModelsAndTheAddRow(t *testing.T) {
	m := loadedPicker(t)
	steps := []struct {
		key  string
		want string
	}{
		{"up", "add"},
		{"up", "local/my-model"},
		{"down", "add"},
		{"down", "local/llama-3"},
		{"down", "cloud/gpt-x"},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if got := rowName(m); got != s.want {
			t.Fatalf("after %s: highlight = %s, want %s", s.key, got, s.want)
		}
	}
}

func TestPageKeysMoveAPageAndStopAtTheEnds(t *testing.T) {
	m := loadedPicker(t)
	m.Height = 4
	steps := []struct {
		key  string
		want int
	}{
		{"pgdown", 2},
		{"pgdown", 4},
		{"pgdown", 5},
		{"pgdown", 5},
		{"pgup", 3},
		{"pgup", 1},
		{"pgup", 0},
		{"pgup", 0},
	}
	for _, s := range steps {
		m, _ = feed(m, press(s.key))
		if m.Picker.Selected != s.want {
			t.Fatalf("after %s: Selected = %d, want %d", s.key, m.Picker.Selected, s.want)
		}
	}
}

func TestThePickerTakesEveryKey(t *testing.T) {
	m := loadedPicker(t)
	m.Input = "draft"
	m.Cursor = 5
	for _, key := range []string{"ctrl+d", "ctrl+u", "tab", "ctrl+k", "alt+enter", "home", "alt+up", "shift+tab"} {
		next, cmds := feed(m, pickerKey(key))
		if len(cmds) != 0 || next.Input != "draft" || next.Quit || !next.Picker.Open {
			t.Errorf("%s: commands = %v, Input = %q, Quit = %v, Open = %v; want the key ignored by the picker",
				key, cmds, next.Input, next.Quit, next.Picker.Open)
		}
	}
	if m.Input != "draft" {
		t.Errorf("Input = %q after the keys, want it unchanged", m.Input)
	}
}

func TestPastedTextKeepsItsPrintableCharacters(t *testing.T) {
	m := loadedPicker(t)
	m, cmds := feed(m, pasted("q\nw\x07"))
	if len(cmds) != 0 || m.Picker.Query != "qw" {
		t.Errorf("Query = %q, commands = %v; want qw and nothing sent", m.Picker.Query, cmds)
	}
}

func TestAddRowStaysWhenTheFilterMatchesNothing(t *testing.T) {
	m := loadedPicker(t)
	m, _ = feed(m, typed("zz"))
	if got := rowName(m); got != "add" {
		t.Fatalf("highlight = %s with no model listed, want the add row", got)
	}
	m, _ = feed(m, press("enter"))
	if m.Picker.Connect == nil {
		t.Error("enter on the add row did not open the list of providers")
	}
}

func TestNoticesAnswerAModelCommandInThePicker(t *testing.T) {
	m := loadedPicker(t)
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewSetModel("local", "llama-3", "", false)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "unknown profile"})
	if m.Picker.Error != "unknown profile" || !m.Picker.Open {
		t.Errorf("Error = %q, Open = %v; want the refused change shown in the picker", m.Picker.Error, m.Picker.Open)
	}

	m, cmds = feed(m, press("ctrl+f"))
	if want := []any{protocol.NewFavorite("local", "llama-3", false)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "favorites are read only"})
	if m.Picker.Error != "favorites are read only" || m.Picker.Loading {
		t.Errorf("Error = %q, Loading = %v; want the warning shown in the picker", m.Picker.Error, m.Picker.Loading)
	}

	m, _ = feed(m, pickerModels())
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "too late"})
	if m.Picker.Error != "" {
		t.Errorf("Error = %q after the catalogue answered, want the picker to be idle", m.Picker.Error)
	}
}

func TestErrorNoticesWhileThePickerWaitsAreShownInIt(t *testing.T) {
	m := loadedPicker(t)
	m, _ = feed(m, press("ctrl+r"))
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: "listing models"})
	if !m.Picker.Loading || m.Picker.Error != "" {
		t.Errorf("Loading = %v, Error = %q; want the wait kept by an info notice", m.Picker.Loading, m.Picker.Error)
	}

	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "a profile is slow"})
	if m.Picker.Loading || m.Picker.Error != "a profile is slow" {
		t.Errorf("Loading = %v, Error = %q; want the wait ended by the warning", m.Picker.Loading, m.Picker.Error)
	}

	m, _ = feed(m, press("ctrl+r"))
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "the catalogue could not be read"})
	if m.Picker.Loading || m.Picker.Error != "the catalogue could not be read" {
		t.Errorf("Loading = %v, Error = %q; want the wait ended by the error", m.Picker.Loading, m.Picker.Error)
	}
	if last := m.Blocks[len(m.Blocks)-1]; last.Kind != state.KindNotice || last.Text != "the catalogue could not be read" {
		t.Errorf("the error is not in the transcript: %+v", last)
	}

	m, _ = feed(m, pickerModels())
	if m.Picker.Error != "" {
		t.Errorf("Error = %q after a new catalogue, want it cleared", m.Picker.Error)
	}
}

func TestNoticesLeaveAnIdlePickerAlone(t *testing.T) {
	m := loadedPicker(t)
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "a profile is slow"})
	if m.Picker.Error != "" || !m.Picker.Open {
		t.Errorf("Error = %q, Open = %v; want the idle picker untouched", m.Picker.Error, m.Picker.Open)
	}
	if last := m.Blocks[len(m.Blocks)-1]; last.Kind != state.KindNotice || last.Text != "a profile is slow" {
		t.Errorf("the warning is not in the transcript: %+v", last)
	}
}

func TestNoticesDoNotTouchAClosedPicker(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "boom"})
	if m.Picker.Error != "" || m.Picker.Loading {
		t.Errorf("Error = %q, Loading = %v; want the closed picker untouched", m.Picker.Error, m.Picker.Loading)
	}
}

func TestCtrlNOpensTheCustomInputForTheProviderOfTheHighlight(t *testing.T) {
	m := onModel(t, "cloud/gpt-x")
	m, cmds := feed(m, pickerKey("ctrl+n"))
	if len(cmds) != 0 || !m.Picker.CustomOpen || m.Picker.CustomFor != "cloud" {
		t.Errorf("commands = %v, CustomOpen = %v, CustomFor = %q; want the input open for cloud and nothing sent",
			cmds, m.Picker.CustomOpen, m.Picker.CustomFor)
	}
}

func TestCtrlNOnTheAddRowTargetsTheProviderThatListsNothing(t *testing.T) {
	m, _ := feed(goTo(t, loadedPicker(t), "add"), pickerKey("ctrl+n"))
	if !m.Picker.CustomOpen || m.Picker.CustomFor != "remote" {
		t.Errorf("CustomOpen = %v, CustomFor = %q; want the input open for remote, which lists no model", m.Picker.CustomOpen, m.Picker.CustomFor)
	}
}

func TestCtrlNNeedsAProvider(t *testing.T) {
	m, _ := feed(testModel(), typed("/models"), press("enter"), &protocol.Models{Type: protocol.TypeModels})
	m, cmds := feed(m, pickerKey("ctrl+n"))
	if len(cmds) != 0 || m.Picker.CustomOpen || m.Picker.Error != "add a provider first" {
		t.Errorf("commands = %v, CustomOpen = %v, Error = %q; want the input refused", cmds, m.Picker.CustomOpen, m.Picker.Error)
	}
}

func TestCustomModelIdAddsAndAppliesTheTypedID(t *testing.T) {
	m := onModel(t, "local/qwen-coder")
	m, _ = feed(m, pickerKey("ctrl+n"), typed("  qwen-x-7b "))
	m, cmds := feed(m, press("enter"))
	want := []any{
		protocol.NewCustomModel("local", "qwen-x-7b", protocol.CustomAdd),
		protocol.NewSetModel("local", "qwen-x-7b", "", false),
	}
	if !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Picker.CustomOpen || m.Picker.CustomID != "" || m.Picker.CustomFor != "" || !m.Picker.Open || !m.Picker.Pending {
		t.Errorf("CustomOpen = %v, CustomID = %q, CustomFor = %q, Open = %v, Pending = %v; want the input closed and the picker waiting",
			m.Picker.CustomOpen, m.Picker.CustomID, m.Picker.CustomFor, m.Picker.Open, m.Picker.Pending)
	}

	catalogue := pickerModels()
	catalogue.Entries = append(catalogue.Entries, protocol.ModelEntry{Profile: "local", Model: "qwen-x-7b", Protocol: "openai", Custom: true})
	m, _ = feed(m, catalogue)
	if got := rowName(m); got != "local/qwen-x-7b" {
		t.Errorf("highlight = %s after the catalogue, want the new id", got)
	}

	m, _ = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "qwen-x-7b"})
	if m.Picker.Open {
		t.Error("the picker is still open after the model changed")
	}
}

func TestCustomModelIdEscCancelsTheInputOnly(t *testing.T) {
	m := onModel(t, "local/qwen-coder")
	m, _ = feed(m, pickerKey("ctrl+n"), typed("abc"))
	m, cmds := feed(m, press("esc"))
	if len(cmds) != 0 || m.Picker.CustomOpen || m.Picker.CustomID != "" || !m.Picker.Open {
		t.Errorf("commands = %v, CustomOpen = %v, CustomID = %q, Open = %v; want the input cancelled and nothing sent",
			cmds, m.Picker.CustomOpen, m.Picker.CustomID, m.Picker.Open)
	}
	if got := rowName(m); got != "local/qwen-coder" {
		t.Errorf("highlight = %s after cancelling, want it kept", got)
	}
}

func TestCustomModelIdNeedsAnIDAndTakesEdits(t *testing.T) {
	m := onModel(t, "local/qwen-coder")
	m, cmds := feed(m, pickerKey("ctrl+n"), press("enter"))
	if len(cmds) != 0 || m.Picker.Error != "type a model id" || !m.Picker.CustomOpen {
		t.Errorf("commands = %v, Error = %q, CustomOpen = %v; want the empty id refused and the input kept", cmds, m.Picker.Error, m.Picker.CustomOpen)
	}

	m, _ = feed(m, typed("abc"), press("backspace"), pasted("x\ny"))
	if m.Picker.CustomID != "abxy" {
		t.Errorf("CustomID = %q, want abxy: backspace removes one character and a paste keeps its printable ones", m.Picker.CustomID)
	}
}

func TestCustomModelIdWhileBusyStaysOpen(t *testing.T) {
	m := onModel(t, "local/qwen-coder")
	m, _ = feed(m, pickerKey("ctrl+n"), typed("qwen-x"))
	m.Busy = true
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Picker.Error != "wait for the turn to finish" || !m.Picker.CustomOpen || m.Picker.CustomID != "qwen-x" {
		t.Errorf("commands = %v, Error = %q, CustomOpen = %v, CustomID = %q; want the id refused and kept",
			cmds, m.Picker.Error, m.Picker.CustomOpen, m.Picker.CustomID)
	}

	m.Busy = false
	_, cmds = feed(m, press("enter"))
	if len(cmds) != 2 {
		t.Errorf("commands = %v after the turn, want the id sent", cmds)
	}
}

func TestCtrlXRemovesACustomIDOnly(t *testing.T) {
	m := onModel(t, "local/my-model")
	_, cmds := feed(m, pickerKey("ctrl+x"))
	if want := []any{protocol.NewCustomModel("local", "my-model", protocol.CustomRemove)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("on a custom id: commands = %v, want %v", cmds, want)
	}

	other, cmds := feed(onModel(t, "local/llama-3"), pickerKey("ctrl+x"))
	if len(cmds) != 0 || other.Flash != "only a custom model id can be removed" {
		t.Errorf("on llama-3: commands = %v, Flash = %q; want nothing sent and the reason flashed", cmds, other.Flash)
	}

	m.Busy = true
	m, cmds = feed(m, pickerKey("ctrl+x"))
	if len(cmds) != 0 || m.Picker.Error != "wait for the turn to finish" {
		t.Errorf("during a turn: commands = %v, Error = %q; want the removal refused", cmds, m.Picker.Error)
	}
}

func TestEnterOnTheAddRowAndCtrlAOpenTheListOfProvidersAndTheCustomRowOpensTheForm(t *testing.T) {
	m, cmds := feed(loadedPicker(t), press("ctrl+a"))
	if m.Picker.Connect == nil || m.Picker.Connect.Stage != state.ConnectChoose || !m.Picker.Detecting {
		t.Fatalf("Connect = %+v, Detecting = %v; want the list open, looking for local servers", m.Picker.Connect, m.Picker.Detecting)
	}
	if want := []any{protocol.NewDetectEndpoints()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	m, _ = feed(goTo(t, loadedPicker(t), "add"), press("enter"))
	if m.Picker.Connect == nil {
		t.Error("enter on the add row did not open the list of providers")
	}
	f := formOpen(t).Picker.Form
	if f.Focus != state.FieldName || f.Values[state.FieldProtocol] != "openai" {
		t.Errorf("form = %+v; want the cursor on the name and openai", *f)
	}
}

func TestAddProviderFormTabOrderAndTypedText(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"))
	if m.Picker.Form.Focus != state.FieldName || m.Picker.Form.Values[state.FieldName] != "home" {
		t.Fatalf("name = %q on field %d, want home typed on the name", m.Picker.Form.Values[state.FieldName], m.Picker.Form.Focus)
	}
	var order []int
	m, _ = feed(m, press("tab"), typed("zz"), press("right"), press("left"))
	order = append(order, m.Picker.Form.Focus)
	if m.Picker.Form.Values[state.FieldProtocol] != "openai" {
		t.Errorf("on the protocol: protocol = %q; want it kept after the arrows and typing ignored", m.Picker.Form.Values[state.FieldProtocol])
	}
	for range 5 {
		m, _ = feed(m, press("tab"))
		order = append(order, m.Picker.Form.Focus)
	}
	if want := []int{state.FieldProtocol, state.FieldBaseURL, state.FieldAPIKey, state.FieldAPIKeyEnv, state.FieldModel, state.FieldName}; !reflect.DeepEqual(order, want) {
		t.Errorf("Tab visits %v, want %v", order, want)
	}

	m, _ = feed(formOpen(t), pickerKey("shift+tab"), typed("x"))
	if m.Picker.Form.Focus != state.FieldModel || m.Picker.Form.Values[state.FieldModel] != "x" {
		t.Errorf("Shift+Tab from the name: focus = %d, model = %q; want the model field with x", m.Picker.Form.Focus, m.Picker.Form.Values[state.FieldModel])
	}
}

func TestAddProviderFormValidatesBeforeSending(t *testing.T) {
	m := formOpen(t)
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Picker.Error != "name is required" || m.Picker.Form == nil || m.Picker.Form.Focus != state.FieldName {
		t.Errorf("commands = %v, Error = %q, Focus = %d; want the name asked for and nothing sent", cmds, m.Picker.Error, m.Picker.Form.Focus)
	}

	m, cmds = feed(m, typed("home"), press("enter"))
	if len(cmds) != 0 || m.Picker.Error != "base_url is required" || m.Picker.Form.Focus != state.FieldBaseURL {
		t.Errorf("commands = %v, Error = %q, Focus = %d; want base_url asked for and nothing sent", cmds, m.Picker.Error, m.Picker.Form.Focus)
	}
}

func TestAddProviderFormSendsItsFieldsAndWaitsForTheBackend(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"), press("tab"), press("right"), press("tab"), typed("http://127.0.0.1:11434/v1/"))
	m, cmds := feed(withModel(m, "gemma4:e2b"), press("enter"))
	want := []any{protocol.NewAddProvider("home", "ollama", "http://127.0.0.1:11434", "", "gemma4:e2b")}
	if !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v: the /v1 that the address came with is dropped", cmds, want)
	}
	if m.Picker.Form == nil || m.Picker.Form.Sent != "home" || !m.Picker.Pending {
		t.Fatalf("Form = %+v, Pending = %v; want the form waiting for home", m.Picker.Form, m.Picker.Pending)
	}

	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "the name home is already used"})
	if m.Picker.Form == nil || m.Picker.Error != "the name home is already used" {
		t.Fatalf("Form = %v, Error = %q; want the refusal shown in the open form", m.Picker.Form, m.Picker.Error)
	}

	m, cmds = feed(m, press("enter"))
	if len(cmds) != 1 || m.Picker.Error != "" {
		t.Fatalf("commands = %v, Error = %q; want the form sent again with the error cleared", cmds, m.Picker.Error)
	}

	catalogue := pickerModels()
	catalogue.Providers = append(catalogue.Providers, protocol.Provider{Profile: "home", Protocol: "ollama", Endpoint: "http://127.0.0.1:11434", Count: 1})
	catalogue.Entries = append(catalogue.Entries, protocol.ModelEntry{Profile: "home", Model: "gemma", Protocol: "ollama"})
	m, _ = feed(m, catalogue)
	if m.Picker.Form != nil || m.Picker.Error != "" || m.Picker.Pending {
		t.Errorf("Form = %v, Error = %q, Pending = %v; want the form closed once home is listed", m.Picker.Form, m.Picker.Error, m.Picker.Pending)
	}
	if got := rowName(m); got != "home/gemma" {
		t.Errorf("highlight = %s, want the first model of the new provider, home/gemma", got)
	}
	if m.Flash != "connected home · 1 model" {
		t.Errorf("Flash = %q, want connected home · 1 model", m.Flash)
	}
}

func TestAServerThatAnswersWithAProblemTurnsTheFormIntoTheKeyLineWithTheProblemShown(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"), press("tab"), press("tab"), typed("https://api.example.com"))
	m, _ = feed(m, press("enter"))
	refused := "HTTP 401: Invalid API Key"
	catalogue := pickerModels()
	catalogue.Providers = append(catalogue.Providers, protocol.Provider{Profile: "home", Protocol: "openai", Endpoint: "https://api.example.com", Error: &refused})
	m, _ = feed(m, catalogue)
	c := m.Picker.Connect
	if m.Picker.Form != nil || c == nil || c.Stage != state.ConnectKey || c.Error != refused || !c.Target.Existing || c.Target.Name != "home" || c.Pending {
		t.Fatalf("Form = %v, Connect = %+v; want the key line for home, saved, with the server's problem under it", m.Picker.Form, c)
	}
	if m.Picker.Pending {
		t.Error("the picker still waits after the answer")
	}
	m, cmds := feed(m, typed("sk-new-key"), press("enter"))
	if want := []any{protocol.NewSetProviderKey("home", "sk-new-key")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the provider is in the configuration, so the key is its own", cmds, want)
	}
}

func TestAServerOfOneOwnThatDoesNotAnswerIsListedWithItsProblemAndNotAskedForAKey(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"), press("tab"), press("tab"), typed("http://127.0.0.1:8082"), press("enter"))
	down := "connection to http://127.0.0.1:8082/v1/models failed: Failed to connect"
	catalogue := pickerModels()
	catalogue.Providers = append(catalogue.Providers, protocol.Provider{Profile: "home", Protocol: "openai", Endpoint: "http://127.0.0.1:8082", Error: &down})
	m, _ = feed(m, catalogue)
	if c := m.Picker.Connect; m.Picker.Form != nil || c == nil || c.Stage != state.ConnectChoose || c.Pending {
		t.Fatalf("Form = %v, Connect = %+v; want the list with home and no key line", m.Picker.Form, c)
	}
	if row, ok := m.Picker.ConnectHighlighted(); !ok || row.Title != "home" || row.Status != "not answering" {
		t.Errorf("highlight = %+v; want home, with its problem, highlighted", row)
	}
}

func TestAddProviderFormKeepsWaitingForAnUnrelatedCatalogue(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"), press("tab"), press("tab"), typed("http://127.0.0.1:11434"))
	m, _ = feed(withModel(m, "m1"), press("enter"))
	m, _ = feed(m, pickerModels())
	if m.Picker.Form == nil || !m.Picker.Pending {
		t.Fatalf("Form = %v, Pending = %v; want the form still waiting for its answer", m.Picker.Form, m.Picker.Pending)
	}
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "the name home is taken"})
	if m.Picker.Error != "the name home is taken" {
		t.Errorf("Error = %q, want the refusal shown", m.Picker.Error)
	}
}

func TestAddProviderFormSendsTheOptionalFields(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m,
		typed("cloud"), press("tab"), press("right"), press("right"), press("tab"),
		typed("https://api.example.com"), press("tab"), press("tab"),
		typed("  EXAMPLE_KEY "), press("tab"),
		typed(" gpt-x "),
	)
	_, cmds := feed(m, press("enter"))
	want := []any{protocol.NewAddProvider("cloud", "openai-responses", "https://api.example.com", "EXAMPLE_KEY", "gpt-x")}
	if !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestAddProviderFormWhileBusyIsRefused(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"), press("tab"), press("tab"), typed("http://127.0.0.1:11434"))
	m = withModel(m, "m1")
	m.Busy = true
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Picker.Error != "wait for the turn to finish" || m.Picker.Form == nil || m.Picker.Pending {
		t.Errorf("commands = %v, Error = %q, Form = %v, Pending = %v; want the form kept and nothing sent",
			cmds, m.Picker.Error, m.Picker.Form, m.Picker.Pending)
	}

	m.Busy = false
	_, cmds = feed(m, press("enter"))
	if len(cmds) != 1 {
		t.Errorf("commands = %v after the turn, want the form sent", cmds)
	}
}

func TestEscCancelsTheProviderFormAndItsLaterNoticesAreNotShown(t *testing.T) {
	m := formOpen(t)
	m, cmds := feed(m, typed("half"), press("esc"))
	if len(cmds) != 0 || m.Picker.Form != nil || !m.Picker.Open || m.Picker.Pending {
		t.Errorf("commands = %v, Form = %v, Open = %v, Pending = %v; want the form cancelled and nothing sent",
			cmds, m.Picker.Form, m.Picker.Open, m.Picker.Pending)
	}
	if got := rowName(m); got != "local/llama-3" {
		t.Errorf("highlight = %s after cancelling, want the highlight kept", got)
	}
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "late"})
	if m.Picker.Error != "" {
		t.Errorf("Error = %q after the form was cancelled, want it untouched", m.Picker.Error)
	}
}

func TestTheKeyGoesOutOnceAndTheFormForgetsIt(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"), press("tab"), press("tab"), typed("http://127.0.0.1:11434"), press("tab"), typed("sk-test-123"))
	m, cmds := feed(withModel(m, "m1"), press("enter"))
	want := []any{protocol.NewAddProvider("home", "openai", "http://127.0.0.1:11434", "", "m1").WithAPIKey("sk-test-123")}
	if !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if m.Picker.Form == nil || m.Picker.Form.Sent != "home" || m.Picker.Form.Values[state.FieldAPIKey] != "" {
		t.Fatalf("Form = %+v; want the form waiting for home with the key forgotten", m.Picker.Form)
	}

	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "the name home is already used"})
	if m.Picker.Form == nil || m.Picker.Form.Values[state.FieldAPIKey] != "" || m.Picker.Error != "the name home is already used" {
		t.Errorf("after a refusal: Form = %+v, Error = %q; want the key still forgotten", m.Picker.Form, m.Picker.Error)
	}
}

func TestAKeyAndAKeyVariableTogetherAreRefusedBeforeTheyAreSent(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("home"), press("tab"), press("tab"), typed("http://127.0.0.1:11434"), press("tab"), typed("sk-test"),
		press("tab"), typed("OPENAI_API_KEY"))
	m, cmds := feed(withModel(m, "m1"), press("enter"))
	if len(cmds) != 0 || m.Picker.Pending || m.Picker.Error != "use api_key or api_key_env, not both" ||
		m.Picker.Form.Focus != state.FieldAPIKeyEnv || m.Picker.Form.Values[state.FieldAPIKey] != "sk-test" {
		t.Errorf("commands = %v, Pending = %v, Error = %q, Focus = %d; want the form kept with the key and nothing sent",
			cmds, m.Picker.Pending, m.Picker.Error, m.Picker.Form.Focus)
	}
}

func TestPickerFlowSwitchesTheModelForTheNextTurn(t *testing.T) {
	m, cmds := feed(testModel(),
		typed("/models"), press("enter"),
		pickerModels(),
		typed("deep"),
		press("right"),
		press("enter"),
		&protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "deepseek-r1", Thinking: "low"},
		typed("hola"), press("enter"),
	)
	wantCmds := []any{
		protocol.NewListModels(false),
		protocol.NewSetModel("local", "deepseek-r1", "low", false),
		protocol.NewInput("hola"),
	}
	if !reflect.DeepEqual(cmds, wantCmds) {
		t.Errorf("commands = %v, want %v", cmds, wantCmds)
	}
	if m.Picker.Open {
		t.Error("the picker is still open after the change")
	}
	if want := []state.Block{{Kind: state.KindUser, Text: "hola"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want %+v: the picker commands must not appear in the transcript", m.Blocks, want)
	}
	if !m.Busy {
		t.Error("Busy = false after the next message, want a running turn")
	}
}

func TestModelChangedClosesThePickerAndSaysWhenTheCacheResets(t *testing.T) {
	m := onModel(t, "local/deepseek-r1")
	m.App.Model = "qwen-coder"
	m, cmds := feed(m, press("right"), press("enter"))
	if want := []any{protocol.NewSetModel("local", "deepseek-r1", "low", false)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}

	m, cmds = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "deepseek-r1", Thinking: "low"})
	if len(cmds) != 0 || m.Picker.Open {
		t.Errorf("commands = %v, Open = %v; want the picker closed and nothing sent", cmds, m.Picker.Open)
	}
	if m.App.Profile != "local" || m.App.Model != "deepseek-r1" || m.App.Thinking != "low" {
		t.Errorf("App = %+v; want local, deepseek-r1 and low", m.App)
	}
	if want := (protocol.ModelRef{Profile: "local", Model: "deepseek-r1", Thinking: "low"}); m.Picker.Current != want {
		t.Errorf("Current = %+v, want %+v", m.Picker.Current, want)
	}
	want := "thinking low · applies from the next turn · the prompt cache resets"
	if m.Flash != want {
		t.Errorf("Flash = %q, want %q", m.Flash, want)
	}
}

func TestModelChangedKeepsTheCacheForTheSameModel(t *testing.T) {
	m := testModel()
	m.App.Model = "qwen-coder"
	m.Picker.Applying = true
	m, _ = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "qwen-coder", Thinking: "on"})
	if want := "thinking on · applies from the next turn"; m.Flash != want {
		t.Errorf("Flash = %q, want %q", m.Flash, want)
	}
}

func TestModelChangedShowsADashWithoutThinkingControl(t *testing.T) {
	m := testModel()
	m.Picker.Applying = true
	m, _ = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "cloud", Model: "gpt-x"})
	if want := "thinking – · applies from the next turn · the prompt cache resets"; m.Flash != want {
		t.Errorf("Flash = %q, want %q", m.Flash, want)
	}
}

func TestModelChangedSaysWhenItAppliesOnlyForAPickerChange(t *testing.T) {
	change := &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "deepseek-r1", Thinking: "low"}

	m := testModel()
	m.App.Model = "qwen-coder"
	m, _ = feed(m, change)
	if m.Flash != "" {
		t.Errorf("Flash = %q after a change the picker did not ask for, want none", m.Flash)
	}
	if m.App.Model != "deepseek-r1" || m.Effort.Level != "low" {
		t.Errorf("App.Model = %q, Effort.Level = %q; want the new model and effort kept", m.App.Model, m.Effort.Level)
	}

	m = onModel(t, "local/deepseek-r1")
	m.App.Model = "qwen-coder"
	m, _ = feed(m, press("right"), press("enter"), change)
	if want := "thinking low · applies from the next turn · the prompt cache resets"; m.Flash != want {
		t.Errorf("Flash = %q after the change the picker asked for, want %q", m.Flash, want)
	}
}

func TestModelChangedSetsTheEffortByItsCanonicalName(t *testing.T) {
	for _, tt := range []struct{ thinking, want string }{
		{"low", "low"},
		{"medium", "medium"},
		{"on", "medium"},
		{"off", "none"},
		{"none", "none"},
		{"", ""},
	} {
		m, _ := feed(testModel(), &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "qwen", Thinking: tt.thinking})
		if m.Effort.Level != tt.want {
			t.Errorf("thinking %q: Effort.Level = %q, want %q", tt.thinking, m.Effort.Level, tt.want)
		}
	}
}

func TestTheStatusFollowsTheModelSwitchAndTheEffortEvent(t *testing.T) {
	m, _ := feed(state.New(), tea.WindowSizeMsg{Width: 200, Height: 10},
		&protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version, App: protocol.AppInfo{Model: "qwen", Thinking: "medium"}})
	if status := statusOf(m); !strings.Contains(status, "effort medium") {
		t.Fatalf("status = %q at the start, want effort medium", status)
	}
	m, _ = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "cloud", Model: "gpt-x", Thinking: "none"})
	if status := statusOf(m); strings.Contains(status, "effort") {
		t.Errorf("status = %q after a switch to none, want no effort slot", status)
	}
	m, _ = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "deepseek-r1", Thinking: "on"})
	if status := statusOf(m); !strings.Contains(status, "effort medium") {
		t.Errorf("status = %q after a switch to on, want effort medium", status)
	}
	m, _ = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "deepseek-r1", Thinking: "off"})
	if status := statusOf(m); strings.Contains(status, "effort") {
		t.Errorf("status = %q after a switch to the old off, want no effort slot", status)
	}
	m, _ = feed(m, &protocol.Effort{Type: protocol.TypeEffort, Level: "high", Levels: effortLevels()})
	if status := statusOf(m); !strings.Contains(status, "effort high") {
		t.Errorf("status = %q after the effort event, want effort high", status)
	}
}

func TestReadyAndTurnEndKeepTheBranchAndTheUsage(t *testing.T) {
	main := "main"
	m, _ := feed(testModel(), &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version, App: protocol.AppInfo{Branch: &main}})
	if m.Branch != "main" {
		t.Errorf("Branch = %q after ready, want main", m.Branch)
	}

	feature := "feature/picker"
	prompt, rate := 812, 41.5
	m, _ = feed(m, &protocol.TurnEnd{
		Type: protocol.TypeTurnEnd, Status: "completed",
		Usage:  &protocol.Usage{PromptTokens: &prompt, TokensPerSecond: &rate},
		Branch: &feature,
	})
	if m.Branch != "feature/picker" || m.Usage == nil || m.Usage.PromptTokens == nil || *m.Usage.PromptTokens != 812 || m.Usage.CompletionTokens != nil {
		t.Errorf("Branch = %q, Usage = %+v; want the new branch and the usage that came", m.Branch, m.Usage)
	}

	m, _ = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "error", Reason: ptr("model unreachable")})
	if m.Branch != "" || m.Usage != nil {
		t.Errorf("Branch = %q, Usage = %+v after a turn without them; want none", m.Branch, m.Usage)
	}
}

func TestAddProviderFormSendsWithoutAModel(t *testing.T) {
	m := formOpen(t)
	m, _ = feed(m, typed("bdns"), press("tab"), press("tab"), typed("http://ok.col"))
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewAddProvider("bdns", "openai", "http://ok.col", "", "")}; !reflect.DeepEqual(cmds, want) || !m.Picker.Pending || m.Picker.Error != "" {
		t.Errorf("commands = %v, Pending = %v, Error = %q; want %v sent with no model", cmds, m.Picker.Pending, m.Picker.Error, want)
	}
}
