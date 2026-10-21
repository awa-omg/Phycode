package app

import (
	"reflect"
	"testing"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

func compactAnswer() *protocol.Panel {
	return &protocol.Panel{Type: protocol.TypePanel, Title: "compact", Kind: "text", Text: "tokens before 4200\ntokens after 900"}
}

func openedCompact(t *testing.T, line string) state.Model {
	t.Helper()
	m, cmds := feed(withV3Commands(), typed(line), press("enter"))
	if len(cmds) != 0 || m.Busy || m.Panel.Kind != "compact" || !m.Panel.Open {
		t.Fatalf("commands = %v, Busy = %v, panel = %+v; want the compact line open and nothing sent", cmds, m.Busy, m.Panel)
	}
	return m
}

func TestCompactOpensItsLineWithoutStartingATurn(t *testing.T) {
	m := openedCompact(t, "/compact")
	if m.Panel.Title != "Compact" || m.Panel.Input != "" || m.Compacting {
		t.Errorf("panel = %+v, Compacting = %v; want an empty Compact line", m.Panel, m.Compacting)
	}
	if want := []state.Block{{Kind: state.KindUser, Text: "/compact"}}; !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want the line shown", m.Blocks)
	}
}

func TestEnterSendsTheInstructionsOnTheLine(t *testing.T) {
	m, cmds := feed(openedCompact(t, "/compact"), typed("  keep the file names "), press("enter"))
	if want := []any{protocol.NewCompact("keep the file names")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v", cmds, want)
	}
	if !m.Compacting || !m.Panel.Open || m.Panel.Kind != "compact" || m.Busy {
		t.Errorf("Compacting = %v, panel = %+v, Busy = %v; want the line open while the compaction runs", m.Compacting, m.Panel, m.Busy)
	}
}

func TestEnterOnAnEmptyLineSendsNull(t *testing.T) {
	m, cmds := feed(openedCompact(t, "/compact"), press("enter"))
	if len(cmds) != 1 || !reflect.DeepEqual(cmds[0], protocol.NewCompact("")) {
		t.Fatalf("commands = %v, want one compact command", cmds)
	}
	if got := cmds[0].(protocol.Compact); got.Instructions != nil {
		t.Errorf("instructions = %q, want null", *got.Instructions)
	}
	if !m.Compacting {
		t.Error("Compacting = false after the compaction was sent")
	}
}

func TestTheArgumentOfTheCommandIsTheFirstText(t *testing.T) {
	m := openedCompact(t, "/compact keep the tests")
	if m.Panel.Input != "keep the tests" {
		t.Fatalf("line = %q, want the argument typed on the line", m.Panel.Input)
	}
	if _, cmds := feed(m, press("enter")); !reflect.DeepEqual(cmds, []any{protocol.NewCompact("keep the tests")}) {
		t.Errorf("commands = %v, want the argument sent", cmds)
	}
}

func TestEscCancelsTheLineBeforeAnythingIsSent(t *testing.T) {
	m, cmds := feed(openedCompact(t, "/compact"), typed("x"), press("esc"))
	if len(cmds) != 0 || m.Panel.Open || m.Compacting {
		t.Errorf("commands = %v, Open = %v, Compacting = %v; want the line closed and nothing sent", cmds, m.Panel.Open, m.Compacting)
	}
}

func TestTheLineTakesNoTypingOrEnterWhileTheCompactionRuns(t *testing.T) {
	m, _ := feed(openedCompact(t, "/compact"), typed("keep"), press("enter"))
	m, cmds := feed(m, typed("x"), press("backspace"), press("backspace"), press("enter"))
	if len(cmds) != 0 || m.Panel.Input != "keep" || !m.Compacting {
		t.Errorf("commands = %v, Input = %q, Compacting = %v; want the line kept and nothing sent again", cmds, m.Panel.Input, m.Compacting)
	}
}

func TestEscDuringTheCompactionClosesTheLineButTheStatusKeepsSayingIt(t *testing.T) {
	m, cmds := feed(openedCompact(t, "/compact"), press("enter"), press("esc"))
	if len(cmds) != 1 || m.Panel.Open || !m.Compacting {
		t.Errorf("commands = %v, Open = %v, Compacting = %v; want the line closed and the compaction still marked as running", cmds, m.Panel.Open, m.Compacting)
	}
}

func TestCompactAnswerShowsItsPanelAndClosesTheLine(t *testing.T) {
	m, _ := feed(openedCompact(t, "/compact"), press("enter"),
		&protocol.Context{Type: protocol.TypeContext, Used: intp(900), Limit: intp(10000), Percent: floatp(9), Messages: intp(4)},
		compactAnswer())
	if m.Compacting || !m.Panel.Open || m.Panel.Kind != "text" || m.Panel.Title != "compact" || m.Panel.Text != "tokens before 4200\ntokens after 900" {
		t.Errorf("Compacting = %v, panel = %+v; want the compact panel shown and the line closed", m.Compacting, m.Panel)
	}
	if m.Context.Used == nil || *m.Context.Used != 900 {
		t.Errorf("Context = %+v, want the numbers after the compaction", m.Context)
	}
}

func TestCompactIsRefusedDuringATurnAndTheLineKeepsWhatWasTyped(t *testing.T) {
	m := openedCompact(t, "/compact")
	m.Busy = true
	m, cmds := feed(m, typed("keep"), press("enter"))
	if len(cmds) != 0 || m.Compacting || m.Flash != busyText || m.Panel.Input != "keep" {
		t.Errorf("commands = %v, Compacting = %v, Flash = %q, Input = %q; want the change refused and the line kept",
			cmds, m.Compacting, m.Flash, m.Panel.Input)
	}
}

func TestAWarningEndsACompactionThatWasRefused(t *testing.T) {
	m, _ := feed(openedCompact(t, "/compact"), press("enter"),
		&protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "nothing to compact yet"})
	if m.Compacting || !m.Panel.Open || m.Flash != "nothing to compact yet" || m.Panel.Hint != state.CompactPanel("").Hint {
		t.Errorf("Compacting = %v, Open = %v, Flash = %q, hint = %q; want the refusal shown in the line and the compaction over",
			m.Compacting, m.Panel.Open, m.Flash, m.Panel.Hint)
	}
}

func TestCompactWhileItRunsSaysSoInsteadOfOpeningTheLineAgain(t *testing.T) {
	m, _ := feed(openedCompact(t, "/compact"), press("enter"), press("esc"))
	m, cmds := feed(m, typed("/compact"), press("enter"))
	if len(cmds) != 0 || m.Panel.Open || m.Flash != "wait for the compaction to finish" {
		t.Errorf("commands = %v, Open = %v, Flash = %q; want the line not opened again", cmds, m.Panel.Open, m.Flash)
	}
}

func TestCompactIsSentAsItsOwnCommandAndNeverAsALine(t *testing.T) {
	fb := newFakeBackend()
	var m tea.Model = New(fb)
	for _, msg := range []tea.Msg{typed("/compact"), press("enter"), typed("keep"), press("enter")} {
		m, _ = m.Update(msg)
	}
	if want := []any{protocol.NewCompact("keep")}; !reflect.DeepEqual(fb.sent, want) {
		t.Errorf("the backend received %v, want only the compact command", fb.sent)
	}
}

func TestAFailedCompactionEndsWithItsNoticeAndTheLineAsksAgain(t *testing.T) {
	m, _ := feed(openedCompact(t, "/compact"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.Notice{Type: protocol.TypeNotice, Level: "error", Message: "compaction failed: the model gave no summary"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "error", Reason: ptr("the model gave no summary")})
	if m.Compacting || m.Busy {
		t.Errorf("Compacting = %v, Busy = %v; want the status line to stop saying the compaction runs", m.Compacting, m.Busy)
	}
	if m.Panel.Hint != state.CompactPanel("").Hint {
		t.Errorf("line hint = %q; want the line asking for instructions again", m.Panel.Hint)
	}
	if n := len(m.Blocks); n == 0 || m.Blocks[n-1].Text != "compaction failed: the model gave no summary" {
		t.Errorf("Blocks = %+v; want the failure kept in the transcript", m.Blocks)
	}
}

func TestAFailedCompactionWithoutANoticeStillEndsIt(t *testing.T) {
	m, _ := feed(openedCompact(t, "/compact"), press("enter"),
		&protocol.TurnStart{Type: protocol.TypeTurnStart},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "error"})
	if m.Compacting || m.Busy || m.Panel.Hint != state.CompactPanel("").Hint {
		t.Errorf("Compacting = %v, Busy = %v, hint = %q; want the compaction over and the line asking again", m.Compacting, m.Busy, m.Panel.Hint)
	}
}

func TestAutocompactNoticeShowsInTheTranscriptAndSaysItRuns(t *testing.T) {
	m, cmds := feed(testModel(), &protocol.Notice{Type: protocol.TypeNotice, Level: "info",
		Message: "autocompact: 7800 of 8000 tokens, summarising the older history"})
	if len(cmds) != 0 || !m.Autocompacting || m.Busy {
		t.Errorf("commands = %v, Autocompacting = %v, Busy = %v; want no command and the compaction marked as running", cmds, m.Autocompacting, m.Busy)
	}
	want := []state.Block{{Kind: state.KindNotice, Level: "info", Text: "autocompact: 7800 of 8000 tokens, summarising the older history"}}
	if !reflect.DeepEqual(m.Blocks, want) {
		t.Errorf("Blocks = %+v, want the notice in the transcript", m.Blocks)
	}
}

func TestContextAfterAnAutocompactionEndsIt(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: "autocompact: summarising"},
		&protocol.Context{Type: protocol.TypeContext, Used: intp(900), Limit: intp(10000), Messages: intp(4)})
	if m.Autocompacting {
		t.Error("Autocompacting = true after the context event, want the status line to stop saying it runs")
	}
	if m.Context.Used == nil || *m.Context.Used != 900 {
		t.Errorf("Context = %+v, want the numbers after the compaction", m.Context)
	}
}

func TestTheAnswerAfterAnAutocompactionEndsIt(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: "autocompact: summarised"})
	if !m.Autocompacting {
		t.Fatal("Autocompacting = false after the notice")
	}
	m, _ = feed(m, &protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "Hi"})
	if m.Autocompacting {
		t.Error("Autocompacting = true after the answer started, want the compaction over")
	}
}

func TestTurnEndEndsAnAutocompactionThatSentNoContext(t *testing.T) {
	m, _ := feed(testModel(), &protocol.Notice{Type: protocol.TypeNotice, Level: "warn", Message: "autocompact: the model failed"},
		&protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "error"})
	if m.Autocompacting || m.Busy {
		t.Errorf("Autocompacting = %v, Busy = %v; want nothing left running after the turn", m.Autocompacting, m.Busy)
	}
	if len(m.Blocks) != 1 || m.Blocks[0].Level != "warn" {
		t.Errorf("Blocks = %+v; want the warning kept in the transcript", m.Blocks)
	}
}
