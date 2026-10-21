//go:build !unix

package backend

import (
	"errors"
	"os/exec"
)

func newGroup(*exec.Cmd) {}

func killGroup(int) error { return errors.New("process groups are not supported") }
