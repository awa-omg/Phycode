package state

import (
	"reflect"
	"slices"
	"testing"

	"phycode/tui/internal/protocol"
)

func words(cmds []Command) []string {
	out := []string{}
	for _, c := range cmds {
		out = append(out, c.Word)
	}
	return out
}

func TestCatalogOrdersTheBuiltInCommandsByUseNotAlphabetically(t *testing.T) {
	cmds := Catalog([]protocol.Command{
		{Name: "/exit"}, {Name: "/diff"}, {Name: "/models"}, {Name: "/help"}, {Name: "/compact [INSTRUCTIONS]"}, {Name: "/agent NAME"},
	})
	want := []string{"/models", "/connect", "/compact", "/copy", "/export", "/diff", "/agent", "/tasks", "/edit", "/web", "/theme", "/details", "/help", "/exit"}
	if got := words(cmds); !reflect.DeepEqual(got, want) {
		t.Errorf("order = %v, want %v: by use, with the commands of the UI among the built-in ones", got, want)
	}
}

func TestCatalogPutsCustomCommandsAfterTheBuiltInOnesInTheOrderListed(t *testing.T) {
	cmds := Catalog([]protocol.Command{{Name: "/zeta"}, {Name: "/help"}, {Name: "/alpha"}, {Name: "/models"}})
	want := []string{"/models", "/connect", "/copy", "/export", "/tasks", "/edit", "/web", "/theme", "/details", "/help", "/zeta", "/alpha"}
	if got := words(cmds); !reflect.DeepEqual(got, want) {
		t.Fatalf("order = %v, want %v", got, want)
	}
	for _, c := range cmds {
		if (c.Word == "/zeta" || c.Word == "/alpha") && c.Group != GroupCustom {
			t.Errorf("%s is in group %q, want Custom", c.Word, c.Group)
		}
	}
}

func TestCatalogKeepsTheVariantsOfACommandTogetherWithTheBareFormFirst(t *testing.T) {
	cmds := Catalog([]protocol.Command{{Name: "/context max N|Nk|off [--save]"}, {Name: "/help"}, {Name: "/context"}})
	if got := words(cmds); !reflect.DeepEqual(got[:3], []string{"/connect", "/context", "/context"}) {
		t.Fatalf("order = %v, want both /context entries together", got)
	}
	if cmds[1].Args != "" || cmds[2].Args == "" {
		t.Errorf("the entries are %q then %q, want the bare form first", cmds[1].Name, cmds[2].Name)
	}
}

func TestCatalogSplitsTheUsageIntoWordAndArguments(t *testing.T) {
	cmds := Catalog([]protocol.Command{{Name: "/effort LEVEL", Description: "set it", Turn: true}})
	c := cmds[slices.IndexFunc(cmds, func(c Command) bool { return c.Word == "/effort" })]
	if c.Word != "/effort" || c.Args != "LEVEL" || c.Name != "/effort LEVEL" || c.Description != "set it" || !c.Turn || c.Group != GroupModel {
		t.Errorf("command = %+v", c)
	}
	if c.Optional() {
		t.Error("an argument that is not in brackets is required")
	}
	if !(Command{Args: "[INSTRUCTIONS]"}).Optional() || !(Command{}).Optional() {
		t.Error("a command without arguments, or with its arguments in brackets, runs bare")
	}
}

func TestCatalogAddsTheCommandsOfTheUIOnceEvenWhenTheBackendListsThem(t *testing.T) {
	plain := words(Catalog(nil))
	if !reflect.DeepEqual(plain, []string{"/connect", "/copy", "/export", "/tasks", "/edit", "/web", "/theme", "/details"}) {
		t.Errorf("an empty list gives %v, want the UI's own commands", plain)
	}
	listed := words(Catalog([]protocol.Command{{Name: "/tasks"}}))
	n := 0
	for _, w := range listed {
		if w == "/tasks" {
			n++
		}
	}
	if n != 1 {
		t.Errorf("/tasks is listed %d times in %v, want once", n, listed)
	}
}

func TestChoicesAreTheWordsAnArgumentCanBe(t *testing.T) {
	cases := map[string][]string{
		"[gentleman|neutral|off]":       {"gentleman", "neutral", "off"},
		"[status|install|uninstall]":    {"status", "install", "uninstall"},
		"LEVEL":                         nil,
		"max N|Nk|off [--save]":         nil,
		"switch ID|new":                 nil,
		"":                              nil,
		"preset [engram|context7|code]": nil,
	}
	for args, want := range cases {
		if got := (Command{Args: args}).Choices(); !reflect.DeepEqual(got, want) {
			t.Errorf("Choices(%q) = %v, want %v", args, got, want)
		}
	}
}

func TestGroupsFollowTheOrderOfTheCommands(t *testing.T) {
	got := Groups(Catalog([]protocol.Command{{Name: "/exit"}, {Name: "/models"}, {Name: "/diff"}, {Name: "/custom"}}))
	want := []string{GroupModel, GroupSession, GroupChanges, GroupAgents, GroupProject, GroupExtensions, GroupHelp, GroupCustom}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("groups = %v, want %v", got, want)
	}
}

func TestCommandsPanelListsGroupsThenSyntaxThenKeys(t *testing.T) {
	p := CommandsPanel([]protocol.Command{{Name: "/help", Description: "show"}, {Name: "/models", Description: "pick"}, {Name: "/mine", Description: "mine"}})
	if !p.Open || p.Kind != "commands" || p.Title != "Commands" || !p.IsList() {
		t.Fatalf("panel = %+v, want an open list titled Commands", p)
	}
	var categories []string
	for _, it := range p.Visible() {
		if len(categories) == 0 || categories[len(categories)-1] != it.Category {
			categories = append(categories, it.Category)
		}
	}
	want := []string{GroupModel, GroupSession, GroupAgents, GroupProject, GroupExtensions, GroupHelp, GroupCustom, CategorySyntax, CategoryKeys}
	if !reflect.DeepEqual(categories, want) {
		t.Errorf("categories = %v, want %v", categories, want)
	}
}

func TestCommandsPanelRowsCarryWhatTheInputBecomes(t *testing.T) {
	p := CommandsPanel([]protocol.Command{
		{Name: "/models"}, {Name: "/effort LEVEL"}, {Name: "/compact [INSTRUCTIONS]"}, {Name: "/mine"},
	})
	keys := map[string]string{}
	for _, it := range p.Items {
		keys[it.Title] = it.Key
	}
	for title, want := range map[string]string{
		"/models": "/models", "/effort LEVEL": "/effort ", "/compact [INSTRUCTIONS]": "/compact", "/mine": "/mine", "@NAME TASK": "@", "enter": "",
	} {
		if got, ok := keys[title]; !ok || got != want {
			t.Errorf("the row %q carries %q (listed: %v), want %q", title, got, ok, want)
		}
	}
	for _, it := range p.Items {
		if it.Title == "/mine" && it.Badge != "custom" {
			t.Errorf("the custom command carries the badge %q, want custom", it.Badge)
		}
	}
}

func TestCommandsPanelFiltersByNameAndDescription(t *testing.T) {
	p := CommandsPanel([]protocol.Command{{Name: "/models", Description: "switch the model"}, {Name: "/diff", Description: "show changes"}})
	p.Query = "switch"
	if got := panelTitles(p.Visible()); !reflect.DeepEqual(got, []string{"/models", "tab"}) {
		t.Errorf("filter switch: %v, want /models and the tab key, which switches the agent", got)
	}
	p.Query = "ctrl+p"
	if got := panelTitles(p.Visible()); !reflect.DeepEqual(got, []string{"ctrl+p"}) {
		t.Errorf("filter ctrl+p: %v, want the key", got)
	}
}
