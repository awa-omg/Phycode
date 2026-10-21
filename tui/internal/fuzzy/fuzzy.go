package fuzzy

import (
	"strings"
	"unicode"
)

const (
	scorePrefix   = 0
	scoreWord     = 1
	scoreContains = 2
	scoreSpread   = 100
)

type Result struct {
	Score     int
	Positions []int
}

func Match(query, text string) (Result, bool) {
	q, t := []rune(strings.ToLower(query)), []rune(strings.ToLower(text))
	if len(q) == 0 {
		return Result{}, true
	}
	if at := index(t, q); at >= 0 {
		return Result{Score: contiguousScore(t, at), Positions: run(at, len(q))}, true
	}
	positions, ok := subsequence(t, q)
	if !ok {
		return Result{}, false
	}
	span := positions[len(positions)-1] - positions[0] + 1 - len(q)
	return Result{Score: scoreSpread + span, Positions: positions}, true
}

func Contains(query, text string) (Result, bool) {
	res, ok := Match(query, text)
	return res, ok && res.Score < scoreSpread
}

func contiguousScore(text []rune, at int) int {
	switch {
	case at == 0:
		return scorePrefix
	case isSeparator(text[at-1]):
		return scoreWord
	}
	return scoreContains + min(at, 50)
}

func subsequence(t, q []rune) ([]int, bool) {
	if !fits(t, q) {
		return nil, false
	}
	positions := make([]int, 0, len(q))
	from := 0
	for k, want := range q {
		found := -1
		for i := from; i < len(t); i++ {
			if t[i] != want {
				continue
			}
			if found < 0 {
				found = i
			}
			if (i == 0 || isSeparator(t[i-1])) && fits(t[i+1:], q[k+1:]) {
				found = i
				break
			}
		}
		positions = append(positions, found)
		from = found + 1
	}
	return positions, true
}

func fits(t, q []rune) bool {
	k := 0
	for _, r := range t {
		if k < len(q) && r == q[k] {
			k++
		}
	}
	return k == len(q)
}

func index(t, q []rune) int {
	for i := 0; i+len(q) <= len(t); i++ {
		match := true
		for j, r := range q {
			if t[i+j] != r {
				match = false
				break
			}
		}
		if match {
			return i
		}
	}
	return -1
}

func run(start, n int) []int {
	out := make([]int, n)
	for i := range out {
		out[i] = start + i
	}
	return out
}

func isSeparator(r rune) bool {
	return unicode.IsSpace(r) || strings.ContainsRune("/-_:.", r)
}
