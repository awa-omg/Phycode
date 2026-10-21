package view

import (
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func pickerView(w, h int) state.Model {
	m := newModel(w, h)
	ctx, smallCtx, tools, noTools, loaded := 32768, 8192, true, false, true
	queried := time.Now().Add(-3 * time.Minute).Format(time.RFC3339)
	problem := "protocol anthropic is not available yet"
	m.Picker = state.Picker{
		Open:  true,
		Known: true,
		Providers: []protocol.Provider{
			{Profile: "local", Protocol: "openai", Endpoint: "http://127.0.0.1:9931", Count: 4, UpdatedAt: &queried},
			{Profile: "cloud", Protocol: "openai", Endpoint: "https://api.example.com", Count: 1, UpdatedAt: &queried},
			{Profile: "remote", Protocol: "anthropic", Endpoint: "https://api.anthropic.com", Error: &problem},
		},
		Entries: []protocol.ModelEntry{
			{Profile: "local", Model: "qwen-coder", Protocol: "openai", ContextWindow: &ctx, Tools: &tools, Thinking: []string{"off", "on"}, Loaded: &loaded},
			{Profile: "local", Model: "llama-3", Protocol: "openai", ContextWindow: &smallCtx, Tools: &noTools},
			{Profile: "local", Model: "deepseek-r1", Protocol: "openai", Thinking: []string{"off", "low", "high"}},
			{Profile: "local", Model: "my-model", Protocol: "openai", Custom: true},
			{Profile: "cloud", Model: "gpt-x", Protocol: "openai", Thinking: []string{"off", "low", "medium", "high"}},
			{Profile: "cloud", Protocol: "openai", Error: ptr("the server lists no models")},
		},
		Favorites: []protocol.ModelRef{{Profile: "local", Model: "llama-3"}},
		Recents:   []protocol.ModelRef{{Profile: "cloud", Model: "gpt-x"}},
		Current:   protocol.ModelRef{Profile: "local", Model: "qwen-coder", Thinking: "off"},
	}
	return m
}

func ptr(s string) *string { return &s }

func rowWith(rows []string, s string) string {
	if i := rowOf(rows, s); i >= 0 {
		return plain(rows[i])
	}
	return ""
}

func highlightRow(rows []string) string {
	for _, row := range rows {
		if p := plain(row); onBar(p) {
			return p
		}
	}
	return ""
}

func TestPickerFitsSmallSizes(t *testing.T) {
	r := NewRenderer(theme.Default())
	widths := []int{1, 3, 8, 12, 20, 40}
	heights := []int{1, 2, 4, 6, 8, 12}
	for _, w := range widths {
		for _, h := range heights {
			m := pickerView(w, h)
			screen(t, r, m)

			m.Picker.Error = strings.Repeat("a long error ", 10)
			m.Picker.Query = strings.Repeat("filter", 10)
			screen(t, r, m)

			m = pickerView(w, h)
			m.Picker.CustomOpen = true
			m.Picker.CustomFor = "local"
			m.Picker.CustomID = strings.Repeat("model", 10)
			screen(t, r, m)

			m = pickerView(w, h)
			f := state.NewProviderForm()
			f.Values[state.FieldName] = strings.Repeat("name", 10)
			f.Focus = state.FieldModel
			m.Picker.Form = &f
			m.Picker.Error = strings.Repeat("a long error ", 10)
			screen(t, r, m)
		}
	}
}

func TestPickerKeepsTheHeaderAndTheStatusLine(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, pickerView(80, 24))
	if !strings.Contains(plain(rows[0]), "phycode") {
		t.Errorf("the header is covered: %q", plain(rows[0]))
	}
	if !strings.Contains(plain(rows[len(rows)-1]), "ready") {
		t.Errorf("the status line is covered: %q", plain(rows[len(rows)-1]))
	}
}

func TestTheListGroupsFavoritesRecentAndEachProvider(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	order := []string{"Favorites", "llama-3", "Recent", "gpt-x", "local ─", "qwen-coder", "deepseek-r1", "my-model", "remote ─", "+ Connect a provider…"}
	last := -1
	for _, want := range order {
		i := rowOf(rows, want)
		if i <= last {
			t.Fatalf("%q is at row %d, want it after row %d: %q", want, i, last, rows)
		}
		last = i
	}
	if rowOf(rows, "cloud ─") >= 0 {
		t.Errorf("cloud has no group of its own while its only model is listed under Recent: %q", rows)
	}
}

func TestAProviderHeadingSaysWhatItSpeaksWhereItIsAndWhenItWasQueried(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	local := rowWith(rows, "local ─")
	for _, want := range []string{"openai", "127.0.0.1:9931", "4 models", "3m ago"} {
		if !strings.Contains(local, want) {
			t.Errorf("local heading = %q, want %q", local, want)
		}
	}
	if strings.Contains(local, "http://") {
		t.Errorf("local heading = %q, want the endpoint without its scheme", local)
	}
	remote := rowWith(rows, "remote ─")
	for _, want := range []string{"✖ failed", "anthropic", "not listed yet"} {
		if !strings.Contains(remote, want) {
			t.Errorf("remote heading = %q, want %q", remote, want)
		}
	}
	if rowOf(rows, "✖ protocol anthropic is not available yet") < 0 {
		t.Errorf("the problem of remote is not shown under its heading: %q", rows)
	}
}

func TestAProviderHeadingGivesUpDetailsBeforeItsName(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(50, 40)))
	local := rowWith(rows, "local ─")
	if local == "" || !strings.Contains(local, "openai") {
		t.Errorf("local heading = %q at 50 columns, want its name and its protocol", local)
	}
	if strings.Contains(local, "3m ago") {
		t.Errorf("local heading = %q at 50 columns, want the age given up first", local)
	}
}

func TestModelRowsShowLoadedContextToolsAndThinking(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	qwen := rowWith(rows, "qwen-coder")
	for _, want := range []string{"●", "loaded", "32k", "tools", "off"} {
		if !strings.Contains(qwen, want) {
			t.Errorf("qwen-coder row = %q, want %q", qwen, want)
		}
	}
	llama := rowWith(rows, "llama-3")
	if !strings.Contains(llama, "8k") || !strings.Contains(llama, "no tools") || strings.Contains(llama, "–") {
		t.Errorf("llama-3 row = %q; want 8k and no tools, with a blank, not a dash, for what the server did not report", llama)
	}
	if mine := rowWith(rows, "my-model"); !strings.Contains(mine, "custom") || strings.Contains(qwen, "custom") {
		t.Errorf("my-model row = %q; want the custom tag, and no tag on qwen-coder", mine)
	}
}

func TestFavoritesAndRecentsNameTheProviderOfEachModel(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	if row := rowWith(rows, "llama-3"); !strings.Contains(row, "local") {
		t.Errorf("llama-3 row = %q, want its provider", row)
	}
	if row := rowWith(rows, "gpt-x"); !strings.Contains(row, "cloud") {
		t.Errorf("gpt-x row = %q, want its provider", row)
	}
	if row := rowWith(rows, "deepseek-r1"); strings.Contains(row, "local") {
		t.Errorf("deepseek-r1 row = %q, want no provider: its heading says it", row)
	}
}

func TestOnlyTheCurrentModelHasTheCurrentMark(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	if n := strings.Count(strings.Join(dockRowsOf(screen(t, r, pickerView(100, 40))), "\n"), "●"); n != 1 {
		t.Errorf("%d current marks in the dock, want 1: %q", n, rows)
	}
}

func TestTheHighlightIsOneBarOnTheRowWithTheSelectBackground(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	rows := screen(t, r, pickerView(100, 40))
	marked := highlightedRows(rows)
	if len(marked) != 1 {
		t.Fatalf("%d highlighted rows on the screen, want 1", len(marked))
	}
	for _, row := range rows {
		if wearsBar(row) != (onBar(plain(row))) {
			t.Errorf("the highlight background and the bar do not go together on row %q", plain(row))
		}
	}
}

func TestTheHighlightedModelShowsItsThinkingLevelBetweenArrows(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	m.Picker.Selected = 2
	m.Picker.Level = "on"
	rows := plainRows(screen(t, r, m))
	if row := highlightRow(rows); !strings.Contains(row, "qwen-coder") || !strings.Contains(row, "‹ on ›") {
		t.Errorf("highlighted row = %q, want qwen-coder with ‹ on ›", row)
	}
	if row := rowWith(rows, "deepseek-r1"); strings.Contains(row, "‹") {
		t.Errorf("deepseek-r1 row = %q, want no arrows on a row that is not highlighted", row)
	}
}

func TestTheValuesAtTheRightLineUpAcrossRows(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	qwen, deep := rowWith(rows, "qwen-coder"), rowWith(rows, "deepseek-r1")
	if a, b := cellAt(qwen, "off"), cellAt(deep, "off"); a < 0 || a != b {
		t.Errorf("thinking levels start at cells %d and %d, want the same column:\n%s\n%s", a, b, qwen, deep)
	}
}

func cellAt(row, s string) int {
	i := strings.Index(row, s)
	if i < 0 {
		return -1
	}
	return lipgloss.Width(row[:i])
}

func TestTheFilterMarksTheLettersItMatched(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	m.Picker.Query = "coder"
	m.Picker.Selected = 1
	rows := screen(t, r, m)
	row := rows[rowOf(rows, "qwen-coder")]
	if n := strings.Count(row, seqOf(theme.Default().Match)); n < len("coder") {
		t.Errorf("row = %q, want the letters coder drawn as a match (%d styled runs)", row, n)
	}
	if rowOf(rows, "deepseek-r1") >= 0 {
		t.Errorf("deepseek-r1 is listed for the filter coder: %q", plainRows(rows))
	}
}

func TestTheTitleShowsHowOldTheCatalogueIs(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	if rowOf(rows, "Models") < 0 || rowWith(rows, "Models") == "" || !strings.Contains(rowWith(rows, "Models"), "updated 3m ago") {
		t.Errorf("the title does not say how old the catalogue is: %q", rows)
	}
	m := pickerView(100, 40)
	m.Picker.Providers[0].Refreshing = true
	rows = plainRows(screen(t, r, m))
	if !strings.Contains(rowWith(rows, "Models"), "refreshing…") {
		t.Errorf("the title does not say that a provider is queried: %q", rows)
	}
	m = pickerView(100, 40)
	m.Picker.Applying = true
	if !strings.Contains(rowWith(plainRows(screen(t, r, m)), "Models"), "applying…") {
		t.Error("the title does not say that a model is being applied")
	}
}

func TestTheSearchLineShowsThePlaceholderOrTheFilter(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(100, 40)))
	if rowOf(rows, "search models or providers") < 0 {
		t.Errorf("the empty search line has no placeholder: %q", rows)
	}
	m := pickerView(100, 40)
	m.Picker.Query = "gp"
	rows = plainRows(screen(t, r, m))
	if rowOf(rows, "❯ gp█") < 0 {
		t.Errorf("the search line does not show the filter and a cursor: %q", rows)
	}
}

func TestPickerShowsLoadingAndEmptyStates(t *testing.T) {
	r := NewRenderer(theme.Default())

	m := pickerView(100, 40)
	m.Picker.Loading = true
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "loading models…") < 0 || !strings.Contains(rowWith(rows, "Models"), "loading…") {
		t.Errorf("the loading state is missing: %q", rows)
	}

	m = pickerView(100, 40)
	m.Busy = true
	m.Picker.Loading = true
	rows = plainRows(screen(t, r, m))
	if rowOf(rows, "models load when the turn is over") < 0 || rowOf(rows, "loading models…") >= 0 || !strings.Contains(rowWith(rows, "Models"), "waiting for the turn…") {
		t.Errorf("a picker that waits during a turn does not say when the models load: %q", rows)
	}

	m = pickerView(100, 40)
	m.Picker.Providers, m.Picker.Entries = nil, nil
	rows = plainRows(screen(t, r, m))
	if rowOf(rows, "no providers yet") < 0 || rowOf(rows, "+ Connect a provider…") < 0 {
		t.Errorf("an empty catalogue shows neither its note nor the add row: %q", rows)
	}

	m = pickerView(100, 40)
	m.Picker.Query = "zzz"
	rows = plainRows(screen(t, r, m))
	if rowOf(rows, "no model or provider matches “zzz”") < 0 || rowOf(rows, "+ Connect a provider…") < 0 {
		t.Errorf("a filter that matches nothing shows neither its note nor the add row: %q", rows)
	}
}

func TestPickerShowsAProblemReportedByTheBackend(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	m.Picker.Error = "the change could not be saved"
	if rowOf(screen(t, r, m), "the change could not be saved") < 0 {
		t.Error("the picker error is not shown")
	}
}

func TestAModelWithAProblemShowsItInPlaceOfItsValues(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	down := "connection refused"
	m.Picker.Entries = append(m.Picker.Entries, protocol.ModelEntry{Profile: "cloud", Model: "broken", Error: &down})
	m.Picker.Recents = nil
	rows := plainRows(screen(t, r, m))
	if row := rowWith(rows, "broken"); !strings.Contains(row, "connection refused") {
		t.Errorf("broken row = %q, want its problem", row)
	}
}

func TestAProviderThatListsNoModelSaysSo(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	m.Picker.Providers = append(m.Picker.Providers, protocol.Provider{Profile: "idle", Protocol: "openai"})
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "no models listed · ctrl+n types a model id") < 0 {
		t.Errorf("a provider without models has no note: %q", rows)
	}
}

func TestPickerFooterHintsFollowTheMode(t *testing.T) {
	r := NewRenderer(theme.Default())
	modes := []struct {
		name string
		m    state.Model
		want []string
	}{
		{"list", pickerView(160, 40), []string{"enter use", "ctrl+f favourite", "ctrl+s save default", "ctrl+a connect", "ctrl+n custom id", "ctrl+r refresh", "esc close"}},
		{"custom id", func() state.Model {
			m := pickerView(120, 40)
			m.Picker.CustomOpen, m.Picker.CustomFor = true, "local"
			return m
		}(), []string{"enter use", "type a model id", "esc cancel"}},
		{"form", func() state.Model {
			m := pickerView(120, 40)
			f := state.NewProviderForm()
			m.Picker.Form = &f
			return m
		}(), []string{"shift+tab previous", "enter add"}},
	}
	for _, mode := range modes {
		rows := plainRows(screen(t, r, mode.m))
		for _, hint := range mode.want {
			if rowOf(rows, hint) < 0 {
				t.Errorf("%s: the hint %q is missing: %q", mode.name, hint, rows)
			}
		}
	}
}

func TestOnlyACustomModelOffersToRemoveIt(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(160, 40)
	if rowOf(plainRows(screen(t, r, m)), "ctrl+x remove custom") >= 0 {
		t.Error("the footer offers ctrl+x while a model that is not custom is highlighted")
	}
	m.Picker.Selected = 4
	if rowOf(plainRows(screen(t, r, m)), "ctrl+x remove custom") < 0 {
		t.Error("the footer does not offer ctrl+x on a custom model id")
	}
}

func TestHintKeysAreBrightAndTheirMeaningIsMuted(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, pickerView(160, 40))
	row := rows[rowOf(rows, "ctrl+f favourite")]
	s := theme.Default()
	if !strings.Contains(row, s.Key.Render("ctrl+f")) || !strings.Contains(row, s.Dim.Render(" favourite")) {
		t.Errorf("hint row = %q, want the key drawn as a key and what it does muted", row)
	}
}

func TestHintLinesKeepEveryHintWhole(t *testing.T) {
	text := strings.Join(hintsListHead, " · ")
	if got := hintLines(text, 400); len(got) != 1 || got[0] != text {
		t.Errorf("hintLines at 400 cells = %q, want the footer on one row", got)
	}
	lines := hintLines(text, 32)
	for _, line := range lines {
		if lipgloss.Width(line) > 32 {
			t.Errorf("line %q is wider than 32 cells", line)
		}
	}
	if joined := strings.Join(lines, " · "); joined != text {
		t.Errorf("joined lines = %q, want the footer back", joined)
	}
}

func TestPickerScrollsToKeepTheHighlightInView(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Picker = state.Picker{Open: true, Providers: []protocol.Provider{{Profile: "local", Protocol: "openai"}}}
	for i := 0; i < 40; i++ {
		m.Picker.Entries = append(m.Picker.Entries, protocol.ModelEntry{Profile: "local", Model: "m" + twoDigits(i), Protocol: "openai"})
	}
	m.Picker.Selected = 30
	rows := plainRows(screen(t, r, m))
	if row := highlightRow(rows); !strings.Contains(row, "m30") {
		t.Errorf("the highlighted model is not in view: %q", rows)
	}
	if rowOf(rows, "more") < 0 {
		t.Errorf("a list that does not fit says nothing about the rows it cuts: %q", rows)
	}
	if i := rowOf(rows, "m30"); strings.Contains(rows[i], "↑") || strings.Contains(rows[i], "↓") {
		t.Errorf("the highlighted row is under a note: %q", rows[i])
	}
}

func TestPickerSmallTerminalsKeepTheHighlightedRow(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, size := range [][2]int{{20, 8}, {40, 12}, {60, 14}} {
		rows := screen(t, r, pickerView(size[0], size[1]))
		if highlightRow(rows) == "" {
			t.Errorf("%dx%d: the highlighted row is not visible: %q", size[0], size[1], plainRows(rows))
		}
		if rowOf(rows, "Models") < 0 {
			t.Errorf("%dx%d: the title is missing: %q", size[0], size[1], plainRows(rows))
		}
	}
}

func TestPickerFooterKeepsHowToLeaveOnAShortScreen(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, pickerView(40, 14))
	if rowOf(rows, "↑↓ move") < 0 || rowOf(rows, "esc close") < 0 {
		t.Errorf("40x14: the footer is missing its first or its closing hint: %q", plainRows(rows))
	}
}

func TestAddProviderFormShowsItsFieldsAndFocus(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	f := state.NewProviderForm()
	f.Values[state.FieldName] = "home"
	f.Focus = state.FieldBaseURL
	m.Picker.Form = &f
	m.Picker.Error = "base_url is required"
	rows := plainRows(screen(t, r, m))

	if rowOf(rows, "Custom endpoint") < 0 {
		t.Errorf("the form has no title: %q", rows)
	}
	if row := rowWith(rows, "name  "); !strings.Contains(row, "home") {
		t.Errorf("name row = %q, want the typed name", row)
	}
	if row := rowWith(rows, "api  "); !strings.Contains(row, "‹ OpenAI-compatible ›") {
		t.Errorf("api row = %q, want the protocol between arrows", row)
	}
	if row := highlightRow(rows); !strings.Contains(row, "base_url") || !strings.Contains(row, "█") {
		t.Errorf("focused row = %q; want the base_url field with the cursor", row)
	}
	if rowOf(rows, "where it listens, e.g. https://api.example.com or http://127.0.0.1:9931") < 0 {
		t.Errorf("the help of the focused field is missing: %q", rows)
	}
	if row := rowWith(rows, "api_key_env"); strings.Contains(row, "optional") || strings.Contains(row, "NAME") {
		t.Errorf("api_key_env row = %q, want the field alone: its help shows under the form, for the focused field only", row)
	}
	if rowOf(rows, "base_url is required") < 0 {
		t.Errorf("the form's problem is not shown: %q", rows)
	}
	if rowOf(rows, "shift+tab previous") < 0 {
		t.Errorf("the form's hints are missing: %q", rows)
	}
	if rowOf(rows, "search models") >= 0 {
		t.Errorf("the form still shows the search line: %q", rows)
	}
}

func TestAddProviderFormMasksTheKey(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	f := state.NewProviderForm()
	f.Values[state.FieldName] = "home"
	f.Values[state.FieldAPIKey] = "sk-secret-123"
	f.Focus = state.FieldAPIKey
	m.Picker.Form = &f
	rows := plainRows(screen(t, r, m))
	if row := highlightRow(rows); !strings.Contains(row, "api_key") || !strings.Contains(row, strings.Repeat("•", 13)+"█") {
		t.Errorf("focused row = %q; want the api_key field with one dot for each of the 13 characters and the cursor after them", row)
	}
	if text := strings.Join(rows, "\n"); strings.Contains(text, "sk-secret") || strings.Count(text, "•") != 13 {
		t.Errorf("the screen shows the key itself, or not exactly its dots: %q", rows)
	}

	f.Focus = state.FieldName
	rows = plainRows(screen(t, r, m))
	if i := rowOf(rows, "api_key "); i < 0 || !strings.Contains(rows[i], strings.Repeat("•", 13)) {
		t.Errorf("unfocused api_key row = %q; want the dots", rowWith(rows, "api_key"))
	}
	if strings.Contains(strings.Join(rows, "\n"), "sk-secret") {
		t.Errorf("the key shows when the field is not focused: %q", rows)
	}
}

func TestTheHelpUnderTheFormFollowsTheFocusedField(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	f := state.NewProviderForm()
	m.Picker.Form = &f
	for field, want := range map[int]string{
		state.FieldAPIKey:    "the key itself, saved encrypted and never shown",
		state.FieldModel:     "optional: leave it empty and choose one from the list the server gives",
		state.FieldAPIKeyEnv: "the NAME of an environment variable",
	} {
		f.Focus = field
		if rowOf(plainRows(screen(t, r, m)), want) < 0 {
			t.Errorf("the focused %s field does not say what it takes (%q)", formLabels[field], want)
		}
	}
}

func TestTheFormHasItsFieldsInTwoSectionsAndTheModelIsOptional(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	f := state.NewProviderForm()
	m.Picker.Form = &f
	rows := inDock(t, r, m)
	last := -1
	for _, want := range []string{"Endpoint", "name", "api", "base_url", "api_key", "Optional", "api_key_env", "model"} {
		i := rowStarting(rows, want)
		if i <= last {
			t.Fatalf("%q is at row %d, want it after row %d: %q", want, i, last, rows)
		}
		last = i
	}
	if strings.Contains(strings.Join(rows, "\n"), "required") {
		t.Errorf("the form calls something required that it does not need: %q", rows)
	}
	if rowOf(rows, "Custom endpoint") < 0 {
		t.Errorf("the form is not titled as the custom endpoint: %q", rows)
	}
}

func TestTheApiFieldNamesTheProtocolAsAPersonWouldAndTheArrowsChangeIt(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	f := state.NewProviderForm()
	m.Picker.Form = &f
	for protocol, label := range map[string]string{
		"openai": "OpenAI-compatible", "anthropic": "Anthropic-compatible", "ollama": "Ollama", "openai-responses": "OpenAI Responses",
	} {
		f.Values[state.FieldProtocol] = protocol
		if row := rowWith(inDock(t, r, m), "api  "); !strings.Contains(row, "‹ "+label+" ›") {
			t.Errorf("api row = %q, want %s between arrows", row, label)
		}
	}
}

func TestAddProviderFormKeepsTheFocusedFieldInViewOnASmallScreen(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(60, 14)
	f := state.NewProviderForm()
	f.Focus = state.FieldModel
	m.Picker.Form = &f
	rows := screen(t, r, m)
	if row := highlightRow(rows); !strings.Contains(row, "model") {
		t.Errorf("the focused model field is not in view: %q", plainRows(rows))
	}
}

func TestCustomModelIdInputShowsWhatIsTypedAndForWhichProvider(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	m.Picker.CustomOpen = true
	m.Picker.CustomFor = "local"
	m.Picker.CustomID = "qwen-x"
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "❯ qwen-x█") < 0 {
		t.Errorf("the input does not show the typed id: %q", rows)
	}
	m.Picker.CustomID = ""
	if rowOf(plainRows(screen(t, r, m)), "model id for local") < 0 {
		t.Error("the empty input does not say which provider the id is for")
	}
	if rowOf(rows, "type a model id") < 0 {
		t.Errorf("the input's hints are missing: %q", rows)
	}
}

func TestEveryPickerModeFitsEveryRowInTheWidth(t *testing.T) {
	r := NewRenderer(theme.Default())
	modes := map[string]func(w, h int) state.Model{
		"models": pickerView,
		"filtered": func(w, h int) state.Model {
			m := pickerView(w, h)
			m.Picker.Query = "o"
			return m
		},
		"loading": func(w, h int) state.Model {
			m := pickerView(w, h)
			m.Picker.Loading = true
			return m
		},
		"custom id": func(w, h int) state.Model {
			m := pickerView(w, h)
			m.Picker.CustomOpen = true
			m.Picker.CustomFor = "local"
			m.Picker.CustomID = strings.Repeat("model", 12)
			return m
		},
		"form": func(w, h int) state.Model {
			m := pickerView(w, h)
			f := state.NewProviderForm()
			f.Values[state.FieldName] = strings.Repeat("name", 12)
			f.Focus = state.FieldAPIKeyEnv
			m.Picker.Form = &f
			m.Picker.Error = strings.Repeat("a long problem ", 8)
			return m
		},
	}
	for name, mode := range modes {
		for _, size := range [][2]int{{20, 8}, {40, 12}, {80, 24}, {120, 40}} {
			t.Run(fmt.Sprintf("%s at %dx%d", name, size[0], size[1]), func(t *testing.T) {
				screen(t, r, mode(size[0], size[1]))
			})
		}
	}
}

func TestRelativeAgeReadsTheLargestUnitThatFits(t *testing.T) {
	tests := []struct {
		d    time.Duration
		want string
	}{
		{-time.Minute, "just now"},
		{59 * time.Second, "just now"},
		{3 * time.Minute, "3m ago"},
		{150 * time.Minute, "2h ago"},
		{49 * time.Hour, "2d ago"},
	}
	for _, tt := range tests {
		if got := relativeAge(tt.d); got != tt.want {
			t.Errorf("relativeAge(%v) = %q, want %q", tt.d, got, tt.want)
		}
	}
}

func TestContextLabel(t *testing.T) {
	n := func(v int) *int { return &v }
	tests := []struct {
		tokens *int
		want   string
	}{
		{nil, ""},
		{n(0), ""},
		{n(300), "<1k"},
		{n(512), "1k"},
		{n(4096), "4k"},
		{n(16384), "16k"},
		{n(131072), "128k"},
		{n(1000 * 1024), "1M"},
		{n(2 << 20), "2M"},
	}
	for _, tt := range tests {
		if got := contextLabel(tt.tokens); got != tt.want {
			t.Errorf("contextLabel(%v) = %q, want %q", tt.tokens, got, tt.want)
		}
	}
}

func TestHostOfDropsTheSchemeAndKeepsTheSocket(t *testing.T) {
	for in, want := range map[string]string{
		"http://127.0.0.1:9931":   "127.0.0.1:9931",
		"https://api.example.com": "api.example.com",
		"unix:/tmp/llama.sock":    "unix:/tmp/llama.sock",
		"":                        "",
	} {
		if got := hostOf(in); got != want {
			t.Errorf("hostOf(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestALongProblemNeverTakesTheNameOfTheModelAway(t *testing.T) {
	r := NewRenderer(theme.Default())
	long := "connection to http://127.0.0.1:8082/v1/models failed: Failed to connect to 127.0.0.1 port 8082 after 0 ms: Connection refused"
	for _, w := range []int{50, 70, 100} {
		m := pickerView(w, 40)
		m.Picker.Entries = append(m.Picker.Entries, protocol.ModelEntry{Profile: "cloud", Model: "qwen35-2b", Error: &long})
		m.Picker.Recents = append(m.Picker.Recents, protocol.ModelRef{Profile: "cloud", Model: "qwen35-2b"})
		rows := plainRows(screen(t, r, m))
		row := rowWith(rows, "qwen35-2b")
		if row == "" || !strings.Contains(row, "cloud") || !strings.Contains(row, "connection") || !strings.Contains(row, "…") {
			t.Errorf("width %d: row = %q, want the name, the provider and the problem cut with an ellipsis", w, row)
		}
	}
}

func TestAProviderThatIsDownStaysVisibleWhenItsOnlyModelIsListedUnderRecent(t *testing.T) {
	r := NewRenderer(theme.Default())
	down := "connection refused"
	m := newModel(100, 40)
	m.Picker = state.Picker{
		Open: true, Known: true,
		Providers: []protocol.Provider{{Profile: "qwen-2b", Protocol: "openai", Endpoint: "http://127.0.0.1:8082", Count: 1, Error: &down}},
		Entries:   []protocol.ModelEntry{{Profile: "qwen-2b", Model: "qwen35-2b", Error: &down}},
		Recents:   []protocol.ModelRef{{Profile: "qwen-2b", Model: "qwen35-2b"}},
	}
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "qwen-2b ─") < 0 || rowOf(rows, "✖ failed") < 0 || rowOf(rows, "✖ connection refused") < 0 {
		t.Errorf("the provider that is down has no heading with its problem: %q", rows)
	}
	m.Picker.Query = "qwen"
	if rowOf(plainRows(screen(t, r, m)), "✖ connection refused") >= 0 {
		t.Error("a typed filter still shows the notes")
	}
}

func TestAProblemOfAProviderThatIsDownIsSaidOnce(t *testing.T) {
	r := NewRenderer(theme.Default())
	down := "connection refused"
	m := newModel(100, 40)
	m.Picker = state.Picker{
		Open: true, Known: true,
		Providers: []protocol.Provider{{Profile: "qwen-2b", Protocol: "openai", Endpoint: "http://127.0.0.1:8082", Count: 2, Error: &down}},
		Entries: []protocol.ModelEntry{
			{Profile: "qwen-2b", Model: "qwen35-2b", Protocol: "openai", Error: &down},
			{Profile: "qwen-2b", Model: "qwen35-4b", Protocol: "openai", Error: &down},
		},
		Favorites: []protocol.ModelRef{{Profile: "qwen-2b", Model: "qwen35-2b"}},
		Recents:   []protocol.ModelRef{{Profile: "qwen-2b", Model: "qwen35-4b"}},
	}
	rows := plainRows(screen(t, r, m))
	if n := strings.Count(strings.Join(rows, "\n"), down); n != 1 {
		t.Errorf("%q is shown %d times, want once: %q", down, n, rows)
	}
	if rowOf(rows, "✖ failed") < 0 || rowOf(rows, "✖ unavailable") < 0 {
		t.Errorf("the heading keeps its status and the models say they are unavailable: %q", rows)
	}

	m.Picker.Favorites, m.Picker.Recents = nil, nil
	rows = plainRows(screen(t, r, m))
	if n := strings.Count(strings.Join(rows, "\n"), down); n != 1 {
		t.Errorf("with the models listed under the provider, %q is shown %d times, want once: %q", down, n, rows)
	}

	m.Picker.Query = "qwen"
	rows = plainRows(screen(t, r, m))
	if n := strings.Count(strings.Join(rows, "\n"), down); n < 1 {
		t.Errorf("with a filter typed, the note is hidden and no row says the problem: %q", rows)
	}
}

func TestWhileAModelIdIsTypedNoRowOfTheListWearsTheBar(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	m := pickerView(100, 40)
	m.Picker.CustomOpen, m.Picker.CustomFor = true, "local"
	rows := screen(t, r, m)
	if highlightRow(rows) != "" {
		t.Errorf("a row wears the bar while the id is typed: %q", plainRows(rows))
	}
	if wearsBar(rows[rowOf(rows, "qwen-coder")]) {
		t.Error("a row of the list paints the highlight background while the id is typed")
	}
}

func TestAShortScreenKeepsTwoRowsOfHintsAtMost(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, pickerView(54, 20)))
	hints := 0
	for _, want := range []string{"↑↓ move", "ctrl+f favourite", "ctrl+a connect", "ctrl+r refresh"} {
		if rowOf(rows, want) >= 0 {
			hints++
		}
	}
	if hints > 2 {
		t.Errorf("%d rows of hints on a 54x20 screen, want at most 2: %q", hints, rows)
	}
	if highlightRow(rows) == "" {
		t.Errorf("the highlighted row is not shown: %q", rows)
	}
}
