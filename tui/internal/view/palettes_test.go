package view

import (
	"fmt"
	"strings"
	"testing"

	"phycode/tui/internal/state"
	"phycode/tui/internal/theme"
)

func TestEveryPaletteDrawsEveryScreenPainted(t *testing.T) {
	trueColour(t)
	scenes := map[string]func(w, h int) state.Model{
		"empty":    func(w, h int) state.Model { return newModel(w, h) },
		"chat":     richModel,
		"diff":     func(w, h int) state.Model { return expandedModel(w, h, modifiedDiff()) },
		"picker":   pickerView,
		"menu":     menuModel,
		"panel":    func(w, h int) state.Model { return panelModel(w, h, panelSkills()) },
		"approval": func(w, h int) state.Model { return approvalOver(richModel(w, h)) },
		"ask":      askModel,
	}
	for _, name := range theme.Names() {
		r := NewRenderer(theme.Build(theme.Lookup(name)))
		for scene, build := range scenes {
			for _, size := range [][2]int{{20, 8}, {40, 12}, {60, 18}, {100, 30}, {160, 50}} {
				t.Run(fmt.Sprintf("%s/%s/%dx%d", name, scene, size[0], size[1]), func(t *testing.T) {
					for i, row := range screen(t, r, build(size[0], size[1])) {
						if !strings.Contains(row, "\x1b[48;") {
							t.Fatalf("row %d has no background: %q", i, plain(row))
						}
					}
				})
			}
		}
	}
}
