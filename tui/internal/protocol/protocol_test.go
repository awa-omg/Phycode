package protocol

import (
	"reflect"
	"testing"
)

func parseLine(t *testing.T, line string) any {
	t.Helper()
	ev, err := Parse([]byte(line))
	if err != nil {
		t.Fatalf("Parse(%s): %v", line, err)
	}
	return ev
}

func TestParseModelsKeepsValuesAndNulls(t *testing.T) {
	ev := parseLine(t, `{"type":"models",
		"entries":[
			{"profile":"local","model":"qwen","protocol":"openai","contextWindow":32768,"tools":true,"thinking":["off","on"],"loaded":true,"error":null},
			{"profile":"cloud","model":"","protocol":"openai","contextWindow":null,"tools":null,"thinking":null,"loaded":null,"error":"the server lists no models"}
		],
		"current":{"profile":"local","model":"qwen","thinking":"off"},
		"favorites":[{"profile":"local","model":"qwen"}],
		"recents":[{"profile":"cloud","model":"gpt"}]}`)
	got, ok := ev.(*Models)
	if !ok {
		t.Fatalf("Parse returned %T, want *Models", ev)
	}
	ctx, tools, loaded := 32768, true, true
	noModels := "the server lists no models"
	want := &Models{
		Type: TypeModels,
		Entries: []ModelEntry{
			{Profile: "local", Model: "qwen", Protocol: "openai", ContextWindow: &ctx, Tools: &tools, Thinking: []string{"off", "on"}, Loaded: &loaded},
			{Profile: "cloud", Protocol: "openai", Error: &noModels},
		},
		Current:   ModelRef{Profile: "local", Model: "qwen", Thinking: "off"},
		Favorites: []ModelRef{{Profile: "local", Model: "qwen"}},
		Recents:   []ModelRef{{Profile: "cloud", Model: "gpt"}},
	}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("Models = %+v, want %+v", got, want)
	}
	if got.Entries[1].ContextWindow != nil || got.Entries[1].Tools != nil || got.Entries[1].Loaded != nil {
		t.Error("null fields decoded to non-nil pointers")
	}
}

func TestParseModelsKeepsThinkingDefault(t *testing.T) {
	ev := parseLine(t, `{"type":"models",
		"entries":[
			{"profile":"local","model":"qwen","protocol":"openai","thinking":["off","on"],"thinkingDefault":"on"},
			{"profile":"cloud","model":"","protocol":"openai","thinking":null,"thinkingDefault":null,"error":"the server lists no models"},
			{"profile":"local","model":"llama","protocol":"openai","thinking":[]}
		]}`)
	got, ok := ev.(*Models)
	if !ok {
		t.Fatalf("Parse returned %T, want *Models", ev)
	}
	if d := got.Entries[0].ThinkingDefault; d == nil || *d != "on" {
		t.Errorf("ThinkingDefault = %v, want on", d)
	}
	if got.Entries[1].ThinkingDefault != nil || got.Entries[2].ThinkingDefault != nil {
		t.Error("a null or absent thinkingDefault decoded to a non-nil pointer")
	}
}

func TestParseModelsKeepsProvidersAndCustomIds(t *testing.T) {
	ev := parseLine(t, `{"type":"models",
		"entries":[
			{"profile":"local","model":"my-model","protocol":"openai","custom":true},
			{"profile":"local","model":"qwen","protocol":"openai","custom":false}
		],
		"providers":[
			{"profile":"local","protocol":"openai","endpoint":"http://127.0.0.1:9931","count":3,"error":null,"updatedAt":"2026-10-19T12:00:00+00:00","refreshing":false},
			{"profile":"cloud","protocol":"anthropic","endpoint":"https://api.anthropic.com","count":0,"error":"protocol anthropic is not available yet","updatedAt":null,"refreshing":true}
		]}`)
	got, ok := ev.(*Models)
	if !ok {
		t.Fatalf("Parse returned %T, want *Models", ev)
	}
	if !got.Entries[0].Custom || got.Entries[1].Custom {
		t.Errorf("Custom = %v, %v; want true and false", got.Entries[0].Custom, got.Entries[1].Custom)
	}
	updated := "2026-10-19T12:00:00+00:00"
	problem := "protocol anthropic is not available yet"
	want := []Provider{
		{Profile: "local", Protocol: "openai", Endpoint: "http://127.0.0.1:9931", Count: 3, UpdatedAt: &updated},
		{Profile: "cloud", Protocol: "anthropic", Endpoint: "https://api.anthropic.com", Error: &problem, Refreshing: true},
	}
	if !reflect.DeepEqual(got.Providers, want) {
		t.Errorf("Providers = %+v, want %+v", got.Providers, want)
	}
}

func TestParseModelChanged(t *testing.T) {
	got, ok := parseLine(t, `{"type":"model_changed","profile":"local","model":"qwen","thinking":"high","saved":true}`).(*ModelChanged)
	if !ok {
		t.Fatal("Parse did not return *ModelChanged")
	}
	want := ModelChanged{Type: TypeModelChanged, Profile: "local", Model: "qwen", Thinking: "high", Saved: true}
	if *got != want {
		t.Errorf("ModelChanged = %+v, want %+v", *got, want)
	}

	got, ok = parseLine(t, `{"type":"model_changed","profile":"cloud","model":"gpt","thinking":null,"saved":false}`).(*ModelChanged)
	if !ok || got.Thinking != "" || got.Saved {
		t.Errorf("ModelChanged with a null thinking = %+v, want an empty level and saved false", got)
	}
}

func TestParseTurnEndUsageAndBranch(t *testing.T) {
	got, ok := parseLine(t, `{"type":"turn_end","status":"completed","reason":"","changes":[],
		"usage":{"promptTokens":812,"completionTokens":94,"tokensPerSecond":41.5,"contextWindow":32768},
		"branch":"main"}`).(*TurnEnd)
	if !ok {
		t.Fatal("Parse did not return *TurnEnd")
	}
	prompt, completion, context := 812, 94, 32768
	rate := 41.5
	if got.Usage == nil || !reflect.DeepEqual(*got.Usage, Usage{PromptTokens: &prompt, CompletionTokens: &completion, TokensPerSecond: &rate, ContextWindow: &context}) {
		t.Errorf("Usage = %+v, want every field", got.Usage)
	}
	if got.Branch == nil || *got.Branch != "main" {
		t.Errorf("Branch = %v, want main", got.Branch)
	}

	got, ok = parseLine(t, `{"type":"turn_end","status":"error","reason":"model unreachable","changes":null,"usage":null,"branch":null}`).(*TurnEnd)
	if !ok || got.Usage != nil || got.Branch != nil {
		t.Errorf("TurnEnd with nulls = %+v, want nil usage and branch", got)
	}

	got, ok = parseLine(t, `{"type":"turn_end","status":"completed","usage":{"promptTokens":null,"completionTokens":5,"tokensPerSecond":null,"contextWindow":null}}`).(*TurnEnd)
	if !ok || got.Usage == nil || got.Usage.PromptTokens != nil || got.Usage.CompletionTokens == nil || *got.Usage.CompletionTokens != 5 {
		t.Errorf("TurnEnd with a partial usage = %+v, want only completionTokens set", got.Usage)
	}
}

func TestParseTurnEndReasonKeepsNullAndValue(t *testing.T) {
	got, ok := parseLine(t, `{"type":"turn_end","status":"completed","reason":null,"changes":[]}`).(*TurnEnd)
	if !ok || got.Reason != nil {
		t.Errorf("TurnEnd with a null reason = %+v, want a nil reason", got)
	}

	got, ok = parseLine(t, `{"type":"turn_end","status":"cancelled","reason":"interrupted by the user","changes":[]}`).(*TurnEnd)
	if !ok || got.Reason == nil || *got.Reason != "interrupted by the user" {
		t.Errorf("TurnEnd with a reason = %+v, want the reason kept", got)
	}
}

func TestParseReadyBranchAndThinking(t *testing.T) {
	got, ok := parseLine(t, `{"type":"ready","protocol":1,"app":{"version":"1","cwd":"/p","agent":"build","profile":"local","model":"qwen","toolMode":"full","sandbox":"host","permissionMode":"ask","transcript":"","git":true,"branch":"main","thinking":"off"},"agents":[],"commands":[]}`).(*Ready)
	if !ok {
		t.Fatal("Parse did not return *Ready")
	}
	if got.App.Branch == nil || *got.App.Branch != "main" || got.App.Thinking != "off" {
		t.Errorf("App branch = %v, thinking = %q; want main and off", got.App.Branch, got.App.Thinking)
	}

	got, ok = parseLine(t, `{"type":"ready","protocol":1,"app":{"branch":null,"thinking":""},"agents":[],"commands":[]}`).(*Ready)
	if !ok || got.App.Branch != nil {
		t.Errorf("App with a null branch = %+v, want a nil branch", got.App)
	}
}

func TestParseReadyKeepsTheTurnFlagOfACommand(t *testing.T) {
	got, ok := parseLine(t, `{"type":"ready","protocol":2,"app":{},"agents":[],"commands":[{"name":"/init","description":"write AGENTS.md","turn":true},{"name":"/help","description":"show the commands"}]}`).(*Ready)
	if !ok {
		t.Fatal("Parse did not return *Ready")
	}
	want := []Command{{Name: "/init", Description: "write AGENTS.md", Turn: true}, {Name: "/help", Description: "show the commands"}}
	if !reflect.DeepEqual(got.Commands, want) {
		t.Errorf("Commands = %+v, want %+v", got.Commands, want)
	}
}

func TestCommandsEncodeAsAnnexB(t *testing.T) {
	tests := []struct {
		name string
		cmd  any
		want string
	}{
		{
			name: "list_models",
			cmd:  NewListModels(true),
			want: `{"type":"list_models","refresh":true}` + "\n",
		},
		{
			name: "set_model with a level",
			cmd:  NewSetModel("local", "qwen", "high", true),
			want: `{"type":"set_model","profile":"local","model":"qwen","thinking":"high","save":true}` + "\n",
		},
		{
			name: "set_model without a level leaves it out",
			cmd:  NewSetModel("cloud", "gpt", "", false),
			want: `{"type":"set_model","profile":"cloud","model":"gpt","save":false}` + "\n",
		},
		{
			name: "favorite",
			cmd:  NewFavorite("local", "qwen", false),
			want: `{"type":"favorite","profile":"local","model":"qwen","on":false}` + "\n",
		},
		{
			name: "custom_model add",
			cmd:  NewCustomModel("local", "qwen-x", CustomAdd),
			want: `{"type":"custom_model","profile":"local","model":"qwen-x","action":"add"}` + "\n",
		},
		{
			name: "custom_model remove",
			cmd:  NewCustomModel("local", "qwen-x", CustomRemove),
			want: `{"type":"custom_model","profile":"local","model":"qwen-x","action":"remove"}` + "\n",
		},
		{
			name: "add_provider with the optional fields left out",
			cmd:  NewAddProvider("home", "ollama", "http://127.0.0.1:11434", "", ""),
			want: `{"type":"add_provider","name":"home","protocol":"ollama","base_url":"http://127.0.0.1:11434","api_key_env":null,"model":null}` + "\n",
		},
		{
			name: "add_provider with the optional fields",
			cmd:  NewAddProvider("cloud", "anthropic", "https://api.anthropic.com", "ANTHROPIC_API_KEY", "claude-haiku-5-5"),
			want: `{"type":"add_provider","name":"cloud","protocol":"anthropic","base_url":"https://api.anthropic.com","api_key_env":"ANTHROPIC_API_KEY","model":"claude-haiku-5-5"}` + "\n",
		},
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

func intPtr(n int) *int { return &n }

func TestParseToolEndKeepsTheDiff(t *testing.T) {
	got, ok := parseLine(t, `{"type":"tool_end","id":"t1","ok":true,"summary":"edited a.go","diff":{
		"path":"a.go","added":1,"removed":1,"binary":false,
		"hunks":[{"oldStart":3,"oldLines":3,"newStart":3,"newLines":3,"lines":[
			{"kind":"ctx","text":"func a() {","oldNo":3,"newNo":3},
			{"kind":"del","text":"\treturn 1","oldNo":4,"newNo":null},
			{"kind":"add","text":"\treturn 2","oldNo":null,"newNo":4},
			{"kind":"ctx","text":"}","oldNo":5,"newNo":5}
		]}]}}`).(*ToolEnd)
	if !ok {
		t.Fatal("Parse did not return *ToolEnd")
	}
	want := Diff{Path: "a.go", Added: 1, Removed: 1, Hunks: []Hunk{{
		OldStart: 3, OldLines: 3, NewStart: 3, NewLines: 3,
		Lines: []DiffLine{
			{Kind: DiffCtx, Text: "func a() {", OldNo: intPtr(3), NewNo: intPtr(3)},
			{Kind: DiffDel, Text: "\treturn 1", OldNo: intPtr(4)},
			{Kind: DiffAdd, Text: "\treturn 2", NewNo: intPtr(4)},
			{Kind: DiffCtx, Text: "}", OldNo: intPtr(5), NewNo: intPtr(5)},
		},
	}}}
	if got.Diff == nil || !reflect.DeepEqual(*got.Diff, want) {
		t.Errorf("Diff = %+v, want %+v", got.Diff, want)
	}
}

func TestParseToolEndWithoutADiff(t *testing.T) {
	for _, line := range []string{
		`{"type":"tool_end","id":"t2","ok":false,"summary":"boom","diff":null}`,
		`{"type":"tool_end","id":"t3","ok":true,"summary":"read a.go"}`,
	} {
		got, ok := parseLine(t, line).(*ToolEnd)
		if !ok || got.Diff != nil {
			t.Errorf("Parse(%s) has a diff, want none", line)
		}
	}
}

func TestParseToolEndBinaryDiff(t *testing.T) {
	got, ok := parseLine(t, `{"type":"tool_end","id":"t4","ok":true,"summary":"wrote logo.png","diff":{"path":"logo.png","added":0,"removed":0,"binary":true,"hunks":[]}}`).(*ToolEnd)
	if !ok || got.Diff == nil || got.Diff.Path != "logo.png" || !got.Diff.Binary || len(got.Diff.Hunks) != 0 {
		t.Errorf("binary diff = %+v, want the path and binary set with no hunks", got)
	}
}

func TestParseConfirmKeepsThePreview(t *testing.T) {
	got, ok := parseLine(t, `{"type":"confirm","id":"c1","tool":"write_file","category":"write","agent":"build","depth":0,"summary":"edit a.go",
		"preview":{"path":"a.go","added":1,"removed":0,"binary":false,"hunks":[{"oldStart":1,"oldLines":0,"newStart":1,"newLines":1,"lines":[{"kind":"add","text":"x","oldNo":null,"newNo":1}]}]}}`).(*Confirm)
	if !ok {
		t.Fatal("Parse did not return *Confirm")
	}
	if got.Preview == nil || got.Preview.Path != "a.go" || got.Preview.Added != 1 || len(got.Preview.Hunks) != 1 {
		t.Fatalf("Preview = %+v, want the diff of a.go", got.Preview)
	}
	if line := got.Preview.Hunks[0].Lines[0]; line.Kind != DiffAdd || line.Text != "x" || line.OldNo != nil || line.NewNo == nil || *line.NewNo != 1 {
		t.Errorf("preview line = %+v, want an added line 1", line)
	}
}

func TestParseReadsTheParentOfASubagentEvent(t *testing.T) {
	got, ok := parseLine(t, `{"type":"tool_start","id":"t2","agent":"explore","depth":1,"tool":"grep","summary":"x","parent":"build"}`).(*ToolStart)
	if !ok || got.Parent != "build" {
		t.Errorf("the tool_start has no parent build: %+v", got)
	}
	if got := parseLine(t, `{"type":"notice","level":"info","message":"x"}`).(*Notice); got.Parent != "" {
		t.Errorf("a notice without a parent has Parent %q", got.Parent)
	}
}

func TestParseConfirmWithoutAPreview(t *testing.T) {
	for _, line := range []string{
		`{"type":"confirm","id":"c2","tool":"bash","category":"exec","agent":"build","summary":"ls","preview":null}`,
		`{"type":"confirm","id":"c3","tool":"bash","category":"exec","agent":"build","summary":"ls"}`,
	} {
		got, ok := parseLine(t, line).(*Confirm)
		if !ok || got.Preview != nil {
			t.Errorf("Parse(%s) has a preview, want none", line)
		}
	}
}
