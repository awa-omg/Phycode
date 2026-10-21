package state

import (
	"slices"
	"strings"

	"phycode/tui/internal/protocol"
)

const (
	GroupModel      = "Model"
	GroupSession    = "Session"
	GroupChanges    = "Changes"
	GroupAgents     = "Agents"
	GroupProject    = "Project"
	GroupExtensions = "Extensions"
	GroupGentle     = "Gentle"
	GroupHelp       = "Help"
	GroupCustom     = "Custom"
)

var order = []struct{ word, group string }{
	{"/models", GroupModel},
	{"/connect", GroupModel},
	{"/effort", GroupModel},
	{"/context", GroupModel},
	{"/compact", GroupModel},
	{"/config", GroupModel},

	{"/sessions", GroupSession},
	{"/clear", GroupSession},
	{"/btw", GroupSession},
	{"/handoff", GroupSession},
	{"/status", GroupSession},
	{"/copy", GroupSession},
	{"/export", GroupSession},

	{"/diff", GroupChanges},
	{"/undo", GroupChanges},
	{"/redo", GroupChanges},
	{"/rewind", GroupChanges},
	{"/review", GroupChanges},

	{"/agent", GroupAgents},
	{"/agents", GroupAgents},
	{"/tasks", GroupAgents},

	{"/reload", GroupProject},
	{"/init", GroupProject},
	{"/edit", GroupProject},
	{"/deepresearch", GroupProject},
	{"/odd", GroupProject},

	{"/skill", GroupExtensions},
	{"/skills", GroupExtensions},
	{"/mcp", GroupExtensions},
	{"/web", GroupExtensions},

	{"/gentle", GroupGentle},
	{"/persona", GroupGentle},
	{"/shell", GroupGentle},

	{"/theme", GroupHelp},
	{"/details", GroupHelp},
	{"/help", GroupHelp},
	{"/exit", GroupHelp},
	{"/quit", GroupHelp},
}

var LocalCommands = []protocol.Command{
	{Name: "/connect", Description: "connect a provider: pick it and paste its key, which is saved encrypted and tested at once"},
	{Name: "/theme [NAME]", Description: "choose the colour theme; moving the highlight shows it, and enter saves it"},
	{Name: "/copy", Description: "copy the last reply to the clipboard"},
	{Name: "/export [PATH]", Description: "write the conversation as Markdown, in .phycode/exports of the project unless a path is given"},
	{Name: "/details", Description: "show or fold the output of every tool call, like ctrl+o"},
	{Name: "/tasks", Description: "list the subagents of this session: who works, who waits for you, and what each one did"},
	{Name: "/edit PATH", Description: "open a file of the project in your editor"},
	{Name: "/web", Description: "choose the web search: pick a provider, or add one with its key, which is saved encrypted"},
}

type Command struct {
	Name        string
	Word        string
	Args        string
	Description string
	Group       string
	Rank        int
	Turn        bool
}

func (c Command) Optional() bool {
	return c.Args == "" || strings.HasPrefix(c.Args, "[")
}

func (c Command) Choices() []string {
	args := strings.Trim(c.Args, "[]")
	if !strings.Contains(args, "|") || strings.ContainsAny(args, " ABCDEFGHIJKLMNOPQRSTUVWXYZ.") {
		return nil
	}
	return strings.Split(args, "|")
}

func Catalog(listed []protocol.Command) []Command {
	all := slices.Clone(listed)
	for _, local := range LocalCommands {
		if !slices.ContainsFunc(all, func(c protocol.Command) bool { return wordOf(c.Name) == wordOf(local.Name) }) {
			all = append(all, local)
		}
	}
	out := make([]Command, 0, len(all))
	for i, c := range all {
		word, args, _ := strings.Cut(strings.TrimSpace(c.Name), " ")
		cmd := Command{Name: strings.TrimSpace(c.Name), Word: word, Args: strings.TrimSpace(args), Description: c.Description, Turn: c.Turn}
		cmd.Group, cmd.Rank = rankOf(word, i)
		out = append(out, cmd)
	}
	slices.SortStableFunc(out, func(a, b Command) int {
		if a.Rank != b.Rank {
			return a.Rank - b.Rank
		}
		return len(a.Args) - len(b.Args)
	})
	return out
}

func rankOf(word string, listed int) (string, int) {
	for i, o := range order {
		if o.word == word {
			return o.group, i
		}
	}
	return GroupCustom, len(order) + listed
}

func Groups(cmds []Command) []string {
	var out []string
	for _, c := range cmds {
		if !slices.Contains(out, c.Group) {
			out = append(out, c.Group)
		}
	}
	return out
}

func wordOf(name string) string {
	word, _, _ := strings.Cut(strings.TrimSpace(name), " ")
	return word
}
