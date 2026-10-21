package view

import (
	"fmt"
	"reflect"
	"strings"
	"testing"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/protocol"
	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func lineNo(n int) *int { return &n }

func ctxLine(text string, oldNo, newNo int) protocol.DiffLine {
	return protocol.DiffLine{Kind: protocol.DiffCtx, Text: text, OldNo: lineNo(oldNo), NewNo: lineNo(newNo)}
}

func addLine(text string, newNo int) protocol.DiffLine {
	return protocol.DiffLine{Kind: protocol.DiffAdd, Text: text, NewNo: lineNo(newNo)}
}

func delLine(text string, oldNo int) protocol.DiffLine {
	return protocol.DiffLine{Kind: protocol.DiffDel, Text: text, OldNo: lineNo(oldNo)}
}

func modifiedDiff() protocol.Diff {
	return protocol.Diff{Path: "a.go", Added: 1, Removed: 1, Hunks: []protocol.Hunk{{
		OldStart: 10, OldLines: 3, NewStart: 10, NewLines: 3,
		Lines: []protocol.DiffLine{
			ctxLine("func a() {", 10, 10),
			delLine("\treturn 1", 11),
			addLine("\treturn 2", 11),
			ctxLine("}", 12, 12),
		},
	}}}
}

func addedDiff() protocol.Diff {
	return protocol.Diff{Path: "new.go", Added: 3, Hunks: []protocol.Hunk{{
		NewStart: 1, NewLines: 3,
		Lines: []protocol.DiffLine{addLine("package new", 1), addLine("", 2), addLine("func New() {}", 3)},
	}}}
}

func deletedDiff() protocol.Diff {
	return protocol.Diff{Path: "old.go", Removed: 2, Hunks: []protocol.Hunk{{
		OldStart: 1, OldLines: 2,
		Lines: []protocol.DiffLine{delLine("package old", 1), delLine("func Old() {}", 2)},
	}}}
}

func twoHunkDiff() protocol.Diff {
	return protocol.Diff{Path: "b.txt", Added: 2, Removed: 1, Hunks: []protocol.Hunk{
		{
			OldStart: 1, OldLines: 4, NewStart: 1, NewLines: 4,
			Lines: []protocol.DiffLine{
				ctxLine("alpha", 1, 1),
				delLine("beta", 2),
				addLine("BETA", 2),
				ctxLine("gamma", 3, 3),
				ctxLine("delta", 4, 4),
			},
		},
		{
			OldStart: 40, OldLines: 1, NewStart: 40, NewLines: 2,
			Lines: []protocol.DiffLine{ctxLine("omega", 40, 40), addLine("ALPHA", 41)},
		},
	}}
}

func longDiff(n int) protocol.Diff {
	lines := make([]protocol.DiffLine, n)
	for i := range lines {
		lines[i] = addLine(fmt.Sprintf("line %02d", i+1), i+1)
	}
	return protocol.Diff{Path: "long.txt", Added: n, Hunks: []protocol.Hunk{{NewStart: 1, NewLines: n, Lines: lines}}}
}

func wideDiff() protocol.Diff {
	return protocol.Diff{Path: "データ/文件.txt", Added: 1, Removed: 1, Hunks: []protocol.Hunk{{
		OldStart: 1, OldLines: 2, NewStart: 1, NewLines: 2,
		Lines: []protocol.DiffLine{
			delLine("漢字 テキスト "+strings.Repeat("長い行 ", 20), 1),
			addLine("漢字 変更 "+strings.Repeat("字 ", 60), 1),
			ctxLine("end", 2, 2),
		},
	}}}
}

func binaryDiff() protocol.Diff {
	return protocol.Diff{Path: "logo.png", Binary: true}
}

func diffModel(w, h int, d protocol.Diff) state.Model {
	m := newModel(w, h)
	m.Blocks = []state.Block{{Kind: state.KindTool, ID: "t1", Tool: "write_file", Summary: "a.go", Status: "ok", Diff: &d}}
	return m
}

func expandedModel(w, h int, d protocol.Diff) state.Model {
	m := diffModel(w, h, d)
	m.ToolsExpanded = true
	return m
}

func TestDiffCardShowsThePathAndTheCounts(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, diffModel(80, 24, modifiedDiff()))
	line := rowWith(plainRows(rows), "✓ write_file  a.go")
	if line == "" || !strings.HasSuffix(strings.TrimRight(line, " "), "+1 −1") {
		t.Errorf("the line of the call lacks the path or the counts at its right edge: %q", plainRows(rows))
	}
}

func TestDiffCardReplacesTheResultWithTheDiff(t *testing.T) {
	r := NewRenderer(theme.Default())
	m := diffModel(80, 24, modifiedDiff())
	m.Blocks[0].Text = "edited a.go"
	rows := screen(t, r, m)
	if rowOf(rows, "edited a.go") >= 0 || rowOf(rows, "return 2") < 0 {
		t.Errorf("the card shows the result instead of the diff: %q", plainRows(rows))
	}
}

func TestDiffCardDrawsAddsDeletesAndChanges(t *testing.T) {
	r := NewRenderer(theme.Default())
	tests := []struct {
		name string
		d    protocol.Diff
		want []string
	}{
		{"add", addedDiff(), []string{"+ package new", "+ func New() {}"}},
		{"delete", deletedDiff(), []string{"- package old", "- func Old() {}"}},
		{"modify", modifiedDiff(), []string{"-     return 1", "+     return 2"}},
	}
	for _, tt := range tests {
		rows := screen(t, r, diffModel(80, 24, tt.d))
		for _, want := range tt.want {
			if rowOf(rows, want) < 0 {
				t.Errorf("%s: the row %q is missing: %q", tt.name, want, plainRows(rows))
			}
		}
	}
}

func TestDiffCardShowsOnlyTheFirstHunkUntilExpanded(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, diffModel(80, 24, twoHunkDiff()))
	if rowOf(rows, "alpha") < 0 || rowOf(rows, "omega") >= 0 {
		t.Errorf("the collapsed card shows the wrong hunks: %q", plainRows(rows))
	}
	if rowOf(rows, "… 2 lines hidden (ctrl+o to expand)") < 0 {
		t.Errorf("the card does not count the hidden lines: %q", plainRows(rows))
	}

	rows = screen(t, r, expandedModel(80, 24, twoHunkDiff()))
	for _, want := range []string{"alpha", "omega", "ALPHA", "@@ -40,1 +40,2 @@"} {
		if rowOf(rows, want) < 0 {
			t.Errorf("the expanded card lacks %q: %q", want, plainRows(rows))
		}
	}
	if rowOf(rows, "hidden") >= 0 {
		t.Errorf("the expanded card still hides lines: %q", plainRows(rows))
	}
}

func TestLongHunkFoldsUntilExpanded(t *testing.T) {
	r := NewRenderer(theme.Default())
	d := longDiff(50)
	rows := screen(t, r, diffModel(80, 24, d))
	if rowOf(rows, "… 50 lines hidden (ctrl+o to expand)") < 0 || rowOf(rows, "line 01") >= 0 {
		t.Errorf("a hunk of 50 lines does not fold into its marker: %q", plainRows(rows))
	}

	rows = screen(t, r, expandedModel(80, 70, d))
	if rowOf(rows, "line 01") < 0 || rowOf(rows, "line 50") < 0 || rowOf(rows, "hidden") >= 0 {
		t.Errorf("the expanded card does not show every line: %q", plainRows(rows))
	}
}

func TestCollapsedCardShowsTwentyRows(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, diffModel(80, 40, longDiff(30)))
	if rowOf(rows, "line 19") < 0 || rowOf(rows, "line 20") >= 0 {
		t.Errorf("the card does not stop at the nineteenth line of a hunk: %q", plainRows(rows))
	}
	if rowOf(rows, "… 11 lines hidden (ctrl+o to expand)") < 0 {
		t.Errorf("the card does not count the eleven hidden lines: %q", plainRows(rows))
	}
}

func TestBinaryFileSaysItChanged(t *testing.T) {
	r := NewRenderer(theme.Default())
	rows := screen(t, r, diffModel(80, 24, binaryDiff()))
	if row := rowWith(plainRows(rows), "logo.png"); !strings.Contains(row, "+0 −0") || rowOf(rows, "binary file changed") < 0 {
		t.Errorf("the binary card lacks the path or the note: %q", plainRows(rows))
	}

	d := binaryDiff()
	m := newModel(80, 60)
	m.Confirm = &state.Confirm{ID: "c", Tool: "write_file", Category: "write", Agent: "build", Summary: "logo.png", Preview: &d}
	if rowOf(screen(t, r, m), "binary file changed") < 0 {
		t.Error("the approval preview of a binary file lacks the note")
	}
}

func TestSideBySideFromWidth120(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, tt := range []struct {
		w     int
		split bool
	}{{120, true}, {119, false}} {
		rows := screen(t, r, diffModel(tt.w, 24, modifiedDiff()))
		i := rowOf(rows, "return 1")
		if i < 0 {
			t.Fatalf("width %d: the deleted line is missing: %q", tt.w, plainRows(rows))
		}
		if got := strings.Contains(plain(rows[i]), "return 2"); got != tt.split {
			t.Errorf("width %d: the new line shares the row of the old one = %v, want %v: %q", tt.w, got, tt.split, plain(rows[i]))
		}
	}
}

func TestSplitViewRule(t *testing.T) {
	tests := []struct {
		mode           string
		screen, w      int
		wantSideBySide bool
	}{
		{"auto", 120, 114, true},
		{"", 120, 114, true},
		{"auto", 119, 114, false},
		{"auto", 300, 50, false},
		{"auto", 300, 51, true},
		{"split", 80, 74, true},
		{"split", 40, 34, false},
		{"unified", 200, 194, false},
		{"bogus", 120, 114, true},
	}
	for _, tt := range tests {
		if got := splitView(tt.mode, tt.screen, tt.w); got != tt.wantSideBySide {
			t.Errorf("splitView(%q, screen %d, width %d) = %v, want %v", tt.mode, tt.screen, tt.w, got, tt.wantSideBySide)
		}
	}
}

func TestIntralineHighlightsOnlyTheChangedWords(t *testing.T) {
	r := NewRenderer(theme.Default())
	for _, w := range []int{80, 120} {
		rows := screen(t, r, diffModel(w, 24, modifiedDiff()))
		del, add := rowOf(rows, "return 1"), rowOf(rows, "return 2")
		if del < 0 || add < 0 {
			t.Fatalf("width %d: the changed lines are missing: %q", w, plainRows(rows))
		}
		st := theme.Default()
		if !strings.Contains(rows[del], st.DiffDelWord.Render("1")) || strings.Contains(rows[del], st.DiffDelWord.Render("return")) {
			t.Errorf("width %d: the deleted line does not highlight only its changed word: %q", w, rows[del])
		}
		if !strings.Contains(rows[add], st.DiffAddWord.Render("2")) || strings.Contains(rows[add], st.DiffAddWord.Render("return")) {
			t.Errorf("width %d: the added line does not highlight only its changed word: %q", w, rows[add])
		}
	}
}

func checkRows(t *testing.T, what string, w int, rows []string) {
	t.Helper()
	for _, row := range rows {
		if lipgloss.Width(row) > w {
			t.Fatalf("width %d: a %s row is %d cells wide: %q", w, what, lipgloss.Width(row), plain(row))
		}
	}
}

func TestDiffRowsNeverExceedTheWidth(t *testing.T) {
	r := NewRenderer(theme.Default())
	small := []protocol.Diff{modifiedDiff(), addedDiff(), deletedDiff(), twoHunkDiff(), binaryDiff()}
	for _, w := range []int{1, 5, 20, 51, 80, 120} {
		for _, d := range small {
			for _, split := range []bool{false, true} {
				checkRows(t, "card", w, r.cardDiff(d, w, split, false))
				checkRows(t, "expanded card", w, r.cardDiff(d, w, split, true))
			}
			checkRows(t, "preview", w, r.previewRows(d, w, previewDiffRows))
		}
	}

	diffs := append(small, wideDiff())
	for _, w := range []int{20, 80, 120} {
		for _, d := range diffs {
			checkRows(t, "card", w, r.cardDiff(d, w, true, false))
			checkRows(t, "expanded card", w, r.cardDiff(d, w, false, true))
			checkRows(t, "preview", w, r.previewRows(d, w, previewDiffRows))
		}
	}

	for _, w := range []int{20, 80, 120} {
		for _, d := range diffs {
			m := diffModel(w, 30, d)
			m.Blocks[0].Depth = 1
			screen(t, r, m)
			m.Blocks[0].Depth = 0
			m.Confirm = &state.Confirm{ID: "c", Tool: "write_file", Category: "write", Agent: "build", Summary: "edit a.go", Preview: &d}
			screen(t, r, m)
		}
	}
}

func TestApprovalPreviewSitsAboveTheKeys(t *testing.T) {
	r := NewRenderer(theme.Default())
	d := modifiedDiff()
	m := newModel(80, 60)
	m.Confirm = &state.Confirm{ID: "c", Tool: "write_file", Category: "write", Agent: "build", Summary: "edit a.go", Preview: &d}
	rows := screen(t, r, m)
	head, change, keys := rowOf(rows, "a.go  +1 −1"), rowOf(rows, "return 2"), rowOf(rows, "y once")
	if head < 0 || change < 0 || keys < 0 || !(head < change && change < keys) {
		t.Fatalf("the preview is not between the summary and the keys (head %d, change %d, keys %d): %q", head, change, keys, plainRows(rows))
	}
	for _, hint := range []string{"y once", "a always this session", "n deny", "esc deny"} {
		if rowOf(rows, hint) < 0 {
			t.Errorf("the key hint %q is missing: %q", hint, plainRows(rows))
		}
	}
}

func TestApprovalPreviewIsCappedAtThirtyRows(t *testing.T) {
	r := NewRenderer(theme.Default())
	d := longDiff(100)
	m := newModel(80, 60)
	m.Confirm = &state.Confirm{ID: "c", Tool: "write_file", Category: "write", Agent: "build", Summary: "edit long.txt", Preview: &d}
	rows := screen(t, r, m)
	head, keys := rowOf(rows, "long.txt  +100 −0"), rowOf(rows, "y once")
	if head < 0 || keys < 0 {
		t.Fatalf("the preview or the keys are missing: %q", plainRows(rows))
	}
	if got := keys - head - 1; got != previewDiffRows {
		t.Errorf("the preview takes %d rows, want %d", got, previewDiffRows)
	}
	if rowOf(rows, "… 72 more lines") < 0 {
		t.Errorf("the preview does not say how many lines it leaves out: %q", plainRows(rows))
	}
}

func TestApprovalPreviewKeepsTheKeysOnSmallScreens(t *testing.T) {
	r := NewRenderer(theme.Default())
	tests := []struct {
		w, h int
		keys []string
	}{
		{w: 80, h: 24, keys: []string{"y once", "n deny"}},
		{w: 40, h: 14, keys: []string{"y once", "n deny"}},
		{w: 20, h: 8, keys: []string{"y once", "n deny"}},
	}
	for _, tt := range tests {
		d := longDiff(100)
		m := newModel(tt.w, tt.h)
		m.Confirm = &state.Confirm{ID: "c", Tool: "bash", Category: "exec", Agent: "build", Summary: "rm -rf build", Preview: &d}
		rows := screen(t, r, m)
		for _, key := range tt.keys {
			if rowOf(rows, key) < 0 {
				t.Errorf("%dx%d: %q is not visible: %q", tt.w, tt.h, key, plainRows(rows))
			}
		}
	}
}

func TestApprovalPreviewFitsSmallSizes(t *testing.T) {
	r := NewRenderer(theme.Default())
	d := twoHunkDiff()
	for _, w := range []int{1, 7, 12, 20, 24, 40} {
		for _, h := range []int{1, 4, 6, 8, 10, 14, 24} {
			m := newModel(w, h)
			m.Blocks = []state.Block{{Kind: state.KindUser, Text: "fix it"}}
			m.Confirm = &state.Confirm{ID: "c", Tool: "write_file", Category: "write", Agent: "build", Summary: strings.Repeat("summary ", 12), Preview: &d}
			screen(t, r, m)
		}
	}
}

func TestDiffWordsRebuildTheLine(t *testing.T) {
	for _, s := range []string{"", "return 1", "    x := f(a, b)", "漢字 テキスト  end_1", "a+b-c"} {
		if got := strings.Join(diffWords(s), ""); got != s {
			t.Errorf("diffWords(%q) joins back to %q", s, got)
		}
	}
}

func TestUnchangedWordsKeepTheSharedWords(t *testing.T) {
	a, b := diffWords("return 1"), diffWords("return 2")
	sameA, sameB := unchangedWords(a, b)
	want := []bool{true, true, false}
	if !reflect.DeepEqual(sameA, want) || !reflect.DeepEqual(sameB, want) {
		t.Errorf("unchangedWords(%q, %q) = %v, %v; want %v for both", a, b, sameA, sameB, want)
	}
}

func TestPairLinesPairsEachRunOfDeletesWithTheAddsAfterIt(t *testing.T) {
	lines := []protocol.DiffLine{
		ctxLine("a", 1, 1),
		delLine("b", 2),
		delLine("c", 3),
		addLine("B", 2),
		ctxLine("d", 4, 3),
		addLine("e", 4),
	}
	if got, want := pairLines(lines), []int{-1, 3, -1, 1, -1, -1}; !reflect.DeepEqual(got, want) {
		t.Errorf("pairLines = %v, want %v", got, want)
	}
}
