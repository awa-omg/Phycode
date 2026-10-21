package state

import (
	"slices"
	"strings"
	"time"

	"phycode/tui/internal/protocol"
)

const (
	FieldName = iota
	FieldProtocol
	FieldBaseURL
	FieldAPIKey
	FieldAPIKeyEnv
	FieldModel
	FieldCount
)

var providerProtocols = []string{"openai", "ollama", "openai-responses", "anthropic"}

type ProviderForm struct {
	Focus  int
	Values [FieldCount]string
	Sent   string
}

func NewProviderForm() ProviderForm {
	var f ProviderForm
	f.Values[FieldProtocol] = providerProtocols[0]
	return f
}

func (f ProviderForm) IsText() bool {
	return f.Focus != FieldProtocol
}

func (f ProviderForm) Moved(step int) ProviderForm {
	f.Focus = ((f.Focus+step)%FieldCount + FieldCount) % FieldCount
	return f
}

func (f ProviderForm) Cycled(step int) ProviderForm {
	if f.Focus != FieldProtocol {
		return f
	}
	n := len(providerProtocols)
	at := max(slices.Index(providerProtocols, f.Values[FieldProtocol]), 0)
	f.Values[FieldProtocol] = providerProtocols[((at+step)%n+n)%n]
	return f
}

func (f ProviderForm) Typed(text string) ProviderForm {
	if f.IsText() {
		f.Values[f.Focus] += text
	}
	return f
}

func (f ProviderForm) Erased() ProviderForm {
	if f.IsText() {
		f.Values[f.Focus] = DropLastRune(f.Values[f.Focus])
	}
	return f
}

func (f ProviderForm) Problem() (int, string) {
	switch {
	case strings.TrimSpace(f.Values[FieldName]) == "":
		return FieldName, "name is required"
	case strings.TrimSpace(f.Values[FieldBaseURL]) == "":
		return FieldBaseURL, "base_url is required"
	case strings.TrimSpace(f.Values[FieldAPIKey]) != "" && strings.TrimSpace(f.Values[FieldAPIKeyEnv]) != "":
		return FieldAPIKeyEnv, "use api_key or api_key_env, not both"
	}
	return -1, ""
}

func DropLastRune(s string) string {
	r := []rune(s)
	if len(r) == 0 {
		return s
	}
	return string(r[:len(r)-1])
}

func (p Picker) Updated() (time.Time, bool) {
	var latest time.Time
	found := false
	for _, pr := range p.Providers {
		if t, ok := QueriedAt(pr); ok && (!found || t.After(latest)) {
			latest, found = t, true
		}
	}
	return latest, found
}

func QueriedAt(pr protocol.Provider) (time.Time, bool) {
	if pr.UpdatedAt == nil {
		return time.Time{}, false
	}
	t, err := time.Parse(time.RFC3339, *pr.UpdatedAt)
	return t, err == nil
}

func (p Picker) IsFavorite(profile, model string) bool {
	return containsRef(p.Favorites, profile, model)
}

func (p Picker) DefaultLevel(e protocol.ModelEntry) string {
	levels := e.Thinking
	if len(levels) == 0 {
		return ""
	}
	if e.Profile == p.Current.Profile && e.Model == p.Current.Model {
		if level, ok := OfferedLevel(levels, p.Current.Thinking); ok {
			return level
		}
	}
	if e.ThinkingDefault != nil {
		if level, ok := OfferedLevel(levels, *e.ThinkingDefault); ok {
			return level
		}
	}
	if level, ok := OfferedLevel(levels, "none"); ok {
		return level
	}
	return levels[0]
}

func OfferedLevel(levels []string, name string) (string, bool) {
	if name == "" {
		return "", false
	}
	want := protocol.CanonicalLevel(name)
	for _, l := range levels {
		if protocol.CanonicalLevel(l) == want {
			return l, true
		}
	}
	return "", false
}

func containsRef(refs []protocol.ModelRef, profile, model string) bool {
	return slices.ContainsFunc(refs, func(r protocol.ModelRef) bool {
		return r.Profile == profile && r.Model == model
	})
}

func NormalizeBaseURL(url string) string {
	url = strings.TrimRight(strings.TrimSpace(url), "/")
	for _, tail := range []string{"/chat/completions", "/messages", "/models"} {
		url = strings.TrimSuffix(url, tail)
	}
	return strings.TrimSuffix(strings.TrimRight(url, "/"), "/v1")
}
