package app

import (
	"reflect"
	"testing"

	"phycode/tui/internal/protocol"
)

func TestATurnCommandIsBusyAndTheLineAfterItWaitsForIt(t *testing.T) {
	m := testModel()
	m.Commands = append(m.Commands, protocol.Command{Name: "/init", Description: "write AGENTS.md", Turn: true})
	m, cmds := feed(m, typed("/init"), press("enter"), typed("next question"), press("enter"))
	if want := []any{protocol.NewInput("/init")}; !reflect.DeepEqual(cmds, want) {
		t.Errorf("commands = %v, want %v: the line after the turn command waits", cmds, want)
	}
	if !m.Busy || m.TurnStarted.IsZero() || !reflect.DeepEqual(m.Queue, []string{"next question"}) {
		t.Errorf("Busy = %v, TurnStarted set = %v, Queue = %v; want /init running as a turn and the next line queued",
			m.Busy, !m.TurnStarted.IsZero(), m.Queue)
	}
	m, cmds = feed(m, &protocol.TurnEnd{Type: protocol.TypeTurnEnd, Status: "completed"})
	if want := []any{protocol.NewInput("next question")}; !reflect.DeepEqual(cmds, want) || len(m.Queue) != 0 {
		t.Errorf("commands = %v, Queue = %v; want the queued line sent when the turn ends", cmds, m.Queue)
	}
}

func TestATurnCommandWithArgumentsIsBusy(t *testing.T) {
	for _, line := range []string{"/triage", "/triage the failing test"} {
		m := testModel()
		m.Commands = append(m.Commands, protocol.Command{Name: "/triage [ARGUMENTS]", Description: "prompt template", Turn: true})
		m, cmds := sendLine(m, line)
		if want := []any{protocol.NewInput(line)}; !reflect.DeepEqual(cmds, want) || !m.Busy {
			t.Errorf("%q: commands = %v, Busy = %v; want the template sent as a turn", line, cmds, m.Busy)
		}
	}
}

func TestACommandWithoutTurnLeavesTheUIIdle(t *testing.T) {
	m := testModel()
	m.Commands = append(m.Commands, protocol.Command{Name: "/status", Description: "show the status"})
	m.Queue = []string{"/status", "/agents"}
	m, cmds := sendQueued(m)
	if want := []any{protocol.NewInput("/status"), protocol.NewInput("/agents")}; !reflect.DeepEqual(cmds, want) || m.Busy {
		t.Errorf("commands = %v, Busy = %v; want both commands sent at once and no turn", cmds, m.Busy)
	}
}
