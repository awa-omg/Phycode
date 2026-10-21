//go:build unix

package backend

import (
	"os/exec"
	"syscall"
)

func newGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

func killGroup(pid int) error {
	return syscall.Kill(-pid, syscall.SIGKILL)
}
