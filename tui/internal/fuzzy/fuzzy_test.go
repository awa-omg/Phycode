package fuzzy

import (
	"reflect"
	"testing"
)

func TestEmptyQueryMatchesEverythingWithoutPositions(t *testing.T) {
	got, ok := Match("", "anything")
	if !ok || got.Score != 0 || got.Positions != nil {
		t.Errorf("Match(\"\") = %+v, %v; want the best score and no positions", got, ok)
	}
}

func TestMatchIgnoresCaseAndNeedsEveryLetterInOrder(t *testing.T) {
	if _, ok := Match("QWEN", "qwen-coder"); !ok {
		t.Error("an upper case query must match a lower case name")
	}
	for _, query := range []string{"nwq", "qwenx", "z"} {
		if _, ok := Match(query, "qwen-coder"); ok {
			t.Errorf("%q must not match qwen-coder", query)
		}
	}
}

func TestScoresRankPrefixThenWordThenInsideThenSpread(t *testing.T) {
	names := []string{"qwen-coder", "my-qwen", "xqwen", "q-w-e-n"}
	var scores []int
	for _, name := range names {
		got, ok := Match("qwen", name)
		if !ok {
			t.Fatalf("qwen must match %q", name)
		}
		scores = append(scores, got.Score)
	}
	for i := 1; i < len(scores); i++ {
		if scores[i-1] >= scores[i] {
			t.Errorf("scores of %q = %v; want them to rise in that order", names, scores)
			break
		}
	}
	if prefix, _ := Match("qwen", "qwen-coder"); prefix.Score != 0 {
		t.Errorf("a prefix scores %d, want 0", prefix.Score)
	}
}

func TestPositionsMarkTheMatchedRunes(t *testing.T) {
	cases := []struct {
		query, text string
		want        []int
	}{
		{"coder", "qwen-coder", []int{5, 6, 7, 8, 9}},
		{"adddir", "add-dir", []int{0, 1, 2, 4, 5, 6}},
		{"ad", "a-add", []int{2, 3}},
		{"mdl", "/models", []int{1, 3, 5}},
	}
	for _, c := range cases {
		got, ok := Match(c.query, c.text)
		if !ok || !reflect.DeepEqual(got.Positions, c.want) {
			t.Errorf("Match(%q, %q) = %v, %v; want positions %v", c.query, c.text, got.Positions, ok, c.want)
		}
	}
}

func TestPositionsCountRunesNotBytes(t *testing.T) {
	got, ok := Match("é", "café")
	if !ok || !reflect.DeepEqual(got.Positions, []int{3}) {
		t.Errorf("Match(é, café) = %v, %v; want rune index 3", got.Positions, ok)
	}
}

func TestContainsRejectsLettersSpreadOverTheText(t *testing.T) {
	if _, ok := Contains("cod", "cloud"); ok {
		t.Error("cod is spread over cloud, so it is not contained")
	}
	if res, ok := Contains("clo", "cloud"); !ok || res.Score != 0 {
		t.Errorf("Contains(clo, cloud) = %+v, %v; want a prefix match", res, ok)
	}
}
