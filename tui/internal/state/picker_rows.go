package state

import (
	"slices"
	"strings"

	"phycode/tui/internal/fuzzy"
	"phycode/tui/internal/protocol"
)

const (
	GroupFavorites = "Favorites"
	GroupRecent    = "Recent"
)

type RowKind int

const (
	RowSection RowKind = iota
	RowModel
	RowNote
	RowAdd
)

type Row struct {
	Kind     RowKind
	Title    string
	Provider *protocol.Provider
	Entry    protocol.ModelEntry
	Group    string
	Match    []int
	Bad      bool
}

type candidate struct {
	entry protocol.ModelEntry
	match []int
	score int
	order int
}

func (p Picker) Rows() []Row {
	query := strings.TrimSpace(p.Query)
	var found []candidate
	for i, e := range p.Entries {
		if e.Model == "" {
			continue
		}
		if c, ok := rank(query, e, i); ok {
			found = append(found, c)
		}
	}

	var favorites, recents []candidate
	byProfile := map[string][]candidate{}
	for _, c := range found {
		switch {
		case p.IsFavorite(c.entry.Profile, c.entry.Model):
			favorites = append(favorites, c)
		case containsRef(p.Recents, c.entry.Profile, c.entry.Model):
			recents = append(recents, c)
		default:
			byProfile[c.entry.Profile] = append(byProfile[c.entry.Profile], c)
		}
	}
	slices.SortStableFunc(recents, func(a, b candidate) int {
		return refIndex(p.Recents, a.entry) - refIndex(p.Recents, b.entry)
	})

	var rows []Row
	addGroup := func(title string, cs []candidate) {
		if len(cs) == 0 {
			return
		}
		rows = append(rows, Row{Kind: RowSection, Title: title})
		for _, c := range sorted(cs, query != "") {
			rows = append(rows, Row{Kind: RowModel, Entry: c.entry, Group: title, Match: c.match})
		}
	}
	addGroup(GroupFavorites, favorites)
	addGroup(GroupRecent, recents)

	for _, pr := range p.groups() {
		cs := byProfile[pr.Profile]
		notes := p.notes(pr)
		if query != "" {
			notes = nil
		}
		if len(cs) == 0 && len(notes) == 0 {
			continue
		}
		pr := pr
		rows = append(rows, Row{Kind: RowSection, Title: pr.Profile, Provider: &pr})
		for _, c := range sortedProviderGroup(cs, query != "") {
			rows = append(rows, Row{Kind: RowModel, Entry: c.entry, Match: c.match})
		}
		rows = append(rows, notes...)
	}
	return append(rows, Row{Kind: RowAdd, Title: "Add provider…"})
}

func rank(query string, e protocol.ModelEntry, order int) (candidate, bool) {
	if res, ok := fuzzy.Match(query, e.Model); ok {
		return candidate{entry: e, match: res.Positions, score: res.Score, order: order}, true
	}
	if res, ok := fuzzy.Contains(query, e.Profile); ok {
		return candidate{entry: e, score: 1000 + res.Score, order: order}, true
	}
	return candidate{}, false
}

func sorted(cs []candidate, byScore bool) []candidate {
	if !byScore {
		return cs
	}
	out := slices.Clone(cs)
	slices.SortStableFunc(out, func(a, b candidate) int { return a.score - b.score })
	return out
}

const bigGroup = 12

func sortedProviderGroup(cs []candidate, byScore bool) []candidate {
	if byScore || len(cs) <= bigGroup {
		return sorted(cs, byScore)
	}
	type keyed struct {
		at   int
		chat bool
		name string
	}
	keys := make([]keyed, len(cs))
	for i, c := range cs {
		keys[i] = keyed{i, c.entry.Kind == "", strings.ToLower(c.entry.Model)}
	}
	slices.SortStableFunc(keys, func(a, b keyed) int {
		if a.chat != b.chat {
			if a.chat {
				return -1
			}
			return 1
		}
		return strings.Compare(a.name, b.name)
	})
	out := make([]candidate, len(keys))
	for i, k := range keys {
		out[i] = cs[k.at]
	}
	return out
}

func refIndex(refs []protocol.ModelRef, e protocol.ModelEntry) int {
	i := slices.IndexFunc(refs, func(r protocol.ModelRef) bool { return r.Profile == e.Profile && r.Model == e.Model })
	if i < 0 {
		return len(refs)
	}
	return i
}

func (p Picker) groups() []protocol.Provider {
	out := slices.Clone(p.Providers)
	for _, e := range p.Entries {
		if !slices.ContainsFunc(out, func(pr protocol.Provider) bool { return pr.Profile == e.Profile }) {
			out = append(out, protocol.Provider{Profile: e.Profile, Protocol: e.Protocol})
		}
	}
	return out
}

func (p Picker) notes(pr protocol.Provider) []Row {
	var out []Row
	if pr.Error != nil && *pr.Error != "" {
		out = append(out, Row{Kind: RowNote, Title: *pr.Error, Bad: true})
	}
	if slices.ContainsFunc(p.Entries, func(e protocol.ModelEntry) bool { return e.Profile == pr.Profile && e.Model != "" }) {
		return out
	}
	for _, e := range p.Entries {
		if e.Profile == pr.Profile && e.Error != nil && *e.Error != "" && (pr.Error == nil || *pr.Error != *e.Error) {
			out = append(out, Row{Kind: RowNote, Title: *e.Error, Bad: true})
		}
	}
	if len(out) == 0 {
		out = append(out, Row{Kind: RowNote, Title: "no models listed · ctrl+n types a model id"})
	}
	return out
}

func (p Picker) Visible() []protocol.ModelEntry {
	var out []protocol.ModelEntry
	for _, r := range p.Rows() {
		if r.Kind == RowModel {
			out = append(out, r.Entry)
		}
	}
	return out
}

func (p Picker) Items() int {
	n := 0
	for _, r := range p.Rows() {
		if r.Kind == RowModel {
			n++
		}
	}
	return n
}

func (p Picker) OnSpecial() bool {
	return p.Selected == p.Items()
}

func (p Picker) Highlighted() (protocol.ModelEntry, bool) {
	list := p.Visible()
	if p.Loading || p.Selected < 0 || p.Selected >= len(list) {
		return protocol.ModelEntry{}, false
	}
	return list[p.Selected], true
}

func (p Picker) CustomTarget() string {
	if e, ok := p.Highlighted(); ok {
		return e.Profile
	}
	groups := p.groups()
	for _, pr := range groups {
		if !slices.ContainsFunc(p.Entries, func(e protocol.ModelEntry) bool { return e.Profile == pr.Profile && e.Model != "" }) {
			return pr.Profile
		}
	}
	if len(groups) > 0 {
		return groups[0].Profile
	}
	return ""
}

func (p Picker) Provider(profile string) (protocol.Provider, bool) {
	for _, pr := range p.groups() {
		if pr.Profile == profile {
			return pr, true
		}
	}
	return protocol.Provider{}, false
}
