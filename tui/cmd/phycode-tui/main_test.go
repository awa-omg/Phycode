package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"reflect"
	"strings"
	"sync"
	"testing"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/app"
	"phycode/tui/internal/backend"
	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

const helperEnv = "PHYCODE_TUI_HELPER"

const helperBye = `{"type":"bye","reason":"quit"}`

func TestRunWithoutABackendCommandIsAUsageError(t *testing.T) {
	tests := []struct {
		name string
		args []string
	}{
		{name: "no arguments", args: nil},
		{name: "separator without a command", args: []string{"--"}},
		{name: "no separator", args: []string{"php", "bin/phycode", "rpc"}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := run(tt.args); got != 2 {
				t.Errorf("run(%q) = %d, want 2", tt.args, got)
			}
		})
	}
}

func TestReportNamesAnInterfaceThatEndedWithoutTheApp(t *testing.T) {
	var stderr bytes.Buffer
	if got := report(&stderr, nil, nil, nil); got != 1 {
		t.Errorf("report(nil model) = %d, want 1", got)
	}
	if want := "phycode-tui: the interface ended unexpectedly\n"; stderr.String() != want {
		t.Errorf("stderr = %q, want %q", stderr.String(), want)
	}

	stderr.Reset()
	if got := report(&stderr, app.New(newRecordingBackend()), nil, nil); got != 0 || stderr.Len() != 0 {
		t.Errorf("report(app) = %d with stderr %q, want 0 and nothing printed", got, stderr.String())
	}
}

func TestReportWritesNoEscapeSequenceOfTheBackend(t *testing.T) {
	const hostile = "\x1b[2J\x1b]52;c;SGVsbG8=\x07"
	tests := []struct {
		name  string
		event any
		want  string
	}{
		{"fatal message", &protocol.Fatal{Type: protocol.TypeError, Message: "boom " + hostile}, "phycode-tui: boom ]52;c;SGVsbG8=\n"},
		{"stderr line", &backend.Closed{Stderr: "panic: " + hostile + "\n"}, "phycode-tui: the backend stopped: panic: ]52;c;SGVsbG8=\n"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			fb := &recordingBackend{events: make(chan any, 1), listed: make(chan struct{})}
			fb.events <- tt.event
			a := app.New(fb)
			next, _ := a.Update(a.Init()())
			var stderr bytes.Buffer
			if got := report(&stderr, next, nil, nil); got != 1 {
				t.Errorf("report = %d, want 1", got)
			}
			if stderr.String() != tt.want {
				t.Errorf("stderr = %q, want %q", stderr.String(), tt.want)
			}
		})
	}
}

func TestHelperProcess(t *testing.T) {
	if os.Getenv(helperEnv) == "" {
		return
	}
	fmt.Println(`{"type":"ready","protocol":2,"app":{"version":"test"},"agents":[],"commands":[]}`)
	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		var command struct {
			Type string `json:"type"`
		}
		if json.Unmarshal(scanner.Bytes(), &command) == nil && command.Type == protocol.CmdQuit {
			fmt.Println(helperBye)
			os.Exit(0)
		}
	}
	os.Exit(1)
}

func TestBackendRoundTripUpdatesTheState(t *testing.T) {
	t.Setenv(helperEnv, "1")
	t.Setenv("GORACE", strings.TrimSpace(os.Getenv("GORACE")+" atexit_sleep_ms=0"))
	proc, err := backend.Start([]string{os.Args[0], "-test.run=TestHelperProcess"})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { proc.Stop(time.Second) })

	m := state.New()
	for {
		value, ok := nextEvent(t, proc.Events())
		if !ok {
			break
		}
		m, _ = app.Update(m, value)
		switch value.(type) {
		case *protocol.Ready:
			if !m.Ready || m.Quit {
				t.Fatalf("after ready: Ready = %v, Quit = %v; want Ready only", m.Ready, m.Quit)
			}
			if err := proc.Send(protocol.NewQuit()); err != nil {
				t.Fatal(err)
			}
		case *protocol.Bye:
			if !m.Quit {
				t.Fatal("Quit = false after bye, want true")
			}
		}
	}
	if m.Error != "" {
		t.Errorf("Error = %q after a clean exit, want none", m.Error)
	}
	if err := proc.Stop(5 * time.Second); err != nil {
		t.Errorf("Stop = %v", err)
	}
}

func nextEvent(t *testing.T, events <-chan any) (any, bool) {
	t.Helper()
	select {
	case value, ok := <-events:
		return value, ok
	case <-time.After(5 * time.Second):
		t.Fatal("timed out waiting for the backend")
		return nil, false
	}
}

const endMarker = "marker: turn over"

type recordingBackend struct {
	events chan any
	listed chan struct{}

	mu         sync.Mutex
	sent       []any
	listedOnce sync.Once
}

func newRecordingBackend() *recordingBackend {
	b := &recordingBackend{events: make(chan any, 16), listed: make(chan struct{})}
	b.events <- &protocol.Ready{Type: protocol.TypeReady, Protocol: protocol.Version}
	return b
}

func (b *recordingBackend) Events() <-chan any { return b.events }

func (b *recordingBackend) Send(command any) error {
	b.mu.Lock()
	b.sent = append(b.sent, command)
	b.mu.Unlock()
	switch command.(type) {
	case protocol.ListModels:
		b.listedOnce.Do(func() { close(b.listed) })
	case protocol.Input:
		b.events <- &protocol.TurnStart{Type: protocol.TypeTurnStart}
		b.events <- &protocol.Text{Type: protocol.TypeText, Agent: "build", Chunk: "Hola!"}
		b.events <- &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"}
		b.events <- &protocol.Output{Type: protocol.TypeOutput, Text: endMarker}
	case protocol.Quit:
		b.events <- &protocol.Bye{Type: protocol.TypeBye, Reason: "quit"}
	}
	return nil
}

type gatedInput struct {
	open <-chan struct{}
	r    io.Reader
}

func (g gatedInput) Read(p []byte) (int, error) {
	<-g.open
	return g.r.Read(p)
}

func (b *recordingBackend) Stop(time.Duration) error { return nil }

func (b *recordingBackend) commands() []any {
	b.mu.Lock()
	defer b.mu.Unlock()
	return append([]any(nil), b.sent...)
}

type syncBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.String()
}

func TestProgramRunsWithoutATerminal(t *testing.T) {
	fb := newRecordingBackend()
	var out syncBuffer
	program := tea.NewProgram(app.New(fb),
		tea.WithInput(gatedInput{open: fb.listed, r: strings.NewReader("hola\r")}),
		tea.WithOutput(&out),
		tea.WithoutSignalHandler(),
	)
	done := make(chan error, 1)
	go func() {
		_, err := program.Run()
		done <- err
	}()

	program.Send(tea.WindowSizeMsg{Width: 80, Height: 24})
	timeout := time.After(5 * time.Second)
	for !strings.Contains(out.String(), endMarker) {
		select {
		case err := <-done:
			t.Fatalf("the program returned before the turn ended: %v", err)
		case <-timeout:
			program.Kill()
			t.Fatal("the turn did not reach the output")
		case <-time.After(10 * time.Millisecond):
		}
	}

	program.Send(tea.KeyMsg{Type: tea.KeyCtrlC})
	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("Run = %v", err)
		}
	case <-time.After(5 * time.Second):
		program.Kill()
		t.Fatal("the program did not return after ctrl+c")
	}

	if !strings.Contains(out.String(), "hola") {
		t.Error("output does not show the typed line")
	}
	if want := []any{protocol.NewListModels(true), protocol.NewInput("hola"), protocol.NewQuit()}; !reflect.DeepEqual(fb.commands(), want) {
		t.Errorf("commands = %v, want %v", fb.commands(), want)
	}
}
