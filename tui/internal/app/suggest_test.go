package app

import (
	"reflect"
	"strings"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func cmd(insert, label, description, group string, match ...int) state.Suggestion {
	return state.Suggestion{Insert: insert, Label: label, Description: description, Kind: state.SuggestCommand, Group: group, Match: match}
}

var agentSuggestions = []state.Suggestion{
	{Insert: "/agent build", Label: "build", Description: "writes code", Kind: state.SuggestAgent},
	{Insert: "/agent plan", Label: "plan", Description: "plans the work", Kind: state.SuggestAgent},
}

func uiDescription(word string) string {
	for _, c := range state.LocalCommands {
		if w, _, _ := strings.Cut(c.Name, " "); w == word {
			return c.Description
		}
	}
	return ""
}

func subagent(name, description string, match ...int) state.Suggestion {
	return state.Suggestion{Insert: "@" + name + " ", Label: "@" + name, Description: description, Kind: state.SuggestSubagent, Match: match}
}

func TestSuggestionsForTheInput(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  []state.Suggestion
	}{
		{
			name:  "slash lists every command in the order of the catalogue, not alphabetically",
			input: "/",
			want: []state.Suggestion{
				cmd("/connect", "/connect", uiDescription("/connect"), state.GroupModel),
				cmd("/clear", "/clear", "start a new conversation", state.GroupSession),
				cmd("/copy", "/copy", uiDescription("/copy"), state.GroupSession),
				cmd("/export ", "/export [PATH]", uiDescription("/export"), state.GroupSession),
				cmd("/agent ", "/agent NAME", "switch the primary agent", state.GroupAgents),
				cmd("/agents", "/agents", "list the agents", state.GroupAgents),
				cmd("/tasks", "/tasks", uiDescription("/tasks"), state.GroupAgents),
				cmd("/edit ", "/edit PATH", uiDescription("/edit"), state.GroupProject),
				cmd("/web", "/web", uiDescription("/web"), state.GroupExtensions),
				cmd("/theme ", "/theme [NAME]", uiDescription("/theme"), state.GroupHelp),
				cmd("/details", "/details", uiDescription("/details"), state.GroupHelp),
				cmd("/help", "/help", "show the commands", state.GroupHelp),
				cmd("/exit", "/exit", "leave", state.GroupHelp),
			},
		},
		{
			name:  "a command with arguments is inserted up to its first word",
			input: "/ag",
			want: []state.Suggestion{
				cmd("/agent ", "/agent NAME", "switch the primary agent", state.GroupAgents, 1, 2),
				cmd("/agents", "/agents", "list the agents", state.GroupAgents, 1, 2),
			},
		},
		{
			name:  "command names match in any case",
			input: "/AG",
			want: []state.Suggestion{
				cmd("/agent ", "/agent NAME", "switch the primary agent", state.GroupAgents, 1, 2),
				cmd("/agents", "/agents", "list the agents", state.GroupAgents, 1, 2),
			},
		},
		{
			name:  "the exact word comes before the commands that only start with it",
			input: "/agent",
			want: []state.Suggestion{
				cmd("/agent ", "/agent NAME", "switch the primary agent", state.GroupAgents, 1, 2, 3, 4, 5),
				cmd("/agents", "/agents", "list the agents", state.GroupAgents, 1, 2, 3, 4, 5),
			},
		},
		{
			name:  "letters spread over a name still match, and are marked where they fall",
			input: "/cr",
			want:  []state.Suggestion{cmd("/clear", "/clear", "start a new conversation", state.GroupSession, 1, 5)},
		},
		{
			name:  "a word of four letters or more also finds what a command does",
			input: "/switch",
			want:  []state.Suggestion{cmd("/agent ", "/agent NAME", "switch the primary agent", state.GroupAgents)},
		},
		{
			name:  "a short word does not search the descriptions",
			input: "/ow",
		},
		{
			name:  "an agent name after /agent",
			input: "/agent b",
			want:  []state.Suggestion{{Insert: "/agent build", Label: "build", Description: "writes code", Kind: state.SuggestAgent, Match: []int{0}}},
		},
		{
			name:  "only primary agents after /agent with no name typed",
			input: "/agent ",
			want:  agentSuggestions,
		},
		{
			name:  "a subagent is not offered after /agent",
			input: "/agent e",
		},
		{
			name:  "general is not offered after /agent",
			input: "/agent g",
		},
		{
			name:  "subagent-capable agents after @",
			input: "@",
			want:  []state.Suggestion{subagent("explore", "reads the code"), subagent("general", "does anything")},
		},
		{
			name:  "an @ name filters the subagents and marks the letters",
			input: "@ex",
			want:  []state.Suggestion{subagent("explore", "reads the code", 1, 2)},
		},
		{
			name:  "a primary agent is not offered after @",
			input: "@pla",
		},
		{
			name:  "no list once an @ task has started",
			input: "@explore find the parser",
		},
		{
			name:  "plain text gets no list",
			input: "hola",
		},
		{
			name:  "no command matches",
			input: "/zz",
		},
		{
			name:  "a newline hides the list",
			input: "/\n",
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			m := testModel()
			m.Input = tt.input
			if got := suggest(m); !reflect.DeepEqual(got, tt.want) {
				t.Errorf("suggest(%q) = %+v, want %+v", tt.input, got, tt.want)
			}
		})
	}
}

func TestTheCatalogueOrdersTheMenuByUseNotByTheBackendsOrder(t *testing.T) {
	m := testModel()
	m.Commands = []protocol.Command{{Name: "/exit"}, {Name: "/zeta"}, {Name: "/diff"}, {Name: "/models"}, {Name: "/help"}}
	m.Input = "/"
	var labels []string
	for _, s := range suggest(m) {
		labels = append(labels, s.Label)
	}
	want := []string{"/models", "/connect", "/copy", "/export [PATH]", "/diff", "/tasks", "/edit PATH", "/web", "/theme [NAME]", "/details", "/help", "/exit", "/zeta"}
	if !reflect.DeepEqual(labels, want) {
		t.Errorf("labels = %v, want %v: built-in commands by use, then the custom ones", labels, want)
	}
}

func TestTheWordsOfAnArgumentAreOfferedAfterTheCommand(t *testing.T) {
	m := testModel()
	m.Commands = append(m.Commands, protocol.Command{Name: "/persona [gentleman|neutral|off]", Description: "set the persona"})
	m.Input = "/persona "
	var got []string
	for _, s := range suggest(m) {
		got = append(got, s.Insert)
		if s.Kind != state.SuggestChoice {
			t.Errorf("suggestion %+v is not a choice", s)
		}
	}
	if want := []string{"/persona gentleman", "/persona neutral", "/persona off"}; !reflect.DeepEqual(got, want) {
		t.Errorf("choices = %v, want %v", got, want)
	}
	m.Input = "/persona ne"
	if got := suggest(m); len(got) == 0 || got[0].Insert != "/persona neutral" || !reflect.DeepEqual(got[0].Match, []int{0, 1}) {
		t.Errorf("suggest(%q) = %+v, want neutral first with ne marked", m.Input, got)
	}
	m.Input = "/persona neutral x"
	if got := suggest(m); got != nil {
		t.Errorf("suggest(%q) = %+v, want none past the argument", m.Input, got)
	}
}

func TestEffortOffersTheLevelsTheBackendNamedOrAllOfThem(t *testing.T) {
	m := testModel()
	m.Commands = append(m.Commands, protocol.Command{Name: "/effort LEVEL", Description: "set the effort"})
	m.Input = "/effort "
	if got := suggest(m); len(got) != len(fallbackLevels) || got[0].Insert != "/effort none" || got[6].Insert != "/effort max" {
		t.Errorf("suggest(%q) = %+v, want every level", m.Input, got)
	}
	m.Effort.Levels = []protocol.EffortLevel{{Name: "none"}, {Name: "low"}, {Name: "high"}}
	if got := suggest(m); len(got) != 3 || got[1].Insert != "/effort low" {
		t.Errorf("suggest(%q) = %+v, want the three levels the model offers", m.Input, got)
	}
}

func TestAgentChoiceOffersAgentsThatWorkAsPrimary(t *testing.T) {
	m := testModel()
	m.Agents = []protocol.Agent{
		{Name: "builder", Mode: "primary", Description: "a longer name"},
		{Name: "build", Mode: "all", Description: "the short name"},
		{Name: "explore", Mode: "subagent", Description: "reads the code"},
	}
	m.Input = "/agent build"
	want := []state.Suggestion{
		{Insert: "/agent build", Label: "build", Description: "the short name", Kind: state.SuggestAgent, Match: []int{0, 1, 2, 3, 4}},
		{Insert: "/agent builder", Label: "builder", Description: "a longer name", Kind: state.SuggestAgent, Match: []int{0, 1, 2, 3, 4}},
	}
	if got := suggest(m); !reflect.DeepEqual(got, want) {
		t.Errorf("suggest(%q) = %+v, want %+v", m.Input, got, want)
	}
}

func TestTabAcceptsTheSelectedSuggestion(t *testing.T) {
	m, cmds := feed(testModel(), typed("/he"), press("tab"))
	if m.Input != "/help" || m.Cursor != len("/help") {
		t.Errorf("Input = %q, Cursor = %d; want \"/help\" with the cursor at its end", m.Input, m.Cursor)
	}
	if len(cmds) != 0 || m.Busy {
		t.Errorf("commands = %v, Busy = %v; want nothing sent", cmds, m.Busy)
	}
}

func TestEnterSubmitsACompletedCommand(t *testing.T) {
	m, cmds := feed(testModel(), typed("/cle"), press("enter"))
	if want := []any{protocol.NewInput("/clear")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Input != "" || m.Busy {
		t.Errorf("Input = %q, Busy = %v; want an empty input and no turn", m.Input, m.Busy)
	}
}

func TestEnterAcceptsACommandWithArgumentsWithoutSubmitting(t *testing.T) {
	m, cmds := feed(testModel(), typed("/ag"), press("enter"))
	if len(cmds) != 0 || m.Busy || len(m.Blocks) != 0 {
		t.Errorf("commands = %v, Busy = %v, Blocks = %+v; want nothing submitted", cmds, m.Busy, m.Blocks)
	}
	if m.Input != "/agent " {
		t.Errorf("Input = %q, want \"/agent \"", m.Input)
	}
	if !reflect.DeepEqual(m.Suggestions, agentSuggestions) {
		t.Errorf("Suggestions = %+v, want the agents to follow", m.Suggestions)
	}
}

func TestEnterSubmitsAnAgentChoice(t *testing.T) {
	m, cmds := feed(testModel(), typed("/agent b"), press("enter"))
	if want := []any{protocol.NewInput("/agent build")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Input != "" {
		t.Errorf("Input = %q, want it cleared", m.Input)
	}
}

func TestEnterAcceptsASubagentAndTheTaskFollows(t *testing.T) {
	m, cmds := feed(testModel(), typed("@ex"), press("enter"))
	if m.Input != "@explore " || len(cmds) != 0 || m.Busy {
		t.Fatalf("Input = %q, commands = %v, Busy = %v; want \"@explore \" and nothing sent", m.Input, cmds, m.Busy)
	}
	m, cmds = feed(m, typed("find the parser"), press("enter"))
	if want := []any{protocol.NewInput("@explore find the parser")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Busy {
		t.Error("Busy = false, want a running turn")
	}
}

func TestEscClearsTheList(t *testing.T) {
	m, _ := feed(testModel(), typed("/"))
	if len(m.Suggestions) == 0 {
		t.Fatal("no suggestions for \"/\"")
	}
	m, cmds := feed(m, press("esc"))
	if m.Suggestions != nil || m.Selected != 0 || m.Input != "/" || len(cmds) != 0 {
		t.Errorf("Suggestions = %v, Selected = %d, Input = %q, commands = %v; want the list cleared and the input kept",
			m.Suggestions, m.Selected, m.Input, cmds)
	}
}

func TestEditingRecomputesTheList(t *testing.T) {
	m, _ := feed(testModel(), typed("/"), press("down"), typed("h"))
	if m.Input != "/h" || m.Selected != 0 {
		t.Errorf("Input = %q, Selected = %d; want \"/h\" and the first suggestion selected", m.Input, m.Selected)
	}
	if want := []state.Suggestion{cmd("/help", "/help", "show the commands", state.GroupHelp, 1), cmd("/theme ", "/theme [NAME]", uiDescription("/theme"), state.GroupHelp, 2)}; !reflect.DeepEqual(m.Suggestions, want) {
		t.Errorf("Suggestions = %+v, want %+v", m.Suggestions, want)
	}
	m, _ = feed(m, press("backspace"), press("backspace"))
	if m.Suggestions != nil || m.Input != "" {
		t.Errorf("after deleting the input: Suggestions = %v, Input = %q; want none", m.Suggestions, m.Input)
	}
}

func TestEnterOnABareCommandWithChoicesRunsItAsItIs(t *testing.T) {
	m := testModel()
	m.Commands = append(m.Commands, protocol.Command{Name: "/persona [gentleman|neutral|off]", Description: "set the persona"})
	m, cmds := feed(m, typed("/persona "), press("enter"))
	if want := []any{protocol.NewInput("/persona")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the first word is not chosen for the user", cmds, want)
	}
	if m.Input != "" {
		t.Errorf("Input = %q, want it cleared", m.Input)
	}
}

func TestEnterOnAPartialChoiceCompletesAndRunsIt(t *testing.T) {
	m := testModel()
	m.Commands = append(m.Commands, protocol.Command{Name: "/persona [gentleman|neutral|off]", Description: "set the persona"})
	_, cmds := feed(m, typed("/persona ne"), press("enter"))
	if want := []any{protocol.NewInput("/persona neutral")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}
