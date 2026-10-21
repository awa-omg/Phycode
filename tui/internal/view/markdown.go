package view

import (
	"crypto/sha256"
	"strings"

	"github.com/charmbracelet/glamour"
	"github.com/charmbracelet/glamour/ansi"
)

const (
	maxCachedMarkdown      = 1024
	maxCachedMarkdownBytes = 16 << 20
)

type mdKey struct {
	sum   [sha256.Size]byte
	width int
}

type mdRender struct {
	lines []string
	size  int
}

func (r *Renderer) markdown(text string, width int) []string {
	key := mdKey{sum: sha256.Sum256([]byte(text)), width: width}
	if rendered, ok := r.md[key]; ok {
		return rendered.lines
	}
	lines := renderMarkdown(text, width, r.styles.Markdown)
	r.cacheMarkdown(key, lines)
	return lines
}

func (r *Renderer) cacheMarkdown(key mdKey, lines []string) {
	size := 0
	for _, line := range lines {
		size += len(line)
	}
	if size > maxCachedMarkdownBytes {
		return
	}
	if r.md == nil {
		r.md = make(map[mdKey]mdRender)
	}
	for len(r.mdOrder) > 0 && (len(r.md) >= maxCachedMarkdown || r.mdBytes+size > maxCachedMarkdownBytes) {
		oldest := r.mdOrder[0]
		r.mdOrder = r.mdOrder[1:]
		r.mdBytes -= r.md[oldest].size
		delete(r.md, oldest)
	}
	r.md[key] = mdRender{lines: lines, size: size}
	r.mdOrder = append(r.mdOrder, key)
	r.mdBytes += size
}

func renderMarkdown(text string, width int, style ansi.StyleConfig) []string {
	text = trimBlank(text)
	if text == "" {
		return nil
	}
	if width < 8 {
		return wrapText(text, width)
	}
	tr, err := glamour.NewTermRenderer(glamour.WithStyles(style), glamour.WithWordWrap(width))
	if err != nil {
		return wrapText(text, width)
	}
	out, err := tr.Render(text)
	if err != nil {
		return wrapText(text, width)
	}
	lines := trimBlankLines(strings.Split(out, "\n"))
	for i := range lines {
		lines[i] = cut(strings.ReplaceAll(lines[i], "\t", "    "), width)
	}
	return lines
}
