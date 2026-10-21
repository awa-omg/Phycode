package protocol

import (
	"reflect"
	"testing"
)

func TestParseReadyCarriesTheVersionThreeFields(t *testing.T) {
	got, ok := parseLine(t, `{"type":"ready","protocol":2,"app":{"version":"1"},"agents":[],"commands":[],
		"capabilities":["panels","ask","btw"],"theme":"paper","editor":"nvim"}`).(*Ready)
	if !ok {
		t.Fatal("Parse did not return *Ready")
	}
	editor := "nvim"
	if got.Protocol != 2 || got.Theme != "paper" || got.Editor == nil || *got.Editor != editor {
		t.Errorf("Ready = %+v, want protocol 2, theme paper and editor nvim", got)
	}
	if !reflect.DeepEqual(got.Capabilities, []string{"panels", "ask", "btw"}) {
		t.Errorf("Capabilities = %v", got.Capabilities)
	}
}

func TestParseReadyWithNullEditor(t *testing.T) {
	got, ok := parseLine(t, `{"type":"ready","protocol":2,"app":{},"agents":[],"commands":[],"capabilities":[],"theme":"tokyo-night","editor":null}`).(*Ready)
	if !ok || got.Editor != nil || got.Theme != "tokyo-night" {
		t.Errorf("Ready with a null editor = %+v, want a nil editor and the theme", got)
	}
}

func TestParseReadyWithoutProtocolIsZero(t *testing.T) {
	got, ok := parseLine(t, `{"type":"ready","app":{},"agents":[],"commands":[]}`).(*Ready)
	if !ok || got.Protocol != 0 {
		t.Errorf("Protocol = %d for a ready without the field, want 0", got.Protocol)
	}
}

func TestParseAsk(t *testing.T) {
	got, ok := parseLine(t, `{"type":"ask","id":"q1","question":"Which file?","options":["a.go","b.go"],"allowFreeText":true}`).(*Ask)
	if !ok {
		t.Fatal("Parse did not return *Ask")
	}
	want := &Ask{Type: TypeAsk, ID: "q1", Question: "Which file?", Options: []string{"a.go", "b.go"}, AllowFreeText: true}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("Ask = %+v, want %+v", got, want)
	}

	got, ok = parseLine(t, `{"type":"ask","id":"q2","question":"Continue?","options":[],"allowFreeText":false}`).(*Ask)
	if !ok || len(got.Options) != 0 || got.AllowFreeText {
		t.Errorf("Ask without options = %+v", got)
	}
}

func TestParseConfigKeepsEveryKind(t *testing.T) {
	got, ok := parseLine(t, `{"type":"config","items":[
		{"key":"tools.web","label":"Web search","value":true,"kind":"bool","choices":null,"category":"Tools"},
		{"key":"ui.theme","label":"Theme","value":"paper","kind":"choice","choices":["tokyo-night","paper"],"category":"Interface"},
		{"key":"ui.editor","label":"Editor","value":"","kind":"text","category":"Interface"}
	]}`).(*Config)
	if !ok {
		t.Fatal("Parse did not return *Config")
	}
	if len(got.Items) != 3 {
		t.Fatalf("Items = %d, want 3", len(got.Items))
	}
	bool_, choice, text := got.Items[0], got.Items[1], got.Items[2]
	if bool_.Kind != ConfigBool || !bool_.Bool() || bool_.Category != "Tools" || bool_.Choices != nil {
		t.Errorf("bool item = %+v", bool_)
	}
	if choice.Kind != ConfigChoice || choice.Text() != "paper" || !reflect.DeepEqual(choice.Choices, []string{"tokyo-night", "paper"}) {
		t.Errorf("choice item = %+v", choice)
	}
	if text.Kind != ConfigText || text.Text() != "" || text.Bool() {
		t.Errorf("text item = %+v", text)
	}
}

func TestConfigItemAccessorsIgnoreTheOtherKinds(t *testing.T) {
	if (ConfigItem{Value: "on"}).Bool() || (ConfigItem{Value: true}).Text() != "" || (ConfigItem{}).Text() != "" {
		t.Error("an accessor read a value of another kind")
	}
}

func TestParseSessions(t *testing.T) {
	got, ok := parseLine(t, `{"type":"sessions","items":[
		{"id":"s1","title":"fix parser","updatedAt":"2026-10-19T12:00:00+00:00","messages":12,"current":true},
		{"id":"s2","title":null,"updatedAt":null,"messages":0,"current":false}
	]}`).(*Sessions)
	if !ok {
		t.Fatal("Parse did not return *Sessions")
	}
	want := []Session{
		{ID: "s1", Title: "fix parser", UpdatedAt: "2026-10-19T12:00:00+00:00", Messages: 12, Current: true},
		{ID: "s2"},
	}
	if !reflect.DeepEqual(got.Items, want) {
		t.Errorf("Items = %+v, want %+v", got.Items, want)
	}
}

func TestParseContextKeepsNullsThresholdAndAutoCompact(t *testing.T) {
	got, ok := parseLine(t, `{"type":"context","used":4200,"limit":10000,"percent":42.0,"max":null,"threshold":7800,"autoCompact":true,"messages":7}`).(*Context)
	if !ok {
		t.Fatal("Parse did not return *Context")
	}
	used, limit, messages, threshold := 4200, 10000, 7, 7800
	percent := 42.0
	if got.Used == nil || *got.Used != used || got.Limit == nil || *got.Limit != limit || got.Percent == nil || *got.Percent != percent {
		t.Errorf("Context = %+v, want used 4200 of 10000 at 42%%", got)
	}
	if got.Max != nil || got.Threshold == nil || *got.Threshold != threshold || !got.AutoCompact || got.Messages == nil || *got.Messages != messages {
		t.Errorf("Context max = %v, threshold = %v, autoCompact = %v, messages = %v; want no max, the threshold 7800, autocompaction on and 7 messages",
			got.Max, got.Threshold, got.AutoCompact, got.Messages)
	}

	got, ok = parseLine(t, `{"type":"context","used":null,"limit":null,"percent":null,"max":8000,"threshold":null,"autoCompact":false,"messages":null}`).(*Context)
	if !ok || got.Used != nil || got.Percent != nil || got.Max == nil || *got.Max != 8000 || got.AutoCompact || got.Threshold != nil {
		t.Errorf("Context with nulls = %+v, want only the max", got)
	}
}

func TestParseContextKeepsTheBreakdown(t *testing.T) {
	got, ok := parseLine(t, `{"type":"context","used":4200,"limit":10000,"percent":42.0,"max":null,"threshold":null,"autoCompact":false,"messages":7,
		"breakdown":[{"category":"system","tokens":1200},{"category":"messages","tokens":900}]}`).(*Context)
	if !ok {
		t.Fatal("Parse did not return *Context")
	}
	want := []ContextPart{{Category: "system", Tokens: 1200}, {Category: "messages", Tokens: 900}}
	if !reflect.DeepEqual(got.Breakdown, want) {
		t.Errorf("Breakdown = %+v, want %+v", got.Breakdown, want)
	}

	got, ok = parseLine(t, `{"type":"context","used":null,"limit":null,"percent":null,"max":null,"threshold":null,"autoCompact":false,"messages":null,"breakdown":[]}`).(*Context)
	if !ok || len(got.Breakdown) != 0 {
		t.Errorf("Context without a breakdown = %+v, want no categories", got)
	}
}

func TestParseEffortReadsTheLevelNamesAndTheNoteOfTheBackend(t *testing.T) {
	got, ok := parseLine(t, `{"type":"effort","level":"medium","levels":["none","minimal","low","medium"],"note":"reasoning budget per level"}`).(*Effort)
	if !ok {
		t.Fatal("Parse did not return *Effort")
	}
	want := &Effort{Type: TypeEffort, Level: "medium", Levels: []EffortLevel{{Name: "none"}, {Name: "minimal"}, {Name: "low"}, {Name: "medium"}}, Note: "reasoning budget per level"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("Effort = %+v, want %+v", got, want)
	}
}

func TestParseEffortReadsTheModelAndTheLevelsItTakes(t *testing.T) {
	got, ok := parseLine(t, `{"type":"effort","profile":"claude","model":"claude-haiku-5-5","level":"medium","levels":["none","minimal"],"accepted":["none","low"],"note":"x"}`).(*Effort)
	if !ok || got.Profile != "claude" || got.Model != "claude-haiku-5-5" || !reflect.DeepEqual(got.Accepted, []string{"none", "low"}) {
		t.Errorf("Effort = %+v, want the profile, the model and the levels the model takes", got)
	}
	got, ok = parseLine(t, `{"type":"effort","level":"low","levels":[],"accepted":null,"note":""}`).(*Effort)
	if !ok || got.Accepted != nil {
		t.Errorf("Effort with accepted null = %+v, want no accepted levels: they are not known", got)
	}
}

func TestCanonicalLevelGivesTheLevelThatAnAliasStandsFor(t *testing.T) {
	for name, want := range map[string]string{"off": "none", "on": "medium", "none": "none", "medium": "medium", "xhigh": "xhigh", "": ""} {
		if got := CanonicalLevel(name); got != want {
			t.Errorf("CanonicalLevel(%q) = %q, want %q", name, got, want)
		}
	}
}

func TestParseProgress(t *testing.T) {
	got, ok := parseLine(t, `{"type":"progress","id":"r1","step":"search","detail":"3 queries","done":false}`).(*Progress)
	if !ok {
		t.Fatal("Parse did not return *Progress")
	}
	want := &Progress{Type: TypeProgress, ID: "r1", Step: "search", Detail: "3 queries"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("Progress = %+v, want %+v", got, want)
	}
	if done, ok := parseLine(t, `{"type":"progress","id":"r1","step":"answer","detail":"","done":true}`).(*Progress); !ok || !done.Done {
		t.Errorf("the last step is not done: %+v", done)
	}
}

func TestParseExecKeepsItsProgramAndConfirm(t *testing.T) {
	got, ok := parseLine(t, `{"type":"exec","title":"gentle-ai","argv":["gentle-ai","tui"],"confirm":true}`).(*Exec)
	if !ok {
		t.Fatal("Parse did not return *Exec")
	}
	want := &Exec{Type: TypeExec, Title: "gentle-ai", Argv: []string{"gentle-ai", "tui"}, Confirm: true}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("Exec = %+v, want %+v", got, want)
	}
}

func TestParseModelsKeepsThePresetsWithoutKeys(t *testing.T) {
	got, ok := parseLine(t, `{"type":"models","entries":[],"presets":[
		{"name":"openai","protocol":"openai","baseUrl":"https://api.openai.com/v1","apiKeyEnv":"OPENAI_API_KEY"},
		{"name":"ollama-cloud","protocol":"ollama","baseUrl":"https://ollama.com","apiKeyEnv":null}
	]}`).(*Models)
	if !ok {
		t.Fatal("Parse did not return *Models")
	}
	env := "OPENAI_API_KEY"
	want := []Preset{
		{Name: "openai", Protocol: "openai", BaseURL: "https://api.openai.com/v1", APIKeyEnv: &env},
		{Name: "ollama-cloud", Protocol: "ollama", BaseURL: "https://ollama.com"},
	}
	if !reflect.DeepEqual(got.Presets, want) {
		t.Errorf("Presets = %+v, want %+v", got.Presets, want)
	}
}

func TestParsePanelItemKeepsItsKey(t *testing.T) {
	got, ok := parseLine(t, `{"type":"panel","title":"x","kind":"list","items":[{"category":"","title":"t","key":"ui.theme"}]}`).(*Panel)
	if !ok || len(got.Items) != 1 || got.Items[0].Key != "ui.theme" {
		t.Errorf("Panel items = %+v, want the key kept", got)
	}
}

func TestCommandsEncodeAsAnnexC(t *testing.T) {
	answer, limit := "a.go", 4096
	tests := []struct {
		name string
		cmd  any
		want string
	}{
		{"ask_reply with an answer", NewAskReply("q1", &answer), `{"type":"ask_reply","id":"q1","answer":"a.go"}` + "\n"},
		{"ask_reply skipped", NewAskReply("q1", nil), `{"type":"ask_reply","id":"q1","answer":null}` + "\n"},
		{"config_set with a bool", NewConfigSet("tools.web", false), `{"type":"config_set","key":"tools.web","value":false}` + "\n"},
		{"config_set with a choice", NewConfigSet("ui.theme", "paper"), `{"type":"config_set","key":"ui.theme","value":"paper"}` + "\n"},
		{"session_list", NewSessionList(), `{"type":"session_list"}` + "\n"},
		{"session_switch", NewSessionSwitch("s1"), `{"type":"session_switch","id":"s1"}` + "\n"},
		{"session_new", NewSessionNew(), `{"type":"session_new"}` + "\n"},
		{"set_effort", NewSetEffort("high"), `{"type":"set_effort","level":"high"}` + "\n"},
		{"set_context with a max", NewSetContext(&limit), `{"type":"set_context","max":4096}` + "\n"},
		{"set_context goes back to the effective window", NewSetContext(nil), `{"type":"set_context","max":null}` + "\n"},
		{"compact without instructions", NewCompact(""), `{"type":"compact","instructions":null}` + "\n"},
		{"compact with instructions", NewCompact("keep the file names"), `{"type":"compact","instructions":"keep the file names"}` + "\n"},
		{"btw", NewBtw("what is a lexer?"), `{"type":"btw","question":"what is a lexer?"}` + "\n"},
		{"deepresearch", NewDeepResearch("state of WebGPU", 3), `{"type":"deepresearch","question":"state of WebGPU","rounds":3}` + "\n"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := Encode(tt.cmd)
			if err != nil {
				t.Fatalf("Encode: %v", err)
			}
			if string(got) != tt.want {
				t.Errorf("Encode = %q, want %q", got, tt.want)
			}
		})
	}
}

func TestAddProviderSendsTheKeyOnlyWhenGiven(t *testing.T) {
	without, err := Encode(NewAddProvider("cloud", "openai", "https://api.example.com", "", "").WithAPIKey(""))
	if err != nil {
		t.Fatal(err)
	}
	if want := `{"type":"add_provider","name":"cloud","protocol":"openai","base_url":"https://api.example.com","api_key_env":null,"model":null}` + "\n"; string(without) != want {
		t.Errorf("without a key = %q, want %q", without, want)
	}

	with, err := Encode(NewAddProvider("cloud", "openai", "https://api.example.com", "", "").WithAPIKey("sk-test"))
	if err != nil {
		t.Fatal(err)
	}
	if want := `{"type":"add_provider","name":"cloud","protocol":"openai","base_url":"https://api.example.com","api_key_env":null,"model":null,"api_key":"sk-test"}` + "\n"; string(with) != want {
		t.Errorf("with a key = %q, want %q", with, want)
	}
}

func TestProtocolVersionIsTwo(t *testing.T) {
	if Version != 2 {
		t.Errorf("Version = %d, want 2", Version)
	}
}
