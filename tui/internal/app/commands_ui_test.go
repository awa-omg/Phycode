package app

import (
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func TestTheThemeListOpensOnTheThemeInUseAndEveryMoveShowsThatTheme(t *testing.T) {
	m := testModel()
	m.Theme = "nord"
	m, cmds := feed(m, typed("/theme"), press("enter"))
	if len(cmds) != 0 || !m.Panel.Open || m.Panel.Kind != "themes" || len(m.Panel.Items) != len(theme.Names()) {
		t.Fatalf("commands = %v, Panel = %+v; want the list of every palette", cmds, m.Panel)
	}
	if it, _ := m.Panel.Highlighted(); it.Key != "nord" || it.Badge != "in use" {
		t.Errorf("highlight = %+v, want nord, the one in use", it)
	}
	m, _ = feed(m, press("down"))
	if it, _ := m.Panel.Highlighted(); m.Theme != it.Key || m.Theme == "nord" {
		t.Errorf("Theme = %q, highlight = %q; want the palette under the highlight shown at once", m.Theme, it.Key)
	}
	m, _ = feed(m, press("up"), press("up"))
	if it, _ := m.Panel.Highlighted(); m.Theme != it.Key {
		t.Errorf("Theme = %q, highlight = %q; want the preview to follow the highlight up too", m.Theme, it.Key)
	}
}

func TestEnterKeepsTheThemeAndSavesItAndEscPutsTheOldOneBack(t *testing.T) {
	m := testModel()
	m.Theme = "nord"
	opened, _ := feed(m, typed("/theme"), press("enter"))
	chosen, cmds := feed(opened, press("down"), press("enter"))
	name := chosen.Theme
	if name == "nord" || chosen.Panel.Open {
		t.Fatalf("Theme = %q, Panel open = %v; want a new theme kept and the list closed", name, chosen.Panel.Open)
	}
	if want := []any{protocol.NewConfigSet("ui.theme", name)}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	cancelled, cmds := feed(opened, press("down"), press("down"), press("esc"))
	if cancelled.Theme != "nord" || cancelled.Panel.Open || len(cmds) != 0 {
		t.Errorf("Theme = %q, commands = %v; want esc to put nord back and save nothing", cancelled.Theme, cmds)
	}
	cancelled, _ = feed(opened, press("down"), press("ctrl+c"))
	if cancelled.Theme != "nord" {
		t.Errorf("Theme = %q after ctrl+c, want nord", cancelled.Theme)
	}
}

func TestTheThemeListFiltersAsYouType(t *testing.T) {
	m, _ := feed(testModel(), typed("/theme"), press("enter"), typed("gru"))
	if it, ok := m.Panel.Highlighted(); !ok || it.Key != "gruvbox" || m.Theme != "gruvbox" {
		t.Errorf("highlight = %+v, Theme = %q; want gruvbox shown", it, m.Theme)
	}
}

func TestSlashThemeWithANameSetsAndSavesItAndAnUnknownOneListsTheOnes(t *testing.T) {
	m, cmds := feed(testModel(), typed("/theme "), typed("Paper"), press("enter"))
	if m.Theme != "paper" || !reflect.DeepEqual(cmds, []any{protocol.NewConfigSet("ui.theme", "paper")}) {
		t.Errorf("Theme = %q, commands = %v; want paper set and saved", m.Theme, cmds)
	}
	m, cmds = feed(testModel(), typed("/theme "), typed("neon"), press("enter"))
	if len(cmds) != 0 || !strings.Contains(m.Flash, "no theme neon") || !strings.Contains(m.Flash, "gruvbox") {
		t.Errorf("commands = %v, Flash = %q; want the themes named", cmds, m.Flash)
	}
}

func TestEveryThemeOfTheListIsOneThatTheBackendAccepts(t *testing.T) {
	for _, name := range theme.Names() {
		if themeNotes[name] == "" {
			t.Errorf("the theme %s has no note in the list", name)
		}
	}
}

func TestCopyPutsTheLastReplyOnTheClipboardAndSaysSoWhenThereIsNone(t *testing.T) {
	m := testModel()
	m.Blocks = []state.Block{
		{Kind: state.KindAssistant, Text: "first answer"},
		{Kind: state.KindAssistant, Depth: 1, Text: "a subagent's text"},
		{Kind: state.KindAssistant, Text: "  last answer \n"},
		{Kind: state.KindNotice, Text: "later notice"},
	}
	_, cmds := feed(m, typed("/copy"), press("enter"))
	if want := []any{copyText{Text: "last answer"}}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the last answer of the main agent, trimmed", cmds, want)
	}
	empty, cmds := feed(testModel(), typed("/copy"), press("enter"))
	if len(cmds) != 0 || empty.Flash != "nothing to copy yet" {
		t.Errorf("commands = %v, Flash = %q; want nothing sent and the reason said", cmds, empty.Flash)
	}
}

func TestCopyDoneSaysHowMuchWentToTheClipboardAndWhenItWasCut(t *testing.T) {
	m, _ := feed(testModel(), copyDone{Bytes: 512})
	if m.Flash != "copied the last reply (512 bytes)" {
		t.Errorf("Flash = %q", m.Flash)
	}
	m, _ = feed(testModel(), copyDone{Bytes: 60000, Cut: true})
	if !strings.Contains(m.Flash, "copied the start of the last reply (60.0 kB)") {
		t.Errorf("Flash = %q, want the cut said", m.Flash)
	}
}

func TestTheCopyCommandWritesAnOsc52SequenceThatCarriesTheTextAsBase64(t *testing.T) {
	t.Setenv("TMUX", "")
	old := os.Stdout
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	os.Stdout = w
	msg := copyCommand(copyText{Text: "héllo"})()
	os.Stdout = old
	_ = w.Close()
	buf := make([]byte, 256)
	n, _ := r.Read(buf)
	if got := string(buf[:n]); got != "\x1b]52;c;aMOpbGxv\x07" {
		t.Errorf("sequence = %q, want the OSC 52 of héllo", got)
	}
	if done, ok := msg.(copyDone); !ok || done.Bytes != len("héllo") || done.Cut {
		t.Errorf("message = %+v, want %d bytes copied", msg, len("héllo"))
	}
}

func TestExportWritesTheConversationAsMarkdownInThePrivateFolderOfTheProject(t *testing.T) {
	dir := t.TempDir()
	m := testModel()
	m.App.Cwd = dir
	m.App.Profile, m.App.Model = "local", "qwen"
	m.Session.Title = "fix the parser"
	m.Blocks = []state.Block{
		{Kind: state.KindUser, Text: "fix a.go"},
		{Kind: state.KindAssistant, Text: "I'll read it."},
		{Kind: state.KindTool, Tool: "read", Summary: "a.go"},
		{Kind: state.KindSubagent, Agent: "explore", Text: "find callers", Summary: "two callers"},
		{Kind: state.KindOutput, Text: "$ ls\na.go"},
		{Kind: state.KindNotice, Text: "context is nearly full"},
	}
	_, cmds := feed(m, typed("/export"), press("enter"))
	if len(cmds) != 1 {
		t.Fatalf("commands = %v, want one export", cmds)
	}
	e, ok := cmds[0].(exportFile)
	if !ok || !strings.HasPrefix(e.Path, filepath.Join(dir, ".phycode", "exports")+string(filepath.Separator)) || !strings.HasSuffix(e.Path, ".md") {
		t.Fatalf("command = %+v, want a .md in .phycode/exports of the project", cmds[0])
	}
	for _, want := range []string{"# fix the parser", "local · qwen", "## You\n\nfix a.go", "## phycode\n\nI'll read it.", "- `read` a.go", "### Subagent explore", "> two callers", "```\n$ ls\na.go\n```", "> context is nearly full"} {
		if !strings.Contains(e.Text, want) {
			t.Errorf("the Markdown lacks %q:\n%s", want, e.Text)
		}
	}
	done := exportCommand(e)().(exportDone)
	if done.Err != nil {
		t.Fatal(done.Err)
	}
	info, err := os.Stat(e.Path)
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Errorf("file = %v, %v; want it written readable by the user alone", info, err)
	}
	if dirInfo, _ := os.Stat(filepath.Dir(e.Path)); dirInfo.Mode().Perm() != 0o700 {
		t.Errorf("folder mode = %v, want 0700", dirInfo.Mode().Perm())
	}
	got, _ := feed(testModel(), done)
	if got.Flash != "exported to "+e.Path {
		t.Errorf("Flash = %q", got.Flash)
	}
}

func TestExportTakesAPathRelativeToTheProjectAndSaysWhyItFailed(t *testing.T) {
	m := testModel()
	m.App.Cwd = "/work/p"
	if got := exportPath(m, "notes/talk.md", time.Time{}); got != "/work/p/notes/talk.md" {
		t.Errorf("path = %q, want it under the project", got)
	}
	if got := exportPath(m, "/tmp/out.md", time.Time{}); got != "/tmp/out.md" {
		t.Errorf("path = %q, want an absolute path as it is", got)
	}
	failed, _ := feed(testModel(), exportDone{Err: os.ErrPermission})
	if !strings.HasPrefix(failed.Flash, "export failed: ") {
		t.Errorf("Flash = %q", failed.Flash)
	}
}

func TestExportNeverWritesThroughASymbolicLink(t *testing.T) {
	dir := t.TempDir()
	victim := filepath.Join(dir, "victim.txt")
	if err := os.WriteFile(victim, []byte("keep me"), 0o600); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(dir, "notes.md")
	if err := os.Symlink(victim, link); err != nil {
		t.Skipf("symbolic links are not available: %v", err)
	}
	if done := exportCommand(exportFile{Path: link, Text: "the conversation"})().(exportDone); done.Err == nil {
		t.Error("the export of a link succeeded")
	}
	if got, _ := os.ReadFile(victim); string(got) != "keep me" {
		t.Errorf("the file that the link names now holds %q", got)
	}

	created := filepath.Join(dir, "created.txt")
	dangling := filepath.Join(dir, "dangling.md")
	if err := os.Symlink(created, dangling); err != nil {
		t.Fatal(err)
	}
	if done := exportCommand(exportFile{Path: dangling, Text: "the conversation"})().(exportDone); done.Err == nil {
		t.Error("the export through a dangling link succeeded")
	}
	if _, err := os.Lstat(created); !os.IsNotExist(err) {
		t.Errorf("a dangling link created the file it names (%v)", err)
	}
}

func TestExportReplacesAnExistingFile(t *testing.T) {
	file := filepath.Join(t.TempDir(), "notes.md")
	if err := os.WriteFile(file, []byte("an older export that is longer"), 0o600); err != nil {
		t.Fatal(err)
	}
	if done := exportCommand(exportFile{Path: file, Text: "new"})().(exportDone); done.Err != nil {
		t.Fatal(done.Err)
	}
	if got, _ := os.ReadFile(file); string(got) != "new" {
		t.Errorf("the file holds %q, want the new export alone", got)
	}
}

func TestDetailsTogglesTheToolOutputsLikeCtrlO(t *testing.T) {
	m, _ := feed(testModel(), typed("/details"), press("enter"))
	if !m.ToolsExpanded || m.Flash != "tool details shown" {
		t.Errorf("ToolsExpanded = %v, Flash = %q; want them shown", m.ToolsExpanded, m.Flash)
	}
	m, _ = feed(m, typed("/details"), press("enter"))
	if m.ToolsExpanded || m.Flash != "tool details folded" {
		t.Errorf("ToolsExpanded = %v, Flash = %q; want them folded", m.ToolsExpanded, m.Flash)
	}
	if len(m.Blocks) != 0 {
		t.Errorf("Blocks = %v, want the command not shown in the transcript", m.Blocks)
	}
}

func TestTheNamesOfOpencodeAndPiRunTheCommandsTheyStandFor(t *testing.T) {
	for line, want := range map[string]string{
		"/new": "/clear", "/summarize": "/compact", "/summarize keep names": "/compact keep names", "/resume": "/sessions",
		"/continue": "/sessions", "/themes": "/theme", "/q": "/exit", "/clear": "/clear", "/help me": "/help me",
	} {
		if got := resolveAlias(line); got != want {
			t.Errorf("resolveAlias(%q) = %q, want %q", line, got, want)
		}
	}
	_, cmds := feed(testModel(), typed("/new"), press("enter"))
	if want := []any{protocol.NewInput("/clear")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("/new sent %v, want %v", cmds, want)
	}
	m, cmds := feed(testModel(), typed("/resume"), press("enter"))
	if m.Panel.Kind != "sessions" || !reflect.DeepEqual(cmds, []any{protocol.NewSessionList()}) {
		t.Errorf("/resume: Panel = %q, commands = %v; want the list of sessions", m.Panel.Kind, cmds)
	}
	themes, _ := feed(testModel(), typed("/themes"), press("enter"))
	if themes.Panel.Kind != "themes" {
		t.Errorf("/themes opened %q", themes.Panel.Kind)
	}
}

func TestTheUICommandsRunDuringATurnAndTheOthersWait(t *testing.T) {
	for _, line := range []string{"/theme", "/copy", "/details", "/connect", "/web", "/models", "/export", "/themes"} {
		if !runsDuringTurn(line) {
			t.Errorf("%s waits for the turn, want it to run", line)
		}
	}
	for _, line := range []string{"/clear", "/effort high", "/new", "hello", "!ls"} {
		if runsDuringTurn(line) {
			t.Errorf("%s runs during a turn, want it to wait", line)
		}
	}
}

func TestTabSwitchesThePrimaryAgentWhenNoMenuIsOpenAndTheHeaderFollowsTheBackend(t *testing.T) {
	m := testModel()
	m.App.Agent = "build"
	m, cmds := feed(m, press("tab"))
	if want := []any{protocol.NewInput("/agent plan")}; !reflect.DeepEqual(cmds, want) || len(m.Blocks) != 0 {
		t.Fatalf("commands = %v, Blocks = %v; want /agent plan sent and not shown", cmds, m.Blocks)
	}
	m, _ = feed(m, &protocol.AgentChanged{Type: protocol.TypeAgentChanged, Agent: "plan"})
	if m.App.Agent != "plan" {
		t.Errorf("Agent = %q, want the header to follow the event", m.App.Agent)
	}
	_, cmds = feed(m, press("tab"))
	if want := []any{protocol.NewInput("/agent build")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want the cycle to wrap to build: %v", cmds, want)
	}
}

func TestTabStillCompletesTheMenuAndAgentSwitchingWaitsForTheTurnAndNeedsTwoAgents(t *testing.T) {
	m, cmds := feed(testModel(), typed("/he"), press("tab"))
	if m.Input != "/help" || len(cmds) != 0 {
		t.Errorf("Input = %q, commands = %v; want tab to complete the menu", m.Input, cmds)
	}
	busy := testModel()
	busy.Busy = true
	busy, cmds = feed(busy, press("tab"))
	if len(cmds) != 0 || busy.Flash != busyText {
		t.Errorf("commands = %v, Flash = %q; want the switch to wait for the turn", cmds, busy.Flash)
	}
	one := testModel()
	one.Agents = []protocol.Agent{{Name: "build", Mode: "primary"}}
	one, cmds = feed(one, press("tab"))
	if len(cmds) != 0 || one.Flash != "there is only one primary agent" {
		t.Errorf("commands = %v, Flash = %q; want it said that there is one agent", cmds, one.Flash)
	}
}

func TestShiftTabCyclesTheThinkingLevelAndWrapsFromTheStrongestToTheWeakest(t *testing.T) {
	m := testModel()
	m.Effort = protocol.Effort{Level: "medium", Levels: []protocol.EffortLevel{{Name: "none"}, {Name: "low"}, {Name: "medium"}, {Name: "high"}}}
	_, cmds := feed(m, pickerKey("shift+tab"))
	if want := []any{protocol.NewInput("/effort high")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	m.Effort.Level = "high"
	_, cmds = feed(m, pickerKey("shift+tab"))
	if want := []any{protocol.NewInput("/effort none")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the weakest follows the strongest", cmds, want)
	}
	m.Busy = true
	_, cmds = feed(m, pickerKey("shift+tab"))
	if len(cmds) != 0 {
		t.Errorf("commands = %v during a turn, want none", cmds)
	}
}

func TestCtrlLOpensThePickerLikeModels(t *testing.T) {
	m, cmds := feed(testModel(), pickerKey("ctrl+l"))
	if want := []any{protocol.NewListModels(false)}; !reflect.DeepEqual(cmds, want) || !m.Picker.Open {
		t.Errorf("commands = %v, Open = %v; want the picker open and the list asked", cmds, m.Picker.Open)
	}
}
