//go:build unix

package backend

import (
	"bytes"
	"os"
	"strconv"
	"syscall"
	"testing"
	"time"

	"phycode/tui/internal/protocol"
)

func TestStopKillsTheCommandsOfABackendThatIgnoresQuit(t *testing.T) {
	p := start(t, "spawn")
	expect[*protocol.Ready](t, p)
	child, err := strconv.Atoi(expect[*protocol.Notice](t, p).Message)
	if err != nil {
		t.Fatalf("the helper did not report its child: %v", err)
	}
	if err := p.Stop(200 * time.Millisecond); err == nil {
		t.Fatal("Stop returned nil for a backend that had to be killed")
	}
	deadline := time.Now().Add(5 * time.Second)
	for alive(child) {
		if time.Now().After(deadline) {
			t.Fatalf("child %d still runs after Stop", child)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func alive(pid int) bool {
	if err := syscall.Kill(pid, 0); err != nil {
		return false
	}
	stat, err := os.ReadFile("/proc/" + strconv.Itoa(pid) + "/stat")
	if err != nil {
		return true
	}
	end := bytes.LastIndexByte(stat, ')')
	return end < 0 || end+2 >= len(stat) || stat[end+2] != 'Z'
}
