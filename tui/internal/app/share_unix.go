//go:build unix

package app

import "syscall"

const noFollow = syscall.O_NOFOLLOW
