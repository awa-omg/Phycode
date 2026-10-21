package view

import (
	"strconv"
	"strings"
	"unicode"

	"phycode/tui/internal/state"
)

const execHint = "y run · n cancel · esc cancel"

func (r *Renderer) execLines(ex state.Exec, iw, rows int) []string {
	title := r.styles.Warn.Render("⚠ ") + r.styles.Heading.Render(ellipsize("Run "+oneLine(ex.Title)+"?", max(iw-2, 1)))
	return r.drawOf(r.execBody(ex, iw), nil, -1, execHint, iw, rows).lines(title, 0)
}

func (r *Renderer) execBody(ex state.Exec, iw int) []string {
	var rows []string
	for i, row := range wrapText(displayArgv(ex.Argv), max(iw-2, 1)) {
		lead := "  "
		if i == 0 {
			lead = r.styles.Dim.Render("$ ")
		}
		rows = append(rows, lead+r.styles.Output.Render(row))
	}
	return rows
}

func displayArgv(argv []string) string {
	words := make([]string, len(argv))
	for i, arg := range argv {
		words[i] = arg
		if arg == "" || strings.ContainsFunc(arg, needsQuote) {
			words[i] = strconv.Quote(arg)
		}
	}
	return strings.Join(words, " ")
}

func needsQuote(r rune) bool {
	return r == ' ' || !unicode.IsPrint(r) || strings.ContainsRune(`"'\$`, r)
}
