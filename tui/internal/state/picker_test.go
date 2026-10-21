package state

import (
	"fmt"
	"reflect"
	"strings"
	"testing"
	"time"

	"phycode/tui/internal/protocol"
)

func entry(profile, model string, levels ...string) protocol.ModelEntry {
	return protocol.ModelEntry{Profile: profile, Model: model, Thinking: levels}
}

func withDefault(e protocol.ModelEntry, level string) protocol.ModelEntry {
	e.ThinkingDefault = &level
	return e
}

func provider(profile, proto string) protocol.Provider {
	return protocol.Provider{Profile: profile, Protocol: proto}
}

func listed(list []protocol.ModelEntry) []string {
	out := []string{}
	for _, e := range list {
		out = append(out, e.Profile+"/"+e.Model)
	}
	return out
}

func names(list []protocol.Provider) []string {
	out := []string{}
	for _, pr := range list {
		out = append(out, pr.Profile)
	}
	return out
}

func catalogue() Picker {
	failed := "protocol anthropic is not available yet"
	return Picker{
		Providers: []protocol.Provider{
			{Profile: "local", Protocol: "openai"},
			{Profile: "cloud", Protocol: "openai"},
			{Profile: "remote", Protocol: "anthropic", Error: &failed},
		},
		Entries: []protocol.ModelEntry{
			entry("local", "qwen-coder", "off", "on"),
			entry("local", "llama-3"),
			entry("local", "deepseek-r1", "off", "low", "high"),
			entry("local", "my-model"),
			entry("cloud", "gpt-x", "off", "low"),
		},
	}
}

func kinds(rows []Row) string {
	out := ""
	for _, r := range rows {
		out += map[RowKind]string{RowSection: "S", RowModel: "M", RowNote: "N", RowAdd: "A"}[r.Kind]
	}
	return out
}

func TestRowsGroupTheModelsByProviderInTheBackendsOrder(t *testing.T) {
	p := catalogue()
	if got, want := kinds(p.Rows()), "SMMMMSMSNA"; got != want {
		t.Errorf("rows = %s, want %s: a section for each provider, its models, a note for remote, and the add row", got, want)
	}
	if got, want := listed(p.Visible()), []string{"local/qwen-coder", "local/llama-3", "local/deepseek-r1", "local/my-model", "cloud/gpt-x"}; !reflect.DeepEqual(got, want) {
		t.Errorf("Visible = %v, want %v", got, want)
	}
}

func TestRowsListFavoritesThenRecentsThenTheProviders(t *testing.T) {
	p := catalogue()
	p.Favorites = []protocol.ModelRef{{Profile: "local", Model: "llama-3"}}
	p.Recents = []protocol.ModelRef{{Profile: "cloud", Model: "gpt-x"}, {Profile: "local", Model: "llama-3"}, {Profile: "local", Model: "qwen-coder"}}
	want := []string{"local/llama-3", "cloud/gpt-x", "local/qwen-coder", "local/deepseek-r1", "local/my-model"}
	if got := listed(p.Visible()); !reflect.DeepEqual(got, want) {
		t.Errorf("Visible = %v, want %v: a model is listed once, in the first group that has it, and the recents keep their history order", got, want)
	}
	rows := p.Rows()
	if rows[0].Title != GroupFavorites || rows[2].Title != GroupRecent || rows[2].Provider != nil {
		t.Errorf("the first sections = %q and %q, want Favorites and Recent", rows[0].Title, rows[2].Title)
	}
	if rows[1].Group != GroupFavorites || rows[3].Group != GroupRecent {
		t.Errorf("models of the groups before the providers know their group: %q, %q", rows[1].Group, rows[3].Group)
	}
}

func TestRowsFilterByModelNameOrProvider(t *testing.T) {
	p := catalogue()
	p.Query = "cod"
	if got, want := listed(p.Visible()), []string{"local/qwen-coder"}; !reflect.DeepEqual(got, want) {
		t.Errorf("filter cod: %v, want %v", got, want)
	}
	p.Query = "cloud"
	if got, want := listed(p.Visible()), []string{"cloud/gpt-x"}; !reflect.DeepEqual(got, want) {
		t.Errorf("filter cloud: %v, want the models of that provider", got)
	}
	p.Query = "zzz"
	if got := kinds(p.Rows()); got != "A" {
		t.Errorf("a filter that matches nothing leaves rows %s, want only the add row", got)
	}
}

func TestRowsRankTheBestNameMatchFirstWithinAGroup(t *testing.T) {
	p := Picker{
		Providers: []protocol.Provider{{Profile: "local"}},
		Entries:   []protocol.ModelEntry{entry("local", "xqwen"), entry("local", "q-w-e-n"), entry("local", "qwen-coder"), entry("local", "my-qwen")},
		Query:     "qwen",
	}
	want := []string{"local/qwen-coder", "local/my-qwen", "local/xqwen", "local/q-w-e-n"}
	if got := listed(p.Visible()); !reflect.DeepEqual(got, want) {
		t.Errorf("Visible = %v, want prefix, word, inside, spread", got)
	}
	if got := p.Rows()[1].Match; !reflect.DeepEqual(got, []int{0, 1, 2, 3}) {
		t.Errorf("the match of qwen-coder = %v, want its first four runes", got)
	}
}

func TestProviderNotesNameTheProblemOnlyWhileNoFilterIsTyped(t *testing.T) {
	p := catalogue()
	var notes []string
	for _, r := range p.Rows() {
		if r.Kind == RowNote {
			notes = append(notes, r.Title)
			if !r.Bad {
				t.Errorf("the problem of remote is a plain remark: %+v", r)
			}
		}
	}
	if want := []string{"protocol anthropic is not available yet"}; !reflect.DeepEqual(notes, want) {
		t.Errorf("notes = %v, want %v", notes, want)
	}
	p.Query = "q"
	if strings.Contains(kinds(p.Rows()), "N") {
		t.Errorf("a typed filter still shows notes: %s", kinds(p.Rows()))
	}
}

func TestAProviderThatListsModelsAndIsDownSaysWhyOnce(t *testing.T) {
	down := "connection refused"
	p := Picker{
		Providers: []protocol.Provider{{Profile: "qwen-2b", Error: &down}},
		Entries:   []protocol.ModelEntry{entry("qwen-2b", "qwen35-2b")},
	}
	rows := p.Rows()
	if got := kinds(rows); got != "SMNA" || rows[2].Title != down || !rows[2].Bad {
		t.Errorf("rows = %s, note = %+v; want the models of the provider with its problem once, as a note under them", got, rows[2])
	}
}

func TestAProviderWithoutModelsAndWithoutAProblemSaysSo(t *testing.T) {
	p := Picker{Providers: []protocol.Provider{{Profile: "empty"}}}
	rows := p.Rows()
	if got := kinds(rows); got != "SNA" || rows[1].Bad || !strings.Contains(rows[1].Title, "no models") {
		t.Errorf("rows = %s, note = %+v; want a plain remark that it lists no models", got, rows[1])
	}
}

func TestAnEntryWithoutAModelIsANoteOfItsProvider(t *testing.T) {
	problem := "the server lists no models"
	p := Picker{
		Providers: []protocol.Provider{{Profile: "cloud"}},
		Entries:   []protocol.ModelEntry{{Profile: "cloud", Error: &problem}},
	}
	rows := p.Rows()
	if got := kinds(rows); got != "SNA" || rows[1].Title != problem || !rows[1].Bad {
		t.Errorf("rows = %s, note = %+v; want the problem of the entry as a note", got, rows[1])
	}
}

func TestAProfileOfTheEntriesWithoutAProviderGetsAGroup(t *testing.T) {
	p := Picker{Entries: []protocol.ModelEntry{entry("stray", "m1")}}
	if got := kinds(p.Rows()); got != "SMA" {
		t.Errorf("rows = %s, want a section, the model and the add row", got)
	}
}

func TestItemsAndTheAddRowFollowTheList(t *testing.T) {
	p := catalogue()
	if p.Items() != 5 || p.OnSpecial() {
		t.Fatalf("Items = %d, OnSpecial = %v; want 5 models and the highlight on the first", p.Items(), p.OnSpecial())
	}
	p.Selected = 5
	if !p.OnSpecial() {
		t.Error("the row after the last model is the add row")
	}
	p.Query = "zzz"
	if p.Items() != 0 || p.Selected == 0 {
		t.Fatalf("Items = %d", p.Items())
	}
	p.Selected = 0
	if !p.OnSpecial() {
		t.Error("with no model listed, the add row is the only row to highlight")
	}
}

func TestHighlightedIsNothingWhileLoadingOrOnTheAddRow(t *testing.T) {
	p := catalogue()
	if e, ok := p.Highlighted(); !ok || e.Model != "qwen-coder" {
		t.Errorf("Highlighted = %+v, %v; want qwen-coder", e, ok)
	}
	p.Loading = true
	if _, ok := p.Highlighted(); ok {
		t.Error("nothing is highlighted while the catalogue loads")
	}
	p.Loading, p.Selected = false, p.Items()
	if _, ok := p.Highlighted(); ok {
		t.Error("nothing is highlighted on the add row")
	}
}

func TestCustomTargetIsTheProviderOfTheHighlightOrTheFirstThatListsNothing(t *testing.T) {
	p := catalogue()
	p.Selected = 4
	if got := p.CustomTarget(); got != "cloud" {
		t.Errorf("target = %q, want the provider of the highlighted model", got)
	}
	p.Selected = p.Items()
	if got := p.CustomTarget(); got != "remote" {
		t.Errorf("target = %q, want remote, the first provider that lists no model", got)
	}
	if got := (Picker{}).CustomTarget(); got != "" {
		t.Errorf("target = %q without a provider, want none", got)
	}
}

func TestProviderFindsAProfileOfTheCatalogue(t *testing.T) {
	p := catalogue()
	if pr, ok := p.Provider("cloud"); !ok || pr.Protocol != "openai" {
		t.Errorf("Provider(cloud) = %+v, %v", pr, ok)
	}
	if _, ok := p.Provider("nope"); ok {
		t.Error("Provider(nope) found a provider")
	}
}

func TestQueriedAtReadsRFC3339AndNothingElse(t *testing.T) {
	when := "2026-10-19T12:00:00+00:00"
	bad := "yesterday"
	got, ok := QueriedAt(protocol.Provider{UpdatedAt: &when})
	if !ok || !got.Equal(time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)) {
		t.Errorf("QueriedAt = %v, %v; want 2026-10-19 12:00 UTC", got, ok)
	}
	if _, ok := QueriedAt(protocol.Provider{}); ok {
		t.Error("QueriedAt reports a time for a provider that was never queried")
	}
	if _, ok := QueriedAt(protocol.Provider{UpdatedAt: &bad}); ok {
		t.Error("QueriedAt reports a time for text that is not RFC 3339")
	}
}

func TestUpdatedIsTheLatestQueryOfTheProviders(t *testing.T) {
	early, late := "2026-10-19T12:00:00Z", "2026-10-19T12:30:00Z"
	p := Picker{Providers: []protocol.Provider{
		{Profile: "a", UpdatedAt: &early},
		{Profile: "b"},
		{Profile: "c", UpdatedAt: &late},
	}}
	got, ok := p.Updated()
	if !ok || !got.Equal(time.Date(2026, 10, 19, 12, 30, 0, 0, time.UTC)) {
		t.Errorf("Updated = %v, %v; want the latest query, 12:30", got, ok)
	}
	if _, ok := (Picker{Providers: []protocol.Provider{{Profile: "b"}}}).Updated(); ok {
		t.Error("Updated reports a time when no provider was queried")
	}
}

func TestDefaultLevel(t *testing.T) {
	p := Picker{Current: protocol.ModelRef{Profile: "local", Model: "qwen", Thinking: "high"}}
	tests := []struct {
		name string
		e    protocol.ModelEntry
		want string
	}{
		{"the current model keeps its level", entry("local", "qwen", "off", "low", "high"), "high"},
		{"the current model keeps its level over the profile's", withDefault(entry("local", "qwen", "off", "low", "high"), "low"), "high"},
		{"another model starts at off", entry("local", "other", "off", "on"), "off"},
		{"another model starts at its profile's level", withDefault(entry("local", "other", "off", "on"), "on"), "on"},
		{"a profile level the model does not offer is not used", withDefault(entry("local", "other", "off", "low"), "high"), "off"},
		{"without off, the first level", entry("local", "other", "low", "high"), "low"},
		{"the same model in another profile is not the current one", entry("cloud", "qwen", "off", "high"), "off"},
		{"the same model in another profile starts at its profile's level", withDefault(entry("cloud", "qwen", "off", "high"), "high"), "high"},
		{"a current level the model does not offer falls back", entry("local", "qwen", "off", "on"), "off"},
		{"a current level the model does not offer falls back to the profile's level", withDefault(entry("local", "qwen", "off", "on"), "on"), "on"},
		{"no thinking control", entry("local", "other"), ""},
		{"no thinking control, whatever the profile's level", withDefault(entry("local", "other"), "on"), ""},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := p.DefaultLevel(tt.e); got != tt.want {
				t.Errorf("DefaultLevel = %q, want %q", got, tt.want)
			}
		})
	}
}

func TestDefaultLevelMatchesTheOldAndTheCanonicalNames(t *testing.T) {
	tests := []struct {
		name    string
		current string
		e       protocol.ModelEntry
		want    string
	}{
		{"the current medium is the on of the model", "medium", entry("local", "qwen", "off", "on"), "on"},
		{"the current none is the off of the model", "none", entry("local", "qwen", "off", "on"), "off"},
		{"the profile's medium is the on of another model", "", withDefault(entry("local", "other", "off", "on"), "medium"), "on"},
		{"a model that lists none starts at none", "", entry("local", "other", "none", "low"), "none"},
		{"the current none is kept when the model lists it", "none", entry("local", "qwen", "none", "low", "high"), "none"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			p := Picker{Current: protocol.ModelRef{Profile: "local", Model: "qwen", Thinking: tt.current}}
			if got := p.DefaultLevel(tt.e); got != tt.want {
				t.Errorf("DefaultLevel = %q, want %q", got, tt.want)
			}
		})
	}
}

func formAt(field int) ProviderForm {
	f := NewProviderForm()
	f.Focus = field
	return f
}

func TestNewProviderFormStartsOnOpenAIWithTheCursorOnTheName(t *testing.T) {
	f := NewProviderForm()
	if f.Focus != FieldName || f.Values[FieldProtocol] != "openai" {
		t.Errorf("form = %+v; want the cursor on the name and openai", f)
	}
}

func TestProviderFormTabOrderWraps(t *testing.T) {
	f := NewProviderForm()
	var order []int
	for range FieldCount {
		f = f.Moved(1)
		order = append(order, f.Focus)
	}
	want := []int{FieldProtocol, FieldBaseURL, FieldAPIKey, FieldAPIKeyEnv, FieldModel, FieldName}
	if !reflect.DeepEqual(order, want) {
		t.Errorf("Tab visits %v, want %v", order, want)
	}
	if got := NewProviderForm().Moved(-1).Focus; got != FieldModel {
		t.Errorf("Shift+Tab from the name goes to field %d, want the model field", got)
	}
}

func TestProviderFormCyclesTheProtocolOnItsFieldOnly(t *testing.T) {
	for _, field := range []int{FieldName, FieldBaseURL, FieldAPIKey, FieldAPIKeyEnv, FieldModel} {
		if got := formAt(field).Cycled(1); got.Values[FieldProtocol] != "openai" {
			t.Errorf("cycled from field %d: protocol = %q, want unchanged openai", field, got.Values[FieldProtocol])
		}
	}
	f := formAt(FieldProtocol)
	var seen []string
	for range 5 {
		f = f.Cycled(1)
		seen = append(seen, f.Values[FieldProtocol])
	}
	if want := []string{"ollama", "openai-responses", "anthropic", "openai", "ollama"}; !reflect.DeepEqual(seen, want) {
		t.Errorf("cycling forward = %v, want %v", seen, want)
	}
	if got := f.Cycled(-1).Values[FieldProtocol]; got != "openai" {
		t.Errorf("cycling back from ollama = %q, want openai", got)
	}
	if got := formAt(FieldProtocol).Cycled(-1).Values[FieldProtocol]; got != "anthropic" {
		t.Errorf("cycling back from the first protocol = %q, want anthropic", got)
	}
}

func TestProviderFormTypesOnlyIntoTextFields(t *testing.T) {
	f := NewProviderForm().Typed("home").Erased()
	if f.Values[FieldName] != "hom" {
		t.Errorf("name = %q, want hom", f.Values[FieldName])
	}
	f = f.Moved(1).Typed("zz").Erased().Erased().Erased()
	if f.Focus != FieldProtocol || f.Values[FieldProtocol] != "openai" {
		t.Errorf("typing on the protocol changed it to %q", f.Values[FieldProtocol])
	}
	f = f.Moved(1).Typed("http://é").Erased()
	if f.Values[FieldBaseURL] != "http://" {
		t.Errorf("base_url = %q after erasing one character, want http://", f.Values[FieldBaseURL])
	}
	f = f.Moved(1).Typed("sk-é").Erased()
	if f.Values[FieldAPIKey] != "sk-" {
		t.Errorf("api_key = %q after erasing one character, want sk-", f.Values[FieldAPIKey])
	}
}

func TestNormalizeBaseURLDropsWhatTheClientsAddThemselves(t *testing.T) {
	for in, want := range map[string]string{
		"https://api.openai.com/v1":             "https://api.openai.com",
		"https://api.openai.com/v1/":            "https://api.openai.com",
		"  http://127.0.0.1:9931  ":             "http://127.0.0.1:9931",
		"https://openrouter.ai/api/v1":          "https://openrouter.ai/api",
		"https://x.test/v1/chat/completions":    "https://x.test",
		"https://api.anthropic.com/v1/messages": "https://api.anthropic.com",
		"http://127.0.0.1:11434":                "http://127.0.0.1:11434",
		"unix:/tmp/llama.sock":                  "unix:/tmp/llama.sock",
		"https://host.test/path":                "https://host.test/path",
		"https://host.test/v10":                 "https://host.test/v10",
		"":                                      "",
	} {
		if got := NormalizeBaseURL(in); got != want {
			t.Errorf("NormalizeBaseURL(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestProviderFormProblemNamesBothKeyAndKeyVariable(t *testing.T) {
	f := NewProviderForm()
	f.Values[FieldName] = "home"
	f.Values[FieldBaseURL] = "http://127.0.0.1:9931"
	f.Values[FieldAPIKey] = "sk-test"
	f.Values[FieldAPIKeyEnv] = "OPENAI_API_KEY"
	f.Values[FieldModel] = "m1"
	if field, problem := f.Problem(); field != FieldAPIKeyEnv || problem != "use api_key or api_key_env, not both" {
		t.Errorf("Problem = %d, %q; want api_key_env named as the one to clear", field, problem)
	}
	f.Values[FieldAPIKeyEnv] = ""
	if field, problem := f.Problem(); field != -1 || problem != "" {
		t.Errorf("Problem = %d, %q with the key alone; want none", field, problem)
	}
}

func TestDropLastRuneKeepsWholeCharacters(t *testing.T) {
	for in, want := range map[string]string{"": "", "a": "", "ño": "ñ", "abc": "ab"} {
		if got := DropLastRune(in); got != want {
			t.Errorf("DropLastRune(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestProviderFormProblemNamesTheFirstMissingField(t *testing.T) {
	f := NewProviderForm()
	if field, problem := f.Problem(); field != FieldName || problem != "name is required" {
		t.Errorf("empty form: Problem = %d, %q; want the name", field, problem)
	}
	f.Values[FieldName] = "home"
	if field, problem := f.Problem(); field != FieldBaseURL || problem != "base_url is required" {
		t.Errorf("without base_url: Problem = %d, %q; want base_url", field, problem)
	}
	f.Values[FieldBaseURL] = "http://127.0.0.1:9931"
	if field, problem := f.Problem(); field != -1 || problem != "" {
		t.Errorf("without a model: Problem = %d, %q; want none, since the picker lists the models the server has", field, problem)
	}
	f.Values[FieldModel] = "qwen2.5-coder"
	if field, problem := f.Problem(); field != -1 || problem != "" {
		t.Errorf("complete form: Problem = %d, %q; want none", field, problem)
	}
	f.Values[FieldName] = "   "
	if _, problem := f.Problem(); problem != "name is required" {
		t.Errorf("a blank name: Problem = %q, want the name to be required", problem)
	}
}

func TestAProviderThatIsDownGetsASectionEvenIfItsModelsAreListedAsRecent(t *testing.T) {
	down := "connection refused"
	p := Picker{
		Providers: []protocol.Provider{{Profile: "qwen", Error: &down}},
		Entries:   []protocol.ModelEntry{{Profile: "qwen", Model: "m", Error: &down}},
		Recents:   []protocol.ModelRef{{Profile: "qwen", Model: "m"}},
	}
	if got := kinds(p.Rows()); got != "SMSNA" {
		t.Errorf("rows = %s, want Recent with the model, then the section of the provider with its problem", got)
	}
	p.Query = "m"
	if got := kinds(p.Rows()); strings.Contains(got, "N") {
		t.Errorf("rows = %s, want no note while a filter is typed", got)
	}
}

func TestAProviderWithNoModelAndNoAnswerShowsItsProblemOnce(t *testing.T) {
	down := "connection refused"
	p := Picker{
		Providers: []protocol.Provider{{Profile: "hola", Protocol: "openai", Error: &down}},
		Entries:   []protocol.ModelEntry{{Profile: "hola", Error: &down}},
	}
	var notes []string
	for _, row := range p.Rows() {
		if row.Kind == RowNote {
			notes = append(notes, row.Title)
		}
	}
	if !reflect.DeepEqual(notes, []string{down}) {
		t.Errorf("notes = %q, want the problem once", notes)
	}
}

func bigProvider(models ...protocol.ModelEntry) Picker {
	return Picker{Known: true, Providers: []protocol.Provider{{Profile: "big", Protocol: "openai", Count: len(models)}}, Entries: models}
}

func listedModels(p Picker) []string {
	var out []string
	for _, row := range p.Rows() {
		if row.Kind == RowModel {
			out = append(out, row.Entry.Model)
		}
	}
	return out
}

func TestALongListIsSortedByNameWithTheModelsThatAreNotForChatLast(t *testing.T) {
	var entries []protocol.ModelEntry
	for _, name := range []string{"o3", "gpt-5", "Whisper-1", "text-embedding-3-small", "gpt-4o", "dall-e-3", "gpt-4.1", "o4-mini", "gpt-5-mini", "chatgpt-4o", "gpt-4", "gpt-3.5-turbo", "gpt-4o-mini"} {
		e := protocol.ModelEntry{Profile: "big", Model: name}
		switch name {
		case "Whisper-1":
			e.Kind = "audio"
		case "text-embedding-3-small":
			e.Kind = "embedding"
		case "dall-e-3":
			e.Kind = "image"
		}
		entries = append(entries, e)
	}
	want := []string{"chatgpt-4o", "gpt-3.5-turbo", "gpt-4", "gpt-4.1", "gpt-4o", "gpt-4o-mini", "gpt-5", "gpt-5-mini", "o3", "o4-mini", "dall-e-3", "text-embedding-3-small", "Whisper-1"}
	if got := listedModels(bigProvider(entries...)); !reflect.DeepEqual(got, want) {
		t.Errorf("models = %v, want %v", got, want)
	}
}

func TestAShortListKeepsTheOrderOfTheServerAndAFilterRanksByMatch(t *testing.T) {
	short := bigProvider(protocol.ModelEntry{Profile: "big", Model: "zeta"}, protocol.ModelEntry{Profile: "big", Model: "alpha"})
	if got := listedModels(short); !reflect.DeepEqual(got, []string{"zeta", "alpha"}) {
		t.Errorf("a short list = %v, want the order of the server", got)
	}
	var entries []protocol.ModelEntry
	for i := 0; i < 20; i++ {
		entries = append(entries, protocol.ModelEntry{Profile: "big", Model: fmt.Sprintf("m%02d-x", 20-i)})
	}
	long := bigProvider(entries...)
	long.Query = "m05"
	if got := listedModels(long); len(got) == 0 || got[0] != "m05-x" {
		t.Errorf("filtered = %v, want the best match first", got)
	}
}

func TestEveryModelOfAProviderThatListsHundredsIsInTheList(t *testing.T) {
	var entries []protocol.ModelEntry
	for i := 0; i < 725; i++ {
		entries = append(entries, protocol.ModelEntry{Profile: "big", Model: fmt.Sprintf("model-%03d", i)})
	}
	p := bigProvider(entries...)
	if got := len(listedModels(p)); got != 725 || p.Items() != 725+0 {
		t.Errorf("the list has %d models and %d items, want all 725", got, p.Items())
	}
}
