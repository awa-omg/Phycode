package app

import (
	"slices"
	"strings"

	"phycode/tui/internal/fuzzy"
	"phycode/tui/internal/state"
)

var fallbackLevels = []string{"none", "minimal", "low", "medium", "high", "xhigh", "max"}

func refresh(m state.Model) state.Model {
	if m.ShellMode {
		m.Suggestions, m.Selected, m.NoMatch = nil, 0, ""
		return m
	}
	m.Suggestions = suggest(m)
	m.Selected = 0
	m.NoMatch = ""
	if len(m.Suggestions) == 0 && strings.HasPrefix(m.Input, "/") && !strings.ContainsAny(m.Input, " \n") {
		m.NoMatch = m.Input
	}
	return m
}

func suggest(m state.Model) []state.Suggestion {
	in := m.Input
	if strings.Contains(in, "\n") {
		return nil
	}
	switch {
	case strings.HasPrefix(in, "/") && !strings.Contains(in, " "):
		return suggestCommands(m, in)
	case strings.HasPrefix(in, "/agent "):
		return suggestAgents(m, strings.TrimPrefix(in, "/agent "))
	case strings.HasPrefix(in, "/"):
		return suggestChoices(m, in)
	case strings.HasPrefix(in, "@") && !strings.Contains(in, " "):
		return suggestSubagents(m, in[1:])
	}
	return nil
}

type ranked struct {
	s     state.Suggestion
	score int
}

func order(found []ranked) []state.Suggestion {
	slices.SortStableFunc(found, func(a, b ranked) int { return a.score - b.score })
	var out []state.Suggestion
	for _, r := range found {
		out = append(out, r.s)
	}
	return out
}

const exactScore = -1

func suggestCommands(m state.Model, in string) []state.Suggestion {
	query := strings.TrimPrefix(in, "/")
	var found []ranked
	for _, c := range state.Catalog(m.Commands) {
		name := strings.TrimPrefix(c.Word, "/")
		res, ok := fuzzy.Match(query, name)
		var match []int
		score := res.Score
		switch {
		case ok:
			for _, p := range res.Positions {
				match = append(match, p+1)
			}
		default:
			d, found := fuzzy.Contains(query, c.Description)
			if !found || len([]rune(query)) < 4 || d.Score > 1 {
				continue
			}
			score = 1000 + d.Score
		}
		if strings.EqualFold(c.Word, in) {
			score = exactScore
		}
		insert := c.Word
		if c.Args != "" {
			insert += " "
		}
		found = append(found, ranked{state.Suggestion{
			Insert: insert, Label: c.Name, Description: c.Description, Kind: state.SuggestCommand, Group: c.Group, Match: match,
		}, score})
	}
	return order(found)
}

func suggestAgents(m state.Model, name string) []state.Suggestion {
	var found []ranked
	for _, a := range m.Agents {
		if a.Mode != "primary" && a.Mode != "all" {
			continue
		}
		res, ok := fuzzy.Match(name, a.Name)
		if !ok {
			continue
		}
		score := res.Score
		if strings.EqualFold(a.Name, name) {
			score = exactScore
		}
		found = append(found, ranked{state.Suggestion{
			Insert: "/agent " + a.Name, Label: a.Name, Description: a.Description, Kind: state.SuggestAgent, Match: res.Positions,
		}, score})
	}
	return order(found)
}

func suggestSubagents(m state.Model, name string) []state.Suggestion {
	var found []ranked
	for _, a := range m.Agents {
		if a.Mode != "subagent" && a.Mode != "all" {
			continue
		}
		res, ok := fuzzy.Match(name, a.Name)
		if !ok {
			continue
		}
		var match []int
		for _, p := range res.Positions {
			match = append(match, p+1)
		}
		found = append(found, ranked{state.Suggestion{
			Insert: "@" + a.Name + " ", Label: "@" + a.Name, Description: a.Description, Kind: state.SuggestSubagent, Match: match,
		}, res.Score})
	}
	return order(found)
}

func suggestChoices(m state.Model, in string) []state.Suggestion {
	word, arg, _ := strings.Cut(in, " ")
	if strings.Contains(arg, " ") {
		return nil
	}
	var choices []string
	for _, c := range state.Catalog(m.Commands) {
		if c.Word != word {
			continue
		}
		if choices = c.Choices(); len(choices) > 0 {
			break
		}
	}
	if word == "/effort" {
		choices = effortChoices(m)
	}
	var found []ranked
	for _, choice := range choices {
		res, ok := fuzzy.Match(arg, choice)
		if !ok {
			continue
		}
		found = append(found, ranked{state.Suggestion{
			Insert: word + " " + choice, Label: choice, Kind: state.SuggestChoice, Match: res.Positions,
		}, res.Score})
	}
	return order(found)
}

func effortChoices(m state.Model) []string {
	if len(m.Effort.Levels) == 0 {
		return fallbackLevels
	}
	out := make([]string, len(m.Effort.Levels))
	for i, l := range m.Effort.Levels {
		out[i] = l.Name
	}
	return out
}
