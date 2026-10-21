package app

import (
	"path/filepath"
	"strings"
	"time"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
)

const deepResearchRounds = 3

func runsDuringTurn(text string) bool {
	name, arg, _ := strings.Cut(text, " ")
	switch name {
	case "/theme", "/export", "/themes":
		return true
	}
	return text == "/models" || text == "/connect" || text == "/web" || text == "/help" || text == "/tasks" || text == "/subagents" || text == "/copy" || text == "/details" ||
		name == "/btw" && strings.TrimSpace(arg) != ""
}

func localCommand(m state.Model, text string) (state.Model, []any, bool) {
	name, arg, _ := strings.Cut(text, " ")
	arg = strings.TrimSpace(arg)
	switch {
	case name == "/theme":
		if arg == "" {
			return themesPanel(m), nil, true
		}
		next, cmds := setTheme(m, arg)
		return next, cmds, true
	case text == "/copy":
		reply, ok := lastReply(m)
		if !ok {
			m.Flash = "nothing to copy yet"
			return m, nil, true
		}
		return m, []any{copyText{Text: reply}}, true
	case name == "/export":
		now := time.Now()
		return m, []any{exportFile{Path: exportPath(m, arg, now), Text: exportMarkdown(m, now)}}, true
	case text == "/details":
		m.ToolsExpanded = !m.ToolsExpanded
		m.Flash = "tool details " + map[bool]string{true: "shown", false: "folded"}[m.ToolsExpanded]
		return m, nil, true
	case text == "/help":
		next, cmds := openCommands(m)
		return next, cmds, true
	case text == "/connect":
		next, cmds := openConnect(m)
		return next, cmds, true
	case text == "/web":
		next, cmds := openWeb(m)
		return next, cmds, true
	case text == "/tasks" || text == "/subagents":
		next, cmds := openTasks(m)
		return next, cmds, true
	case name == "/edit":
		next, cmds := editFile(m, arg)
		return next, cmds, true
	case text == "/sessions":
		m.Panel = state.SessionsPanel()
		return m, []any{protocol.NewSessionList()}, true
	case name == "/compact":
		if m.Compacting {
			m.Flash = "wait for the compaction to finish"
			return m, nil, true
		}
		m = showLine(m, text)
		m.Panel = state.CompactPanel(arg)
		return m, nil, true
	case name == "/btw" && arg != "":
		m = showLine(m, text)
		m.Flash = "btw · the answer opens in a panel"
		return m, []any{protocol.NewBtw(arg)}, true
	case name == "/deepresearch" && arg != "":
		m = showLine(m, text)
		m.Flash = "deep research · its steps open in a panel"
		return m, []any{protocol.NewDeepResearch(arg, deepResearchRounds)}, true
	case text == "/effort" || text == "/context" || text == "/config":
		m = showLine(m, text)
		m.Panel = panelOf(text)
		return m, []any{protocol.NewInput(text)}, true
	}
	return m, nil, false
}

func showLine(m state.Model, text string) state.Model {
	m.Blocks = append(m.Blocks, state.Block{Kind: state.KindUser, Text: text})
	return m
}

func panelOf(command string) state.Panel {
	switch command {
	case "/effort":
		return state.EffortPanel()
	case "/context":
		return state.ContextPanel()
	}
	return state.ConfigPanel()
}

func editFile(m state.Model, path string) (state.Model, []any) {
	if path == "" {
		m.Flash = "usage: /edit <path>"
		return m, nil
	}
	if !filepath.IsAbs(path) && m.App.Cwd != "" {
		path = filepath.Join(m.App.Cwd, path)
	}
	return m, []any{openEditor{Editor: m.Editor, Path: path}}
}
