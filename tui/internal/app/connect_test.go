package app

import (
	"reflect"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func connectCatalogue() *protocol.Models {
	openai, anthropic := "OPENAI_API_KEY", "ANTHROPIC_API_KEY"
	c := pickerModels()
	c.Presets = []protocol.Preset{
		{Name: "openai", Protocol: "openai", BaseURL: "https://api.openai.com", APIKeyEnv: &openai},
		{Name: "anthropic", Protocol: "anthropic", BaseURL: "https://api.anthropic.com", APIKeyEnv: &anthropic, KeyInEnv: true},
		{Name: "lmstudio", Protocol: "openai", BaseURL: "http://127.0.0.1:1234"},
	}
	return c
}

func connectOpen(t *testing.T) state.Model {
	t.Helper()
	m, _ := feed(loadedPicker(t), connectCatalogue(), pickerKey("ctrl+a"))
	if m.Picker.Connect == nil || m.Picker.Connect.Stage != state.ConnectChoose {
		t.Fatalf("Connect = %+v; want the list of providers", m.Picker.Connect)
	}
	return m
}

func choose(m state.Model, filter string) (state.Model, []any) {
	return feed(m, typed(filter), press("enter"))
}

func TestTheListOfProvidersOpensOverThePickerAndLooksForLocalServers(t *testing.T) {
	m, cmds := feed(loadedPicker(t), pickerKey("ctrl+a"))
	if want := []any{protocol.NewDetectEndpoints()}; !reflect.DeepEqual(cmds, want) || !m.Picker.Detecting {
		t.Errorf("commands = %v, Detecting = %v; want %v and the wait for the answer", cmds, m.Picker.Detecting, want)
	}
	m, _ = feed(m, &protocol.Endpoints{Type: protocol.TypeEndpoints, Items: []protocol.Endpoint{{Name: "llama-cpp", Protocol: "openai", BaseURL: "http://127.0.0.1:9931", Server: "llama.cpp", Count: 1}}})
	if m.Picker.Detecting || len(m.Picker.Detected) != 1 {
		t.Errorf("Detecting = %v, Detected = %v; want the answer kept", m.Picker.Detecting, m.Picker.Detected)
	}
}

func TestTheListAsksForTheCatalogueWhenThePickerHasNoneAndOnlyBrowsesDuringATurn(t *testing.T) {
	m, cmds := feed(testModel(), typed("/connect"), press("enter"))
	if want := []any{protocol.NewListModels(false), protocol.NewDetectEndpoints()}; !reflect.DeepEqual(cmds, want) || !m.Picker.Open || m.Picker.Connect == nil {
		t.Errorf("commands = %v, Open = %v; want %v and the list open", cmds, m.Picker.Open, want)
	}
	if len(m.Blocks) != 0 {
		t.Errorf("Blocks = %+v, want /connect not shown in the transcript", m.Blocks)
	}
	busy := testModel()
	busy.Busy = true
	m, cmds = feed(busy, typed("/connect"), press("enter"))
	if len(cmds) != 0 || m.Picker.Connect == nil {
		t.Errorf("during a turn: commands = %v, Connect = %v; want the list open and nothing asked", cmds, m.Picker.Connect)
	}
}

func TestAProviderThatNeedsAKeyAsksForItAndSendsItWithTheProviderAddedWithIt(t *testing.T) {
	m := connectOpen(t)
	m, cmds := choose(m, "openai")
	c := m.Picker.Connect
	if len(cmds) != 0 || c.Stage != state.ConnectKey || c.Target.Name != "openai" || c.Target.Existing {
		t.Fatalf("commands = %v, Connect = %+v; want the key line of the new provider openai", cmds, c)
	}
	m, cmds = feed(m, press("enter"))
	if len(cmds) != 0 || m.Picker.Connect.Error != "type the key" {
		t.Errorf("commands = %v, Error = %q; want the key asked for and nothing sent", cmds, m.Picker.Connect.Error)
	}
	m, cmds = feed(m, typed("sk-abc"), typed("-123"), press("enter"))
	want := []any{protocol.NewAddProvider("openai", "openai", "https://api.openai.com", "", "").WithAPIKey("sk-abc-123")}
	c = m.Picker.Connect
	if !reflect.DeepEqual(cmds, want) || !c.Pending || c.Key != "" || c.Error != "" || !m.Picker.Pending {
		t.Errorf("commands = %v, Pending = %v, Key = %q; want %v sent, waiting, with the key forgotten", cmds, c.Pending, c.Key, want)
	}
}

func TestAPastedKeyIsKeptWithoutItsSurroundingSpacesAndLineBreaks(t *testing.T) {
	m := connectOpen(t)
	m, _ = choose(m, "openai")
	m, cmds := feed(m, pasted("  sk-pasted-1  "), press("enter"))
	if want := []any{protocol.NewAddProvider("openai", "openai", "https://api.openai.com", "", "").WithAPIKey("sk-pasted-1")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestAPresetWhoseKeyIsInTheEnvironmentConnectsWithItsVariableAtOnce(t *testing.T) {
	m, cmds := choose(connectOpen(t), "anthropic")
	if want := []any{protocol.NewAddProvider("anthropic", "anthropic", "https://api.anthropic.com", "ANTHROPIC_API_KEY", "")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the key is in the variable, so no line asks for it", cmds, want)
	}
	if c := m.Picker.Connect; !c.Pending || c.Stage != state.ConnectKey {
		t.Errorf("Connect = %+v, want the line waiting for the answer", c)
	}
}

func TestAServerThatNeedsNoKeyIsConnectedAtOnce(t *testing.T) {
	m, cmds := choose(connectOpen(t), "lmstudio")
	if want := []any{protocol.NewAddProvider("lmstudio", "openai", "http://127.0.0.1:1234", "", "")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if c := m.Picker.Connect; !c.Pending || c.Stage != state.ConnectKey {
		t.Errorf("Connect = %+v, want the line waiting for the answer", c)
	}
}

func TestAServerFoundOnThisMachineIsListedAndConnectedWithItsOwnName(t *testing.T) {
	m := connectOpen(t)
	m, _ = feed(m, &protocol.Endpoints{Type: protocol.TypeEndpoints, Items: []protocol.Endpoint{
		{Name: "llama-cpp", Protocol: "openai", BaseURL: "http://127.0.0.1:9931", Server: "llama.cpp", Count: 1, Models: []string{"qwen"}},
		{Name: "secret", Protocol: "openai", BaseURL: "http://127.0.0.1:8080", Server: "openai-compatible", NeedsKey: true},
	}})
	first, _ := m.Picker.ConnectHighlighted()
	if first.Title != "llama-cpp" {
		t.Fatalf("first row = %q, want the server found, above the presets", first.Title)
	}
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewAddProvider("llama-cpp", "openai", "http://127.0.0.1:9931", "", "")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	m = connectOpen(t)
	m, _ = feed(m, &protocol.Endpoints{Type: protocol.TypeEndpoints, Items: []protocol.Endpoint{{Name: "secret", Protocol: "openai", BaseURL: "http://127.0.0.1:8080", Server: "openai-compatible", NeedsKey: true}}})
	m, cmds = feed(m, press("enter"))
	if len(cmds) != 0 || m.Picker.Connect.Stage != state.ConnectKey || m.Picker.Connect.Target.NoKey {
		t.Errorf("commands = %v, Connect = %+v; want a server that wants a key to ask for it", cmds, m.Picker.Connect)
	}
}

func TestAProviderOfTheConfigurationIsAskedAgainAndAKeyIsAskedForOnlyWhenItIsRefused(t *testing.T) {
	refused := "HTTP 401: nope"
	c := connectCatalogue()
	c.Providers = append(c.Providers, protocol.Provider{Profile: "openai", Protocol: "openai", Endpoint: "https://api.openai.com", Error: &refused})
	m, _ := feed(loadedPicker(t), c, pickerKey("ctrl+a"))
	m, cmds := choose(m, "openai")
	if want := []any{protocol.NewListModels(true)}; !reflect.DeepEqual(cmds, want) || m.Picker.Connect.Stage != state.ConnectChoose {
		t.Fatalf("commands = %v, Stage = %d; want %v sent and the list still open", cmds, m.Picker.Connect.Stage, want)
	}
	m, _ = feed(m, c)
	if m.Picker.Connect.Stage != state.ConnectKey || m.Picker.Connect.Error != refused || !m.Picker.Connect.Target.Existing {
		t.Fatalf("Connect = %+v; want the key line of openai with the refusal under it", m.Picker.Connect)
	}
	_, cmds = feed(m, typed("sk-new"), press("enter"))
	if want := []any{protocol.NewSetProviderKey("openai", "sk-new")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func configuredOpenAI(problem *string) *protocol.Models {
	c := connectCatalogue()
	c.Providers = append(c.Providers, protocol.Provider{Profile: "openai", Protocol: "openai", Endpoint: "https://api.openai.com", Count: 2, Error: problem})
	return c
}

func TestAProviderOfTheConfigurationThatIsListedIsAskedAgainWithoutAKeyAndTheListStaysOpen(t *testing.T) {
	m, _ := feed(loadedPicker(t), configuredOpenAI(nil), pickerKey("ctrl+a"))
	m, cmds := choose(m, "openai")
	if want := []any{protocol.NewListModels(true)}; !reflect.DeepEqual(cmds, want) || m.Picker.Connect.Stage != state.ConnectChoose || !m.Picker.Connect.Pending {
		t.Fatalf("commands = %v, Connect = %+v; want %v and the list waiting", cmds, m.Picker.Connect, want)
	}
	if _, cmds = feed(m, press("enter")); len(cmds) != 0 {
		t.Errorf("commands = %v for a second Enter while the answer is awaited, want none", cmds)
	}
	listed := configuredOpenAI(nil)
	listed.Entries = append(listed.Entries, protocol.ModelEntry{Profile: "openai", Model: "gpt-5", Protocol: "openai"})
	m, _ = feed(m, listed)
	if m.Picker.Connect != nil || m.Picker.Pending {
		t.Errorf("Connect = %v, Pending = %v; want the flow closed once openai lists its models", m.Picker.Connect, m.Picker.Pending)
	}
	if got := rowName(m); got != "openai/gpt-5" {
		t.Errorf("highlight = %s, want the first model of openai", got)
	}
}

func TestAProviderOfTheConfigurationThatDoesNotAnswerStaysInTheListWithItsProblem(t *testing.T) {
	down := "connection to http://127.0.0.1:8082/v1/models failed: Failed to connect"
	c := connectCatalogue()
	c.Providers = append(c.Providers, protocol.Provider{Profile: "qwen-2b", Protocol: "openai", Endpoint: "http://127.0.0.1:8082", Error: &down})
	m, _ := feed(loadedPicker(t), c, pickerKey("ctrl+a"))
	m, cmds := choose(m, "qwen-2b")
	if want := []any{protocol.NewListModels(true)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v: a server that is down is asked again, and no key is asked for", cmds, want)
	}
	m, _ = feed(m, c)
	if cn := m.Picker.Connect; cn == nil || cn.Stage != state.ConnectChoose || cn.Pending || cn.Error != "" || m.Picker.Pending {
		t.Fatalf("Connect = %+v, Pending = %v; want the list again, not waiting, with no key line", cn, m.Picker.Pending)
	}
	if row, ok := m.Picker.ConnectHighlighted(); !ok || row.Title != "qwen-2b" || row.Status != "not answering" || !row.Bad {
		t.Errorf("highlight = %+v; want qwen-2b with its problem in its row", row)
	}
}

func TestAProviderOfTheConfigurationDuringATurnSaysSoAndAsksForNothing(t *testing.T) {
	m, _ := feed(loadedPicker(t), configuredOpenAI(nil), pickerKey("ctrl+a"))
	m.Busy = true
	m, cmds := choose(m, "openai")
	if len(cmds) != 0 || m.Picker.Connect.Stage != state.ConnectChoose || m.Picker.Connect.Error != busyText {
		t.Errorf("commands = %v, Connect = %+v; want nothing sent and the wait for the turn shown in the list", cmds, m.Picker.Connect)
	}
}

func TestCtrlRTestsTheHighlightedProviderOfTheConfigurationAgain(t *testing.T) {
	m, _ := feed(loadedPicker(t), configuredOpenAI(nil), pickerKey("ctrl+a"))
	m, _ = feed(m, typed("openai"))
	m, cmds := feed(m, pickerKey("ctrl+r"))
	if want := []any{protocol.NewListModels(true)}; !reflect.DeepEqual(cmds, want) || m.Picker.Connect.Stage != state.ConnectChoose || !m.Picker.Connect.Pending {
		t.Errorf("commands = %v, Connect = %+v; want %v with the list open", cmds, m.Picker.Connect, want)
	}
}

func keySent(t *testing.T) state.Model {
	t.Helper()
	m, _ := choose(connectOpen(t), "openai")
	m, cmds := feed(m, typed("sk-bad"), press("enter"))
	if len(cmds) != 1 {
		t.Fatalf("commands = %v, want the key sent", cmds)
	}
	return m
}

func TestAKeyThatTheServerAcceptsClosesTheFlowAndHighlightsItsFirstModel(t *testing.T) {
	m := keySent(t)
	listed := connectCatalogue()
	listed.Providers = append(listed.Providers, protocol.Provider{Profile: "openai", Protocol: "openai", Endpoint: "https://api.openai.com", Count: 2})
	listed.Entries = append(listed.Entries, protocol.ModelEntry{Profile: "openai", Model: "gpt-5", Protocol: "openai"}, protocol.ModelEntry{Profile: "openai", Model: "gpt-5-mini", Protocol: "openai"})
	m, _ = feed(m, listed)
	if m.Picker.Connect != nil || m.Picker.Pending || m.Picker.Error != "" {
		t.Errorf("Connect = %v, Pending = %v, Error = %q; want the flow closed", m.Picker.Connect, m.Picker.Pending, m.Picker.Error)
	}
	if got := rowName(m); got != "openai/gpt-5" {
		t.Errorf("highlight = %s, want the first model of the provider just connected", got)
	}
	if m.Flash != "connected openai · 2 models" {
		t.Errorf("Flash = %q, want connected openai · 2 models", m.Flash)
	}
}

func TestAKeyThatTheServerRefusesKeepsTheLineOpenWithTheProblemAndTriesAgainAsAKeyOfTheProvider(t *testing.T) {
	m := keySent(t)
	refused := "HTTP 401: Invalid API Key"
	answer := connectCatalogue()
	answer.Providers = append(answer.Providers, protocol.Provider{Profile: "openai", Protocol: "openai", Endpoint: "https://api.openai.com", Error: &refused})
	m, _ = feed(m, answer)
	c := m.Picker.Connect
	if c == nil || c.Stage != state.ConnectKey || c.Pending || c.Error != refused || !c.Target.Existing || c.Key != "" {
		t.Fatalf("Connect = %+v; want the line open for another key, with the problem, and the provider saved", c)
	}
	if m.Picker.Pending {
		t.Error("the picker still waits after the answer")
	}
	m, cmds := feed(m, typed("sk-good"), press("enter"))
	if want := []any{protocol.NewSetProviderKey("openai", "sk-good")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the provider is in the configuration now, so a new key replaces its key", cmds, want)
	}
	if m.Picker.Connect.Error != "" || !m.Picker.Connect.Pending {
		t.Errorf("Error = %q, Pending = %v; want the problem cleared while the new key is tested", m.Picker.Connect.Error, m.Picker.Connect.Pending)
	}
}

func TestACatalogueThatDoesNotListTheProviderYetLeavesTheFlowWaiting(t *testing.T) {
	m := keySent(t)
	m, _ = feed(m, connectCatalogue())
	if c := m.Picker.Connect; c == nil || !c.Pending || !m.Picker.Pending {
		t.Errorf("Connect = %+v, Pending = %v; want the wait kept for the answer about openai", c, m.Picker.Pending)
	}
}

func TestARefusalFromTheBackendIsShownOnTheKeyLineAndStopsTheWait(t *testing.T) {
	m := keySent(t)
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "profile 'openai' already exists"})
	c := m.Picker.Connect
	if c == nil || c.Pending || c.Error != "profile 'openai' already exists" || m.Picker.Pending || m.Picker.Error != "" {
		t.Errorf("Connect = %+v, Pending = %v, Picker.Error = %q; want the refusal on the line, and no wait", c, m.Picker.Pending, m.Picker.Error)
	}
}

func TestEscGoesBackFromTheKeyToTheListAndFromTheListToThePickerAndLateAnswersAreIgnored(t *testing.T) {
	m := keySent(t)
	m, _ = feed(m, press("esc"))
	if c := m.Picker.Connect; c == nil || c.Stage != state.ConnectChoose || c.Pending || m.Picker.Pending {
		t.Fatalf("Connect = %+v; want the list again, not waiting", c)
	}
	late := connectCatalogue()
	problem := "HTTP 401: late"
	late.Providers = append(late.Providers, protocol.Provider{Profile: "openai", Protocol: "openai", Endpoint: "https://api.openai.com", Error: &problem})
	m, _ = feed(m, late)
	if m.Picker.Connect.Error != "" {
		t.Errorf("Error = %q, want an answer that nobody waits for ignored", m.Picker.Connect.Error)
	}
	m, _ = feed(m, press("esc"))
	if m.Picker.Connect != nil || !m.Picker.Open {
		t.Errorf("Connect = %v, Open = %v; want the picker back", m.Picker.Connect, m.Picker.Open)
	}
}

func TestCtrlKOpensTheKeyLineOfTheHighlightedProviderAndEscLeavesTheFlow(t *testing.T) {
	m := loadedPicker(t)
	m, cmds := feed(m, pickerKey("ctrl+k"))
	c := m.Picker.Connect
	if len(cmds) != 0 || c == nil || c.Stage != state.ConnectKey || !c.Direct || c.Target.Name != "local" || !c.Target.Existing {
		t.Fatalf("commands = %v, Connect = %+v; want the key line of local", cmds, c)
	}
	m, _ = feed(m, press("esc"))
	if m.Picker.Connect != nil || !m.Picker.Open {
		t.Errorf("Connect = %v, Open = %v; want Esc to go back to the picker", m.Picker.Connect, m.Picker.Open)
	}
	if empty, _ := feed(testModel(), typed("/models"), press("enter"), &protocol.Models{Type: protocol.TypeModels}, pickerKey("ctrl+k")); empty.Picker.Error != "add a provider first" {
		t.Errorf("Error = %q, want ctrl+k to ask for a provider first when there is none", empty.Picker.Error)
	}
}

func TestAKeyIsRefusedDuringATurnAndTheLineIsKept(t *testing.T) {
	m, _ := choose(connectOpen(t), "openai")
	m.Busy = true
	m, cmds := feed(m, typed("sk-x"), press("enter"))
	if len(cmds) != 0 || m.Picker.Connect.Error != busyText || m.Picker.Connect.Pending || m.Picker.Connect.Key != "sk-x" {
		t.Errorf("commands = %v, Connect = %+v; want the key kept and the wait for the turn explained", cmds, m.Picker.Connect)
	}
}

func TestTheKeyLineIgnoresTheKeysWhileTheKeyIsBeingTested(t *testing.T) {
	m := keySent(t)
	m, cmds := feed(m, typed("more"), press("enter"), press("backspace"))
	if len(cmds) != 0 || m.Picker.Connect.Key != "" || !m.Picker.Connect.Pending {
		t.Errorf("commands = %v, Key = %q; want the keys ignored until the answer", cmds, m.Picker.Connect.Key)
	}
}

func TestBackspaceAndCtrlUEditTheKeyAndTheFilter(t *testing.T) {
	m, _ := choose(connectOpen(t), "openai")
	m, _ = feed(m, typed("sk-abc"), press("backspace"))
	if m.Picker.Connect.Key != "sk-ab" {
		t.Errorf("Key = %q, want sk-ab", m.Picker.Connect.Key)
	}
	m, _ = feed(m, pickerKey("ctrl+u"))
	if m.Picker.Connect.Key != "" {
		t.Errorf("Key = %q, want it cleared", m.Picker.Connect.Key)
	}
	m = connectOpen(t)
	m, _ = feed(m, typed("lm"), press("backspace"))
	if m.Picker.Connect.Query != "l" {
		t.Errorf("Query = %q, want l", m.Picker.Connect.Query)
	}
	m, _ = feed(m, pickerKey("ctrl+u"))
	if m.Picker.Connect.Query != "" {
		t.Errorf("Query = %q, want it cleared", m.Picker.Connect.Query)
	}
}

func TestTheArrowsMoveThroughTheProvidersAndStopAtBothEnds(t *testing.T) {
	m := connectOpen(t)
	n := len(m.Picker.ConnectChoices())
	m, _ = feed(m, press("up"))
	if m.Picker.Connect.Selected != 0 {
		t.Errorf("Selected = %d above the first, want 0", m.Picker.Connect.Selected)
	}
	for range n + 3 {
		m, _ = feed(m, press("down"))
	}
	if m.Picker.Connect.Selected != n-1 {
		t.Errorf("Selected = %d below the last, want %d", m.Picker.Connect.Selected, n-1)
	}
	if row, _ := m.Picker.ConnectHighlighted(); row.Kind != state.ConnectCustom {
		t.Errorf("the last row is %+v, want the custom row", row)
	}
}

func TestTheCustomRowOpensTheFormOfAServerOfOnesOwn(t *testing.T) {
	m, cmds := choose(connectOpen(t), "custom")
	if len(cmds) != 0 || m.Picker.Form == nil || m.Picker.Connect != nil {
		t.Errorf("commands = %v, Form = %v, Connect = %v; want the form open and the list gone", cmds, m.Picker.Form, m.Picker.Connect)
	}
}

func TestACtrlRLooksForLocalServersAgain(t *testing.T) {
	m := connectOpen(t)
	m, cmds := feed(m, pickerKey("ctrl+r"))
	if want := []any{protocol.NewDetectEndpoints()}; !reflect.DeepEqual(cmds, want) || !m.Picker.Detecting {
		t.Errorf("commands = %v, Detecting = %v; want %v", cmds, m.Picker.Detecting, want)
	}
}

func TestAProviderIsRemovedOnTheSecondPressOfCtrlXAndTheAnswerBringsTheListBack(t *testing.T) {
	m := connectOpen(t)
	m, _ = feed(m, typed("local"))
	m, cmds := feed(m, pickerKey("ctrl+x"))
	if len(cmds) != 0 || m.Picker.Connect.Confirm != "local" {
		t.Fatalf("commands = %v, Confirm = %q; want the first press to ask", cmds, m.Picker.Connect.Confirm)
	}
	m, cmds = feed(m, pickerKey("ctrl+x"))
	if want := []any{protocol.NewRemoveProvider("local")}; !reflect.DeepEqual(cmds, want) || !m.Picker.Connect.Pending || m.Picker.Connect.Removing != "local" {
		t.Fatalf("commands = %v, Connect = %+v; want %v sent", cmds, m.Picker.Connect, want)
	}
	gone := connectCatalogue()
	gone.Providers = gone.Providers[1:]
	m, _ = feed(m, gone)
	if c := m.Picker.Connect; c == nil || c.Pending || c.Removing != "" || c.Stage != state.ConnectChoose {
		t.Errorf("Connect = %+v; want the list again once local is gone", c)
	}
	if m.Flash != "removed local" {
		t.Errorf("Flash = %q, want removed local", m.Flash)
	}
}

func TestAnyOtherKeyTakesBackTheQuestionOfRemoving(t *testing.T) {
	m := connectOpen(t)
	m, _ = feed(m, typed("local"), pickerKey("ctrl+x"), press("down"), pickerKey("ctrl+x"))
	if m.Picker.Connect.Confirm == "" || m.Picker.Connect.Removing != "" {
		t.Errorf("Confirm = %q, Removing = %q; want a new question, not a removal", m.Picker.Connect.Confirm, m.Picker.Connect.Removing)
	}
}

func TestOnlyAProviderOfTheConfigurationCanBeRemovedAndNotDuringATurn(t *testing.T) {
	m, cmds := feed(connectOpen(t), pickerKey("ctrl+x"))
	if len(cmds) != 0 || m.Picker.Error != "only a provider of the configuration can be removed" {
		t.Errorf("commands = %v, Error = %q; want the removal of a preset that is not connected refused", cmds, m.Picker.Error)
	}
	busy := connectOpen(t)
	busy, _ = feed(busy, typed("local"))
	busy.Busy = true
	busy, cmds = feed(busy, pickerKey("ctrl+x"))
	if len(cmds) != 0 || busy.Picker.Connect.Error != busyText {
		t.Errorf("commands = %v, Error = %q; want the removal refused during a turn", cmds, busy.Picker.Connect.Error)
	}
}

func TestTheKeyLineOfAProviderCanTestItAgainAndRemoveIt(t *testing.T) {
	m, _ := feed(loadedPicker(t), pickerKey("ctrl+k"))
	m, cmds := feed(m, pickerKey("ctrl+r"))
	if want := []any{protocol.NewListModels(true)}; !reflect.DeepEqual(cmds, want) || !m.Picker.Connect.Pending {
		t.Errorf("commands = %v, Pending = %v; want %v", cmds, m.Picker.Connect.Pending, want)
	}
	m, _ = feed(loadedPicker(t), pickerKey("ctrl+k"), pickerKey("ctrl+x"))
	if m.Picker.Connect.Confirm != "local" {
		t.Errorf("Confirm = %q, want the question about local", m.Picker.Connect.Confirm)
	}
}
