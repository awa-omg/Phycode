package view

import (
	"strings"
	"testing"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func menuSuggestions() []state.Suggestion {
	cmd := func(insert, label, description string, match ...int) state.Suggestion {
		return state.Suggestion{Insert: insert, Label: label, Description: description, Kind: state.SuggestCommand, Match: match}
	}
	return []state.Suggestion{
		cmd("/models", "/models", "list models and switch the model for this session"),
		cmd("/effort ", "/effort LEVEL", "show or set the thinking effort of the next turn: none, minimal, low, medium, high, xhigh or max"),
		cmd("/context", "/context", "show the context: the tokens used against the window"),
		cmd("/compact ", "/compact [INSTRUCTIONS]", "summarise the older turns with the model"),
		cmd("/config", "/config", "show and change the settings"),
		cmd("/sessions ", "/sessions [switch ID|new]", "list the saved sessions, switch to one, or start a new one"),
		cmd("/clear", "/clear", "start a new conversation"),
		cmd("/btw ", "/btw QUESTION", "ask a side question about the conversation"),
		cmd("/handoff", "/handoff", "write a handoff of this session"),
		cmd("/status", "/status", "show the version, profile, model"),
	}
}

func menuModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Input, m.Cursor = "/", 1
	m.Suggestions = menuSuggestions()
	return m
}

func TestTheMenuIsABlockWithTheRailJustAboveTheInputBand(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, menuModel(100, 24))
	title := rowOf(rows, "commands  1 of 10")
	if title < 0 || !strings.HasPrefix(plain(rows[title]), " ▌") {
		t.Fatalf("the menu has no heading with its title and the rail: %q", plainRows(rows))
	}
	last := len(rows) - 1 - 3 - 1
	if !strings.HasPrefix(plain(rows[last]), " ▌ ") || !strings.Contains(plain(rows[last+1]), "▌") {
		t.Fatalf("the last row of the menu is not directly above the input band: %q", plainRows(rows))
	}
	if got := last - title; got != maxSuggestions {
		t.Errorf("the menu shows %d rows under its heading, want %d", got, maxSuggestions)
	}
	if strings.ContainsAny(strings.Join(plainRows(rows), ""), "╭╮╰╯") {
		t.Errorf("the menu draws a border: %q", plainRows(rows))
	}
}

func TestTheMenuDoesNotMoveTheTranscript(t *testing.T) {
	r := NewRenderer(theme.Default())
	closed := newModel(100, 24)
	closed.Blocks = []state.Block{{Kind: state.KindUser, Text: "first"}, {Kind: state.KindAssistant, Text: "second"}}
	open := closed
	open.Input, open.Cursor, open.Suggestions = "/", 1, menuSuggestions()
	a, b := screen(t, r, closed), screen(t, r, open)
	if rowOf(a, "second") != rowOf(b, "second") && rowOf(b, "second") >= 0 {
		t.Errorf("the transcript moved from row %d to row %d when the menu opened", rowOf(a, "second"), rowOf(b, "second"))
	}
	for i := 0; i < 5; i++ {
		if plain(a[i]) != plain(b[i]) {
			t.Errorf("row %d changed when the menu opened: %q -> %q", i, plain(a[i]), plain(b[i]))
		}
	}
}

func TestTheMenuMarksTheHighlightedRowWithTheBar(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	m := menuModel(100, 24)
	m.Selected = 1
	rows := screen(t, r, m)
	i := rowOf(rows, "/effort")
	if !onBar(plain(rows[i])) {
		t.Errorf("the highlighted row %q has no mark", plain(rows[i]))
	}
	if !wearsBar(rows[i]) {
		t.Errorf("the highlighted row is not on the highlight background: %q", rows[i])
	}
	for _, other := range []string{"/models", "/context"} {
		j := rowOf(rows, other)
		if onBar(plain(rows[j])) {
			t.Errorf("row %q is marked but is not highlighted", plain(rows[j]))
		}
		if wearsBar(rows[j]) {
			t.Errorf("row %q is on the highlight background but is not highlighted", plain(rows[j]))
		}
	}
}

func TestTheMenuDescriptionsStartAtOneColumn(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, menuModel(100, 24)))
	want := cellAt(rows[rowOf(rows, "/models")], "list models")
	for _, pair := range [][2]string{{"/effort", "show or set"}, {"/compact", "summarise"}, {"/sessions", "list the saved"}} {
		if got := cellAt(rows[rowOf(rows, pair[0])], pair[1]); got != want {
			t.Errorf("the description of %s starts at cell %d, want %d, where the others start", pair[0], got, want)
		}
	}
}

func TestTheMenuShowsArgumentsAndCutsLongDescriptions(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, menuModel(80, 24)))
	if row := rowWith(rows, "/effort"); !strings.Contains(row, "/effort LEVEL") || !strings.Contains(row, "…") {
		t.Errorf("effort row = %q, want its argument and a description cut with an ellipsis", row)
	}
}

func TestTheMenuMarksTheLettersTheFilterMatched(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := menuModel(100, 24)
	m.Input, m.Cursor = "/mod", 4
	m.Suggestions = []state.Suggestion{{Insert: "/models", Label: "/models", Description: "x", Kind: state.SuggestCommand, Match: []int{1, 2, 3}}}
	m.Selected = 0
	rows := screen(t, r, m)
	if row := rows[rowOf(rows, "/models")]; strings.Count(row, seqOf(theme.Default().Match.Background(theme.Default().Bar.GetBackground()))) < len("mod") {
		t.Errorf("row = %q, want the matched letters drawn as a match on the bar", row)
	}
}

func TestTheMenuScrollsWithTheHighlightAndSaysWhereItIs(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := menuModel(100, 24)
	m.Selected = 9
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "10 of 10") < 0 {
		t.Errorf("the heading does not say where the highlight is: %q", rows)
	}
	if rowOf(rows, "/status") < 0 || rowOf(rows, "/models") >= 0 {
		t.Errorf("the menu does not follow the highlight: %q", rows)
	}
	if row := highlightRow(rows); !strings.Contains(row, "/status") {
		t.Errorf("highlighted row = %q, want /status", row)
	}
}

func TestTheMenuTitleNamesWhatItLists(t *testing.T) {
	r := NewRenderer(theme.Default())
	for kind, want := range map[string]string{
		state.SuggestCommand: "commands", state.SuggestAgent: "agents", state.SuggestSubagent: "subagents", state.SuggestChoice: "options",
	} {
		m := menuModel(100, 24)
		m.Suggestions = []state.Suggestion{{Insert: "x", Label: "x", Kind: kind}}
		if rowOf(plainRows(screen(t, r, m)), want+"  1 of 1") < 0 {
			t.Errorf("the menu of kind %s is not titled %q", kind, want)
		}
	}
}

func TestTheMenuSaysWhenNoCommandMatches(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Input, m.Cursor, m.NoMatch = "/zzz", 4, "/zzz"
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "no command matches “/zzz”") < 0 {
		t.Errorf("the empty menu says nothing: %q", rows)
	}
	m.NoMatch = ""
	if rowOf(plainRows(screen(t, r, m)), "no command matches") >= 0 {
		t.Error("the note stays after the match is gone")
	}
}

func TestTheMenuHeadingCarriesTheKeysAndShrinksThemOnANarrowScreen(t *testing.T) {
	r := NewRenderer(theme.Default())
	wide := plainRows(screen(t, r, menuModel(100, 24)))
	if rowOf(wide, "enter run") < 0 {
		t.Errorf("the wide menu does not name enter: %q", wide)
	}
	narrow := plainRows(screen(t, r, menuModel(40, 24)))
	if rowOf(narrow, "enter run") >= 0 || rowOf(narrow, "tab complete") < 0 {
		t.Errorf("the narrow menu keeps the long set of keys or loses them all: %q", narrow)
	}
	keyed := screen(t, r, menuModel(100, 24))
	row := keyed[rowOf(keyed, "tab complete")]
	if !strings.Contains(row, theme.Default().Key.Render("tab")) {
		t.Errorf("the keys of the heading are not drawn as keys: %q", row)
	}
}

func TestTheMenuFitsEveryScreenSize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{1, 8, 15, 16, 20, 30, 60, 120} {
		for _, h := range []int{1, 3, 5, 8, 12, 24} {
			m := menuModel(w, h)
			m.Selected = 9
			screen(t, r, m)
			m.Suggestions = nil
			m.NoMatch = "/zz"
			screen(t, r, m)
		}
	}
}

func TestTheMenuYieldsToAnApproval(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := approvalOver(menuModel(100, 30))
	if rowOf(plainRows(screen(t, r, m)), "commands  1 of 10") >= 0 {
		t.Error("the menu is drawn over the approval prompt")
	}
}

func TestTheMenuNeverReachesTheHeaderOrTheInput(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, menuModel(100, 12))
	if !strings.Contains(plain(rows[0]), "phycode") {
		t.Errorf("the header is covered: %q", plain(rows[0]))
	}
	if !strings.Contains(plain(rows[len(rows)-1]), "ready") {
		t.Errorf("the status line is covered: %q", plain(rows[len(rows)-1]))
	}
	if rowOf(rows, "❯ /") < 0 {
		t.Errorf("the input box is covered: %q", plainRows(rows))
	}
}

func TestAVeryLongUsageIsCutSoThatTheOtherDescriptionsKeepTheirRoom(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := menuModel(100, 24)
	m.Suggestions = append([]state.Suggestion{{
		Insert: "/context ", Label: "/context max N|Nk|off [--save]", Description: "set the window", Kind: state.SuggestCommand,
	}}, menuSuggestions()[:2]...)
	rows := plainRows(screen(t, r, m))
	if got := cellAt(rows[rowOf(rows, "/models")], "list models"); got > maxMenuName+8 {
		t.Errorf("the descriptions start at cell %d, want them no further than the name column allows", got)
	}
	if row := rowWith(rows, "/context max"); !strings.Contains(row, "…") {
		t.Errorf("row = %q, want the long usage cut with an ellipsis", row)
	}
}
