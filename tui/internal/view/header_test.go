package view

import (
	"regexp"
	"strings"
	"testing"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func headerAt(t *testing.T, w int) string {
	t.Helper()
	m := newModel(w, 10)
	m.App.Cwd = "/home/dev/projects/phycode-long-name"
	t.Setenv("HOME", "/nowhere")
	return plain(screen(t, NewRenderer(theme.Default()), m)[0])
}

func TestTheHeaderNeverCutsAValueInTheMiddle(t *testing.T) {
	whole := map[string]bool{"agent build": true, "default": true, "tools full": true, "sandbox host": true, "permissions ask": true}
	for w := 20; w <= 140; w++ {
		header := headerAt(t, w)
		left := regexp.MustCompile(` {2,}`).Split(strings.TrimSpace(header), 2)[0]
		for _, part := range strings.Split(left, " · ")[1:] {
			if !whole[strings.TrimSpace(part)] {
				t.Fatalf("width %d: the header %q holds %q, which is not a whole value", w, header, part)
			}
		}
	}
}

func TestTheHeaderGivesUpTheLeastImportantValuesFirst(t *testing.T) {
	order := []string{"permissions ask", "sandbox host", "tools full", "default", "agent build"}
	prev := len(order) + 1
	for w := 140; w >= 20; w-- {
		header := headerAt(t, w)
		kept := 0
		for i, v := range order {
			if strings.Contains(header, v) {
				kept = max(kept, len(order)-i)
			}
		}
		for i, v := range order {
			if len(order)-i <= kept && !strings.Contains(header, v) {
				t.Fatalf("width %d: the header %q lacks %q but keeps a less important value", w, header, v)
			}
		}
		if kept > prev {
			t.Fatalf("width %d keeps %d values, more than a wider header kept (%d)", w, kept, prev)
		}
		prev = kept
	}
}

func TestTheDirectoryKeepsAGapFromTheValues(t *testing.T) {
	header := plain(screen(t, NewRenderer(theme.Default()), newModel(120, 10))[0])
	if !strings.Contains(header, strings.Repeat(" ", headerGap)+"/work/phycode") {
		t.Errorf("header = %q, want at least %d spaces before the directory", header, headerGap)
	}
}

func TestTheDirectoryIsCutAtASlashWhenItLosesItsStart(t *testing.T) {
	cut := 0
	for w := 45; w <= 140; w++ {
		header := headerAt(t, w)
		i := strings.Index(header, "…")
		if i < 0 || !strings.Contains(header[i:], "/") {
			continue
		}
		cut++
		if !strings.HasPrefix(header[i:], "…/") {
			t.Errorf("width %d: header = %q, want the cut directory to start with …/ and a whole name", w, header)
		}
	}
	if cut == 0 {
		t.Error("no width cut the directory, so the test saw nothing")
	}
}

func TestTheHeaderKeepsItsNameWhateverTheWidth(t *testing.T) {
	r := NewRenderer(theme.Default())
	for w := 1; w <= 30; w++ {
		m := newModel(w, 6)
		screen(t, r, m)
		if w >= 11 && !strings.Contains(plain(r.header(m, w)), "phycode") {
			t.Errorf("width %d: the header lacks the name", w)
		}
	}
}

func TestTheWelcomeStepsAsideForABoxOverTheTranscript(t *testing.T) {
	r := NewRenderer(theme.Default())
	const line = "a local coding agent for llama.cpp"
	for name, open := range map[string]func(m *state.Model){
		"picker":   func(m *state.Model) { m.Picker.Open = true },
		"panel":    func(m *state.Model) { m.Panel = state.Panel{Open: true, Title: "x", Kind: "text"} },
		"question": func(m *state.Model) { m.Ask = &state.Ask{Question: "which?"} },
		"menu":     func(m *state.Model) { m.Suggestions = menuSuggestions() },
		"no match": func(m *state.Model) { m.NoMatch = "/zz" },
	} {
		m := newModel(100, 30)
		open(&m)
		if rowOf(screen(t, r, m), line) >= 0 {
			t.Errorf("%s: the welcome shows around the box", name)
		}
	}
	if rowOf(screen(t, r, newModel(100, 30)), line) < 0 {
		t.Error("the welcome is gone from a quiet session")
	}
}

func TestTheWelcomeAlignsItsKeysAndShowsTheVersion(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := plainRows(screen(t, r, newModel(100, 30)))
	if rowOf(rows, "0.1.0") < 0 {
		t.Errorf("the welcome shows no version: %q", rows)
	}
	want := cellAt(rows[rowOf(rows, "send the message")], "send the message")
	for _, desc := range []string{"commands, settings and help", "hand a task to a subagent", "fold the last subagent"} {
		if got := cellAt(rows[rowOf(rows, desc)], desc); got != want {
			t.Errorf("%q starts at cell %d, want %d, where the others start", desc, got, want)
		}
	}
}

func TestTheWelcomeFitsEverySize(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{1, 5, 10, 20, 40, 80} {
		for _, h := range []int{1, 3, 6, 10, 24} {
			screen(t, r, newModel(w, h))
		}
	}
}

func TestTheBranchSitsAfterTheDirectory(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(100, 10)
	m.Branch = "feature/picker"
	header := plain(screen(t, r, m)[0])
	if !strings.HasSuffix(strings.TrimRight(header, " "), "/work/phycode  feature/picker") {
		t.Errorf("header = %q, want the branch after the directory", header)
	}
	m.Branch = strings.Repeat("b", 60)
	header = plain(screen(t, r, m)[0])
	if !strings.Contains(header, strings.Repeat("b", 23)+"…") || strings.Contains(header, strings.Repeat("b", 24)) {
		t.Errorf("header = %q, want a long branch cut to 24 cells", header)
	}
}

func TestTheBranchGivesWayBeforeTheDirectoryDoes(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := newModel(46, 10)
	m.Branch = "feature/picker"
	m.App.Cwd = "/home/dev/projects/phycode"
	header := plain(screen(t, r, m)[0])
	if !strings.Contains(header, "phycode") || !strings.Contains(header, "…") {
		t.Errorf("header = %q, want the name and the cut directory", header)
	}
}
