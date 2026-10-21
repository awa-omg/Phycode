package state

import (
	"slices"
	"strconv"
	"strings"

	"phycode/tui/internal/fuzzy"
	"phycode/tui/internal/protocol"
)

const (
	ConnectChoose = iota
	ConnectKey
)

const (
	ConnectDetected   = "Detected"
	ConnectPopular    = "Popular"
	ConnectLocal      = "Local"
	ConnectConfigured = "Configured"
	ConnectOther      = "Other"
)

type Connect struct {
	Stage    int
	Query    string
	Selected int
	Target   ConnectTarget
	Key      string
	Pending  bool
	Error    string
	Direct   bool
	Confirm  string
	Removing string
}

type ConnectTarget struct {
	Name     string
	Protocol string
	BaseURL  string
	Existing bool
	EnvVar   string
	KeyInEnv bool
	NoKey    bool
}

type ConnectKind int

const (
	ConnectSection ConnectKind = iota
	ConnectProvider
	ConnectCustom
)

type ConnectRow struct {
	Kind   ConnectKind
	Title  string
	Match  []int
	Target ConnectTarget
	Host   string
	Status string
	Bad    bool
}

const CustomTitle = "Custom endpoint…"

func (p Picker) ConnectRows() []ConnectRow {
	query := ""
	if p.Connect != nil {
		query = strings.TrimSpace(p.Connect.Query)
	}
	groups := map[string][]ConnectRow{}
	isPreset := map[string]bool{}
	for _, ep := range p.Detected {
		if ep.Configured != nil {
			continue
		}
		row := ConnectRow{
			Kind: ConnectProvider, Title: ep.Name, Host: hostOf(ep.BaseURL), Status: detectedStatus(ep),
			Target: ConnectTarget{Name: ep.Name, Protocol: ep.Protocol, BaseURL: ep.BaseURL, NoKey: !ep.NeedsKey},
		}
		if match, ok := connectMatch(query, row); ok {
			row.Match = match
			groups[ConnectDetected] = append(groups[ConnectDetected], row)
		}
	}
	found := map[string]bool{}
	for _, ep := range p.Detected {
		found[hostOf(ep.BaseURL)] = true
	}
	for _, pre := range p.Presets {
		isPreset[pre.Name] = true
		if pre.APIKeyEnv == nil && found[hostOf(pre.BaseURL)] {
			continue
		}
		group := ConnectLocal
		if pre.APIKeyEnv != nil {
			group = ConnectPopular
		}
		row := p.connectRow(ConnectTarget{Name: pre.Name, Protocol: pre.Protocol, BaseURL: pre.BaseURL, EnvVar: valueOf(pre.APIKeyEnv), KeyInEnv: pre.KeyInEnv, NoKey: pre.APIKeyEnv == nil})
		if match, ok := connectMatch(query, row); ok {
			row.Match = match
			groups[group] = append(groups[group], row)
		}
	}
	for _, pr := range p.groups() {
		if isPreset[pr.Profile] {
			continue
		}
		row := p.connectRow(ConnectTarget{Name: pr.Profile, Protocol: pr.Protocol, BaseURL: pr.Endpoint})
		if match, ok := connectMatch(query, row); ok {
			row.Match = match
			groups[ConnectConfigured] = append(groups[ConnectConfigured], row)
		}
	}

	var rows []ConnectRow
	for _, group := range []string{ConnectDetected, ConnectPopular, ConnectLocal, ConnectConfigured} {
		if len(groups[group]) == 0 {
			continue
		}
		rows = append(rows, ConnectRow{Kind: ConnectSection, Title: group})
		rows = append(rows, groups[group]...)
	}
	if _, ok := fuzzy.Match(query, CustomTitle); query == "" || ok {
		rows = append(rows, ConnectRow{Kind: ConnectSection, Title: ConnectOther}, ConnectRow{Kind: ConnectCustom, Title: CustomTitle})
	}
	return rows
}

func detectedStatus(ep protocol.Endpoint) string {
	switch {
	case ep.NeedsKey:
		return ep.Server + " · needs a key"
	case ep.Count == 0:
		return ep.Server + " · no models"
	}
	return ep.Server + " · " + modelCount(ep.Count)
}

func (p Picker) connectRow(t ConnectTarget) ConnectRow {
	row := ConnectRow{Kind: ConnectProvider, Title: t.Name, Target: t, Host: hostOf(t.BaseURL)}
	pr, known := p.Provider(t.Name)
	switch {
	case known:
		row.Target.Existing = true
		if row.Target.Protocol == "" {
			row.Target.Protocol = pr.Protocol
		}
		if row.Target.BaseURL == "" {
			row.Target.BaseURL = pr.Endpoint
		}
		row.Host = hostOf(row.Target.BaseURL)
		switch {
		case pr.Error != nil && *pr.Error != "":
			row.Status, row.Bad = KeyProblem(*pr.Error), true
		case pr.Count > 0:
			row.Status = "connected · " + modelCount(pr.Count)
		default:
			row.Status = "connected"
		}
	case t.NoKey:
		row.Status = "no key needed"
	case t.KeyInEnv:
		row.Status = "key from $" + t.EnvVar
	default:
		row.Status = "needs a key"
	}
	return row
}

func connectMatch(query string, row ConnectRow) ([]int, bool) {
	if query == "" {
		return nil, true
	}
	if res, ok := fuzzy.Match(query, row.Title); ok {
		return res.Positions, true
	}
	if _, ok := fuzzy.Contains(query, row.Host); ok {
		return nil, true
	}
	return nil, false
}

func (p Picker) ConnectChoices() []ConnectRow {
	var out []ConnectRow
	for _, row := range p.ConnectRows() {
		if row.Kind != ConnectSection {
			out = append(out, row)
		}
	}
	return out
}

func (p Picker) ConnectHighlighted() (ConnectRow, bool) {
	if p.Connect == nil {
		return ConnectRow{}, false
	}
	choices := p.ConnectChoices()
	if p.Connect.Selected < 0 || p.Connect.Selected >= len(choices) {
		return ConnectRow{}, false
	}
	return choices[p.Connect.Selected], true
}

func KeyProblem(problem string) string {
	switch {
	case strings.Contains(problem, "HTTP 401"), strings.Contains(problem, "HTTP 403"):
		return "key refused"
	case strings.Contains(problem, "connect"), strings.Contains(problem, "timed out"), strings.Contains(problem, "resolve"):
		return "not answering"
	}
	return "failed"
}

func KeyRefused(problem string) bool {
	return KeyProblem(problem) == "key refused"
}

func modelCount(n int) string {
	if n == 1 {
		return "1 model"
	}
	return strconv.Itoa(n) + " models"
}

func hostOf(url string) string {
	if _, rest, ok := strings.Cut(url, "://"); ok {
		url = rest
	}
	return strings.TrimRight(url, "/")
}

func valueOf(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func ConnectFinished(providers []protocol.Provider, name string) (done bool, problem string) {
	i := slices.IndexFunc(providers, func(pr protocol.Provider) bool { return pr.Profile == name })
	if i < 0 {
		return false, ""
	}
	if pr := providers[i]; pr.Error != nil && *pr.Error != "" {
		return true, *pr.Error
	}
	return true, ""
}
