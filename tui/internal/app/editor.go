package app

import (
	"os"
	"os/exec"
	"slices"
	"strings"
	"unicode/utf8"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/state"
)

type openEditor struct {
	Editor string
	Path   string
	Text   string
}

type editorDone struct {
	Temp bool
	Text string
	Err  error
}

func openComposerEditor(m state.Model) (state.Model, []any) {
	return m, []any{openEditor{Editor: m.Editor, Text: m.Input}}
}

func editorFinished(m state.Model, ev editorDone) state.Model {
	if ev.Err != nil {
		m.Flash = "editor: " + ev.Err.Error()
		return m
	}
	if ev.Temp {
		m.Input = strings.TrimSuffix(cleanText(ev.Text), "\n")
		m.Cursor = utf8.RuneCountInString(m.Input)
		m.HistoryIndex = -1
		m = refresh(m)
	}
	return m
}

func editCommand(ed openEditor) tea.Cmd {
	return func() tea.Msg {
		cmd, finish, err := newEditSession(ed)
		if err != nil {
			return editorDone{Err: err}
		}
		return tea.ExecProcess(cmd, func(runErr error) tea.Msg { return finish(runErr) })()
	}
}

func newEditSession(ed openEditor) (*exec.Cmd, func(error) editorDone, error) {
	path, temp := ed.Path, ed.Path == ""
	if temp {
		f, err := os.CreateTemp("", "phycode-*.md")
		if err != nil {
			return nil, nil, err
		}
		if _, err := f.WriteString(ed.Text); err != nil {
			f.Close()
			os.Remove(f.Name())
			return nil, nil, err
		}
		if err := f.Close(); err != nil {
			os.Remove(f.Name())
			return nil, nil, err
		}
		path = f.Name()
	}
	finish := func(runErr error) editorDone {
		done := editorDone{Err: runErr}
		if temp {
			data, err := os.ReadFile(path)
			os.Remove(path)
			if err != nil && runErr == nil {
				done.Err = err
			}
			done.Temp, done.Text = true, string(data)
		}
		return done
	}
	return editorCommand(resolveEditor(ed.Editor), path), finish, nil
}

func editorCommand(editor, path string) *exec.Cmd {
	words := strings.Fields(editor)
	cmd := exec.Command(words[0], append(words[1:], path)...)
	cmd.Env = editorEnv(os.Environ())
	return cmd
}

var editorVars = []string{
	"PATH", "HOME", "USER", "LOGNAME", "SHELL", "TERM", "LANG", "TMPDIR", "TMP", "TEMP", "EDITOR", "VISUAL",
	"XDG_CONFIG_HOME", "XDG_DATA_HOME", "XDG_CACHE_HOME", "XDG_STATE_HOME", "XDG_RUNTIME_DIR",
	"DISPLAY", "WAYLAND_DISPLAY", "XAUTHORITY", "DBUS_SESSION_BUS_ADDRESS",
	"SYSTEMROOT", "SYSTEMDRIVE", "WINDIR", "COMSPEC", "PATHEXT", "USERNAME", "USERDOMAIN", "USERPROFILE",
	"COMPUTERNAME", "HOMEDRIVE", "HOMEPATH", "APPDATA", "LOCALAPPDATA", "PROGRAMDATA", "PROGRAMFILES",
	"PROGRAMFILES(X86)", "COMMONPROGRAMFILES", "PROCESSOR_ARCHITECTURE",
}

func editorEnv(environ []string) []string {
	env := []string{}
	for _, entry := range environ {
		name, _, _ := strings.Cut(entry, "=")
		name = strings.ToUpper(name)
		if slices.Contains(editorVars, name) || strings.HasPrefix(name, "LC_") {
			env = append(env, entry)
		}
	}
	return env
}

func resolveEditor(configured string) string {
	for _, e := range []string{configured, os.Getenv("EDITOR")} {
		if e = strings.TrimSpace(e); e != "" {
			return e
		}
	}
	return "nano"
}
