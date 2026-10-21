package view

import (
	"phycode/tui/internal/state"
)

const askTitle = "Question"

func (r *Renderer) askLines(a state.Ask, iw, rows int) []string {
	body, at := r.askBody(a, iw)
	return r.drawOf(body, nil, at, askHint(a), iw, rows).lines(r.styles.Heading.Render(askTitle), 0)
}

func (r *Renderer) askBody(a state.Ask, iw int) ([]string, int) {
	var rows []string
	for _, row := range wrapText(trimBlank(sanitize(a.Question)), iw) {
		rows = append(rows, r.styles.Output.Render(row))
	}
	if len(rows) > 0 && (len(a.Options) > 0 || a.FreeText) {
		rows = append(rows, "")
	}
	at := -1
	for i, opt := range a.Options {
		style := r.styles.Output
		if i == a.Selected {
			style = r.styles.Heading
			at = len(rows)
		}
		rows = append(rows, r.bar([]segment{r.marker(i == a.Selected), {text: " "}, {text: oneLine(opt), style: style}}, iw, i == a.Selected))
	}
	if a.FreeText {
		if len(a.Options) == 0 {
			at = len(rows)
		}
		rows = append(rows, r.typeLine(a.Input, "your own answer", iw))
	}
	return rows, at
}

func askHint(a state.Ask) string {
	choose := len(a.Options) > 0
	switch {
	case choose && a.FreeText:
		return "↑↓ choose · type or enter answer · esc skip"
	case choose:
		return "↑↓ choose · enter answer · esc skip"
	case a.FreeText:
		return "type an answer · enter answer · esc skip"
	}
	return "esc skip"
}
