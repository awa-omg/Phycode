package backend

import (
	"bufio"
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"sync"
	"time"

	"phycode/tui/internal/protocol"
)

const (
	stderrLimit = 16 << 10
	eventBuffer = 64
	writeBuffer = 256
	waitDelay   = time.Second
	maxLine     = 16 << 20
	keptLine    = 4 << 10
)

var (
	errExited    = errors.New("backend has exited")
	errQueueFull = errors.New("backend is not reading its input: the queue of commands is full")
)

type Process struct {
	cmd     *exec.Cmd
	stdin   io.WriteCloser
	stdout  *os.File
	events  chan any
	writes  chan []byte
	done    chan struct{}
	exited  chan struct{}
	waitErr error
	stderr  stderrTail

	writeMu  sync.Mutex
	closing  bool
	writeErr error
	stopOnce sync.Once
	stopErr  error
}

type Closed struct {
	Err    error
	Stderr string
}

type BadLine struct {
	Line []byte
	Err  error
}

type LineTooLong struct {
	Prefix []byte
}

func (e *LineTooLong) Error() string {
	return fmt.Sprintf("a line of the backend's output is longer than %d MiB", maxLine>>20)
}

func Start(argv []string) (*Process, error) {
	if len(argv) == 0 {
		return nil, errors.New("backend: empty command")
	}
	stdout, stdoutW, err := os.Pipe()
	if err != nil {
		return nil, err
	}
	p := &Process{
		cmd:    exec.Command(argv[0], argv[1:]...),
		stdout: stdout,
		events: make(chan any, eventBuffer),
		writes: make(chan []byte, writeBuffer),
		done:   make(chan struct{}),
		exited: make(chan struct{}),
	}
	p.cmd.Stdout = stdoutW
	p.cmd.Stderr = &p.stderr
	p.cmd.WaitDelay = waitDelay
	newGroup(p.cmd)
	if p.stdin, err = p.cmd.StdinPipe(); err == nil {
		err = p.cmd.Start()
	}
	stdoutW.Close()
	if err != nil {
		stdout.Close()
		return nil, err
	}
	go p.wait()
	go p.writeStdin()
	go p.readStdout()
	return p, nil
}

func (p *Process) Events() <-chan any {
	return p.events
}

func (p *Process) Send(command any) error {
	line, err := protocol.Encode(command)
	if err != nil {
		return err
	}
	p.writeMu.Lock()
	defer p.writeMu.Unlock()
	select {
	case <-p.exited:
		return errExited
	default:
	}
	if p.closing {
		return errExited
	}
	if p.writeErr != nil {
		return p.writeErr
	}
	select {
	case p.writes <- line:
		return nil
	default:
		return errQueueFull
	}
}

func (p *Process) Stop(timeout time.Duration) error {
	p.stopOnce.Do(func() { p.stopErr = p.stop(timeout) })
	return p.stopErr
}

func (p *Process) Stderr() string {
	return p.stderr.String()
}

func (p *Process) stop(timeout time.Duration) error {
	p.Send(protocol.NewQuit())
	p.closeInput()
	timer := time.NewTimer(timeout)
	defer timer.Stop()
	select {
	case <-p.exited:
	case <-timer.C:
		if err := killGroup(p.cmd.Process.Pid); err != nil {
			p.cmd.Process.Kill()
		}
		<-p.exited
	}
	close(p.done)
	p.stdout.Close()
	return p.waitErr
}

func (p *Process) wait() {
	p.waitErr = p.cmd.Wait()
	if errors.Is(p.waitErr, exec.ErrWaitDelay) {
		p.waitErr = nil
	}
	close(p.exited)
}

func (p *Process) closeInput() {
	p.writeMu.Lock()
	defer p.writeMu.Unlock()
	p.closing = true
	close(p.writes)
}

func (p *Process) writeStdin() {
	defer p.stdin.Close()
	for {
		select {
		case line, ok := <-p.writes:
			if !ok {
				return
			}
			if _, err := p.stdin.Write(line); err != nil {
				p.writeMu.Lock()
				p.writeErr = err
				p.writeMu.Unlock()
				return
			}
		case <-p.exited:
			return
		}
	}
}

func readLine(r *bufio.Reader) ([]byte, error) {
	var line []byte
	for {
		part, err := r.ReadSlice('\n')
		if len(line)+len(part) > maxLine {
			return nil, &LineTooLong{Prefix: bytes.Clone(line[:min(len(line), keptLine)])}
		}
		line = append(line, part...)
		if err != bufio.ErrBufferFull {
			return line, err
		}
	}
}

func (p *Process) readStdout() {
	defer close(p.events)
	defer p.stdout.Close()
	r := bufio.NewReader(p.stdout)
	for {
		line, err := readLine(r)
		var long *LineTooLong
		if errors.As(err, &long) {
			p.emit(&Closed{Err: long, Stderr: p.Stderr()})
			return
		}
		line = bytes.TrimRight(line, "\r\n")
		if len(bytes.TrimSpace(line)) > 0 && !p.emit(decode(line)) {
			return
		}
		if err != nil {
			break
		}
	}
	<-p.exited
	p.emit(&Closed{Err: p.waitErr, Stderr: p.Stderr()})
}

func (p *Process) emit(event any) bool {
	select {
	case p.events <- event:
		return true
	case <-p.done:
		return false
	}
}

func decode(line []byte) any {
	event, err := protocol.Parse(line)
	if err != nil {
		return &BadLine{Line: bytes.Clone(line[:min(len(line), keptLine)]), Err: err}
	}
	return event
}

type stderrTail struct {
	mu  sync.Mutex
	buf []byte
}

func (t *stderrTail) Write(b []byte) (int, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.buf = append(t.buf, b...)
	if n := len(t.buf) - stderrLimit; n > 0 {
		t.buf = append(t.buf[:0], t.buf[n:]...)
	}
	return len(b), nil
}

func (t *stderrTail) String() string {
	t.mu.Lock()
	defer t.mu.Unlock()
	return string(t.buf)
}
