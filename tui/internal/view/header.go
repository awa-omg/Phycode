package view

import (
	"os"
	"strings"

	"github.com/charmbracelet/lipgloss"

	"phycode/tui/internal/state"
)

const headerGap = 2

func (r *Renderer) header(m state.Model, w int) string {
	inner := max(w-2, 0)
	brand := []segment{{text: "◆ ", style: r.styles.Emphasis}, {text: "phycode", style: r.styles.Heading}}
	items := r.headerValues(m)
	dir := displayDir(oneLine(m.App.Cwd))
	branch := ellipsize(oneLine(m.Branch), 24)

	left, right := brand, ""
	var branchSeg []segment
	if branch != "" {
		branchSeg = []segment{{text: "  ", style: r.styles.Dim}, {text: branch, style: r.styles.Accent}}
	}
	if k, text, ok := fitHeader(segmentsWidth(brand), items, dir, segmentsWidth(branchSeg), inner); ok {
		left, right = append(left, flatten(items[:k])...), text
	} else if k, text, ok := fitHeader(segmentsWidth(brand), items, dir, 0, inner); ok {
		left, right, branchSeg = append(left, flatten(items[:k])...), text, nil
	} else {
		left, branchSeg = cutSegments(brand, inner), nil
	}
	rightSegs := []segment{{text: right, style: r.styles.Dim}}
	if right != "" {
		rightSegs = append(rightSegs, branchSeg...)
	} else {
		rightSegs = nil
	}
	gap := inner - segmentsWidth(left) - segmentsWidth(rightSegs)
	row := renderSegments(left) + spaces(gap) + renderSegments(rightSegs)
	return band(" "+fit(row, inner)+" ", w, r.bg.raised)
}

func fitHeader(nameW int, items [][]segment, dir string, suffixW, inner int) (kept int, shown string, ok bool) {
	dirW := lipgloss.Width(dir)
	try := func(k, minDir int) (string, bool) {
		room := inner - nameW - segmentsWidth(flatten(items[:k]))
		if dir == "" {
			return "", room >= 0
		}
		dirRoom := room - headerGap - suffixW
		switch {
		case dirRoom >= dirW:
			return dir, true
		case dirRoom >= min(dirW, minDir) && dirRoom >= 2:
			return pathCut(dir, dirRoom), true
		}
		return "", false
	}
	for k := len(items); k >= 0; k-- {
		minDir := 20
		if k < min(2, len(items)) {
			minDir = 12
		}
		if text, found := try(k, minDir); found {
			return k, text, true
		}
	}
	return 0, "", false
}

func pathCut(path string, n int) string {
	cut := tailCut(path, n)
	if lipgloss.Width(path) <= n {
		return cut
	}
	if i := strings.Index(cut, "/"); i > 0 {
		return "…" + cut[i:]
	}
	return cut
}

func flatten(items [][]segment) []segment {
	var out []segment
	for _, it := range items {
		out = append(out, it...)
	}
	return out
}

func (r *Renderer) headerValues(m state.Model) [][]segment {
	sep := segment{text: " · ", style: r.styles.Faint}
	if !m.Ready {
		return [][]segment{{sep, {text: "connecting…", style: r.styles.Dim}}}
	}
	var out [][]segment
	add := func(key, value string) {
		value = oneLine(value)
		if value == "" {
			return
		}
		item := []segment{sep}
		if key != "" {
			item = append(item, segment{text: key + " ", style: r.styles.Dim})
		}
		out = append(out, append(item, segment{text: value, style: r.styles.Output}))
	}
	add("agent", m.App.Agent)
	add("", m.App.Profile)
	add("tools", m.App.ToolMode)
	add("sandbox", m.App.Sandbox)
	add("permissions", m.App.PermissionMode)
	return out
}

func displayDir(dir string) string {
	home, err := os.UserHomeDir()
	home = strings.TrimRight(home, "/")
	if err != nil || home == "" || dir == "" {
		return dir
	}
	if dir == home {
		return "~"
	}
	if strings.HasPrefix(dir, home+"/") {
		return "~" + dir[len(home):]
	}
	return dir
}

func tailCut(s string, n int) string {
	if n <= 0 {
		return ""
	}
	if lipgloss.Width(s) <= n {
		return s
	}
	rs := []rune(s)
	budget := n - 1
	i := len(rs)
	for i > 0 && cellWidth(rs[i-1]) <= budget {
		budget -= cellWidth(rs[i-1])
		i--
	}
	return "…" + string(rs[i:])
}
