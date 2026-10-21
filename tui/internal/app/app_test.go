package app

import (
	"errors"
	"reflect"
	"testing"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/backend"
	"phycode/tui/internal/protocol"
)

type fakeBackend struct {
	events  chan any
	sent    []any
	err     error
	stopped bool
}

func newFakeBackend() *fakeBackend {
	return &fakeBackend{events: make(chan any, 16)}
}

func (f *fakeBackend) Events() <-chan any { return f.events }

func (f *fakeBackend) Send(command any) error {
	f.sent = append(f.sent, command)
	return f.err
}

func (f *fakeBackend) Stop(time.Duration) error {
	f.stopped = true
	return nil
}

func TestInitWaitsForTheNextBackendValue(t *testing.T) {
	fb := newFakeBackend()
	event := &protocol.TurnStart{Type: protocol.TypeTurnStart}
	fb.events <- event
	msg, ok := New(fb).Init()().(eventMsg)
	if !ok || msg.value != any(event) {
		t.Errorf("Init command returned %#v, want the turn start event", msg)
	}
}

func TestClosedChannelEndsTheWait(t *testing.T) {
	fb := newFakeBackend()
	close(fb.events)
	if msg := New(fb).Init()(); msg != nil {
		t.Errorf("wait on a closed channel returned %#v, want nil", msg)
	}
}

func TestEventsUpdateTheStateAndWaitForTheNext(t *testing.T) {
	fb := newFakeBackend()
	next, cmd := New(fb).Update(eventMsg{value: &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version}})
	if !next.(App).state.Ready {
		t.Error("Ready = false after the ready event")
	}
	if cmd == nil {
		t.Error("no command waits for the next event")
	}
}

func TestKeysSendTheirCommands(t *testing.T) {
	fb := newFakeBackend()
	next, _ := New(fb).Update(typed("hola"))
	next, _ = next.(App).Update(tea.KeyMsg{Type: tea.KeyEnter})
	if want := []any{protocol.NewInput("hola")}; !reflect.DeepEqual(fb.sent, want) {
		t.Errorf("sent = %v, want %v", fb.sent, want)
	}
	if !next.(App).state.Busy {
		t.Error("Busy = false after submitting")
	}
}

func TestFailedSendShowsAFlash(t *testing.T) {
	fb := newFakeBackend()
	fb.err = errors.New("broken pipe")
	next, _ := New(fb).Update(typed("hola"))
	next, _ = next.(App).Update(tea.KeyMsg{Type: tea.KeyEnter})
	if got := next.(App).state.Flash; got != "could not reach the backend" {
		t.Errorf("Flash = %q, want \"could not reach the backend\"", got)
	}
}

func TestTickRunsWhileATurnRuns(t *testing.T) {
	fb := newFakeBackend()
	next, cmd := New(fb).Update(typed("hola"))
	a := next.(App)
	if cmd != nil || a.ticking {
		t.Fatal("typing without a turn started the spinner")
	}

	next, cmd = a.Update(tea.KeyMsg{Type: tea.KeyEnter})
	a = next.(App)
	if cmd == nil || !a.ticking {
		t.Fatal("submitting did not start the spinner")
	}
	if _, ok := cmd().(Tick); !ok {
		t.Errorf("spinner command returned something other than Tick")
	}

	next, cmd = a.Update(Tick{})
	a = next.(App)
	if cmd == nil || !a.ticking {
		t.Error("a tick during a turn did not schedule the next one")
	}

	next, _ = a.Update(eventMsg{value: &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"}})
	a = next.(App)
	if a.state.Busy {
		t.Fatal("still busy after turn_end")
	}
	next, cmd = a.Update(Tick{})
	a = next.(App)
	if cmd != nil || a.ticking {
		t.Error("the spinner kept ticking after the turn ended")
	}
}

func TestFlashGoesAwayAfterFourSeconds(t *testing.T) {
	now := fakeClock(t)
	a := New(newFakeBackend())
	a.state.Flash = "saved"
	next, cmd := a.Update(Tick{})
	a = next.(App)
	if cmd == nil || !a.ticking {
		t.Fatal("a flash did not start the tick")
	}
	*now = now.Add(3 * time.Second)
	next, _ = a.Update(Tick{})
	if a = next.(App); a.state.Flash != "saved" {
		t.Fatalf("Flash = %q after 3 s, want it still shown", a.state.Flash)
	}
	*now = now.Add(time.Second)
	next, cmd = a.Update(Tick{})
	a = next.(App)
	if a.state.Flash != "" || cmd != nil || a.ticking {
		t.Errorf("Flash = %q, ticking = %v after 4 s; want the flash gone and the tick stopped", a.state.Flash, a.ticking)
	}
}

func TestQuitSendsQuitAndEndsTheProgram(t *testing.T) {
	fb := newFakeBackend()
	_, cmd := New(fb).Update(tea.KeyMsg{Type: tea.KeyCtrlC})
	if want := []any{protocol.NewQuit()}; !reflect.DeepEqual(fb.sent, want) {
		t.Errorf("sent = %v, want %v", fb.sent, want)
	}
	if cmd == nil {
		t.Fatal("no command ends the program")
	}
	if _, ok := cmd().(tea.QuitMsg); !ok {
		t.Errorf("command returned %#v, want tea.QuitMsg", cmd())
	}
	if fb.stopped {
		t.Error("the app stopped the backend; main does that after the program ends")
	}
}

func TestClosedBackendEndsTheProgramWithAnError(t *testing.T) {
	fb := newFakeBackend()
	next, cmd := New(fb).Update(eventMsg{value: &backend.Closed{Stderr: "panic: boom\n"}})
	if got := next.(App).state.Error; got != "the backend stopped: panic: boom" {
		t.Errorf("Error = %q", got)
	}
	if cmd == nil {
		t.Fatal("no command ends the program")
	}
	if _, ok := cmd().(tea.QuitMsg); !ok {
		t.Errorf("command returned %#v, want tea.QuitMsg", cmd())
	}
}

func TestALineThatIsTooLongSaysWhyTheBackendStopped(t *testing.T) {
	fb := newFakeBackend()
	next, cmd := New(fb).Update(eventMsg{value: &backend.Closed{Err: &backend.LineTooLong{Prefix: []byte("{")}}})
	if got := next.(App).state.Error; got != "the backend stopped: a line of the backend's output is longer than 16 MiB" {
		t.Errorf("Error = %q", got)
	}
	if cmd == nil {
		t.Fatal("no command ends the program")
	}
	if _, ok := cmd().(tea.QuitMsg); !ok {
		t.Errorf("command returned %#v, want tea.QuitMsg", cmd())
	}
}
