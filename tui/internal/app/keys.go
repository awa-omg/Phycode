package app

import (
	"strings"
	"unicode"
	"unicode/utf8"

	tea "github.com/charmbracelet/bubbletea"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/view"
)

func handleKey(m state.Model, msg tea.KeyMsg) (state.Model, []any) {
	if m.Confirm != nil {
		return answerConfirm(m, msg)
	}
	if m.Ask != nil {
		return handleAskKey(m, msg)
	}
	if m.Exec != nil {
		return handleExecKey(m, msg)
	}
	if m.Panel.Open {
		return handlePanelKey(m, msg)
	}
	if m.Picker.Open {
		return handlePickerKey(m, msg)
	}
	m.Cursor = clamp(m.Cursor, utf8.RuneCountInString(m.Input))
	if name := keyName(msg); name != "" {
		return handleNamedKey(m, name)
	}
	return insertText(m, cleanText(string(msg.Runes))), nil
}

func keyName(msg tea.KeyMsg) string {
	if msg.Paste {
		return ""
	}
	if msg.Type == tea.KeyRunes || msg.Type == tea.KeySpace {
		if msg.Alt && (string(msg.Runes) == "b" || string(msg.Runes) == "f") {
			return msg.String()
		}
		return ""
	}
	return msg.String()
}

func handleNamedKey(m state.Model, name string) (state.Model, []any) {
	switch name {
	case "enter":
		return enter(m)
	case "alt+enter", "ctrl+j":
		return insertText(m, "\n"), nil
	case "tab":
		if s, ok := selectedSuggestion(m); ok {
			return accept(m, s), nil
		}
		return cycleAgent(m)
	case "shift+tab":
		return cycleEffort(m)
	case "ctrl+l":
		return openPicker(m)
	case "esc":
		if len(m.Suggestions) > 0 || m.NoMatch != "" {
			m.Suggestions = nil
			m.Selected = 0
			m.NoMatch = ""
		} else if m.Working() {
			return interrupt(m)
		} else if m.ShellMode {
			m.ShellMode = false
		}
	case "ctrl+c":
		switch {
		case m.Working():
			return interrupt(m)
		case m.Input != "":
			return clearInput(m), nil
		case m.ShellMode:
			m.ShellMode = false
			return m, nil
		}
		return quit(m)
	case "ctrl+p":
		return openCommands(m)
	case "ctrl+g":
		return openTasks(m)
	case "ctrl+o":
		m.ToolsExpanded = !m.ToolsExpanded
	case "ctrl+t":
		m = foldLastSubagent(m)
	case "ctrl+d":
		if m.Input == "" && !m.Working() {
			return quit(m)
		}
		return deleteRange(m, m.Cursor, m.Cursor+1), nil
	case "up":
		switch {
		case len(m.Suggestions) > 0:
			m = moveSelection(m, -1)
		case strings.Contains(m.Input, "\n"):
			m = moveLine(m, -1)
		default:
			m = historyUp(m)
		}
	case "down":
		switch {
		case len(m.Suggestions) > 0:
			m = moveSelection(m, 1)
		case strings.Contains(m.Input, "\n"):
			m = moveLine(m, 1)
		default:
			m = historyDown(m)
		}
	case "alt+up":
		if len(m.Queue) > 0 {
			m = restoreQueue(m)
		} else {
			m = scrollBy(m, 1)
		}
	case "alt+down":
		m = scrollBy(m, -1)
	case "pgup":
		m = scrollBy(m, pageSize(m))
	case "pgdown":
		m = scrollBy(m, -pageSize(m))
	case "left", "ctrl+b":
		m.Cursor = max(0, m.Cursor-1)
	case "right", "ctrl+f":
		m.Cursor = min(m.Cursor+1, utf8.RuneCountInString(m.Input))
	case "alt+left", "alt+b":
		m.Cursor = wordStart([]rune(m.Input), m.Cursor)
	case "alt+right", "alt+f":
		m.Cursor = wordEnd([]rune(m.Input), m.Cursor)
	case "home", "ctrl+a":
		m.Cursor = 0
	case "end":
		m.Cursor = utf8.RuneCountInString(m.Input)
	case "ctrl+e":
		return openComposerEditor(m)
	case "backspace", "ctrl+h":
		if m.ShellMode && m.Cursor == 0 {
			m.ShellMode = false
			return refresh(m), nil
		}
		return deleteRange(m, m.Cursor-1, m.Cursor), nil
	case "delete":
		return deleteRange(m, m.Cursor, m.Cursor+1), nil
	case "ctrl+w", "alt+backspace":
		return deleteRange(m, wordStart([]rune(m.Input), m.Cursor), m.Cursor), nil
	case "ctrl+u":
		return deleteRange(m, 0, m.Cursor), nil
	case "ctrl+k":
		return deleteRange(m, m.Cursor, utf8.RuneCountInString(m.Input)), nil
	}
	return m, nil
}

func enter(m state.Model) (state.Model, []any) {
	s, ok := selectedSuggestion(m)
	if !ok || m.Input == s.Insert || strings.Contains(m.Input, " ") && !strings.HasPrefix(s.Insert, m.Input) {
		return submit(m)
	}
	if optionalArgument(s) && m.Input == strings.TrimSuffix(s.Insert, " ") {
		return submit(m)
	}
	if s.Kind == state.SuggestChoice && strings.HasSuffix(m.Input, " ") {
		return submit(m)
	}
	m = accept(m, s)
	if strings.HasSuffix(s.Insert, " ") {
		return m, nil
	}
	return submit(m)
}

func optionalArgument(s state.Suggestion) bool {
	_, args, found := strings.Cut(s.Label, " ")
	return found && strings.HasPrefix(args, "[")
}

func submit(m state.Model) (state.Model, []any) {
	text := strings.Trim(m.Input, " \t\r\n\x00\x0B")
	if m.ShellMode && text != "" {
		text = "!" + text
	}
	if text == "" {
		m.ShellMode = false
		return resume(m)
	}
	if m.Working() && !runsDuringTurn(text) {
		m.ShellMode = false
		return enqueue(m, text), nil
	}
	m = remember(m, text)
	m.ShellMode = false
	m.Input = ""
	m.Cursor = 0
	m.Suggestions = nil
	m.Selected = 0
	m.NoMatch = ""
	m.Scroll = 0
	m.Flash = ""
	if text == "/models" {
		return openPicker(m)
	}
	m.QueuePaused = false
	m, cmds := sendLine(m, text)
	if !m.Working() {
		var queued []any
		m, queued = sendQueued(m)
		cmds = append(cmds, queued...)
	}
	return m, cmds
}

func remember(m state.Model, text string) state.Model {
	if n := len(m.History); n == 0 || m.History[n-1] != text {
		m.History = append(m.History, text)
	}
	m.HistoryIndex = -1
	return m
}

func answerConfirm(m state.Model, msg tea.KeyMsg) (state.Model, []any) {
	decision := confirmDecision(msg)
	if decision == "" {
		return m, nil
	}
	reply := protocol.NewConfirmReply(m.Confirm.ID, decision)
	m.Confirm = nil
	if m.Panel.Open && (m.Panel.Kind == "text" || m.Panel.Kind == "list") {
		m.Panel = state.Panel{}
	}
	if msg.Type == tea.KeyCtrlC {
		var stop []any
		m, stop = interrupt(m)
		return m, append([]any{reply}, stop...)
	}
	return m, []any{reply}
}

func confirmDecision(msg tea.KeyMsg) string {
	if msg.Paste {
		return ""
	}
	if msg.Type == tea.KeyRunes && !msg.Alt {
		switch string(msg.Runes) {
		case "y":
			return protocol.DecisionOnce
		case "a":
			return protocol.DecisionSession
		case "n":
			return protocol.DecisionDeny
		}
		return ""
	}
	switch msg.String() {
	case "esc", "ctrl+c":
		return protocol.DecisionDeny
	}
	return ""
}

func interrupt(m state.Model) (state.Model, []any) {
	m.Flash = "stopping…"
	return m, []any{protocol.NewInterrupt()}
}

func quit(m state.Model) (state.Model, []any) {
	m.Quit = true
	return m, []any{protocol.NewQuit()}
}

func clearInput(m state.Model) state.Model {
	m.Input = ""
	m.Cursor = 0
	m.HistoryIndex = -1
	return refresh(m)
}

func insertText(m state.Model, text string) state.Model {
	if text != "" && !m.ShellMode && m.Input == "" && strings.HasPrefix(text, "!") {
		m.ShellMode = true
		m.Cursor = 0
		text = text[1:]
	}
	if text == "" {
		return refresh(m)
	}
	runes := []rune(m.Input)
	at := m.Cursor
	m.Input = string(runes[:at]) + text + string(runes[at:])
	m.Cursor = at + utf8.RuneCountInString(text)
	m.HistoryIndex = -1
	return refresh(m)
}

func deleteRange(m state.Model, from, to int) state.Model {
	runes := []rune(m.Input)
	from, to = max(from, 0), min(to, len(runes))
	if from >= to {
		return m
	}
	m.Input = string(runes[:from]) + string(runes[to:])
	m.Cursor = from
	m.HistoryIndex = -1
	return refresh(m)
}

func accept(m state.Model, s state.Suggestion) state.Model {
	m.Input = s.Insert
	m.Cursor = utf8.RuneCountInString(s.Insert)
	m.HistoryIndex = -1
	return refresh(m)
}

func selectedSuggestion(m state.Model) (state.Suggestion, bool) {
	if m.Selected < 0 || m.Selected >= len(m.Suggestions) {
		return state.Suggestion{}, false
	}
	return m.Suggestions[m.Selected], true
}

func moveSelection(m state.Model, step int) state.Model {
	n := len(m.Suggestions)
	m.Selected = (m.Selected + step + n) % n
	return m
}

func historyUp(m state.Model) state.Model {
	switch {
	case len(m.History) == 0 || m.HistoryIndex == 0:
		return m
	case m.HistoryIndex < 0:
		m.HistoryIndex = len(m.History) - 1
	default:
		m.HistoryIndex--
	}
	return recall(m, m.History[m.HistoryIndex])
}

func historyDown(m state.Model) state.Model {
	switch {
	case m.HistoryIndex < 0:
		return m
	case m.HistoryIndex >= len(m.History)-1:
		m.HistoryIndex = -1
		return recall(m, "")
	default:
		m.HistoryIndex++
		return recall(m, m.History[m.HistoryIndex])
	}
}

func recall(m state.Model, text string) state.Model {
	m.ShellMode = strings.HasPrefix(text, "!")
	if m.ShellMode {
		text = text[1:]
	}
	m.Input = text
	m.Cursor = utf8.RuneCountInString(text)
	return m
}

func moveLine(m state.Model, step int) state.Model {
	runes := []rune(m.Input)
	start, end := lineBounds(runes, m.Cursor)
	col := m.Cursor - start
	if step < 0 {
		if start == 0 {
			m.Cursor = 0
			return m
		}
		prevStart, prevEnd := lineBounds(runes, start-1)
		m.Cursor = prevStart + min(col, prevEnd-prevStart)
		return m
	}
	if end == len(runes) {
		m.Cursor = len(runes)
		return m
	}
	nextStart, nextEnd := lineBounds(runes, end+1)
	m.Cursor = nextStart + min(col, nextEnd-nextStart)
	return m
}

func lineBounds(runes []rune, at int) (int, int) {
	start, end := at, at
	for start > 0 && runes[start-1] != '\n' {
		start--
	}
	for end < len(runes) && runes[end] != '\n' {
		end++
	}
	return start, end
}

func wordStart(runes []rune, at int) int {
	for at > 0 && !isWordRune(runes[at-1]) {
		at--
	}
	for at > 0 && isWordRune(runes[at-1]) {
		at--
	}
	return at
}

func wordEnd(runes []rune, at int) int {
	for at < len(runes) && !isWordRune(runes[at]) {
		at++
	}
	for at < len(runes) && isWordRune(runes[at]) {
		at++
	}
	return at
}

func isWordRune(r rune) bool {
	return r == '_' || unicode.IsLetter(r) || unicode.IsDigit(r)
}

func pageSize(m state.Model) int {
	return max(1, m.Height/2)
}

func scrollBy(m state.Model, n int) state.Model {
	m.Scroll = clamp(m.Scroll+n, view.MaxScroll(m))
	return m
}

func foldLastSubagent(m state.Model) state.Model {
	for i := len(m.Blocks) - 1; i >= 0; i-- {
		if b := &m.Blocks[i]; b.Kind == state.KindSubagent {
			if b.Status != "running" {
				b.Folded = !b.Folded
				m.Scroll = clamp(m.Scroll, view.MaxScroll(m))
			}
			break
		}
	}
	return m
}

func normalizeNewlines(text string) string {
	return strings.ReplaceAll(strings.ReplaceAll(text, "\r\n", "\n"), "\r", "\n")
}

func cleanText(text string) string {
	text = strings.ReplaceAll(normalizeNewlines(text), "\t", "    ")
	return strings.Map(func(r rune) rune {
		if r != '\n' && unicode.IsControl(r) {
			return -1
		}
		return r
	}, text)
}

func clamp(v, hi int) int {
	return max(0, min(v, hi))
}
