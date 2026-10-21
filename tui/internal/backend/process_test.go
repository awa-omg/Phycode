package backend

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"phycode/tui/internal/protocol"
)

const helperEnv = "PHYCODE_BACKEND_HELPER"

const (
	readyLine   = `{"type":"ready","protocol":2,"app":{"version":"test"},"agents":[],"commands":[]}`
	textLine    = `{"type":"text","agent":"main","chunk":"hello"}`
	turnEndLine = `{"type":"turn_end","status":"completed","reason":"","changes":[]}`
)

var helperScripts = map[string][]string{
	"basic":      {readyLine, textLine, turnEndLine},
	"idle":       {readyLine},
	"stubborn":   {readyLine},
	"flood":      {readyLine},
	"crash":      {readyLine},
	"orphan":     {readyLine},
	"spawn":      {readyLine},
	"no-newline": {readyLine},
	"bad-line":   {readyLine, "", "  ", "this is not json\r", textLine},
	"unknown":    {readyLine, `{"type":"future_event","detail":42}`, turnEndLine},
	"long-line":  {readyLine, `{"type":"text","agent":"main","chunk":"` + strings.Repeat("x", 200<<10) + `"}`},
	"mega-line":  {readyLine, `{"type":"text","agent":"main","chunk":"` + strings.Repeat("x", 1<<20) + `"}`},
	"long-bad":   {readyLine, "{" + strings.Repeat("y", 100<<10) + "}", textLine},
	"overflow":   {readyLine},
	"deaf":       {readyLine},
	"many":       append([]string{readyLine}, slices.Repeat([]string{textLine}, 200)...),
}

func TestHelperProcess(t *testing.T) {
	mode := os.Getenv(helperEnv)
	if mode == "" {
		return
	}
	os.Exit(runHelper(mode))
}

func runHelper(mode string) int {
	var child *exec.Cmd
	switch mode {
	case "flood":
		fmt.Fprint(os.Stderr, strings.Repeat("x", 40<<10)+"END")
	case "crash":
		fmt.Fprintln(os.Stderr, "boom")
	case "orphan":
		cmd := exec.Command("sleep", "3")
		cmd.Stderr = os.Stderr
		if err := cmd.Start(); err != nil {
			return 1
		}
	case "spawn":
		child = exec.Command("sleep", "60")
		child.Stderr = os.Stderr
		if err := child.Start(); err != nil {
			return 1
		}
	}
	for _, line := range helperScripts[mode] {
		fmt.Println(line)
	}
	if child != nil {
		report, _ := json.Marshal(protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: strconv.Itoa(child.Process.Pid)})
		fmt.Println(string(report))
	}
	switch mode {
	case "crash":
		return 3
	case "no-newline":
		fmt.Print(textLine)
		return 0
	case "overflow":
		fmt.Print(`{"type":"text","agent":"main","chunk":"` + strings.Repeat("x", 17<<20) + "\n")
		return 0
	case "deaf":
		time.Sleep(time.Hour)
	}
	stubborn := mode == "stubborn" || mode == "spawn"
	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		var command struct {
			Type string `json:"type"`
		}
		json.Unmarshal(scanner.Bytes(), &command)
		if command.Type == protocol.CmdQuit && !stubborn {
			return 0
		}
		notice, _ := json.Marshal(protocol.Notice{Type: protocol.TypeNotice, Level: "info", Message: command.Type})
		fmt.Println(string(notice))
	}
	if stubborn {
		time.Sleep(time.Hour)
	}
	return 0
}

func start(t *testing.T, mode string) *Process {
	t.Helper()
	t.Setenv(helperEnv, mode)
	t.Setenv("GORACE", strings.TrimSpace(os.Getenv("GORACE")+" atexit_sleep_ms=0"))
	p, err := Start([]string{os.Args[0], "-test.run=TestHelperProcess"})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { p.Stop(time.Second) })
	return p
}

func recv(t *testing.T, p *Process) (any, bool) {
	t.Helper()
	select {
	case event, ok := <-p.Events():
		return event, ok
	case <-time.After(5 * time.Second):
		t.Fatal("timed out waiting for an event")
		return nil, false
	}
}

func expect[T any](t *testing.T, p *Process) T {
	t.Helper()
	var want T
	event, ok := recv(t, p)
	if !ok {
		t.Fatalf("events channel closed, want %T", want)
	}
	got, ok := event.(T)
	if !ok {
		t.Fatalf("got %T, want %T", event, want)
	}
	return got
}

func TestEventsArriveInOrder(t *testing.T) {
	p := start(t, "basic")
	if ready := expect[*protocol.Ready](t, p); ready.Protocol != protocol.Version {
		t.Fatalf("protocol = %d, want %d", ready.Protocol, protocol.Version)
	}
	if text := expect[*protocol.Text](t, p); text.Chunk != "hello" {
		t.Fatalf("chunk = %q, want %q", text.Chunk, "hello")
	}
	expect[*protocol.TurnEnd](t, p)
	if err := p.Send(protocol.NewQuit()); err != nil {
		t.Fatal(err)
	}
	if closed := expect[*Closed](t, p); closed.Err != nil {
		t.Fatalf("clean exit reported %v", closed.Err)
	}
	if event, ok := recv(t, p); ok {
		t.Fatalf("got %T after Closed, want a closed channel", event)
	}
}

func TestBadLineDoesNotEndStream(t *testing.T) {
	p := start(t, "bad-line")
	expect[*protocol.Ready](t, p)
	bad := expect[*BadLine](t, p)
	if string(bad.Line) != "this is not json" || bad.Err == nil {
		t.Fatalf("bad line = %q, err = %v", bad.Line, bad.Err)
	}
	if text := expect[*protocol.Text](t, p); text.Chunk != "hello" {
		t.Fatalf("chunk after bad line = %q", text.Chunk)
	}
}

func TestUnknownTypeIsDelivered(t *testing.T) {
	p := start(t, "unknown")
	expect[*protocol.Ready](t, p)
	unknown := expect[*protocol.Unknown](t, p)
	if unknown.Type != "future_event" || string(unknown.Raw) != `{"type":"future_event","detail":42}` {
		t.Fatalf("unknown = %q %q", unknown.Type, unknown.Raw)
	}
	expect[*protocol.TurnEnd](t, p)
}

func TestFinalLineWithoutNewlineIsDelivered(t *testing.T) {
	p := start(t, "no-newline")
	expect[*protocol.Ready](t, p)
	if text := expect[*protocol.Text](t, p); text.Chunk != "hello" {
		t.Fatalf("chunk = %q", text.Chunk)
	}
	expect[*Closed](t, p)
}

func TestSendReachesBackend(t *testing.T) {
	p := start(t, "idle")
	expect[*protocol.Ready](t, p)
	if err := p.Send(protocol.NewInput("hi")); err != nil {
		t.Fatal(err)
	}
	if got := expect[*protocol.Notice](t, p).Message; got != protocol.CmdInput {
		t.Fatalf("backend echoed %q, want %q", got, protocol.CmdInput)
	}
	if err := p.Send(protocol.NewInterrupt()); err != nil {
		t.Fatal(err)
	}
	if got := expect[*protocol.Notice](t, p).Message; got != protocol.CmdInterrupt {
		t.Fatalf("backend echoed %q, want %q", got, protocol.CmdInterrupt)
	}
}

func TestConcurrentSendsStayWholeLines(t *testing.T) {
	p := start(t, "idle")
	expect[*protocol.Ready](t, p)
	var wg sync.WaitGroup
	for range 2 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for range 50 {
				if err := p.Send(protocol.NewInput("hi")); err != nil {
					t.Error(err)
					return
				}
			}
		}()
	}
	wg.Wait()
	for range 100 {
		if got := expect[*protocol.Notice](t, p).Message; got != protocol.CmdInput {
			t.Fatalf("backend echoed %q, want %q", got, protocol.CmdInput)
		}
	}
}

func TestStopReturnsNilOnCleanExit(t *testing.T) {
	p := start(t, "idle")
	expect[*protocol.Ready](t, p)
	if err := p.Stop(5 * time.Second); err != nil {
		t.Fatalf("Stop = %v", err)
	}
	if state := p.cmd.ProcessState; state == nil || !state.Exited() || state.ExitCode() != 0 {
		t.Fatalf("process state = %v, want exit status 0", state)
	}
	if err := p.Stop(5 * time.Second); err != nil {
		t.Fatalf("second Stop = %v", err)
	}
}

func TestStopKillsBackendThatIgnoresQuit(t *testing.T) {
	p := start(t, "stubborn")
	expect[*protocol.Ready](t, p)
	began := time.Now()
	err := p.Stop(200 * time.Millisecond)
	if elapsed := time.Since(began); err == nil || elapsed < 200*time.Millisecond || elapsed > 5*time.Second {
		t.Fatalf("Stop = %v after %v", err, time.Since(began))
	}
	if state := p.cmd.ProcessState; state == nil || state.Exited() {
		t.Fatalf("process state = %v, want killed by a signal", state)
	}
	if again := p.Stop(time.Second); again != err {
		t.Fatalf("second Stop = %v, want %v", again, err)
	}
}

func TestSendFailsAfterExit(t *testing.T) {
	p := start(t, "idle")
	expect[*protocol.Ready](t, p)
	if err := p.Send(protocol.NewQuit()); err != nil {
		t.Fatal(err)
	}
	expect[*Closed](t, p)
	if err := p.Send(protocol.NewInput("late")); err == nil {
		t.Fatal("Send after exit returned nil")
	}
}

func TestCleanExitIgnoresStderrHeldByChild(t *testing.T) {
	p := start(t, "orphan")
	expect[*protocol.Ready](t, p)
	if err := p.Stop(5 * time.Second); err != nil {
		t.Fatalf("Stop = %v", err)
	}
}

func TestStderrIsCaptured(t *testing.T) {
	p := start(t, "crash")
	expect[*protocol.Ready](t, p)
	closed := expect[*Closed](t, p)
	if closed.Err == nil || !strings.Contains(closed.Stderr, "boom") {
		t.Fatalf("closed = %+v", closed)
	}
	if got := p.Stderr(); !strings.Contains(got, "boom") {
		t.Fatalf("Stderr() = %q", got)
	}
	if err := p.Stop(time.Second); err == nil {
		t.Fatal("Stop returned nil after a non-zero exit")
	}
}

func TestStderrKeepsLastBytes(t *testing.T) {
	p := start(t, "flood")
	expect[*protocol.Ready](t, p)
	if err := p.Stop(5 * time.Second); err != nil {
		t.Fatalf("Stop = %v", err)
	}
	if got := p.Stderr(); len(got) != stderrLimit || !strings.HasSuffix(got, "END") {
		t.Fatalf("Stderr() is %d bytes, want the last %d ending in END", len(got), stderrLimit)
	}
}

func TestLongLineIsNotTruncated(t *testing.T) {
	p := start(t, "long-line")
	expect[*protocol.Ready](t, p)
	if text := expect[*protocol.Text](t, p); len(text.Chunk) != 200<<10 {
		t.Fatalf("chunk is %d bytes, want %d", len(text.Chunk), 200<<10)
	}
}

func TestLongValidLineBelowTheCapIsParsed(t *testing.T) {
	p := start(t, "mega-line")
	expect[*protocol.Ready](t, p)
	if text := expect[*protocol.Text](t, p); len(text.Chunk) != 1<<20 {
		t.Fatalf("chunk is %d bytes, want %d", len(text.Chunk), 1<<20)
	}
}

func TestLineLongerThanTheCapEndsTheStream(t *testing.T) {
	p := start(t, "overflow")
	expect[*protocol.Ready](t, p)
	closed := expect[*Closed](t, p)
	var long *LineTooLong
	if !errors.As(closed.Err, &long) {
		t.Fatalf("Closed.Err = %v, want a *LineTooLong", closed.Err)
	}
	if !bytes.HasPrefix(long.Prefix, []byte(`{"type":"text","agent":"main"`)) || len(long.Prefix) != keptLine {
		t.Errorf("prefix is %d bytes, want the first %d bytes of the line", len(long.Prefix), keptLine)
	}
	if event, ok := recv(t, p); ok {
		t.Fatalf("got %T after Closed, want a closed channel", event)
	}
}

func TestBadLineKeepsOnlyItsStart(t *testing.T) {
	p := start(t, "long-bad")
	expect[*protocol.Ready](t, p)
	if bad := expect[*BadLine](t, p); len(bad.Line) != keptLine {
		t.Errorf("bad line keeps %d bytes, want %d", len(bad.Line), keptLine)
	}
	expect[*protocol.Text](t, p)
}

func TestSendDoesNotWaitForABackendThatNeverReads(t *testing.T) {
	p := start(t, "deaf")
	expect[*protocol.Ready](t, p)
	payload := strings.Repeat("x", 4<<10)
	began := time.Now()
	var err error
	for i := 0; i < 10000 && err == nil; i++ {
		err = p.Send(protocol.NewInput(payload))
	}
	if !errors.Is(err, errQueueFull) {
		t.Fatalf("Send returned %v once the backend stopped reading, want a full queue", err)
	}
	if elapsed := time.Since(began); elapsed > 5*time.Second {
		t.Errorf("the sends took %v", elapsed)
	}
}

func TestCommandsReachTheBackendInTheOrderSent(t *testing.T) {
	p := start(t, "idle")
	expect[*protocol.Ready](t, p)
	for _, command := range []any{protocol.NewInput("1"), protocol.NewInterrupt(), protocol.NewInput("2")} {
		if err := p.Send(command); err != nil {
			t.Fatal(err)
		}
	}
	for _, want := range []string{protocol.CmdInput, protocol.CmdInterrupt, protocol.CmdInput} {
		if got := expect[*protocol.Notice](t, p).Message; got != want {
			t.Fatalf("backend echoed %q, want %q", got, want)
		}
	}
}

func TestStopDoesNotWaitForUnreadEvents(t *testing.T) {
	p := start(t, "many")
	if err := p.Stop(5 * time.Second); err != nil {
		t.Fatalf("Stop = %v", err)
	}
	for {
		if _, ok := recv(t, p); !ok {
			break
		}
	}
}

func TestStartRejectsMissingProgram(t *testing.T) {
	if _, err := Start(nil); err == nil {
		t.Fatal("Start(nil) succeeded")
	}
	if _, err := Start([]string{"/nonexistent/phycode-backend"}); err == nil {
		t.Fatal("Start of a missing program succeeded")
	}
}

func TestInterruptLeavesTheBackendRunning(t *testing.T) {
	p := start(t, "idle")
	expect[*protocol.Ready](t, p)
	for range 2 {
		if err := p.Send(protocol.NewInterrupt()); err != nil {
			t.Fatalf("Send(interrupt) = %v", err)
		}
		if got := expect[*protocol.Notice](t, p); got.Message != protocol.CmdInterrupt {
			t.Fatalf("echo = %q, want interrupt", got.Message)
		}
	}
	if err := p.Stop(5 * time.Second); err != nil {
		t.Fatalf("Stop = %v, want a clean exit", err)
	}
	if state := p.cmd.ProcessState; state == nil || !state.Exited() || state.ExitCode() != 0 {
		t.Fatalf("process state = %v, want exit status 0", state)
	}
}
