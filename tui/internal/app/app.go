package app

import (
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
	"phycode/tui/internal/view"
)

type Backend interface {
	Events() <-chan any
	Send(command any) error
	Stop(timeout time.Duration) error
}

type App struct {
	backend  Backend
	events   <-chan any
	state    state.Model
	renderer *view.Renderer
	palette  string
	ticking  bool
	flash    string
	flashAt  time.Time
}

const flashLife = 4 * time.Second

var _ tea.Model = App{}

func New(b Backend) App {
	return App{
		backend:  b,
		events:   b.Events(),
		state:    state.New(),
		renderer: view.NewRenderer(theme.Default()),
	}
}

type eventMsg struct{ value any }

func (a App) Init() tea.Cmd {
	return waitForEvent(a.events)
}

func (a App) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	var cmds []tea.Cmd
	var value any = msg
	if e, ok := msg.(eventMsg); ok {
		value = e.value
		cmds = append(cmds, waitForEvent(a.events))
	}

	var commands []any
	a.state, commands = Update(a.state, value)
	a.expireFlash(value)
	commands = catalogueCommands(&a.state, value, commands)
	a.restyle()
	for _, command := range commands {
		if ed, ok := command.(openEditor); ok {
			cmds = append(cmds, editCommand(ed))
			continue
		}
		if run, ok := command.(runProgram); ok {
			cmds = append(cmds, programCommand(run))
			continue
		}
		if c, ok := command.(copyText); ok {
			cmds = append(cmds, copyCommand(c))
			continue
		}
		if e, ok := command.(exportFile); ok {
			cmds = append(cmds, exportCommand(e))
			continue
		}
		if err := a.backend.Send(command); err != nil {
			a.state.Flash = "could not reach the backend"
		}
	}
	if a.state.Quit {
		return a, tea.Quit
	}

	if _, ok := value.(Tick); ok {
		a.ticking = false
	}
	if (a.state.Working() || a.state.Flash != "") && !a.ticking {
		a.ticking = true
		cmds = append(cmds, tick())
	}
	cmds = append(cmds, catalogueTimer(value))
	return a, tea.Batch(cmds...)
}

func (a *App) expireFlash(value any) {
	if a.state.Flash != a.flash {
		a.flash, a.flashAt = a.state.Flash, clock()
		return
	}
	if _, ok := value.(Tick); ok && a.flash != "" && clock().Sub(a.flashAt) >= flashLife {
		a.state.Flash, a.flash = "", ""
	}
}

func (a *App) restyle() {
	if a.state.Theme != a.palette {
		a.palette = a.state.Theme
		a.renderer = view.NewRenderer(theme.Build(theme.Lookup(a.palette)))
	}
}

func (a App) View() string {
	return a.renderer.View(a.state)
}

func (a App) Failure() string {
	return a.state.Error
}

func waitForEvent(events <-chan any) tea.Cmd {
	return func() tea.Msg {
		value, ok := <-events
		if !ok {
			return nil
		}
		return eventMsg{value: value}
	}
}

func tick() tea.Cmd {
	return tea.Tick(100*time.Millisecond, func(time.Time) tea.Msg { return Tick{} })
}
