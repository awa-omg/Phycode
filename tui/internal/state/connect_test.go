package state

import (
	"reflect"
	"testing"

	"phycode/tui/internal/protocol"
)

func connectPicker() Picker {
	env := func(s string) *string { return &s }
	refused := "HTTP 401: Missing Bearer token"
	down := "connection to http://127.0.0.1:9/v1/models failed: Failed to connect"
	configured := "ollama"
	return Picker{
		Presets: []protocol.Preset{
			{Name: "openai", Protocol: "openai", BaseURL: "https://api.openai.com", APIKeyEnv: env("OPENAI_API_KEY")},
			{Name: "anthropic", Protocol: "anthropic", BaseURL: "https://api.anthropic.com", APIKeyEnv: env("ANTHROPIC_API_KEY"), KeyInEnv: true},
			{Name: "lmstudio", Protocol: "openai", BaseURL: "http://127.0.0.1:1234"},
			{Name: "vllm", Protocol: "openai", BaseURL: "http://127.0.0.1:8000"},
		},
		Providers: []protocol.Provider{
			{Profile: "openai", Protocol: "openai", Endpoint: "https://api.openai.com", Error: &refused},
			{Profile: "qwen-2b", Protocol: "openai", Endpoint: "http://127.0.0.1:9", Error: &down},
			{Profile: "ollama", Protocol: "ollama", Endpoint: "http://127.0.0.1:11434", Count: 3},
		},
		Detected: []protocol.Endpoint{
			{Name: "lm-studio", Protocol: "openai", BaseURL: "http://127.0.0.1:1234", Server: "lm-studio", Count: 2},
			{Name: "llama-cpp", Protocol: "openai", BaseURL: "http://127.0.0.1:9931", Server: "llama.cpp", NeedsKey: true},
			{Name: "ollama-2", Protocol: "ollama", BaseURL: "http://127.0.0.1:11434", Server: "ollama", Count: 3, Configured: &configured},
		},
		Connect: &Connect{},
	}
}

func connectTitles(p Picker) []string {
	var out []string
	for _, row := range p.ConnectRows() {
		out = append(out, row.Title)
	}
	return out
}

func TestTheListOfProvidersHasTheServersFoundFirstThenTheOnesThatNeedAKey(t *testing.T) {
	want := []string{
		ConnectDetected, "lm-studio", "llama-cpp",
		ConnectPopular, "openai", "anthropic",
		ConnectLocal, "vllm",
		ConnectConfigured, "qwen-2b", "ollama",
		ConnectOther, CustomTitle,
	}
	if got := connectTitles(connectPicker()); !reflect.DeepEqual(got, want) {
		t.Errorf("rows = %v, want %v: the lmstudio preset gives way to the server found at its address, and a server a profile points at is not found twice", got, want)
	}
}

func TestEachProviderSaysHowItStands(t *testing.T) {
	p := connectPicker()
	status := map[string]string{}
	bad := map[string]bool{}
	for _, row := range p.ConnectRows() {
		if row.Kind == ConnectProvider {
			status[row.Title], bad[row.Title] = row.Status, row.Bad
		}
	}
	for name, want := range map[string]string{
		"lm-studio": "lm-studio · 2 models", "llama-cpp": "llama.cpp · needs a key", "anthropic": "key from $ANTHROPIC_API_KEY",
		"vllm": "no key needed", "openai": "key refused", "qwen-2b": "not answering", "ollama": "connected · 3 models",
	} {
		if status[name] != want {
			t.Errorf("%s: %q, want %q", name, status[name], want)
		}
	}
	if !bad["openai"] || !bad["qwen-2b"] || bad["ollama"] || bad["anthropic"] {
		t.Errorf("problems = %v, want openai and qwen-2b only", bad)
	}
}

func TestAProviderOfTheConfigurationIsTargetedAsExistingAndOneThatIsNotIsNew(t *testing.T) {
	targets := map[string]ConnectTarget{}
	for _, row := range connectPicker().ConnectRows() {
		if row.Kind == ConnectProvider {
			targets[row.Title] = row.Target
		}
	}
	if want := (ConnectTarget{Name: "openai", Protocol: "openai", BaseURL: "https://api.openai.com", EnvVar: "OPENAI_API_KEY", Existing: true}); targets["openai"] != want {
		t.Errorf("openai = %+v, want %+v", targets["openai"], want)
	}
	if want := (ConnectTarget{Name: "anthropic", Protocol: "anthropic", BaseURL: "https://api.anthropic.com", EnvVar: "ANTHROPIC_API_KEY", KeyInEnv: true}); targets["anthropic"] != want {
		t.Errorf("anthropic = %+v, want %+v", targets["anthropic"], want)
	}
	if want := (ConnectTarget{Name: "lm-studio", Protocol: "openai", BaseURL: "http://127.0.0.1:1234", NoKey: true}); targets["lm-studio"] != want {
		t.Errorf("a server found that needs no key = %+v, want %+v", targets["lm-studio"], want)
	}
	if targets["llama-cpp"].NoKey || targets["llama-cpp"].Existing {
		t.Errorf("a server found that wants a key = %+v, want a new target that asks for it", targets["llama-cpp"])
	}
	if want := (ConnectTarget{Name: "qwen-2b", Protocol: "openai", BaseURL: "http://127.0.0.1:9", Existing: true}); targets["qwen-2b"] != want {
		t.Errorf("a profile that is not a preset = %+v, want %+v", targets["qwen-2b"], want)
	}
}

func TestTheFilterKeepsTheProvidersItMatchesAndTheCustomRowOnlyWhenItMatches(t *testing.T) {
	p := connectPicker()
	p.Connect.Query = "anth"
	if got := connectTitles(p); !reflect.DeepEqual(got, []string{ConnectPopular, "anthropic"}) {
		t.Errorf("anth: %v, want anthropic alone", got)
	}
	p.Connect.Query = "custom"
	if got := connectTitles(p); !reflect.DeepEqual(got, []string{ConnectOther, CustomTitle}) {
		t.Errorf("custom: %v, want the custom row alone", got)
	}
	p.Connect.Query = "11434"
	if got := connectTitles(p); !reflect.DeepEqual(got, []string{ConnectConfigured, "ollama"}) {
		t.Errorf("an address: %v, want the provider that listens there", got)
	}
	p.Connect.Query = "zzzz"
	if got := connectTitles(p); len(got) != 0 {
		t.Errorf("a filter that matches nothing: %v, want no rows", got)
	}
}

func TestTheHighlightIsOnTheProvidersAndTheCustomRowNotOnTheHeadings(t *testing.T) {
	p := connectPicker()
	choices := p.ConnectChoices()
	if len(choices) != 8 || choices[0].Title != "lm-studio" || choices[7].Kind != ConnectCustom {
		t.Fatalf("choices = %d, first %q, last kind %d; want 8, from lm-studio to the custom row", len(choices), choices[0].Title, choices[7].Kind)
	}
	p.Connect.Selected = 2
	if row, ok := p.ConnectHighlighted(); !ok || row.Title != "openai" {
		t.Errorf("highlight 2 = %+v, want openai", row)
	}
	p.Connect.Selected = 99
	if _, ok := p.ConnectHighlighted(); ok {
		t.Error("a highlight past the list names a row")
	}
	if _, ok := (Picker{}).ConnectHighlighted(); ok {
		t.Error("a picker without the flow names a row")
	}
}

func TestAProblemOfAProviderIsReadAsAKeyThatWasRefusedOrAServerThatDoesNotAnswer(t *testing.T) {
	for problem, want := range map[string]string{
		"HTTP 401: Invalid API Key":                             "key refused",
		"HTTP 403: forbidden":                                   "key refused",
		"connection to http://x failed: Failed to connect":      "not answering",
		"connection to http://x failed: Could not resolve host": "not answering",
		"connection to http://x failed: Operation timed out":    "not answering",
		"HTTP 500: boom":                                        "failed",
		"protocol anthropic is not available yet":               "failed",
	} {
		if got := KeyProblem(problem); got != want {
			t.Errorf("KeyProblem(%q) = %q, want %q", problem, got, want)
		}
	}
	if !KeyRefused("HTTP 401: x") || KeyRefused("HTTP 500: x") {
		t.Error("KeyRefused does not follow KeyProblem")
	}
}

func TestTheCatalogueAnswersAKeyOnlyWhenItListsTheProvider(t *testing.T) {
	problem := "HTTP 401: no"
	providers := []protocol.Provider{{Profile: "a"}, {Profile: "b", Error: &problem}}
	if done, p := ConnectFinished(providers, "a"); !done || p != "" {
		t.Errorf("a provider that lists its models: %v, %q; want done with no problem", done, p)
	}
	if done, p := ConnectFinished(providers, "b"); !done || p != problem {
		t.Errorf("a provider with a problem: %v, %q; want done with its problem", done, p)
	}
	if done, _ := ConnectFinished(providers, "c"); done {
		t.Error("a provider the catalogue does not list is answered")
	}
}
