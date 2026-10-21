package app

import (
	"reflect"
	"testing"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
)

func fakeClock(t *testing.T) *time.Time {
	t.Helper()
	now := time.Date(2026, 10, 19, 9, 0, 0, 0, time.UTC)
	clock = func() time.Time { return now }
	t.Cleanup(func() { clock = time.Now })
	return &now
}

func update(t *testing.T, a App, msg tea.Msg) (App, tea.Cmd) {
	t.Helper()
	next, cmd := a.Update(msg)
	return next.(App), cmd
}

func openSession(t *testing.T) (App, *fakeBackend) {
	t.Helper()
	fb := newFakeBackend()
	a, _ := update(t, New(fb), eventMsg{value: &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version}})
	return a, fb
}

func refreshes(fb *fakeBackend) int {
	n := 0
	for _, c := range fb.sent {
		if list, ok := c.(protocol.ListModels); ok && list.Refresh {
			n++
		}
	}
	return n
}

func tickCatalogue(t *testing.T, a App) App {
	t.Helper()
	a, _ = update(t, a, refreshTick{})
	return a
}

func TestTheSessionAsksForARefreshWhenItOpens(t *testing.T) {
	fakeClock(t)
	_, fb := openSession(t)
	if want := []any{protocol.NewListModels(true)}; !reflect.DeepEqual(fb.sent, want) {
		t.Errorf("sent = %v, want %v", fb.sent, want)
	}
	if catalogueTimer(&protocol.Ready{Type: protocol.TypeReady}) == nil {
		t.Error("the ready event does not arm the timer")
	}
}

func TestNothingIsAskedBeforeTheSessionOpens(t *testing.T) {
	fakeClock(t)
	fb := newFakeBackend()
	_, cmd := update(t, New(fb), refreshTick{})
	if len(fb.sent) != 0 || cmd == nil {
		t.Errorf("sent = %v, timer armed = %v; want nothing sent and the timer kept running", fb.sent, cmd != nil)
	}
}

func TestTheCatalogueIsAskedAgainAfterFifteenMinutes(t *testing.T) {
	clk := fakeClock(t)
	a, fb := openSession(t)

	*clk = clk.Add(refreshEvery - refreshCheck)
	a = tickCatalogue(t, a)
	if n := refreshes(fb); n != 1 {
		t.Fatalf("refreshes = %d at 14m30s, want only the one that opened the session", n)
	}

	*clk = clk.Add(refreshCheck)
	a = tickCatalogue(t, a)
	if n := refreshes(fb); n != 2 {
		t.Fatalf("refreshes = %d at 15m, want the second one", n)
	}

	a = tickCatalogue(t, a)
	if n := refreshes(fb); n != 2 {
		t.Errorf("refreshes = %d right after the second, want no more", n)
	}
	_ = a
}

func TestARefreshDueDuringATurnWaitsForTheTurnToEnd(t *testing.T) {
	clk := fakeClock(t)
	a, fb := openSession(t)

	*clk = clk.Add(refreshEvery)
	a, _ = update(t, a, eventMsg{value: &protocol.TurnStart{Type: protocol.TypeTurnStart}})
	a = tickCatalogue(t, a)
	*clk = clk.Add(time.Minute)
	a = tickCatalogue(t, a)
	if n := refreshes(fb); n != 1 {
		t.Fatalf("refreshes = %d during the turn, want the one that opened the session only", n)
	}

	a, _ = update(t, a, eventMsg{value: &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"}})
	if n := refreshes(fb); n != 2 {
		t.Fatalf("refreshes = %d after turn_end, want the deferred refresh", n)
	}
	a = tickCatalogue(t, a)
	if n := refreshes(fb); n != 2 {
		t.Errorf("refreshes = %d after the deferred one, want no more", n)
	}
	_ = a
}

func TestATurnThatStartsAQueuedTurnDefersTheRefreshAgain(t *testing.T) {
	clk := fakeClock(t)
	a, fb := openSession(t)

	*clk = clk.Add(refreshEvery)
	a, _ = update(t, a, eventMsg{value: &protocol.TurnStart{Type: protocol.TypeTurnStart}})
	a, _ = update(t, a, typed("second"))
	a, _ = update(t, a, press("enter"))
	a, _ = update(t, a, eventMsg{value: &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"}})
	if !a.state.Busy || refreshes(fb) != 1 {
		t.Fatalf("Busy = %v, refreshes = %d; want the queued message running and no refresh yet", a.state.Busy, refreshes(fb))
	}

	a, _ = update(t, a, eventMsg{value: &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"}})
	if refreshes(fb) != 2 {
		t.Errorf("refreshes = %d after the queued turn ended, want the refresh then", refreshes(fb))
	}
}

func TestAManualRefreshRestartsTheFifteenMinutes(t *testing.T) {
	clk := fakeClock(t)
	a, fb := openSession(t)
	a, _ = update(t, a, typed("/models"))
	a, _ = update(t, a, press("enter"))
	a, _ = update(t, a, eventMsg{value: pickerModels()})

	*clk = clk.Add(10 * time.Minute)
	a, _ = update(t, a, press("ctrl+r"))
	if n := refreshes(fb); n != 2 {
		t.Fatalf("refreshes = %d after ctrl+r, want two", n)
	}

	*clk = clk.Add(10 * time.Minute)
	a = tickCatalogue(t, a)
	if n := refreshes(fb); n != 2 {
		t.Fatalf("refreshes = %d ten minutes after ctrl+r, want none yet", n)
	}

	*clk = clk.Add(5 * time.Minute)
	a = tickCatalogue(t, a)
	if n := refreshes(fb); n != 3 {
		t.Errorf("refreshes = %d fifteen minutes after ctrl+r, want the automatic one", n)
	}
	_ = a
}

func TestTheRefreshKeepsTheHighlightOfAnOpenPicker(t *testing.T) {
	clk := fakeClock(t)
	a, _ := openSession(t)
	a, _ = update(t, a, typed("/models"))
	a, _ = update(t, a, press("enter"))
	a, _ = update(t, a, eventMsg{value: pickerModels()})
	a, _ = update(t, a, press("down"))
	if got := rowName(a.state); got != "cloud/gpt-x" {
		t.Fatalf("highlight = %s, want provider:cloud", got)
	}

	*clk = clk.Add(refreshEvery)
	a = tickCatalogue(t, a)
	if !a.state.Picker.Pending {
		t.Error("Pending = false after the automatic refresh, want the picker to wait for it")
	}
	a, _ = update(t, a, eventMsg{value: pickerModels()})
	if got := rowName(a.state); got != "cloud/gpt-x" {
		t.Errorf("highlight = %s after the refresh, want provider:cloud kept", got)
	}
}

func TestTheTimerIsArmedAgainAfterEachLookButNotByKeys(t *testing.T) {
	fakeClock(t)
	a, _ := openSession(t)
	if _, cmd := update(t, a, refreshTick{}); cmd == nil {
		t.Error("a look at the catalogue did not arm the next one")
	}
	if _, cmd := update(t, a, typed("x")); cmd != nil {
		t.Error("a key press armed the catalogue timer")
	}
}
