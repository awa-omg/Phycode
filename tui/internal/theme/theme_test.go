package theme

import (
	"os"
	"reflect"
	"regexp"
	"strings"
	"testing"

	"github.com/charmbracelet/glamour"
	"github.com/charmbracelet/lipgloss"
)

func TestMain(m *testing.M) {
	_ = os.Unsetenv("NO_COLOR")
	_ = os.Setenv("CLICOLOR_FORCE", "1")
	os.Exit(m.Run())
}

func TestNamesListTheTenPalettesDefaultFirst(t *testing.T) {
	want := []string{"phycode", "phycode-light", "tokyo-night", "catppuccin", "gruvbox", "rose-pine", "nord", "solarized-dark", "paper", "mono"}
	if got := Names(); !reflect.DeepEqual(got, want) {
		t.Errorf("Names = %v, want %v", got, want)
	}
}

func TestLookupFallsBackToTheDefaultPalette(t *testing.T) {
	for _, name := range []string{"", "no-such-theme", "Paper", "PHYCODE"} {
		if got := Lookup(name); got.Name != NamePhycode {
			t.Errorf("Lookup(%q) = %q, want the default phycode", name, got.Name)
		}
	}
	for _, name := range Names() {
		if got := Lookup(name); got.Name != name {
			t.Errorf("Lookup(%q).Name = %q", name, got.Name)
		}
	}
}

func TestDefaultIsThePhycodePalette(t *testing.T) {
	want := Build(Lookup(NamePhycode))
	if got := Default(); got.Heading.Render("x") != want.Heading.Render("x") || got.Dim.Render("x") != want.Dim.Render("x") {
		t.Error("Default does not draw with the phycode palette")
	}
}

func TestEveryPaletteSetsEveryColour(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		v := reflect.ValueOf(p)
		for i := 0; i < v.NumField(); i++ {
			if field := v.Type().Field(i); field.Type == reflect.TypeOf(lipgloss.Color("")) && v.Field(i).String() == "" {
				t.Errorf("%s: the palette has no %s", name, field.Name)
			}
		}
		if len(p.Tones) < 4 {
			t.Errorf("%s: the palette has %d tones, want at least 4 to tell agents apart", name, len(p.Tones))
		}
	}
}

func TestEveryPaletteIsDarkOrLightAsItsBackgroundSays(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		if dark := luminance(string(p.Bg)) < 0.2; dark != p.Dark {
			t.Errorf("%s: Dark = %v, but the background %s is %s", name, p.Dark, p.Bg, map[bool]string{true: "dark", false: "light"}[!dark])
		}
	}
}

func at(t *testing.T, name, what string, fg, bg lipgloss.Color, ratio float64) {
	t.Helper()
	if got := Contrast(string(fg), string(bg)); got < ratio {
		t.Errorf("%s: %s %s on %s has contrast %.2f, want at least %.1f", name, what, fg, bg, got, ratio)
	}
}

func TestTextReadsOnEverySurfaceOfEveryPalette(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		at(t, name, "text", p.Text, p.Bg, 7)
		at(t, name, "text", p.Text, p.Raised, 7)
		at(t, name, "text", p.Text, p.Overlay, 7)
		at(t, name, "text", p.Text, p.Select, 4.5)
		at(t, name, "muted text", p.Muted, p.Bg, 4.5)
		at(t, name, "muted text", p.Muted, p.Raised, 4.5)
		at(t, name, "muted text", p.Muted, p.Overlay, 4.0)
		at(t, name, "faint text", p.Faint, p.Bg, 2.4)
		at(t, name, "faint text", p.Faint, p.Overlay, 2.0)
	}
}

func TestTheAccentAndTheStatusColoursReadOnEverySurface(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		for _, c := range []struct {
			what string
			col  lipgloss.Color
		}{{"accent", p.Accent}, {"success", p.Success}, {"warning", p.Warning}, {"error", p.Error}, {"info", p.Info}} {
			at(t, name, c.what, c.col, p.Bg, 4.5)
			at(t, name, c.what, c.col, p.Raised, 4.0)
			at(t, name, c.what, c.col, p.Overlay, 4.0)
		}
		at(t, name, "accent", p.Accent, p.Select, 3.5)
	}
}

func TestSurfacesStandApartFromTheBackgroundAndFromEachOther(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		at(t, name, "raised surface", p.Raised, p.Bg, 1.04)
		at(t, name, "overlay surface", p.Overlay, p.Bg, 1.08)
		at(t, name, "overlay surface", p.Overlay, p.Raised, 1.03)
		at(t, name, "highlight", p.Select, p.Bg, 1.2)
		at(t, name, "border", p.Border, p.Bg, 1.3)
		at(t, name, "border", p.Border, p.Overlay, 1.15)
	}
}

func TestTheTintsOfADiffCarryTheirTextAndTheChangedWords(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		at(t, name, "added line", p.Success, p.AddBg, 4.5)
		at(t, name, "removed line", p.Error, p.DelBg, 4.5)
		at(t, name, "text on an added line", p.Text, p.AddBg, 7)
		at(t, name, "text on a removed line", p.Text, p.DelBg, 7)
		at(t, name, "text on an added word", p.Text, p.AddWord, 4.5)
		at(t, name, "text on a removed word", p.Text, p.DelWord, 4.5)
		at(t, name, "an added word", p.AddWord, p.AddBg, 1.15)
		at(t, name, "a removed word", p.DelWord, p.DelBg, 1.15)
	}
}

func TestCodeColoursReadOnTheBackground(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		for what, c := range map[string]lipgloss.Color{
			"keyword": p.Keyword, "function": p.Function, "string": p.String, "number": p.Number,
			"type": p.Type, "operator": p.Operator, "link": p.Link,
		} {
			at(t, name, what, c, p.Bg, 4.5)
		}
		at(t, name, "comment", p.Comment, p.Bg, 2.4)
	}
}

func TestEveryToneReadsOnTheBackground(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		for i, c := range p.Tones {
			if got := Contrast(string(c), string(p.Bg)); got < 4.0 {
				t.Errorf("%s: tone %d %s on %s has contrast %.2f, want at least 4", name, i, c, p.Bg, got)
			}
		}
	}
}

func TestEveryPaletteSetsEveryStyle(t *testing.T) {
	for _, name := range Names() {
		s := Build(Lookup(name))
		v := reflect.ValueOf(s)
		styleType := reflect.TypeOf(lipgloss.Style{})
		for i := 0; i < v.NumField(); i++ {
			field := v.Type().Field(i)
			if field.Type != styleType {
				continue
			}
			if st := v.Field(i).Interface().(lipgloss.Style); !strings.Contains(st.Render("x"), "\x1b[") {
				t.Errorf("%s: style %s draws neither a colour nor an attribute", name, field.Name)
			}
		}
	}
}

func TestPalettesChangeColoursNotLayout(t *testing.T) {
	base := Build(Lookup(NamePhycode))
	styleType := reflect.TypeOf(lipgloss.Style{})
	for _, name := range Names()[1:] {
		other := Build(Lookup(name))
		bv, ov := reflect.ValueOf(base), reflect.ValueOf(other)
		for i := 0; i < bv.NumField(); i++ {
			if bv.Type().Field(i).Type != styleType {
				continue
			}
			want := bv.Field(i).Interface().(lipgloss.Style).Render("width rule")
			got := ov.Field(i).Interface().(lipgloss.Style).Render("width rule")
			if lipgloss.Width(got) != lipgloss.Width(want) || stripANSI(got) != stripANSI(want) {
				t.Errorf("%s: style %s draws %q, want the layout of %q", name, bv.Type().Field(i).Name, stripANSI(got), stripANSI(want))
			}
		}
	}
}

func stripANSI(s string) string {
	var out []rune
	rs := []rune(s)
	for i := 0; i < len(rs); i++ {
		if rs[i] == 0x1b && i+1 < len(rs) && rs[i+1] == '[' {
			i += 2
			for i < len(rs) && !(rs[i] >= '@' && rs[i] <= '~') {
				i++
			}
			continue
		}
		out = append(out, rs[i])
	}
	return string(out)
}

func TestBarPaintsTheSelectBackground(t *testing.T) {
	for _, name := range Names() {
		p := Lookup(name)
		if got := Build(p).Bar.GetBackground(); got != p.Select {
			t.Errorf("%s: Bar background = %v, want the Select colour %v", name, got, p.Select)
		}
	}
}

func TestToneIsStableAndSpreadsAgents(t *testing.T) {
	for _, name := range Names() {
		s := Build(Lookup(name))
		if len(s.Tones) != len(Lookup(name).Tones) {
			t.Fatalf("%s: %d tone styles for %d tone colours", name, len(s.Tones), len(Lookup(name).Tones))
		}
		if s.Tone("explore").Render("x") != s.Tone("explore").Render("x") {
			t.Errorf("%s: the same agent got two styles", name)
		}
		seen := map[lipgloss.TerminalColor]bool{}
		for _, agent := range []string{"explore", "build", "plan", "general", "review"} {
			seen[s.Tone(agent).GetForeground()] = true
		}
		if len(seen) < 3 {
			t.Errorf("%s: five agents share %d tones, want them told apart", name, len(seen))
		}
	}
}

func TestMarkdownStyleDrawsWithGlamourInEveryPalette(t *testing.T) {
	const doc = "# Title\n\nSome **bold**, *slanted* and `code` text with a [link](https://example.com).\n\n- one\n- two\n\n> quoted\n\n```go\nfunc main() { return }\n```\n"
	for _, name := range Names() {
		tr, err := glamour.NewTermRenderer(glamour.WithStyles(Build(Lookup(name)).Markdown), glamour.WithWordWrap(60))
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		out, err := tr.Render(doc)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		plain := stripANSI(out)
		for _, want := range []string{"Title", "bold", "code", "link", "• one", "quoted", "func main"} {
			if !strings.Contains(plain, want) {
				t.Errorf("%s: the rendered markdown lacks %q:\n%s", name, want, plain)
			}
		}
	}
}

func TestMixWalksFromOneColourToTheOther(t *testing.T) {
	if got := mix("#000000", "#ffffff", 0.5); got != "#808080" {
		t.Errorf("mix(black, white, .5) = %s, want #808080", got)
	}
	if mix("#102030", "#ffffff", 0) != "#102030" || mix("#102030", "#ffffff", 1) != "#ffffff" {
		t.Error("mix does not return its ends at 0 and 1")
	}
	if got := Contrast("#000000", "#ffffff"); got < 20.9 || got > 21.1 {
		t.Errorf("Contrast(black, white) = %.2f, want 21", got)
	}
}

func TestThePalettesAreTheThemesTheBackendAccepts(t *testing.T) {
	src, err := os.ReadFile("../../../src/Config/Config.php")
	if err != nil {
		t.Skipf("the backend source is not next to the TUI: %v", err)
	}
	m := regexp.MustCompile(`const THEMES = \[([^\]]*)\]`).FindSubmatch(src)
	if m == nil {
		t.Fatal("Config::THEMES not found in the backend")
	}
	var php []string
	for _, name := range strings.Split(string(m[1]), ",") {
		php = append(php, strings.Trim(strings.TrimSpace(name), `'"`))
	}
	if got := Names(); !reflect.DeepEqual(got, php) {
		t.Errorf("palettes = %v, backend themes = %v", got, php)
	}
}
