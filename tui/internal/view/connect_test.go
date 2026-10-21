package view

import (
	"strings"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func connectView(w, h int) state.Model {
	m := newModel(w, h)
	env := func(s string) *string { return &s }
	refused := "HTTP 401: Missing Bearer token"
	m.Picker = state.Picker{
		Open: true, Known: true,
		Presets: []protocol.Preset{
			{Name: "openai", Protocol: "openai", BaseURL: "https://api.openai.com", APIKeyEnv: env("OPENAI_API_KEY")},
			{Name: "anthropic", Protocol: "anthropic", BaseURL: "https://api.anthropic.com", APIKeyEnv: env("ANTHROPIC_API_KEY"), KeyInEnv: true},
			{Name: "vllm", Protocol: "openai", BaseURL: "http://127.0.0.1:8000"},
		},
		Providers: []protocol.Provider{
			{Profile: "openai", Protocol: "openai", Endpoint: "https://api.openai.com", Error: &refused},
			{Profile: "ollama", Protocol: "ollama", Endpoint: "http://127.0.0.1:11434", Count: 3},
		},
		Detected: []protocol.Endpoint{{Name: "llama-cpp", Protocol: "openai", BaseURL: "http://127.0.0.1:9931", Server: "llama.cpp", Count: 1}},
		Connect:  &state.Connect{},
	}
	return m
}

func keyView(w, h int, target state.ConnectTarget) state.Model {
	m := connectView(w, h)
	m.Picker.Connect = &state.Connect{Stage: state.ConnectKey, Target: target}
	return m
}

var openaiTarget = state.ConnectTarget{Name: "openai", Protocol: "openai", BaseURL: "https://api.openai.com", EnvVar: "OPENAI_API_KEY"}

func TestTheListOfProvidersHasItsGroupsInOrderAndTheHighlightOnTheFirst(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, connectView(110, 36))
	last := -1
	for _, want := range []string{"Connect a provider", "Detected", "llama-cpp", "Popular", "openai", "anthropic", "Local", "vllm", "Configured", "ollama", "Other", "+ Custom endpoint…"} {
		i := rowOf(rows, want)
		if i <= last {
			t.Fatalf("%q is at row %d, want it after row %d: %q", want, i, last, rows)
		}
		last = i
	}
	if row := highlightIn(rows); !strings.Contains(row, "llama-cpp") {
		t.Errorf("highlighted row = %q, want the first provider, the server that was found", row)
	}
	if rowOf(rows, "search providers") < 0 {
		t.Errorf("the search has no placeholder: %q", rows)
	}
}

func TestEachProviderShowsWhereItIsAndHowItStandsAtTheRightEdge(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, connectView(110, 36))
	for name, want := range map[string]string{
		"llama-cpp": "llama.cpp · 1 model", "openai": "✖ key refused", "anthropic": "key from $ANTHROPIC_API_KEY", "vllm": "no key needed", "ollama": "✓ connected · 3 models",
	} {
		row := rowWith(rows, name+" ")
		if !strings.HasSuffix(row, want) {
			t.Errorf("%s row = %q, want it to end with %q", name, row, want)
		}
	}
	if row := rowWith(rows, "llama-cpp"); !strings.Contains(row, "127.0.0.1:9931") {
		t.Errorf("llama-cpp row = %q, want where it listens", row)
	}
}

func TestTheListSaysItLooksForLocalServersWhileTheBackendDoes(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := connectView(110, 36)
	m.Picker.Detecting = true
	if rowOf(inDock(t, r, m), "looking for local servers") < 0 {
		t.Error("the list does not say it looks for local servers")
	}
	m.Picker.Detecting = false
	if rowOf(inDock(t, r, m), "looking for local servers") >= 0 {
		t.Error("the list says it looks after the answer")
	}
}

func TestAProviderOfTheConfigurationOffersItsRetestAndSaysWhileItIsAsked(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := connectView(110, 36)
	if rowOf(inDock(t, r, m), "ctrl+r look again") < 0 {
		t.Errorf("a server found is not offered a look again: %q", inDock(t, r, m))
	}
	m.Picker.Connect.Selected = 1
	rows := inDock(t, r, m)
	if rowOf(rows, "enter test again") < 0 || rowOf(rows, "ctrl+r test again") < 0 || rowOf(rows, "ctrl+x remove") < 0 {
		t.Errorf("the keys of a provider of the configuration are missing: %q", rows)
	}
	m.Picker.Connect.Target = state.ConnectTarget{Name: "openai", Existing: true}
	m.Picker.Connect.Pending = true
	if rowOf(inDock(t, r, m), "asking openai for its models…") < 0 {
		t.Errorf("the list does not say that it asks openai: %q", inDock(t, r, m))
	}
}

func TestTheListShowsTheProblemOfTheFlowUnderItsTitle(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := connectView(110, 36)
	m.Picker.Connect.Error = "wait for the turn to finish"
	rows := inDock(t, r, m)
	if rowOf(rows, "✖ wait for the turn to finish") != 1 {
		t.Errorf("the list does not show its problem under its title: %q", rows)
	}
	m.Picker.Connect.Error, m.Picker.Connect.Confirm = "", "local"
	if rowOf(inDock(t, r, m), "press ctrl+x again to remove local and its key") != 1 {
		t.Error("the list does not ask before it removes a provider")
	}
}

func TestAFilterThatMatchesNothingSaysSo(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := connectView(110, 36)
	m.Picker.Connect.Query = "zzzz"
	rows := inDock(t, r, m)
	if rowOf(rows, "no provider matches “zzzz”") < 0 || rowOf(rows, "zzzz█") < 0 {
		t.Errorf("rows = %q, want the filter and that nothing matches", rows)
	}
}

func TestTheKeyLineNamesTheProviderAndWhereItIsAndShowsDotsNeverTheKey(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := keyView(110, 30, openaiTarget)
	rows := inDock(t, r, m)
	if row := rowWith(rows, "Connect openai"); !strings.HasSuffix(row, "api.openai.com") {
		t.Errorf("title = %q, want the provider and, at the right, where it is", row)
	}
	if rowOf(rows, "Paste the API key of openai. It is saved encrypted and never shown.") < 0 {
		t.Errorf("the line does not say what the key is for: %q", rows)
	}
	if row := rowWith(rows, "❯"); !strings.Contains(row, "API key") || !strings.Contains(row, "█") {
		t.Errorf("empty line = %q, want its placeholder and the cursor", row)
	}
	m.Picker.Connect.Key = "sk-secret-123"
	rows = inDock(t, r, m)
	row := rowWith(rows, "❯")
	if !strings.Contains(row, strings.Repeat("•", 13)+"█") || strings.Contains(strings.Join(rows, "\n"), "sk-secret") || strings.Contains(row, "API key") {
		t.Errorf("typed line = %q, want 13 dots and the cursor, and the key nowhere", row)
	}
}

func TestTheKeyLineSaysWhatEnterDoesForAKeyInTheEnvironmentAndForAProviderThatIsThere(t *testing.T) {
	r := NewRenderer(theme.Default())
	env := openaiTarget
	env.KeyInEnv = true
	if rowOf(inDock(t, r, keyView(110, 30, env)), "$OPENAI_API_KEY is set: press enter to use it, or paste a key to store it encrypted instead.") < 0 {
		t.Error("the line does not say that the variable is set")
	}
	existing := openaiTarget
	existing.Existing = true
	rows := inDock(t, r, keyView(110, 30, existing))
	if rowOf(rows, "Paste a new key for openai. It replaces the one it has and is saved encrypted.") < 0 {
		t.Errorf("the line does not say that the key replaces the old one: %q", rows)
	}
	if rowOf(rows, "ctrl+r test again") < 0 || rowOf(rows, "ctrl+x remove") < 0 {
		t.Errorf("the keys of a provider that is there are missing: %q", rows)
	}
}

func TestWhileTheKeyIsTestedTheLineSaysWhichServerIsAskedAndOnlyEscCounts(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := keyView(110, 30, openaiTarget)
	m.Picker.Connect.Pending = true
	rows := inDock(t, r, m)
	if rowOf(rows, "asking api.openai.com for its models…") < 0 {
		t.Errorf("the line does not say what it waits for: %q", rows)
	}
	if rowOf(rows, "❯") >= 0 || rowOf(rows, "esc cancel") < 0 || rowOf(rows, "enter connect") >= 0 {
		t.Errorf("rows = %q, want no input and only the key that cancels", rows)
	}
	m.Picker.Connect.Removing = "openai"
	if rowOf(inDock(t, r, m), "removing openai…") < 0 {
		t.Error("the line does not say that a provider is being removed")
	}
}

func TestAProblemIsSaidAsAKeyThatWasRefusedOrAServerThatDoesNotAnswerWithTheServersWordsUnderIt(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := keyView(110, 30, openaiTarget)
	m.Picker.Connect.Error = "HTTP 401: Missing Bearer token"
	rows := inDock(t, r, m)
	for _, want := range []string{"✖ api.openai.com refused the key", "HTTP 401: Missing Bearer token", "Paste another key and press enter."} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the refusal lacks %q: %q", want, rows)
		}
	}
	m.Picker.Connect.Error = "connection to http://x/v1/models failed: Failed to connect"
	rows = inDock(t, r, m)
	for _, want := range []string{"✖ api.openai.com did not answer", "Failed to connect", "Check that it runs and that the address is right, then press ctrl+r."} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the silence lacks %q: %q", want, rows)
		}
	}
	m.Picker.Connect.Error = "type the key"
	if rowOf(inDock(t, r, m), "✖ type the key") < 0 {
		t.Error("another problem is not shown as it is")
	}
}

func TestTheQuestionOfRemovingAProviderIsAskedOnTheLine(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := keyView(110, 30, openaiTarget)
	m.Picker.Connect.Confirm = "openai"
	if rowOf(inDock(t, r, m), "press ctrl+x again to remove openai and its key") < 0 {
		t.Error("the line does not ask before it removes")
	}
}

func TestTheListAndTheKeyLineFitEverySizeInEveryPalette(t *testing.T) {
	trueColour(t)
	for _, name := range theme.Names() {
		r := NewRenderer(theme.Build(theme.Lookup(name)))
		for _, size := range [][2]int{{20, 8}, {40, 12}, {60, 18}, {110, 36}} {
			for scene, m := range map[string]state.Model{
				"list":    connectView(size[0], size[1]),
				"key":     keyView(size[0], size[1], openaiTarget),
				"pending": withPending(keyView(size[0], size[1], openaiTarget)),
				"problem": withProblem(keyView(size[0], size[1], openaiTarget)),
				"asking":  withAskedList(connectView(size[0], size[1])),
				"listed":  withListProblem(connectView(size[0], size[1])),
			} {
				for i, row := range screen(t, r, m) {
					if !strings.Contains(row, "\x1b[48;") {
						t.Fatalf("%s %s %dx%d: row %d has no background", name, scene, size[0], size[1], i)
					}
				}
			}
		}
	}
}

func withPending(m state.Model) state.Model {
	m.Picker.Connect.Pending = true
	return m
}

func withProblem(m state.Model) state.Model {
	m.Picker.Connect.Error = "HTTP 401: " + strings.Repeat("a very long problem ", 10)
	return m
}

func withAskedList(m state.Model) state.Model {
	m.Picker.Connect.Selected = 1
	m.Picker.Connect.Target = state.ConnectTarget{Name: "openai", Existing: true}
	m.Picker.Connect.Pending = true
	return m
}

func withListProblem(m state.Model) state.Model {
	m.Picker.Connect.Error = "wait for the turn to finish " + strings.Repeat("and then some more ", 8)
	return m
}

func TestTheShellModeHasItsOwnPromptRailPlaceholderAndKeys(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	m := newModel(100, 24)
	m.ShellMode = true
	rows := screen(t, r, m)
	i := rowOf(rows, "Run a command…")
	if i < 0 || !strings.Contains(plain(rows[i]), "$ ") || strings.Contains(plain(rows[i]), "❯") {
		t.Fatalf("the empty shell line = %q, want the $ prompt and a command as an example", plainRows(rows))
	}
	if !strings.Contains(rows[i], r.styles.Info.Render("▌")) {
		t.Errorf("the rail is not the colour of the shell: %q", rows[i])
	}
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "enter run · esc leave shell mode") || strings.Contains(status, "/ commands") {
		t.Errorf("status = %q, want the keys of the shell mode", status)
	}
	m.Input, m.Cursor = "git status", 3
	rows = screen(t, r, m)
	if i := rowOf(rows, "git status"); i < 0 || !strings.Contains(plain(rows[i]), "$ git status") {
		t.Errorf("the line = %q, want the command after the $", plainRows(rows))
	}
}

func TestTheOutputOfAShellCommandShowsTheCommandFirstInTheColourOfTheShell(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	m := newModel(100, 24)
	m.Blocks = []state.Block{{Kind: state.KindOutput, Text: "$ ls -la\ntotal 0\nfile.go"}}
	rows := screen(t, r, m)
	i := rowOf(rows, "$ ls -la")
	if i < 0 || rowOf(rows, "total 0") != i+1 {
		t.Fatalf("rows = %q, want the command and then what it printed", plainRows(rows))
	}
	if !strings.Contains(rows[i], r.styles.Info.Render("$ ")) || !strings.Contains(rows[i], r.styles.Heading.Render("ls -la")) {
		t.Errorf("the command row = %q, want the $ in the colour of the shell and the command in bold", rows[i])
	}
	if strings.Contains(rows[i+1], r.styles.Info.Render("$ ")) {
		t.Error("the output is drawn as a command")
	}
}

func TestTheWelcomeNamesTheBangAndTheKeys(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, newModel(110, 40)))
	if rowOf(rows, "run a shell command (!! keeps its output from the model)") < 0 {
		t.Errorf("the welcome does not name the bang: %q", rows)
	}
}
