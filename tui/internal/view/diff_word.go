package view

import (
	"strings"
	"unicode"

	"github.com/charmbracelet/lipgloss"
)

const maxWordDiff = 200

func (r *Renderer) wordSegments(oldText, newText string) (oldSegs, newSegs []segment) {
	a, b := diffWords(oldText), diffWords(newText)
	if len(a) > maxWordDiff || len(b) > maxWordDiff {
		return []segment{{text: oldText, style: r.styles.DiffDel}}, []segment{{text: newText, style: r.styles.DiffAdd}}
	}
	sameA, sameB := unchangedWords(a, b)
	return highlightWords(a, sameA, r.styles.DiffDel, r.styles.DiffDelWord),
		highlightWords(b, sameB, r.styles.DiffAdd, r.styles.DiffAddWord)
}

func diffWords(s string) []string {
	rs := []rune(s)
	var words []string
	for i := 0; i < len(rs); {
		j := i + 1
		switch {
		case isWordRune(rs[i]):
			for j < len(rs) && isWordRune(rs[j]) {
				j++
			}
		case rs[i] == ' ':
			for j < len(rs) && rs[j] == ' ' {
				j++
			}
		}
		words = append(words, string(rs[i:j]))
		i = j
	}
	return words
}

func isWordRune(r rune) bool {
	return r == '_' || unicode.IsLetter(r) || unicode.IsDigit(r)
}

func unchangedWords(a, b []string) (sameA, sameB []bool) {
	n, m := len(a), len(b)
	lcs := make([][]int, n+1)
	for i := range lcs {
		lcs[i] = make([]int, m+1)
	}
	for i := n - 1; i >= 0; i-- {
		for j := m - 1; j >= 0; j-- {
			if a[i] == b[j] {
				lcs[i][j] = lcs[i+1][j+1] + 1
			} else {
				lcs[i][j] = max(lcs[i+1][j], lcs[i][j+1])
			}
		}
	}

	sameA, sameB = make([]bool, n), make([]bool, m)
	for i, j := 0, 0; i < n && j < m; {
		switch {
		case a[i] == b[j]:
			sameA[i], sameB[j] = true, true
			i++
			j++
		case lcs[i+1][j] >= lcs[i][j+1]:
			i++
		default:
			j++
		}
	}
	return sameA, sameB
}

func highlightWords(words []string, same []bool, base, hi lipgloss.Style) []segment {
	var segs []segment
	for i := 0; i < len(words); {
		var text strings.Builder
		j := i
		for ; j < len(words) && same[j] == same[i]; j++ {
			text.WriteString(words[j])
		}
		style := hi
		if same[i] {
			style = base
		}
		segs = append(segs, segment{text: text.String(), style: style})
		i = j
	}
	return segs
}
