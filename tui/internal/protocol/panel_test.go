package protocol

import (
	"reflect"
	"testing"
)

func TestParsePanelKeepsItsItemsInOrder(t *testing.T) {
	ev := parseLine(t, `{"type":"panel","title":"Skills","kind":"list","hint":"enter opens",
		"items":[
			{"category":"Project","title":"tdd","detail":"write tests first","badge":"project","status":"ok","path":"/p/tdd/SKILL.md"},
			{"category":"Global","title":"review","detail":"","status":"off"}
		]}`)
	got, ok := ev.(*Panel)
	if !ok {
		t.Fatalf("Parse returned %T, want *Panel", ev)
	}
	want := &Panel{
		Type: TypePanel, Title: "Skills", Kind: "list", Hint: "enter opens",
		Items: []PanelItem{
			{Category: "Project", Title: "tdd", Detail: "write tests first", Badge: "project", Status: "ok", Path: "/p/tdd/SKILL.md"},
			{Category: "Global", Title: "review", Status: "off"},
		},
	}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("Panel = %+v, want %+v", got, want)
	}
}

func TestParsePanelTextAndNullFields(t *testing.T) {
	ev := parseLine(t, `{"type":"panel","title":"Help","kind":"text","text":"one\ntwo","hint":null,"items":null}`)
	got, ok := ev.(*Panel)
	if !ok {
		t.Fatalf("Parse returned %T, want *Panel", ev)
	}
	want := &Panel{Type: TypePanel, Title: "Help", Kind: "text", Text: "one\ntwo"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("Panel = %+v, want %+v", got, want)
	}
}
