package main

import (
	"fmt"
	"io"
	"os"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/app"
	"phycode/tui/internal/backend"
	"phycode/tui/internal/view"
)

const stopTimeout = 2 * time.Second

func main() {
	os.Exit(run(os.Args[1:]))
}

func run(args []string) int {
	if len(args) < 2 || args[0] != "--" {
		fmt.Fprintln(os.Stderr, "usage: phycode-tui -- <backend command> [arguments...]")
		return 2
	}

	proc, err := backend.Start(args[1:])
	if err != nil {
		fmt.Fprintln(os.Stderr, "phycode-tui: cannot start the backend:", err)
		return 1
	}

	final, err := tea.NewProgram(app.New(proc), tea.WithAltScreen()).Run()
	stopErr := proc.Stop(stopTimeout)
	return report(os.Stderr, final, err, stopErr)
}

func report(stderr io.Writer, final tea.Model, runErr, stopErr error) int {
	if runErr != nil {
		fmt.Fprintln(stderr, "phycode-tui:", runErr)
		return 1
	}
	model, ok := final.(app.App)
	if !ok {
		fmt.Fprintln(stderr, "phycode-tui: the interface ended unexpectedly")
		return 1
	}
	if failure := model.Failure(); failure != "" {
		fmt.Fprintln(stderr, "phycode-tui:", view.Sanitize(failure))
		return 1
	}
	if stopErr != nil {
		fmt.Fprintln(stderr, "phycode-tui: backend:", stopErr)
		return 1
	}
	return 0
}
