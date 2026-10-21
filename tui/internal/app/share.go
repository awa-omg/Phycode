package app

import (
	"encoding/base64"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/state"
)

const maxCopyBytes = 60000

type copyText struct{ Text string }

type copyDone struct {
	Bytes int
	Cut   bool
}

func copyCommand(c copyText) tea.Cmd {
	return func() tea.Msg {
		text, cut := c.Text, false
		if len(text) > maxCopyBytes {
			text, cut = strings.ToValidUTF8(text[:maxCopyBytes], ""), true
		}
		seq := "\x1b]52;c;" + base64.StdEncoding.EncodeToString([]byte(text)) + "\x07"
		if os.Getenv("TMUX") != "" {
			seq = "\x1bPtmux;" + strings.ReplaceAll(seq, "\x1b", "\x1b\x1b") + "\x1b\\"
		}
		_, _ = os.Stdout.WriteString(seq)
		return copyDone{Bytes: len(text), Cut: cut}
	}
}

func copied(m state.Model, ev copyDone) state.Model {
	m.Flash = fmt.Sprintf("copied the last reply (%s)", byteSize(ev.Bytes))
	if ev.Cut {
		m.Flash = "copied the start of the last reply (" + byteSize(ev.Bytes) + "), which is too long for the clipboard"
	}
	return m
}

func byteSize(n int) string {
	if n < 1000 {
		return fmt.Sprintf("%d bytes", n)
	}
	return fmt.Sprintf("%.1f kB", float64(n)/1000)
}

func lastReply(m state.Model) (string, bool) {
	for i := len(m.Blocks) - 1; i >= 0; i-- {
		if b := m.Blocks[i]; b.Kind == state.KindAssistant && b.Depth == 0 && strings.TrimSpace(b.Text) != "" {
			return strings.TrimSpace(b.Text), true
		}
	}
	return "", false
}

type exportFile struct {
	Path string
	Text string
}

type exportDone struct {
	Path string
	Err  error
}

func exportCommand(e exportFile) tea.Cmd {
	return func() tea.Msg {
		if err := os.MkdirAll(filepath.Dir(e.Path), 0o700); err != nil {
			return exportDone{Err: err}
		}
		return exportDone{Path: e.Path, Err: writeExport(e.Path, []byte(e.Text))}
	}
}

func writeExport(path string, data []byte) error {
	if info, err := os.Lstat(path); err == nil && info.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("%s is a symbolic link, so nothing was written to it", path)
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|noFollow, 0o600)
	if err != nil {
		return err
	}
	if _, err := f.Write(data); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}

func exported(m state.Model, ev exportDone) state.Model {
	if ev.Err != nil {
		m.Flash = "export failed: " + ev.Err.Error()
		return m
	}
	m.Flash = "exported to " + ev.Path
	return m
}

func exportPath(m state.Model, arg string, now time.Time) string {
	name := arg
	if name == "" {
		name = filepath.Join(".phycode", "exports", now.Format("20060102-150405")+".md")
	}
	if filepath.IsAbs(name) || m.App.Cwd == "" {
		return name
	}
	return filepath.Join(m.App.Cwd, name)
}

func exportMarkdown(m state.Model, now time.Time) string {
	var b strings.Builder
	title := "phycode session"
	if m.Session.Title != "" {
		title = m.Session.Title
	}
	fmt.Fprintf(&b, "# %s\n\n", title)
	fmt.Fprintf(&b, "_%s · %s · %s_\n", now.Format("2006-01-02 15:04"), orDash(m.App.Profile), orDash(m.App.Model))
	for _, blk := range m.Blocks {
		text := strings.TrimSpace(blk.Text)
		switch blk.Kind {
		case state.KindUser:
			fmt.Fprintf(&b, "\n## You\n\n%s\n", text)
		case state.KindAssistant:
			if blk.Depth == 0 && text != "" {
				fmt.Fprintf(&b, "\n## phycode\n\n%s\n", text)
			}
		case state.KindTool:
			fmt.Fprintf(&b, "\n- `%s` %s\n", blk.Tool, strings.ReplaceAll(blk.Summary, "\n", " "))
		case state.KindSubagent:
			fmt.Fprintf(&b, "\n### Subagent %s\n\n%s\n", blk.Agent, text)
			if r := strings.TrimSpace(blk.Summary); r != "" {
				fmt.Fprintf(&b, "\n> %s\n", strings.ReplaceAll(r, "\n", "\n> "))
			}
		case state.KindNotice:
			fmt.Fprintf(&b, "\n> %s\n", strings.ReplaceAll(text, "\n", "\n> "))
		case state.KindOutput:
			fmt.Fprintf(&b, "\n```\n%s\n```\n", text)
		}
	}
	return b.String()
}

func orDash(s string) string {
	if s == "" {
		return "–"
	}
	return s
}
