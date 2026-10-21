package app

import (
	"errors"
	"fmt"
	"strings"
	"time"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/backend"
	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

type Tick struct{}

func Update(m state.Model, msg any) (state.Model, []any) {
	m, cmds := apply(m, msg)
	if m.Panel.Open && m.Panel.Kind == "tasks" {
		m.Panel = m.Panel.WithTasks(m, time.Now())
	}
	if m.Panel.Open && m.Panel.Kind == "themes" {
		m = previewTheme(m)
	}
	return m, cmds
}

func apply(m state.Model, msg any) (state.Model, []any) {
	switch msg := msg.(type) {
	case *protocol.Ready:
		return ready(m, msg)
	case *protocol.TurnStart:
		m.Busy = true
		m.TurnStarted = time.Now()
		m.Changes = nil
	case *protocol.Text:
		m.Blocks = appendChunk(m.Blocks, msg)
		m.Autocompacting = false
	case *protocol.ToolStart:
		m.Autocompacting = false
		m.Blocks = appendCard(m.Blocks, msg.Parent, state.Block{
			Kind: state.KindTool, ID: msg.ID, Agent: msg.Agent, Depth: msg.Depth,
			Tool: msg.Tool, Summary: msg.Summary, Status: "running",
		})
	case *protocol.ToolEnd:
		endTool(m.Blocks, msg)
	case *protocol.SubagentStart:
		m.Blocks = subagentStarted(m.Blocks, msg)
	case *protocol.SubagentEnd:
		if ended, ok := endSubagent(m.Blocks, msg); ok && m.Scroll > 0 {
			m.Flash = "◆ " + ended.Agent + " " + msg.Status + " · " + state.FormatElapsed(ended.Elapsed(time.Now()))
		}
	case *protocol.Notice:
		m.Blocks = appendCard(m.Blocks, msg.Parent, state.Block{Kind: state.KindNotice, Level: msg.Level, Text: msg.Message})
		if strings.HasPrefix(msg.Message, autocompactPrefix) {
			m.Autocompacting = true
		}
		if msg.Level == "warn" || msg.Level == "error" {
			m = refused(m, msg.Message)
		}
	case *protocol.Output:
		m.Blocks = appendCard(m.Blocks, msg.Parent, state.Block{Kind: state.KindOutput, Text: msg.Text})
		m = researchFinished(m)
	case *protocol.Panel:
		m = panelArrived(m, msg)
	case *protocol.Confirm:
		m.Confirm = &state.Confirm{
			ID:       msg.ID,
			Tool:     msg.Tool,
			Category: msg.Category,
			Agent:    msg.Agent,
			Depth:    msg.Depth,
			Summary:  msg.Summary,
			Preview:  msg.Preview,
		}
	case *protocol.Ask:
		m.Ask = state.NewAsk(msg)
	case *protocol.Exec:
		m = execRequested(m, msg)
	case *protocol.Config:
		m = configArrived(m, msg)
	case *protocol.Sessions:
		m = sessionsArrived(m, msg)
	case *protocol.Context:
		m = contextArrived(m, msg)
	case *protocol.Effort:
		m = effortArrived(m, msg)
	case *protocol.Progress:
		m = progressArrived(m, msg)
	case editorDone:
		m = editorFinished(m, msg)
	case copyDone:
		m = copied(m, msg)
	case exportDone:
		m = exported(m, msg)
	case *protocol.AgentChanged:
		m.App.Agent = msg.Agent
	case programDone:
		m = programFinished(m, msg)
	case *protocol.TurnEnd:
		return turnEnded(m, msg)
	case *protocol.Models:
		m = modelsArrived(m, msg)
	case *protocol.Endpoints:
		m.Picker.Detected, m.Picker.Detecting = msg.Items, false
	case *protocol.SearchProviders:
		m = webArrived(m, msg)
	case *protocol.ModelChanged:
		m = modelChanged(m, msg)
	case *protocol.Bye:
		m.Quit = true
		m.Busy = false
		m.Confirm = nil
		m.Ask = nil
		m.Exec = nil
	case *protocol.Fatal:
		m.Error = msg.Message
		if m.Error == "" {
			m.Error = "the backend stopped with an error and gave no message"
		}
		m.Busy = false
		m.Confirm = nil
		m.Ask = nil
		m.Exec = nil
		m.Quit = true
	case *backend.Closed:
		if !m.Quit {
			m.Error = closedMessage(msg)
			m.Quit = true
			m.Busy = false
			m.Confirm = nil
			m.Ask = nil
			m.Exec = nil
		}
	case *backend.BadLine:
		m.Blocks = append(m.Blocks, state.Block{
			Kind: state.KindNotice, Level: "warn", Text: "the backend sent a line that is not JSON",
		})
	case Tick:
		if m.Working() {
			m.Frame++
		}
	case tea.WindowSizeMsg:
		m.Width = msg.Width
		m.Height = msg.Height
	case tea.KeyMsg:
		return handleKey(m, msg)
	}
	return m, nil
}

func ready(m state.Model, ev *protocol.Ready) (state.Model, []any) {
	if ev.Protocol < protocol.Version {
		m.Error = fmt.Sprintf("the backend speaks protocol %d, but this interface needs protocol %d: update phycode so that both match",
			ev.Protocol, protocol.Version)
		m.Quit = true
		return m, nil
	}
	m.App = ev.App
	m.Effort.Level = protocol.CanonicalLevel(ev.App.Thinking)
	m.Agents = ev.Agents
	m.Commands = ev.Commands
	m.Capabilities = ev.Capabilities
	m.Branch = valueOf(ev.App.Branch)
	m.Theme = ev.Theme
	m.Editor = valueOf(ev.Editor)
	m.Ready = true
	return m, nil
}

const autocompactPrefix = "autocompact:"

func refused(m state.Model, message string) state.Model {
	if m.Picker.Open && m.Picker.Connect != nil && m.Picker.Connect.Pending {
		m, c := ownConnect(m)
		c.Error, c.Pending, c.Removing = message, false, ""
		m.Picker.Pending = false
		return compactionEnded(m)
	}
	if m.Picker.Open && (m.Picker.Loading || m.Picker.Pending) {
		m.Picker.Error = message
		m.Picker.Loading = false
		m.Picker.Applying = false
	}
	if m.Panel.Open && m.Panel.Kind == "web" && m.Panel.Web != nil {
		m = webRefused(m, message)
	}
	if m.Panel.Open {
		m.Flash = message
		m.Panel.Loading = false
	}
	return compactionEnded(m)
}

func turnEnded(m state.Model, ev *protocol.TurnEnd) (state.Model, []any) {
	m.Busy = false
	m = compactionEnded(m)
	m.Autocompacting = false
	m.Confirm = nil
	m.Ask = nil
	m.Changes = ev.Changes
	m.Flash = ""
	m.Branch = valueOf(ev.Branch)
	m.Usage = ev.Usage
	if ev.Status == "cancelled" && len(m.Queue) > 0 {
		m.QueuePaused = true
		m.Flash = "queue paused after interrupt · enter sends the next one"
	}
	var cmds []any
	if !m.QueuePaused {
		m, cmds = sendQueued(m)
	}
	cmds = catalogueAfterTurn(&m, cmds)
	return m, cmds
}

func appendChunk(blocks []state.Block, ev *protocol.Text) []state.Block {
	if n := len(blocks); n > 0 {
		last := &blocks[n-1]
		if last.Kind == state.KindAssistant && last.Depth == 0 && last.Agent == ev.Agent {
			last.Text += ev.Chunk
			return blocks
		}
	}
	return append(blocks, state.Block{Kind: state.KindAssistant, Agent: ev.Agent, Text: ev.Chunk})
}

func endTool(blocks []state.Block, ev *protocol.ToolEnd) {
	for i := len(blocks) - 1; i >= 0; i-- {
		if b := &blocks[i]; b.Kind == state.KindTool && b.ID == ev.ID {
			b.Text = ev.Summary
			b.Diff = ev.Diff
			b.Status = "error"
			if ev.OK {
				b.Status = "ok"
			}
			return
		}
	}
}

func endSubagent(blocks []state.Block, ev *protocol.SubagentEnd) (state.Block, bool) {
	for i := len(blocks) - 1; i >= 0; i-- {
		if b := &blocks[i]; b.Kind == state.KindSubagent && b.ID == ev.ID {
			b.Status = ev.Status
			b.Summary = ev.Summary
			b.Ended = time.Now()
			return *b, true
		}
	}
	return state.Block{}, false
}

func subagentStarted(blocks []state.Block, ev *protocol.SubagentStart) []state.Block {
	b := state.Block{Kind: state.KindSubagent, ID: ev.ID, Agent: ev.Agent, Text: ev.Task, Status: "running", Started: time.Now()}
	if i := openSubagent(blocks); i >= 0 && blocks[i].Agent == ev.Parent {
		b = under(blocks[i], b)
	}
	return append(blocks, b)
}

func appendCard(blocks []state.Block, parent string, b state.Block) []state.Block {
	if parent != "" {
		if i := openSubagent(blocks); i >= 0 {
			b = under(blocks[i], b)
		}
	}
	return append(blocks, b)
}

func under(sub, b state.Block) state.Block {
	b.Parent, b.Depth = sub.ID, sub.Depth+1
	return b
}

func openSubagent(blocks []state.Block) int {
	for i := len(blocks) - 1; i >= 0; i-- {
		if b := blocks[i]; b.Kind == state.KindSubagent && b.Status == "running" {
			return i
		}
	}
	return -1
}

func valueOf(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func closedMessage(ev *backend.Closed) string {
	var long *backend.LineTooLong
	if errors.As(ev.Err, &long) {
		return "the backend stopped: " + long.Error()
	}
	text := "the backend stopped"
	if line := lastLine(ev.Stderr); line != "" {
		text += ": " + line
	}
	return text
}

func lastLine(text string) string {
	lines := strings.Split(text, "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if line := strings.TrimSpace(lines[i]); line != "" {
			return line
		}
	}
	return ""
}
