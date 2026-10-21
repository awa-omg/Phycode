package view

import (
	"fmt"
	"reflect"
	"strings"
	"testing"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func viewSettings() []protocol.ConfigItem {
	return []protocol.ConfigItem{
		{Key: "tools.web", Label: "web search", Value: true, Kind: protocol.ConfigBool, Category: "Tools"},
		{Key: "ask.enabled", Label: "ask the user", Value: false, Kind: protocol.ConfigBool, Category: "Tools"},
		{Key: "ui.theme", Label: "theme", Value: "tokyo-night", Kind: protocol.ConfigChoice,
			Choices: []string{"tokyo-night", "solarized-dark", "paper", "mono"}, Category: "Interface"},
		{Key: "ui.editor", Label: "editor", Value: "nvim", Kind: protocol.ConfigText, Category: "Interface"},
	}
}

func configPanelModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Panel = state.ConfigPanel().WithConfig(viewSettings())
	return m
}

func sessionsPanelModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Panel = state.SessionsPanel().WithSessions([]protocol.Session{
		{ID: "s1", Title: "fix parser", Messages: 12, Current: true, UpdatedAt: "2026-10-19T12:00:00+00:00"},
		{ID: "s2", Messages: 1},
		{ID: "s3", Title: "docs", Messages: 4},
	})
	return m
}

func viewEffortLevels() []protocol.EffortLevel {
	return []protocol.EffortLevel{{Name: "none"}, {Name: "minimal"}, {Name: "low"}, {Name: "medium"}, {Name: "high"}, {Name: "xhigh"}, {Name: "max"}}
}

const viewEffortNote = "the default for most tasks: the provider reasons longer at high"

func effortPanelModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Panel = state.EffortPanel().WithEffort(protocol.Effort{Level: "medium", Levels: viewEffortLevels(), Note: viewEffortNote})
	return m
}

func haikuEffort(level string) protocol.Effort {
	return protocol.Effort{
		Profile: "claude", Model: "claude-haiku-5-5", Level: level, Levels: viewEffortLevels(),
		Accepted: []string{"none", "low", "medium", "high", "xhigh", "max"},
		Note:     "claude-haiku-5-5 takes none and low to max: minimal is sent as low; none turns thinking off",
	}
}

func contextPanelModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Panel = state.ContextPanel()
	m.Panel.Loading = false
	used, limit, messages, percent, threshold := 4200, 10000, 7, 42.0, 7800
	m.Context = protocol.Context{Type: protocol.TypeContext, Used: &used, Limit: &limit, Percent: &percent,
		Threshold: &threshold, AutoCompact: true, Messages: &messages}
	return m
}

func researchPanelModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Panel = state.ResearchPanel(&protocol.Progress{Type: protocol.TypeProgress, ID: "r1", Step: "plan", Detail: "3 sub-questions"})
	m.Panel = m.Panel.WithStep(&protocol.Progress{Type: protocol.TypeProgress, ID: "r1", Step: "search", Detail: "9 results"})
	m.Panel = m.Panel.WithStep(&protocol.Progress{Type: protocol.TypeProgress, ID: "r1", Step: "answer", Detail: "done", Done: true})
	return m
}

func askModel(w, h int) state.Model {
	m := newModel(w, h)
	m.Ask = state.NewAsk(&protocol.Ask{Type: protocol.TypeAsk, ID: "q1", Question: "Which file?", Options: []string{"a.go", "b.go"}, AllowFreeText: true})
	return m
}

func rightEnd(row string) string {
	return strings.TrimSpace(strings.TrimRight(strings.TrimSpace(row), "│"))
}

func TestConfigPanelShowsEachKindOfSetting(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, configPanelModel(80, 24))
	for _, want := range []string{"Config", "Tools ─", "Interface ─", "web search", "ask the user", "theme", "editor", "↑↓ move · enter change · esc close"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the config panel lacks %q: %q", want, rows)
		}
	}
	for _, tc := range []struct{ label, value string }{{"web search", "on"}, {"ask the user", "off"}, {"theme", "tokyo-night"}, {"editor", "nvim"}} {
		if row := rowWith(rows, tc.label); !strings.HasSuffix(rightEnd(row), tc.value) {
			t.Errorf("the %s row = %q, want its value %s at the right", tc.label, row, tc.value)
		}
	}
	if row := highlightIn(rows); !strings.Contains(row, "web search") {
		t.Errorf("the highlight is not on the first setting: %q", row)
	}
	if row := rowWith(rows, "web search"); !strings.Contains(row, "tools.web") || !strings.Contains(row, "● on") {
		t.Errorf("the web search row = %q, want its key and its switch drawn as a dot and the word", row)
	}
	if row := rowWith(rows, "ask the user"); !strings.Contains(row, "○ off") {
		t.Errorf("the ask row = %q, want an open dot and off", row)
	}
}

func TestSessionsPanelMarksTheCurrentConversation(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, sessionsPanelModel(80, 24))
	if row := rowWith(rows, "fix parser"); !onBarRow(row) || !strings.Contains(row, "●") || !strings.HasSuffix(rightEnd(row), "2026-10-19 12:00") {
		t.Errorf("the current conversation row = %q; want the highlight, its dot, and its messages and time at the right", row)
	}
	if row := rowWith(rows, "docs"); strings.Contains(row, "●") {
		t.Errorf("the docs row = %q; only the current conversation has the dot", row)
	}
	if rowOf(rows, "2026-10-19 12:00") < 0 || rowOf(rows, "1 message") < 0 {
		t.Errorf("the update time or the message count is missing: %q", rows)
	}
}

func TestEffortPanelShowsTheLevelsInOneRowAndMarksTheOneInUse(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, effortPanelModel(80, 24))
	row := rowWith(rows, "none")
	last := -1
	for _, level := range []string{"none", "minimal", "low", "medium", "high", "xhigh", "max"} {
		at := strings.Index(row, level)
		if at <= last {
			t.Fatalf("the level %s is not in order on the row: %q", level, row)
		}
		last = at
	}
	if !strings.Contains(row, "● medium") || strings.Count(row, "●") != 1 {
		t.Errorf("the levels = %q; want the dot on the level in use alone", row)
	}
	if rowOf(rows, "←→ change · enter set · esc close") < 0 {
		t.Errorf("the keys are missing: %q", rows)
	}
}

func TestEffortPanelPutsTheHighlightOnOneLevelOfTheRow(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	m := effortPanelModel(80, 24)
	m.Panel.Selected = 4
	row := screen(t, r, m)[rowOf(screen(t, r, m), "xhigh")]
	if !wearsBar(styledAs(row, " high ")) {
		t.Errorf("the highlighted level is not on the bar: %q", row)
	}
	for _, other := range []string{"low", "medium", "xhigh"} {
		if wearsBar(styledAs(row, other)) {
			t.Errorf("the level %s is on the bar but is not highlighted: %q", other, row)
		}
	}
}

func TestEffortPanelFallsBackToAListWhenTheRowDoesNotFit(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, effortPanelModel(30, 24))
	if row := rowWith(rows, "none"); strings.Contains(row, "minimal") {
		t.Errorf("the levels still share a row on a narrow dock: %q", rows)
	}
	if row := highlightRow(plainRowsOfDock(t, r, effortPanelModel(30, 24))); !strings.Contains(row, "medium") {
		t.Errorf("the list has no highlight on the level in use: %q", rows)
	}
}

func TestEffortPanelShowsItsNoteOnceUnderTheLevelsWhateverIsHighlighted(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := effortPanelModel(80, 24)
	rows := inDock(t, r, m)
	levels, note, hint := rowOf(rows, "xhigh"), rowOf(rows, "the default for most tasks"), rowOf(rows, "←→ change · enter set · esc close")
	if note < 0 || note <= levels || note >= hint {
		t.Errorf("the note is at row %d; want it under the levels (%d) and above the hint (%d): %q", note, levels, hint, rows)
	}
	for _, selected := range []int{0, 4, 6} {
		m.Panel.Selected = selected
		if n := strings.Count(strings.Join(inDock(t, r, m), "\n"), "the default for most tasks"); n != 1 {
			t.Errorf("highlight %d: the note is shown %d times, want once: %q", selected, n, inDock(t, r, m))
		}
	}
}

func TestEffortPanelTitleNamesTheModelInUse(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(80, 24)
	m.Panel = state.EffortPanel().WithEffort(haikuEffort("medium"))
	if rowOf(screen(t, r, m), "Effort · claude-haiku-5-5") < 0 {
		t.Errorf("the title does not name the model in use: %q", plainRows(screen(t, r, m)))
	}
}

func TestEffortPanelDimsTheLevelsTheModelDoesNotTakeAndSaysWhatTheyAreSentAs(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	m := newModel(100, 24)
	m.Panel = state.EffortPanel().WithEffort(haikuEffort("medium"))
	rows := screen(t, r, m)
	row := rows[rowOf(rows, "xhigh")]
	if !strings.Contains(row, r.styles.Dim.Render(" minimal → low ")) {
		t.Errorf("the level that the model does not take is not dimmed with what it is sent as: %q", row)
	}
	if !strings.Contains(row, r.styles.Output.Render(" high ")) {
		t.Errorf("a level the model takes is not drawn as it is: %q", row)
	}
	if n := strings.Count(strings.Join(plainRows(rows), "\n"), "→ low"); n != 1 {
		t.Errorf("the mark of minimal is shown %d times, want once", n)
	}
}

func TestEffortPanelMarksNoLevelWhenTheModelIsUnknown(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, effortPanelModel(80, 24))
	for _, mark := range []string{"→ none", "→ low", "→ high", "→ max"} {
		if rowOf(rows, mark) >= 0 {
			t.Errorf("a model that is not known is marked with %q: %q", mark, rows)
		}
	}
	if rowOf(rows, "the default for most tasks") < 0 {
		t.Errorf("the note is missing for a model that is not known: %q", rows)
	}
}

func TestContextPanelShowsTheWindowAsABarWithTheThresholdAndTheFacts(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, contextPanelModel(80, 24))
	figure := rowWith(rows, "4.2k of 10k tokens · 42%")
	if figure == "" || !strings.HasSuffix(figure, "autocompact at 7.8k") {
		t.Errorf("the figure row = %q, want the tokens against the window and the threshold at the right", figure)
	}
	if bar := rowWith(rows, "█"); !strings.Contains(bar, "░") || !strings.Contains(bar, "┃") {
		t.Errorf("the bar = %q, want what is used, what is free, and a tick at the threshold", bar)
	}
	if row := rowWith(rows, "max off"); !strings.Contains(row, "the effective window") || !strings.HasSuffix(row, "7 messages") {
		t.Errorf("the facts row = %q, want off and the effective window when no max is set, and the count", row)
	}
	if line := rowWith(rows, "❯"); !strings.Contains(line, "max N, Nk or off") {
		t.Errorf("the line = %q, want its placeholder", line)
	}
	if rowOf(rows, "type max N, Nk or off · enter sets it · esc close") < 0 {
		t.Errorf("the keys are missing: %q", rows)
	}
	if rowOf(rows, "cap") >= 0 || rowOf(rows, "/compact") >= 0 {
		t.Errorf("the context panel still offers a cap or /compact: %q", rows)
	}
}

func TestContextPanelSaysOneMessageInTheSingular(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := contextPanelModel(80, 24)
	one := 1
	m.Context.Messages = &one
	if row := rowWith(inDock(t, r, m), "max off"); !strings.HasSuffix(row, "1 message") {
		t.Errorf("the facts row = %q, want 1 message", row)
	}
	m.Context.Messages = nil
	if row := rowWith(inDock(t, r, m), "max off"); strings.Contains(row, "message") {
		t.Errorf("the facts row = %q, want no count when the backend gives none", row)
	}
}

func TestContextPanelShowsTheMaxTheUserSetAndWhetherAutocompactIsOff(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := contextPanelModel(80, 24)
	eight := 8000
	m.Context.Max = &eight
	m.Context.AutoCompact = false
	rows := inDock(t, r, m)
	if row := rowWith(rows, "max 8k"); !strings.HasSuffix(row, "7 messages") {
		t.Errorf("the facts row = %q, want the 8k the user set", row)
	}
	if row := rowWith(rows, "tokens"); !strings.HasSuffix(row, "autocompact off") || strings.Contains(strings.Join(rows, ""), "┃") {
		t.Errorf("the figure row = %q, want autocompact off and no tick on the bar", row)
	}
}

func TestContextPanelLineShowsWhatIsTyped(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := contextPanelModel(80, 24)
	m.Panel.Input = "max 8k"
	rows := inDock(t, r, m)
	if row := rowWith(rows, "max 8k"); !strings.Contains(row, "❯") || !strings.Contains(row, "█") {
		t.Errorf("the typed line = %q, want the prompt and the cursor", row)
	}
	if line := rowWith(rows, "❯"); strings.Contains(line, "Nk or off") {
		t.Errorf("the placeholder shows while there is text: %q", line)
	}
}

func contextBreakdownModel(w, h int) state.Model {
	m := contextPanelModel(w, h)
	m.Context.Breakdown = []protocol.ContextPart{
		{Category: "system", Tokens: 1200}, {Category: "tool definitions", Tokens: 800},
		{Category: "tool results", Tokens: 2400}, {Category: "summaries", Tokens: 300},
	}
	return m
}

func TestContextPanelShowsTheLegendOfTheBarByCategory(t *testing.T) {
	trueColour(t)
	r := NewRenderer(theme.Default())
	rows := inDock(t, r, contextBreakdownModel(100, 30))
	for _, tc := range []struct{ category, tokens, share string }{
		{"system", "1.2k", "12%"}, {"tool definitions", "800", "8%"}, {"tool results", "2.4k", "24%"}, {"summaries", "300", "3%"}, {"free", "5.3k", "53%"},
	} {
		row := rowWith(rows, "■ "+tc.category)
		if tc.category == "free" {
			row = rowWith(rows, "░ free")
		}
		if row == "" || !strings.Contains(row, tc.tokens) || !strings.HasSuffix(row, tc.share) {
			t.Errorf("the %s row = %q, want its tokens %s and its share %s at the right", tc.category, row, tc.tokens, tc.share)
		}
	}
	bar := screen(t, r, contextBreakdownModel(100, 30))
	line := bar[rowOf(bar, "█")]
	for i := 0; i < 4; i++ {
		if !strings.Contains(line, seqOf(r.partStyle(i))+"█") {
			t.Errorf("the bar has no cells in the colour of part %d: %q", i, line)
		}
	}
}

func TestContextPanelWithoutABreakdownShowsNoLegend(t *testing.T) {
	r := NewRenderer(theme.Default())
	if rows := inDock(t, r, contextPanelModel(80, 24)); rowOf(rows, "■") >= 0 || rowOf(rows, "free") >= 0 {
		t.Errorf("an empty breakdown shows a legend: %q", rows)
	}
}

func TestContextLineStaysInViewOnEverySmallSize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, size := range [][2]int{{20, 8}, {40, 12}, {80, 24}} {
		rows := plainRows(screen(t, r, contextBreakdownModel(size[0], size[1])))
		if rowOf(rows, "❯") < 0 {
			t.Errorf("%dx%d: the line is cut off: %q", size[0], size[1], rows)
		}
	}
}

func compactLineModel(w, h int, text string) state.Model {
	m := newModel(w, h)
	m.Panel = state.CompactPanel(text)
	return m
}

func TestCompactLineShowsWhatCanBeTypedAndItsKeys(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, compactLineModel(80, 24, "")))
	for _, want := range []string{"Compact", "optional instructions", "type instructions · enter compacts · esc cancel"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the compact line lacks %q: %q", want, rows)
		}
	}
	rows = plainRows(screen(t, r, compactLineModel(80, 24, "keep the file names")))
	if row := rowWith(rows, "keep the file names"); !strings.Contains(row, "❯") || !strings.Contains(row, "█") {
		t.Errorf("the typed instructions row = %q, want the prompt and the cursor", row)
	}
	if rowOf(rows, "optional instructions") >= 0 {
		t.Errorf("the placeholder shows while there is text: %q", rows)
	}
}

func TestCompactLineSaysItRunsWhileTheCompactionDoes(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := compactLineModel(80, 24, "keep the file names")
	m.Compacting = true
	m.Panel = m.Panel.CompactSent()
	rows := plainRows(screen(t, r, m))
	if rowOf(rows, "compacting…") < 0 || rowOf(rows, "compacting… · esc close") < 0 {
		t.Errorf("the line does not say the compaction runs: %q", rows)
	}
	if rowOf(rows, "enter compacts") >= 0 {
		t.Errorf("the line still offers to compact while it runs: %q", rows)
	}
}

func TestResearchPanelMarksTheStepsThatAreDone(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, researchPanelModel(80, 24)))
	if rowOf(rows, "Deep research") < 0 {
		t.Errorf("the title is missing: %q", rows)
	}
	if row := rowWith(rows, "answer"); !strings.Contains(row, "●") {
		t.Errorf("the done step has no mark: %q", row)
	}
	if row := rowWith(rows, "3 sub-questions"); strings.Contains(row, "●") {
		t.Errorf("the step that is not done = %q; want no done mark", row)
	}
	if rowOf(rows, "9 results") < 0 {
		t.Errorf("the second step is missing: %q", rows)
	}
}

func TestAskModalShowsTheQuestionTheOptionsAndTheAnswerLine(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, askModel(80, 24)))
	for _, want := range []string{"Question", "Which file?", "› a.go", "b.go", "your own answer", "↑↓ choose · type or enter answer · esc skip"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the question lacks %q: %q", want, rows)
		}
	}
}

func TestStatusShowsTheEffortWhenItIsNotNoneAndTheContextPercentage(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(120, 24)
	m.Effort = protocol.Effort{Type: protocol.TypeEffort, Level: "high", Levels: viewEffortLevels()}
	pct := 42.0
	m.Context = protocol.Context{Type: protocol.TypeContext, Percent: &pct}
	if status := plain(screen(t, r, m)[23]); !strings.Contains(status, "effort high") || !strings.Contains(status, "ctx 42%") {
		t.Errorf("status = %q, want the effort and the context share", status)
	}
	m.Effort.Level = "none"
	if status := plain(screen(t, r, m)[23]); strings.Contains(status, "effort") {
		t.Errorf("status = %q, want no effort slot while it is none", status)
	}
}

func TestStatusShowsTheEffortOnlyWhenItIsNotNone(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, tt := range []struct{ level, shown string }{
		{"none", ""},
		{"off", ""},
		{"on", "effort medium"},
		{"medium", "effort medium"},
		{"xhigh", "effort xhigh"},
	} {
		m := newModel(120, 24)
		m.Effort = protocol.Effort{Type: protocol.TypeEffort, Level: tt.level, Levels: viewEffortLevels()}
		status := plain(screen(t, r, m)[23])
		switch {
		case tt.shown == "" && strings.Contains(status, "effort"):
			t.Errorf("level %s: status = %q, want no effort text", tt.level, status)
		case tt.shown != "" && !strings.Contains(status, tt.shown):
			t.Errorf("level %s: status = %q, want %q", tt.level, status, tt.shown)
		}
	}
}

func TestEffortKeepsTheWidthRuleAt40And80(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{40, 80} {
		m := newModel(w, 10)
		m.Effort = protocol.Effort{Type: protocol.TypeEffort, Level: "none", Levels: viewEffortLevels()}
		rows := screen(t, r, m)
		status := plain(rows[len(rows)-1])
		if strings.Contains(status, "effort") {
			t.Errorf("%d columns: status = %q, want no effort text at the default", w, status)
		}
		if w == 80 && !strings.Contains(status, "commands") {
			t.Errorf("80 columns: status = %q, want the hints, since the default takes no room", status)
		}
		m.Effort.Level = "xhigh"
		rows = screen(t, r, m)
		status = plain(rows[len(rows)-1])
		if !strings.Contains(status, "effort xhigh") || strings.Contains(status, "@ subagents") || strings.Contains(status, "commands") != (w == 80) {
			t.Errorf("%d columns: status = %q, want effort xhigh, no full hints, and the short hints only at 80 columns", w, status)
		}
	}
}

func TestStatusSaysAutocompactionRunsUntilTheContextArrives(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(120, 10)
	m.Autocompacting = true
	rows := screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "autocompacting…") || strings.Contains(status, "ready") {
		t.Errorf("status = %q, want autocompacting… in place of ready", status)
	}
	m.Busy = true
	rows = screen(t, r, m)
	if status := plain(rows[len(rows)-1]); !strings.Contains(status, "working") || !strings.Contains(status, "autocompacting…") {
		t.Errorf("status = %q, want the turn and the autocompaction both shown", status)
	}
}

func TestPanelsAndAskShowTheirTitleAndARowOnSmallTerminals(t *testing.T) {
	r := NewRenderer(theme.Default())
	cases := []struct {
		title, row string
		model      func(w, h int) state.Model
	}{
		{"Config", "web", configPanelModel},
		{"Sessions", "fix", sessionsPanelModel},
		{"Effort", "medium", effortPanelModel},
		{"Effort", "the default", effortPanelModel},
		{"Context", "❯", contextPanelModel},
		{"Context", "❯", contextBreakdownModel},
		{"Compact", "keep", compactKeepModel},
		{"Run", "gentle", execPanelModel},
		{"Deep", "plan", researchPanelModel},
		{"Question", "a.go", askModel},
		{"Models", "›", pickerView},
	}
	for _, c := range cases {
		for _, size := range [][2]int{{20, 8}, {40, 12}, {80, 24}} {
			t.Run(fmt.Sprintf("%s at %dx%d", c.title, size[0], size[1]), func(t *testing.T) {
				rows := screen(t, r, c.model(size[0], size[1]))
				if rowOf(rows, c.title) < 0 {
					t.Errorf("the title is not visible: %q", plainRows(rows))
				}
				if rowOf(rows, c.row) < 0 {
					t.Errorf("no row with %q is visible: %q", c.row, plainRows(rows))
				}
			})
		}
	}
}

func compactKeepModel(w, h int) state.Model {
	return compactLineModel(w, h, "keep")
}

func TestCommandPanelsAndAskFitEverySmallSize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{1, 3, 8, 12, 16, 20, 24} {
		for _, h := range []int{1, 2, 4, 6, 8, 12} {
			for _, model := range []func(int, int) state.Model{configPanelModel, sessionsPanelModel, effortPanelModel, contextPanelModel,
				contextBreakdownModel, compactKeepModel, execPanelModel, researchPanelModel, askModel} {
				screen(t, r, model(w, h))
			}
			long := askModel(w, h)
			long.Ask.Question = strings.Repeat("a long question with many words ", 6)
			long.Ask.Input = strings.Repeat("typed", 12)
			screen(t, r, long)
		}
	}
}

func TestEveryPaletteDrawsTheSameTextInTheSameWidth(t *testing.T) {
	m := richModel(80, 24)
	m.Busy = false
	var blocks []state.Block
	for _, b := range m.Blocks {
		if b.Kind != state.KindAssistant {
			blocks = append(blocks, b)
		}
	}
	m.Blocks = blocks
	want := plainRows(screen(t, NewRenderer(theme.Default()), m))
	for _, name := range theme.Names() {
		got := plainRows(screen(t, NewRenderer(theme.Build(theme.Lookup(name))), m))
		if !reflect.DeepEqual(got, want) {
			t.Errorf("%s draws other text than the default palette:\n%s", name, strings.Join(got, "\n"))
		}
	}
}

func TestMaxScrollMeasuresTheAnswerInEachPalette(t *testing.T) {
	for _, name := range theme.Names() {
		m := newModel(60, 12)
		m.Theme = name
		m.Blocks = []state.Block{{Kind: state.KindAssistant, Text: "# Title\n\nA **long** answer that keeps going past the width of the transcript, so that it wraps over several rows.\n\n- one\n- two"}}
		for i := 1; i <= 20; i++ {
			m.Blocks = append(m.Blocks, state.Block{Kind: state.KindUser, Text: "message " + twoDigits(i)})
		}
		r := NewRenderer(theme.Build(theme.Lookup(name)))
		top := MaxScroll(m)
		m.Scroll = top
		if rowOf(plainRows(screen(t, r, m)), "Title") < 0 {
			t.Errorf("%s: at MaxScroll the first answer is not shown", name)
		}
		m.Scroll = top - 1
		if rowOf(plainRows(screen(t, r, m)), "Title") >= 0 {
			t.Errorf("%s: MaxScroll is too large, one row up still shows the first answer", name)
		}
	}
}

func TestMarkdownFollowsThePalette(t *testing.T) {
	const text = "# Title\n\n**bold** and `code` and [a link](https://example.com)"
	draw := func(name string) string {
		return strings.Join(NewRenderer(theme.Build(theme.Lookup(name))).markdown(text, 40), "\n")
	}
	dark, light := draw(theme.NamePhycode), draw(theme.NamePhycodeLight)
	if dark == light {
		t.Error("the dark and the light palettes draw the answer the same way")
	}
	for name, out := range map[string]string{"dark": dark, "light": light} {
		if !strings.Contains(out, "\x1b[") || !strings.Contains(plain(out), "Title") {
			t.Errorf("the %s palette draws the answer without colour or without its text: %q", name, out)
		}
	}
}
