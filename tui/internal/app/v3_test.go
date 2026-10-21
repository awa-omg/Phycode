package app

import (
	"errors"
	"reflect"
	"strings"
	"testing"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

var v3Commands = []protocol.Command{
	{Name: "/effort [level]", Description: "set the reasoning effort"},
	{Name: "/context [max N|off]", Description: "set the context window"},
	{Name: "/config", Description: "change the settings"},
	{Name: "/sessions", Description: "switch conversations"},
	{Name: "/btw <pregunta>", Description: "ask a side question"},
	{Name: "/deepresearch <pregunta>", Description: "research a question"},
}

func withV3Commands() state.Model {
	m := testModel()
	m.Commands = append(m.Commands, v3Commands...)
	return m
}

func configItems() []protocol.ConfigItem {
	return []protocol.ConfigItem{
		{Key: "tools.web", Label: "web search", Value: true, Kind: protocol.ConfigBool, Category: "Tools"},
		{Key: "ask.enabled", Label: "ask the user", Value: false, Kind: protocol.ConfigBool, Category: "Tools"},
		{Key: "ui.theme", Label: "theme", Value: "tokyo-night", Kind: protocol.ConfigChoice,
			Choices: []string{"tokyo-night", "solarized-dark", "paper", "mono"}, Category: "Interface"},
		{Key: "ui.editor", Label: "editor", Value: "nvim", Kind: protocol.ConfigText, Category: "Interface"},
	}
}

func configEvent(items ...protocol.ConfigItem) *protocol.Config {
	return &protocol.Config{Type: protocol.TypeConfig, Items: items}
}

func openedConfig(t *testing.T) state.Model {
	t.Helper()
	m, cmds := feed(withV3Commands(), typed("/config"), press("enter"), configEvent(configItems()...))
	if want := []any{protocol.NewInput("/config")}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if m.Panel.Kind != "config" || m.Panel.Loading || len(m.Panel.Items) != 4 {
		t.Fatalf("panel = %+v; want the config panel with four settings", m.Panel)
	}
	return m
}

func highlightedKey(m state.Model) string {
	if it, ok := m.Panel.Highlighted(); ok {
		return it.Key
	}
	return ""
}

func TestAReadyFromAnOlderBackendEndsTheSession(t *testing.T) {
	fb := newFakeBackend()
	next, cmd := New(fb).Update(eventMsg{value: &protocol.Ready{Type: protocol.TypeReady, Protocol: 1}})
	a := next.(App)
	if !a.state.Quit || a.state.Ready {
		t.Fatalf("Quit = %v, Ready = %v; want the session ended before it starts", a.state.Quit, a.state.Ready)
	}
	if want := "the backend speaks protocol 1, but this interface needs protocol 2: update phycode so that both match"; a.Failure() != want {
		t.Errorf("Failure = %q, want %q", a.Failure(), want)
	}
	if len(fb.sent) != 0 {
		t.Errorf("sent = %v, want nothing to the backend that cannot be driven", fb.sent)
	}
	if cmd == nil {
		t.Fatal("no command ends the program")
	}
	if _, ok := cmd().(tea.QuitMsg); !ok {
		t.Errorf("command returned %#v, want tea.QuitMsg", cmd())
	}
}

func TestReadyStoresThemeEditorAndCapabilities(t *testing.T) {
	editor := "nvim"
	m, cmds := feed(state.New(), &protocol.Ready{
		Type: protocol.TypeReady, Protocol: protocol.Version, Theme: "paper",
		Editor: &editor, Capabilities: []string{"panels", "btw"},
	})
	if len(cmds) != 0 || m.Quit || !m.Ready {
		t.Fatalf("commands = %v, Quit = %v, Ready = %v; want a running session", cmds, m.Quit, m.Ready)
	}
	if m.Theme != "paper" || m.Editor != "nvim" {
		t.Errorf("Theme = %q, Editor = %q; want paper and nvim", m.Theme, m.Editor)
	}
	if !reflect.DeepEqual(m.Capabilities, []string{"panels", "btw"}) {
		t.Errorf("Capabilities = %v", m.Capabilities)
	}
}

func TestConfigOpensItsPanelAndTheSettingsReplaceTheList(t *testing.T) {
	m := openedConfig(t)
	if m.Panel.Title != "Config" || !m.Panel.Open {
		t.Errorf("panel = %+v, want the Config panel open", m.Panel)
	}
	if want := []state.Block{{Kind: state.KindUser, Text: "/config"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want the line shown", m.Blocks)
	}
	if m.Busy {
		t.Error("Busy = true after /config, want no turn")
	}

	m, _ = feed(m, press("down"), press("down"))
	m, _ = feed(m, configEvent(append([]protocol.ConfigItem{{Key: "new.setting", Label: "new", Value: true, Kind: protocol.ConfigBool}}, configItems()...)...))
	if key := highlightedKey(m); key != "ui.theme" {
		t.Errorf("highlight on %q after a new list, want ui.theme where it was", key)
	}
}

func TestEnterTogglesASwitch(t *testing.T) {
	m, cmds := feed(openedConfig(t), press("enter"))
	if want := []any{protocol.NewConfigSet("tools.web", false)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Panel.Open {
		t.Error("the panel closed; the list stays open until the backend answers")
	}
	_, cmds = feed(m, press("down"), press("enter"))
	if want := []any{protocol.NewConfigSet("ask.enabled", true)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("second switch: commands = %v, want %v", cmds, want)
	}
}

func TestEnterCyclesAChoiceAndWrapsAround(t *testing.T) {
	m, _ := feed(openedConfig(t), press("down"), press("down"))
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewConfigSet("ui.theme", "solarized-dark")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	_, cmds = feed(m, configEvent(withTheme(configItems(), "mono")...), press("enter"))
	if want := []any{protocol.NewConfigSet("ui.theme", "tokyo-night")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("after mono: commands = %v, want %v", cmds, want)
	}
}

func withTheme(items []protocol.ConfigItem, name string) []protocol.ConfigItem {
	out := append([]protocol.ConfigItem(nil), items...)
	for i := range out {
		if out[i].Key == "ui.theme" {
			out[i].Value = name
		}
	}
	return out
}

func TestEnterEditsATextSettingAndEscGivesItUp(t *testing.T) {
	m, _ := feed(openedConfig(t), press("down"), press("down"), press("down"))
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || !m.Panel.Editing || m.Panel.Input != "nvim" {
		t.Fatalf("commands = %v, Editing = %v, Input = %q; want the value open for typing", cmds, m.Panel.Editing, m.Panel.Input)
	}

	m, cmds = feed(m, typed("x"), press("esc"))
	if len(cmds) != 0 || m.Panel.Editing || m.Panel.Input != "" || !m.Panel.Open {
		t.Errorf("after esc: commands = %v, Editing = %v, Input = %q, Open = %v; want the change given up and the panel open",
			cmds, m.Panel.Editing, m.Panel.Input, m.Panel.Open)
	}
}

func TestEnterSavesTheTypedTextSetting(t *testing.T) {
	m, _ := feed(openedConfig(t), press("down"), press("down"), press("down"), press("enter"))
	m, _ = feed(m, press("backspace"), press("backspace"), press("backspace"), press("backspace"), typed("  code --wait "))
	m, cmds := feed(m, press("enter"))
	if want := []any{protocol.NewConfigSet("ui.editor", "code --wait")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Panel.Editing || m.Panel.Input != "" {
		t.Errorf("Editing = %v, Input = %q; want the setting saved and closed", m.Panel.Editing, m.Panel.Input)
	}
}

func TestConfigChangesAreRefusedDuringATurn(t *testing.T) {
	m := openedConfig(t)
	m.Busy = true
	m, cmds := feed(m, press("enter"))
	if len(cmds) != 0 || m.Flash != busyText {
		t.Errorf("commands = %v, Flash = %q; want the change refused with a flash", cmds, m.Flash)
	}
	m, _ = feed(m, press("down"), press("down"), press("down"), press("enter"))
	if m.Panel.Editing {
		t.Error("a text setting opened for typing with the change refused")
	}
}

func TestEscClosesTheConfigPanel(t *testing.T) {
	m, _ := feed(openedConfig(t), press("esc"))
	if m.Panel.Open {
		t.Error("esc did not close the config panel")
	}
}

func TestTheConfigPanelFiltersWhileTyped(t *testing.T) {
	m, cmds := feed(openedConfig(t), typed("theme"))
	if len(cmds) != 0 || m.Panel.Query != "theme" {
		t.Fatalf("commands = %v, query = %q; want the typing in the filter", cmds, m.Panel.Query)
	}
	if got := m.Panel.Visible(); len(got) != 1 || got[0].Key != "ui.theme" {
		t.Errorf("filtered rows = %+v, want ui.theme only", got)
	}
}

func TestAThemeSettingChangesThePalette(t *testing.T) {
	m, _ := feed(state.New(), &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version, Theme: "tokyo-night"})
	m, _ = feed(m, configEvent(withTheme(configItems(), "solarized-dark")...))
	if m.Theme != "solarized-dark" {
		t.Errorf("Theme = %q, want solarized-dark once the setting says so", m.Theme)
	}
}

func TestAnEditorSettingIsTheEditorCommand(t *testing.T) {
	m, _ := feed(state.New(), configEvent(configItems()...))
	if m.Editor != "nvim" {
		t.Errorf("Editor = %q, want nvim from ui.editor", m.Editor)
	}
}

func openedSessions(t *testing.T) state.Model {
	t.Helper()
	m, cmds := feed(withV3Commands(), typed("/sessions"), press("enter"))
	if want := []any{protocol.NewSessionList()}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if len(m.Blocks) != 0 {
		t.Errorf("Blocks = %+v, want /sessions to leave no line in the transcript", m.Blocks)
	}
	m, _ = feed(m, &protocol.Sessions{Type: protocol.TypeSessions, Items: []protocol.Session{
		{ID: "s1", Title: "fix parser", Messages: 12, Current: true, UpdatedAt: "2026-10-19T12:00:00+00:00"},
		{ID: "s2", Messages: 1},
		{ID: "s3", Title: "docs", Messages: 4},
	}})
	return m
}

func TestSessionsListsTheConversationsAndMarksTheCurrentOne(t *testing.T) {
	m := openedSessions(t)
	if m.Panel.Kind != "sessions" || m.Panel.Loading || len(m.Panel.Items) != 3 {
		t.Fatalf("panel = %+v; want the sessions panel with three rows", m.Panel)
	}
	if key := highlightedKey(m); key != "s1" {
		t.Errorf("highlight on %q, want the current session s1", key)
	}
	if m.Panel.Items[0].Badge != "current" || m.Panel.Items[0].Status != "ok" {
		t.Errorf("current row = %+v, want the current badge", m.Panel.Items[0])
	}
	if m.Panel.Items[1].Title != "s2" {
		t.Errorf("a conversation without a title shows its id: %q", m.Panel.Items[1].Title)
	}
}

func TestEnterSwitchesToTheHighlightedSession(t *testing.T) {
	m, cmds := feed(openedSessions(t), press("down"), press("enter"))
	if want := []any{protocol.NewSessionSwitch("s2")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Panel.Open {
		t.Error("the sessions panel closed after a switch")
	}
}

func TestCtrlNStartsANewSession(t *testing.T) {
	m, cmds := feed(openedSessions(t), press("ctrl+n"))
	if want := []any{protocol.NewSessionNew()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Panel.Open {
		t.Error("the sessions panel closed after a new session")
	}
}

func TestSessionChangesAreRefusedDuringATurn(t *testing.T) {
	m := openedSessions(t)
	m.Busy = true
	m, cmds := feed(m, press("enter"), press("ctrl+n"))
	if len(cmds) != 0 || m.Flash != busyText {
		t.Errorf("commands = %v, Flash = %q; want both refused", cmds, m.Flash)
	}
}

func TestSessionsEventWhileTheSessionsPanelIsClosedChangesNothing(t *testing.T) {
	m, cmds := feed(withV3Commands(), &protocol.Sessions{Type: protocol.TypeSessions, Items: []protocol.Session{{ID: "s1"}}})
	if len(cmds) != 0 || m.Panel.Open {
		t.Errorf("commands = %v, Open = %v; want the list kept without a panel", cmds, m.Panel.Open)
	}
}

func sessionList(items ...protocol.Session) *protocol.Sessions {
	return &protocol.Sessions{Type: protocol.TypeSessions, Items: items}
}

func TestTheFirstSessionListOnlyNamesTheShownConversation(t *testing.T) {
	m := withV3Commands()
	m.Blocks = []state.Block{{Kind: state.KindAssistant, Agent: "build", Text: "in lexer.php"}}
	m, cmds := feed(m, sessionList(protocol.Session{ID: "s1", Title: "fix parser", Current: true}))
	if want := []state.Block{{Kind: state.KindAssistant, Agent: "build", Text: "in lexer.php"}}; len(cmds) != 0 || !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("commands = %v, Blocks = %+v; want the transcript kept", cmds, m.Blocks)
	}
	if m.Session.ID != "s1" || m.Session.Title != "fix parser" {
		t.Errorf("Session = %+v, want the current conversation remembered", m.Session)
	}
}

func TestSwitchingToAnotherSessionStartsTheTranscriptAgain(t *testing.T) {
	m, _ := feed(withV3Commands(), sessionList(protocol.Session{ID: "s1", Title: "fix parser", Current: true}))
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: "where is the lexer"}, {Kind: state.KindAssistant, Agent: "build", Text: "in lexer.php"}}
	m.Scroll = 3
	m.Changes = []string{"a.go"}
	m.Usage = &protocol.Usage{}
	m.Research = "r1"
	m, cmds := feed(m, sessionList(protocol.Session{ID: "s1", Title: "fix parser"}, protocol.Session{ID: "s2", Title: "docs", Current: true}))
	if want := []state.Block{{Kind: state.KindNotice, Level: "info", Text: "session switched: docs"}}; len(cmds) != 0 || !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("commands = %v, Blocks = %+v; want only the notice of the switch", cmds, m.Blocks)
	}
	if m.Scroll != 0 || m.Changes != nil || m.Usage != nil || m.Research != "" || m.Session.ID != "s2" {
		t.Errorf("Scroll = %d, Changes = %v, Usage = %v, Research = %q, Session = %q; want the figures of the old conversation dropped",
			m.Scroll, m.Changes, m.Usage, m.Research, m.Session.ID)
	}
}

func TestANewSessionStartsTheTranscriptAgain(t *testing.T) {
	m := openedSessions(t)
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: "hi"}}
	m, cmds := feed(m, press("ctrl+n"))
	if want := []any{protocol.NewSessionNew()}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	m, _ = feed(m, sessionList(protocol.Session{ID: "s1", Title: "fix parser"}, protocol.Session{ID: "s4", Current: true}))
	if want := []state.Block{{Kind: state.KindNotice, Level: "info", Text: "session switched: s4"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want only the notice of the new session", m.Blocks)
	}
	if !m.Panel.Open {
		t.Error("the sessions panel closed when the new session arrived")
	}
}

func TestASessionWithoutATitleIsNamedByItsID(t *testing.T) {
	m, _ := feed(withV3Commands(), sessionList(protocol.Session{ID: "s1", Current: true}))
	m, _ = feed(m, sessionList(protocol.Session{ID: "s3", Current: true}))
	if want := []state.Block{{Kind: state.KindNotice, Level: "info", Text: "session switched: s3"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want the notice to name s3", m.Blocks)
	}
}

func TestTheSameSessionKeepsItsTranscript(t *testing.T) {
	m, _ := feed(withV3Commands(), sessionList(protocol.Session{ID: "s1", Title: "fix parser", Current: true}))
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: "hi"}}
	m, _ = feed(m, sessionList(protocol.Session{ID: "s1", Title: "fix parser", Current: true}, protocol.Session{ID: "s2", Title: "docs"}))
	if want := []state.Block{{Kind: state.KindUser, Text: "hi"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want the transcript kept when the current conversation did not change", m.Blocks)
	}
}

func TestASessionListWithoutACurrentConversationChangesNothing(t *testing.T) {
	m, _ := feed(withV3Commands(), sessionList(protocol.Session{ID: "s1", Current: true}))
	m.Blocks = []state.Block{{Kind: state.KindUser, Text: "hi"}}
	m, _ = feed(m, sessionList(protocol.Session{ID: "s2"}))
	if want := []state.Block{{Kind: state.KindUser, Text: "hi"}}; !reflect.DeepEqual(m.Blocks, want) || m.Session.ID != "s1" {
		t.Errorf("Blocks = %+v, Session = %q; want the transcript and s1 kept", m.Blocks, m.Session.ID)
	}
}

func TestASwitchedSessionTakesTheModelAndTheEffortOfTheBackend(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version,
		App: protocol.AppInfo{Profile: "default", Model: "qwen", Thinking: "medium"}})
	m, _ = feed(m, sessionList(protocol.Session{ID: "s1", Current: true}))
	m, _ = feed(m, sessionList(protocol.Session{ID: "s1"}, protocol.Session{ID: "s2", Current: true}),
		&protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "gemma4:e2b", Thinking: "low"},
		&protocol.Effort{Type: protocol.TypeEffort, Level: "low", Levels: effortLevels()})
	if m.App.Profile != "local" || m.App.Model != "gemma4:e2b" || m.App.Thinking != "low" {
		t.Errorf("App = %+v, want local, gemma4:e2b and low", m.App)
	}
	if m.Effort.Level != "low" || len(m.Effort.Levels) != len(effortLevels()) {
		t.Errorf("Effort = %+v, want level low with the levels of the backend", m.Effort)
	}
	if m.Flash != "" {
		t.Errorf("Flash = %q, want none after a switch that the user did not ask for", m.Flash)
	}
}

func effortLevels() []protocol.EffortLevel {
	return []protocol.EffortLevel{{Name: "none"}, {Name: "minimal"}, {Name: "low"}, {Name: "medium"}, {Name: "high"}, {Name: "xhigh"}, {Name: "max"}}
}

const effortNote = "the default for most tasks: reasons longer at high"

func openedEffort(t *testing.T) state.Model {
	t.Helper()
	m, cmds := feed(withV3Commands(), typed("/effort"), press("enter"),
		&protocol.Effort{Type: protocol.TypeEffort, Level: "medium", Levels: effortLevels(), Note: effortNote})
	if want := []any{protocol.NewInput("/effort")}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	return m
}

func TestEffortPanelMarksTheLevelInUse(t *testing.T) {
	m := openedEffort(t)
	if m.Panel.Kind != "effort" || m.Panel.Loading || len(m.Panel.Items) != 7 {
		t.Fatalf("panel = %+v; want the effort panel with seven levels", m.Panel)
	}
	if key := highlightedKey(m); key != "medium" {
		t.Errorf("highlight on %q, want medium, the level in use", key)
	}
	if m.Effort.Level != "medium" {
		t.Errorf("Effort = %+v, want medium stored", m.Effort)
	}
}

func TestEffortPanelKeepsTheNoteOfTheBackendWhateverLevelIsHighlighted(t *testing.T) {
	m := openedEffort(t)
	if m.Panel.Note != effortNote {
		t.Errorf("Note = %q, want the note of the backend", m.Panel.Note)
	}
	m, _ = feed(m, press("down"))
	if key := highlightedKey(m); key != "high" || m.Panel.Note != effortNote {
		t.Errorf("highlight on %q with Note %q, want high with the note kept", key, m.Panel.Note)
	}
}

func TestTheArrowsMoveAlongTheEffortLevelsAndStopAtBothEnds(t *testing.T) {
	m := openedEffort(t)
	for _, step := range []struct{ key, want string }{
		{"right", "high"}, {"right", "xhigh"}, {"right", "max"}, {"right", "max"}, {"left", "xhigh"}, {"left", "high"}, {"left", "medium"},
		{"left", "low"}, {"left", "minimal"}, {"left", "none"}, {"left", "none"},
	} {
		m, _ = feed(m, press(step.key))
		if key := highlightedKey(m); key != step.want {
			t.Fatalf("after %s: highlight on %q, want %q", step.key, key, step.want)
		}
	}
	m, cmds := feed(m, press("right"), press("enter"))
	if want := []any{protocol.NewSetEffort("minimal")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestTheArrowsDoNothingOnAPanelThatIsNotTheEffortOne(t *testing.T) {
	m := openedConfig(t)
	before := m.Panel.Selected
	m, _ = feed(m, press("right"), press("left"))
	if m.Panel.Selected != before {
		t.Errorf("Selected = %d, want %d: the arrows move along the effort levels only", m.Panel.Selected, before)
	}
}

func TestEffortEventKeepsTheHighlightOnItsLevel(t *testing.T) {
	m, _ := feed(openedEffort(t), press("down"),
		&protocol.Effort{Type: protocol.TypeEffort, Level: "medium", Levels: effortLevels(), Note: effortNote})
	if key := highlightedKey(m); key != "high" {
		t.Errorf("highlight on %q after a new list, want high where it was", key)
	}
}

func TestEffortEventUpdatesTheLevelInUse(t *testing.T) {
	m, _ := feed(state.New(), tea.WindowSizeMsg{Width: 40, Height: 10},
		&protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version, App: protocol.AppInfo{Thinking: "medium"}},
		&protocol.Effort{Type: protocol.TypeEffort, Level: "xhigh", Levels: effortLevels()})
	if m.Effort.Level != "xhigh" {
		t.Errorf("Effort.Level = %q, want xhigh from the effort event", m.Effort.Level)
	}
	if status := statusOf(m); !strings.Contains(status, "effort xhigh") || strings.Contains(status, "commands") {
		t.Errorf("status = %q at 40 columns, want effort xhigh with the hints dropped", status)
	}
}

func TestEnterSetsTheHighlightedEffort(t *testing.T) {
	m, cmds := feed(openedEffort(t), press("down"), press("enter"))
	if want := []any{protocol.NewSetEffort("high")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Panel.Open {
		t.Error("the effort panel closed after a choice")
	}
	m.Busy = true
	if _, cmds := feed(m, press("enter")); len(cmds) != 0 {
		t.Errorf("a level was set during a turn: %v", cmds)
	}
}

func TestEffortWithAnArgumentIsACommandAndNotATurn(t *testing.T) {
	m, cmds := feed(withV3Commands(), typed("/effort high"), press("enter"))
	if want := []any{protocol.NewInput("/effort high")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Busy || m.Panel.Open {
		t.Errorf("Busy = %v, Open = %v; want a command with no turn and no panel", m.Busy, m.Panel.Open)
	}
}

func TestCommandsWithArgumentsAreNotTurnsAndUnlistedOnesAre(t *testing.T) {
	for _, line := range []string{"/context max 4096", "/effort none", "/context max off", "/context max 8k"} {
		m, cmds := feed(withV3Commands(), typed(line), press("enter"))
		if want := []any{protocol.NewInput(line)}; !reflect.DeepEqual(cmds, want) || m.Busy {
			t.Errorf("%s: commands = %v, Busy = %v; want the command sent and no turn", line, cmds, m.Busy)
		}
	}
	if m, _ := feed(testModel(), typed("/effort high"), press("enter")); !m.Busy {
		t.Error("/effort high without the backend listing it should be a turn, as an unknown command is")
	}
}

func openedContext(t *testing.T) state.Model {
	t.Helper()
	m, cmds := feed(withV3Commands(), typed("/context"), press("enter"),
		&protocol.Context{Type: protocol.TypeContext, Used: intp(4200), Limit: intp(10000), Percent: floatp(42),
			Threshold: intp(7800), AutoCompact: true, Messages: intp(7)})
	if want := []any{protocol.NewInput("/context")}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if m.Panel.Kind != "context" || m.Panel.Loading {
		t.Fatalf("panel = %+v; want the context panel with its numbers", m.Panel)
	}
	return m
}

func intp(n int) *int           { return &n }
func floatp(f float64) *float64 { return &f }

func TestContextEventKeepsTheNumbers(t *testing.T) {
	m := openedContext(t)
	if m.Context.Used == nil || *m.Context.Used != 4200 || m.Context.Messages == nil || *m.Context.Messages != 7 ||
		m.Context.Threshold == nil || *m.Context.Threshold != 7800 || !m.Context.AutoCompact {
		t.Errorf("Context = %+v, want the numbers kept", m.Context)
	}
}

func TestMaxSetsTheWindowInTokensOrInThousands(t *testing.T) {
	for _, tt := range []struct {
		text string
		want int
	}{{"max 8192", 8192}, {"max 8k", 8000}, {"MAX 8K", 8000}, {"max  16k ", 16000}} {
		m, cmds := feed(openedContext(t), typed(tt.text), press("enter"))
		if want := []any{protocol.NewSetContext(intp(tt.want))}; !reflect.DeepEqual(cmds, want) {
			t.Errorf("%q: commands = %v, want %v", tt.text, cmds, want)
		}
		if m.Panel.Input != "" || !m.Panel.Open {
			t.Errorf("%q: Input = %q, Open = %v; want the line cleared and the panel open", tt.text, m.Panel.Input, m.Panel.Open)
		}
	}
}

func TestMaxOffGoesBackToTheEffectiveWindow(t *testing.T) {
	for _, text := range []string{"max off", "MAX OFF"} {
		m, cmds := feed(openedContext(t), typed(text), press("enter"))
		if want := []any{protocol.NewSetContext(nil)}; !reflect.DeepEqual(cmds, want) || m.Panel.Input != "" {
			t.Errorf("%q: commands = %v, Input = %q; want the window set to the effective one and the line cleared", text, cmds, m.Panel.Input)
		}
	}
}

func TestOtherContextInputIsRefusedAndKept(t *testing.T) {
	for _, text := range []string{"abc", "8k", "20", "off", "cap 20", "max", "max 0", "max -3", "max +8", "max 1.5k",
		"max 8m", "max k", "maxim 8", "max 9999999999999999k"} {
		m, cmds := feed(openedContext(t), typed(text), press("enter"))
		if len(cmds) != 0 || m.Input != "" || m.Panel.Input != text || m.Flash != "type max N, Nk or off" {
			t.Errorf("%q: commands = %v, Input = %q, Flash = %q; want refused with the text kept", text, cmds, m.Panel.Input, m.Flash)
		}
	}
}

func TestContextChangesAreRefusedDuringATurn(t *testing.T) {
	m := openedContext(t)
	m.Busy = true
	m, cmds := feed(m, typed("max 5"), press("enter"))
	if len(cmds) != 0 || m.Flash != busyText || m.Panel.Input != "max 5" {
		t.Errorf("commands = %v, Flash = %q, Input = %q; want the change refused and the line kept", cmds, m.Flash, m.Panel.Input)
	}
}

func TestEnterOnAnEmptyContextLineSendsNothing(t *testing.T) {
	m, cmds := feed(openedContext(t), press("enter"))
	if len(cmds) != 0 || m.Flash != "" || !m.Panel.Open {
		t.Errorf("commands = %v, Flash = %q, Open = %v; want nothing sent and the panel open", cmds, m.Flash, m.Panel.Open)
	}
}

func TestContextPanelScrollsItsSummaryWithTheArrows(t *testing.T) {
	m := openedContext(t)
	m.Width, m.Height = 40, 12
	m, _ = feed(m, press("down"))
	if m.Panel.Scroll != 1 || m.Panel.Input != "" {
		t.Errorf("Scroll = %d, Input = %q; want the summary scrolled by one row and no typing", m.Panel.Scroll, m.Panel.Input)
	}
	m, _ = feed(m, press("up"), press("up"))
	if m.Panel.Scroll != 0 {
		t.Errorf("Scroll = %d after going back past the top; want 0", m.Panel.Scroll)
	}
}

func TestContextPanelTakesTypedTextOnItsLineOnly(t *testing.T) {
	m, _ := feed(openedContext(t), typed("max 9"), press("backspace"), typed("3"))
	if m.Panel.Input != "max 3" || m.Input != "" {
		t.Errorf("panel input = %q, composer = %q; want the line to hold the typing", m.Panel.Input, m.Input)
	}
	m, _ = feed(m, press("esc"))
	if m.Panel.Open {
		t.Error("esc did not close the context panel")
	}
}

func askEvent() *protocol.Ask {
	return &protocol.Ask{Type: protocol.TypeAsk, ID: "q1", Question: "Which file?", Options: []string{"a.go", "b.go"}, AllowFreeText: true}
}

func TestAskOpensAndTheComposerWaits(t *testing.T) {
	m, cmds := feed(testModel(), askEvent(), typed("hi"))
	if len(cmds) != 0 || m.Ask == nil || m.Ask.Question != "Which file?" {
		t.Fatalf("commands = %v, Ask = %+v; want the question open", cmds, m.Ask)
	}
	if m.Input != "" {
		t.Errorf("the composer took %q while the question was open", m.Input)
	}
	if m.Ask.Input != "hi" {
		t.Errorf("answer line = %q, want the typing there", m.Ask.Input)
	}
}

func TestArrowsChooseAnOptionAndEnterAnswersIt(t *testing.T) {
	m, cmds := feed(testModel(), askEvent(), press("down"), press("enter"))
	if want := []any{protocol.NewAskReply("q1", ptr("b.go"))}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Ask != nil {
		t.Error("the question is still open after the answer")
	}
}

func TestTypedAnswerWinsOverTheOption(t *testing.T) {
	_, cmds := feed(testModel(), askEvent(), typed("  neither "), press("enter"))
	if want := []any{protocol.NewAskReply("q1", ptr("neither"))}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestEscSkipsTheQuestionWithANullAnswer(t *testing.T) {
	m, cmds := feed(testModel(), askEvent(), press("esc"))
	if want := []any{protocol.NewAskReply("q1", nil)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Ask != nil || m.Busy {
		t.Errorf("Ask = %v, Busy = %v; want the question closed and the turn left alone", m.Ask, m.Busy)
	}
}

func TestCtrlCSkipsTheQuestionAndStopsTheTurn(t *testing.T) {
	m := testModel()
	m.Busy = true
	m, cmds := feed(m, askEvent(), press("ctrl+c"))
	if want := []any{protocol.NewAskReply("q1", nil), protocol.NewInterrupt()}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Ask != nil || m.Flash != "stopping…" {
		t.Errorf("Ask = %v, Flash = %q; want the question closed and the stop flashed", m.Ask, m.Flash)
	}
}

func TestAQuestionWithoutOptionsNeedsTypedText(t *testing.T) {
	m, cmds := feed(testModel(), &protocol.Ask{ID: "q2", Question: "Name?", AllowFreeText: true}, press("enter"))
	if len(cmds) != 0 || m.Ask == nil {
		t.Fatalf("commands = %v, Ask = %v; want Enter to do nothing with nothing typed", cmds, m.Ask)
	}
	_, cmds = feed(m, typed("ada"), press("enter"))
	if want := []any{protocol.NewAskReply("q2", ptr("ada"))}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestAQuestionWithoutFreeTextIgnoresTyping(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Ask{ID: "q3", Question: "Go?", Options: []string{"yes", "no"}}, typed("maybe"))
	if m.Ask.Input != "" {
		t.Errorf("answer line = %q, want the typing ignored", m.Ask.Input)
	}
}

func TestTheTurnEndingClosesAnOpenQuestion(t *testing.T) {
	m, _ := feed(testModel(), askEvent(), &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "cancelled"})
	if m.Ask != nil {
		t.Errorf("Ask = %+v after the turn ended, want it closed", m.Ask)
	}
}

func progressSteps(id string) []*protocol.Progress {
	return []*protocol.Progress{
		{Type: protocol.TypeProgress, ID: id, Step: "plan", Detail: "3 sub-questions"},
		{Type: protocol.TypeProgress, ID: id, Step: "search", Detail: "9 results"},
		{Type: protocol.TypeProgress, ID: id, Step: "answer", Detail: "done", Done: true},
	}
}

func TestProgressOpensARunPanelAndAddsItsSteps(t *testing.T) {
	steps := progressSteps("r1")
	m, _ := feed(testModel(), steps[0])
	if !m.Panel.Open || m.Panel.Run != "r1" || m.Panel.Title != "Deep research" || len(m.Panel.Items) != 1 {
		t.Fatalf("after the first step: panel = %+v; want the research panel with one step", m.Panel)
	}
	m, _ = feed(m, steps[1])
	if len(m.Panel.Items) != 2 || m.Panel.Items[1].Title != "search" || m.Panel.Items[1].Detail != "9 results" {
		t.Errorf("after the second step: items = %+v", m.Panel.Items)
	}
	m, _ = feed(m, steps[2])
	if !m.Panel.Open || len(m.Panel.Items) != 3 || m.Panel.Items[2].Status != "ok" {
		t.Fatalf("after the done step: panel = %+v; want it open with the last step marked done", m.Panel)
	}
	m, _ = feed(m, &protocol.Output{Type: protocol.TypeOutput, Text: "WebGPU answer\n\nSources: 3"})
	if m.Panel.Open {
		t.Error("the panel is open after the final output; it should close")
	}
}

func TestOutputLeavesAnOpenCommandPanelAlone(t *testing.T) {
	m, _ := feed(openedConfig(t), &protocol.Output{Type: protocol.TypeOutput, Text: "done"})
	if !m.Panel.Open || m.Panel.Kind != "config" {
		t.Errorf("panel = %+v, want the config panel kept", m.Panel)
	}
}

func TestEscClosesEveryCommandPanel(t *testing.T) {
	research, _ := feed(testModel(), progressSteps("r5")[0])
	for name, m := range map[string]state.Model{
		"config":   openedConfig(t),
		"sessions": openedSessions(t),
		"effort":   openedEffort(t),
		"context":  openedContext(t),
		"research": research,
	} {
		if next, cmds := feed(m, press("esc")); next.Panel.Open || len(cmds) != 0 {
			t.Errorf("%s: Open = %v, commands = %v; want the panel closed and nothing sent", name, next.Panel.Open, cmds)
		}
	}
}

func TestEscKeepsTheRunPanelClosed(t *testing.T) {
	steps := progressSteps("r2")
	m, _ := feed(testModel(), steps[0], press("esc"), steps[1])
	if m.Panel.Open {
		t.Error("the next step reopened the panel the user closed")
	}
	if m.Research != "r2" {
		t.Errorf("Research = %q, want r2 remembered", m.Research)
	}
}

func TestANewRunOpensItsOwnPanel(t *testing.T) {
	m, _ := feed(testModel(), progressSteps("r1")[0], press("esc"), progressSteps("r3")[0])
	if !m.Panel.Open || m.Panel.Run != "r3" {
		t.Errorf("panel = %+v, want a new panel for run r3", m.Panel)
	}
}

func TestDeepResearchSendsItsRoundsAndTheLineStaysInTheTranscript(t *testing.T) {
	m, cmds := feed(withV3Commands(), typed("/deepresearch state of WebGPU"), press("enter"))
	if want := []any{protocol.NewDeepResearch("state of WebGPU", 3)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Busy {
		t.Error("deep research started a turn")
	}
	if want := []state.Block{{Kind: state.KindUser, Text: "/deepresearch state of WebGPU"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want the line shown", m.Blocks)
	}
}

func TestBtwIsSentDuringATurnWithoutQueueing(t *testing.T) {
	m := withV3Commands()
	m.Busy = true
	m, cmds := feed(m, typed("/btw what is a lexer"), press("enter"))
	if want := []any{protocol.NewBtw("what is a lexer")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Busy || len(m.Queue) != 0 {
		t.Errorf("Busy = %v, Queue = %v; want the turn left running and nothing queued", m.Busy, m.Queue)
	}
	if !strings.Contains(m.Flash, "btw") {
		t.Errorf("Flash = %q, want the btw answer named", m.Flash)
	}
}

func TestBtwWithoutAQuestionIsLeftToTheBackend(t *testing.T) {
	m, cmds := feed(withV3Commands(), typed("/btw"), press("enter"))
	if len(cmds) != 0 || m.Input != "/btw " {
		t.Fatalf("commands = %v, Input = %q; want the completion first", cmds, m.Input)
	}
	m, cmds = feed(m, press("enter"))
	if want := []any{protocol.NewInput("/btw")}; !reflect.DeepEqual(cmds, want) || m.Busy {
		t.Errorf("commands = %v, Busy = %v; want the bare command sent without a turn", cmds, m.Busy)
	}
}

func TestACommandWithAnOptionalArgumentIsSubmittedAsItsWord(t *testing.T) {
	m, cmds := feed(withV3Commands(), typed("/context"), press("enter"))
	if want := []any{protocol.NewInput("/context")}; !reflect.DeepEqual(cmds, want) || m.Input != "" {
		t.Errorf("commands = %v, Input = %q; want /context sent at once", cmds, m.Input)
	}
	m, cmds = feed(withV3Commands(), typed("/agent"), press("enter"))
	if len(cmds) != 0 || m.Input != "/agent " {
		t.Errorf("commands = %v, Input = %q; want /agent, whose name is required, completed first", cmds, m.Input)
	}
}

func TestBtwPanelOpensWhenItArrives(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Panel{Type: protocol.TypePanel, Title: "btw", Kind: "text", Text: "A lexer splits text into tokens."})
	if !m.Panel.Open || m.Panel.Title != "btw" {
		t.Errorf("panel = %+v, want the btw panel", m.Panel)
	}
}

func TestEditOpensAProjectFileRelativeToTheProject(t *testing.T) {
	m := withV3Commands()
	m.App.Cwd = "/work/phycode"
	m, cmds := feed(m, typed("/edit notes/todo.md"), press("enter"))
	if want := []any{openEditor{Path: "/work/phycode/notes/todo.md"}}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Input != "" || len(m.Blocks) != 0 || m.Busy {
		t.Errorf("Input = %q, Blocks = %v, Busy = %v; want the editor to change nothing else", m.Input, m.Blocks, m.Busy)
	}
}

func TestEditKeepsAnAbsolutePath(t *testing.T) {
	_, cmds := feed(withV3Commands(), typed("/edit /etc/hosts"), press("enter"))
	if want := []any{openEditor{Path: "/etc/hosts"}}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
}

func TestEditWithoutAPathShowsTheUsage(t *testing.T) {
	m, cmds := feed(withV3Commands(), typed("/edit "), press("enter"))
	if len(cmds) != 0 || m.Flash != "usage: /edit <path>" || m.Busy {
		t.Errorf("commands = %v, Flash = %q, Busy = %v; want the usage and nothing sent", cmds, m.Flash, m.Busy)
	}
}

func TestEditWaitsForTheTurnLikeOtherCommands(t *testing.T) {
	m := withV3Commands()
	m.Busy = true
	m, cmds := feed(m, typed("/edit a.go"), press("enter"))
	if len(cmds) != 0 || !reflect.DeepEqual(m.Queue, []string{"/edit a.go"}) {
		t.Errorf("commands = %v, Queue = %v; want /edit queued during a turn", cmds, m.Queue)
	}
}

func TestCtrlEOpensTheComposerInTheEditor(t *testing.T) {
	m := withV3Commands()
	m.Editor = "vim"
	m, cmds := feed(m, typed("hola"), press("ctrl+e"))
	if want := []any{openEditor{Editor: "vim", Text: "hola"}}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if m.Input != "hola" {
		t.Errorf("Input = %q, want the text kept until the editor answers", m.Input)
	}
}

func TestEditorTextReplacesTheComposer(t *testing.T) {
	m, _ := feed(testModel(), typed("old"), editorDone{Temp: true, Text: "new line\nsecond line\n"})
	if m.Input != "new line\nsecond line" || m.Cursor != len([]rune(m.Input)) {
		t.Errorf("Input = %q, Cursor = %d; want the file's text without its last newline and the cursor at the end", m.Input, m.Cursor)
	}
	m, _ = feed(m, editorDone{Temp: true, Text: "a\r\nb\n\n"})
	if m.Input != "a\nb\n" {
		t.Errorf("Input = %q, want the line breaks normalised and one newline removed", m.Input)
	}
}

func TestEditorFailureKeepsTheComposer(t *testing.T) {
	m, cmds := feed(testModel(), typed("keep me"), editorDone{Temp: true, Text: "changed", Err: errors.New("exit status 1")})
	if len(cmds) != 0 || m.Input != "keep me" || m.Flash != "editor: exit status 1" {
		t.Errorf("Input = %q, Flash = %q; want the composer kept and the failure said", m.Input, m.Flash)
	}
}

func TestCtrlEIsKeptInTheUIAndNotSentToTheBackend(t *testing.T) {
	fb := newFakeBackend()
	_, cmd := New(fb).Update(press("ctrl+e"))
	if len(fb.sent) != 0 {
		t.Errorf("the backend received %v, want the editor request kept in the UI", fb.sent)
	}
	if cmd == nil {
		t.Error("no command opens the editor")
	}
}

func TestTheRendererFollowsTheThemeOfTheBackend(t *testing.T) {
	fb := newFakeBackend()
	next, _ := New(fb).Update(eventMsg{value: &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version, Theme: "paper"}})
	if got := next.(App).palette; got != "paper" {
		t.Errorf("palette = %q, want paper", got)
	}
}

func TestApplyingShowsUntilTheModelChanges(t *testing.T) {
	m, cmds := feed(loadedPicker(t), press("enter"))
	if want := []any{protocol.NewSetModel("local", "llama-3", "", false)}; !reflect.DeepEqual(cmds, want) {
		t.Fatalf("commands = %v, want %v", cmds, want)
	}
	if !m.Picker.Applying || !m.Picker.Pending {
		t.Errorf("Applying = %v, Pending = %v; want the picker to wait for the change", m.Picker.Applying, m.Picker.Pending)
	}
	m, _ = feed(m, &protocol.ModelChanged{Type: protocol.TypeModelChanged, Profile: "local", Model: "llama-3", Saved: false})
	if m.Picker.Open || m.Picker.Applying {
		t.Errorf("Open = %v, Applying = %v after model_changed; want the picker closed", m.Picker.Open, m.Picker.Applying)
	}
}

func TestARefusedChangeStopsApplying(t *testing.T) {
	m, _ := feed(loadedPicker(t), press("enter"), &protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "the model is busy"})
	if m.Picker.Applying || m.Picker.Error != "the model is busy" {
		t.Errorf("Applying = %v, Error = %q; want the change stopped and the error shown", m.Picker.Applying, m.Picker.Error)
	}
}

func TestARefusalShowsInTheStatusWhileAPanelIsOpen(t *testing.T) {
	m := openedConfig(t)
	m, _ = feed(m, &protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "wait for the turn to finish"})
	if m.Flash != "wait for the turn to finish" || !m.Panel.Open {
		t.Errorf("Flash = %q, Open = %v; want the refusal in the status line with the panel open", m.Flash, m.Panel.Open)
	}
}
