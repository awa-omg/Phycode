package state

import (
	"testing"

	"phycode/tui/internal/protocol"
)

var allLevels = []string{"none", "minimal", "low", "medium", "high", "xhigh", "max"}

var haikuLevels = []string{"none", "low", "medium", "high", "xhigh", "max"}

func effortEvent(level string, accepted []string) protocol.Effort {
	ev := protocol.Effort{Level: level, Accepted: accepted}
	for _, name := range allLevels {
		ev.Levels = append(ev.Levels, protocol.EffortLevel{Name: name})
	}
	return ev
}

func TestSentAsGivesTheLevelThatTheRequestSends(t *testing.T) {
	for _, tt := range []struct {
		level    string
		accepted []string
		want     string
	}{
		{"minimal", haikuLevels, "low"},
		{"xhigh", []string{"none", "low", "medium", "high"}, "high"},
		{"max", []string{"none", "low", "medium", "high"}, "high"},
		{"none", []string{"minimal", "low", "medium", "high"}, "minimal"},
		{"minimal", []string{"none"}, "none"},
		{"low", []string{"medium", "high", "xhigh"}, "medium"},
		{"max", []string{"medium", "high", "xhigh"}, "xhigh"},
	} {
		if got := sentAs(tt.level, allLevels, tt.accepted); got != tt.want {
			t.Errorf("sentAs(%s, %v) = %s, want %s", tt.level, tt.accepted, got, tt.want)
		}
	}
}

func TestWithEffortTitleNamesTheModelAndMarksTheLevelsItDoesNotTake(t *testing.T) {
	ev := effortEvent("medium", haikuLevels)
	ev.Profile, ev.Model, ev.Note = "claude", "claude-haiku-5-5", "claude-haiku-5-5 takes none and low to max"
	p := EffortPanel().WithEffort(ev)
	if p.Title != "Effort · claude-haiku-5-5" || p.Note != ev.Note || p.Loading {
		t.Errorf("Title = %q, Note = %q, Loading = %v; want the model in the title and the note kept", p.Title, p.Note, p.Loading)
	}
	rows := map[string]protocol.PanelItem{}
	for _, it := range p.Items {
		rows[it.Key] = it
	}
	if it := rows["minimal"]; it.Detail != "→ low" || it.Status != "off" || it.Badge != "" {
		t.Errorf("minimal = %+v; want the mark that it is sent as low, and no status but off", it)
	}
	if it := rows["medium"]; it.Badge != "current" || it.Status != "ok" || it.Detail != "" {
		t.Errorf("medium = %+v; want the level in use, marked as current and not dimmed", it)
	}
	if it := rows["high"]; it.Detail != "" || it.Status != "" {
		t.Errorf("high = %+v; want a level the model takes drawn as it is", it)
	}
	if it, ok := p.Highlighted(); !ok || it.Key != "medium" {
		t.Errorf("highlight = %+v, want the level in use", it)
	}
}

func TestWithEffortKeepsTheLevelInUseMarkedWhenTheModelDoesNotTakeIt(t *testing.T) {
	p := EffortPanel().WithEffort(effortEvent("minimal", haikuLevels))
	it, ok := p.Highlighted()
	if !ok || it.Key != "minimal" || it.Badge != "current" || it.Status != "ok" || it.Detail != "→ low" {
		t.Errorf("highlight = %+v, %v; want minimal in use, marked current, with what it is sent as", it, ok)
	}
}

func TestWithEffortMarksNoLevelWhenTheModelIsNotKnown(t *testing.T) {
	ev := effortEvent("on", nil)
	p := EffortPanel().WithEffort(ev)
	if p.Title != "Effort" {
		t.Errorf("Title = %q, want Effort without a model", p.Title)
	}
	for _, it := range p.Items {
		if it.Detail != "" || (it.Status != "" && it.Status != "ok") {
			t.Errorf("%s = %+v; want no mark for a model that is not known", it.Key, it)
		}
	}
	if it, ok := p.Highlighted(); !ok || it.Key != "medium" || it.Badge != "current" {
		t.Errorf("highlight = %+v, %v; want medium, the level that on names", it, ok)
	}
	if got := len(p.Items); got != len(allLevels) || p.Items[0].Key != "none" {
		t.Errorf("Items = %d, first %q; want every level, in order", got, p.Items[0].Key)
	}
}
