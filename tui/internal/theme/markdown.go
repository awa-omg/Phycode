package theme

import (
	"github.com/charmbracelet/glamour/ansi"
	"github.com/charmbracelet/lipgloss"
)

func markdownStyle(p Palette) ansi.StyleConfig {
	str := func(c lipgloss.Color) *string { s := string(c); return &s }
	yes := func() *bool { b := true; return &b }
	u := func(n uint) *uint { return &n }
	prim := func(c lipgloss.Color) ansi.StylePrimitive { return ansi.StylePrimitive{Color: str(c)} }
	bold := func(c lipgloss.Color) ansi.StylePrimitive { return ansi.StylePrimitive{Color: str(c), Bold: yes()} }
	block := func(prim ansi.StylePrimitive) ansi.StyleBlock { return ansi.StyleBlock{StylePrimitive: prim} }

	cfg := ansi.StyleConfig{
		Document:  ansi.StyleBlock{StylePrimitive: prim(p.Text), Margin: u(0)},
		Paragraph: block(ansi.StylePrimitive{}),
		BlockQuote: ansi.StyleBlock{
			StylePrimitive: ansi.StylePrimitive{Color: str(p.Muted), Italic: yes()},
			Indent:         u(1), IndentToken: str(lipgloss.Color("│ ")),
		},
		List:    ansi.StyleList{StyleBlock: block(ansi.StylePrimitive{}), LevelIndent: 2},
		Heading: ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{BlockSuffix: "", Bold: yes(), Color: str(p.Text)}},
		H1:      ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{Bold: yes(), Color: str(p.Accent), Prefix: "# "}},
		H2:      ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{Bold: yes(), Color: str(p.Text), Prefix: "## "}},
		H3:      ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{Bold: yes(), Color: str(p.Text), Prefix: "### "}},
		H4:      ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{Bold: yes(), Color: str(p.Muted), Prefix: "#### "}},
		H5:      ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{Bold: yes(), Color: str(p.Muted), Prefix: "##### "}},
		H6:      ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{Color: str(p.Muted), Prefix: "###### "}},

		Text:          prim(p.Text),
		Strikethrough: ansi.StylePrimitive{CrossedOut: yes()},
		Emph:          ansi.StylePrimitive{Italic: yes()},
		Strong:        ansi.StylePrimitive{Bold: yes()},
		HorizontalRule: ansi.StylePrimitive{
			Color:  str(p.Border),
			Format: "\n────────\n",
		},
		Item:        ansi.StylePrimitive{BlockPrefix: "• "},
		Enumeration: ansi.StylePrimitive{BlockPrefix: ". "},
		Task:        ansi.StyleTask{Ticked: "[✓] ", Unticked: "[ ] "},

		Link:      ansi.StylePrimitive{Color: str(p.Link), Underline: yes()},
		LinkText:  bold(p.Link),
		Image:     ansi.StylePrimitive{Color: str(p.Link), Underline: yes()},
		ImageText: prim(p.Muted),

		Code: ansi.StyleBlock{StylePrimitive: ansi.StylePrimitive{
			Color: str(p.Text), BackgroundColor: str(p.Overlay), Prefix: " ", Suffix: " ",
		}},
		CodeBlock: ansi.StyleCodeBlock{
			StyleBlock: ansi.StyleBlock{
				StylePrimitive: prim(p.Text), Indent: u(2), Margin: u(0),
			},
			Chroma: chroma(p),
		},
		Table: ansi.StyleTable{
			StyleBlock:      block(ansi.StylePrimitive{}),
			CenterSeparator: strPtr("┼"), ColumnSeparator: strPtr("│"), RowSeparator: strPtr("─"),
		},
		DefinitionDescription: ansi.StylePrimitive{BlockPrefix: "\n  "},
	}
	return cfg
}

func strPtr(s string) *string { return &s }

func chroma(p Palette) *ansi.Chroma {
	c := func(col lipgloss.Color) ansi.StylePrimitive { s := string(col); return ansi.StylePrimitive{Color: &s} }
	italic := func(col lipgloss.Color) ansi.StylePrimitive {
		s, b := string(col), true
		return ansi.StylePrimitive{Color: &s, Italic: &b}
	}
	return &ansi.Chroma{
		Text:                c(p.Text),
		Error:               c(p.Error),
		Comment:             italic(p.Comment),
		CommentPreproc:      c(p.Keyword),
		Keyword:             c(p.Keyword),
		KeywordReserved:     c(p.Keyword),
		KeywordNamespace:    c(p.Keyword),
		KeywordType:         c(p.Type),
		Operator:            c(p.Operator),
		Punctuation:         c(p.Muted),
		Name:                c(p.Text),
		NameBuiltin:         c(p.Function),
		NameTag:             c(p.Keyword),
		NameAttribute:       c(p.Type),
		NameClass:           c(p.Type),
		NameConstant:        c(p.Number),
		NameDecorator:       c(p.Type),
		NameException:       c(p.Error),
		NameFunction:        c(p.Function),
		NameOther:           c(p.Text),
		Literal:             c(p.String),
		LiteralNumber:       c(p.Number),
		LiteralDate:         c(p.Number),
		LiteralString:       c(p.String),
		LiteralStringEscape: c(p.Operator),
		GenericDeleted:      c(p.Error),
		GenericEmph:         ansi.StylePrimitive{Italic: boolPtr(true)},
		GenericInserted:     c(p.Success),
		GenericStrong:       ansi.StylePrimitive{Bold: boolPtr(true)},
		GenericSubheading:   c(p.Muted),
	}
}

func boolPtr(b bool) *bool { return &b }
