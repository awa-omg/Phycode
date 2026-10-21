package state

import (
	"reflect"
	"testing"
	"time"
)

var epoch = time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)

func agentsModel() Model {
	m := New()
	m.Blocks = []Block{
		{Kind: KindUser, Text: "go"},
		{Kind: KindSubagent, ID: "s1", Agent: "explore", Text: "find the\ncallers", Status: "running", Started: epoch},
		{Kind: KindTool, ID: "t1", Parent: "s1", Depth: 1, Tool: "grep", Status: "ok"},
		{Kind: KindTool, ID: "t2", Parent: "s1", Depth: 1, Tool: "read", Status: "running"},
		{Kind: KindSubagent, ID: "s2", Agent: "lint", Text: "check style", Status: "running", Parent: "s1", Depth: 1, Started: epoch.Add(5 * time.Second)},
		{Kind: KindSubagent, ID: "s3", Agent: "general", Text: "write notes", Status: "completed", Started: epoch, Ended: epoch.Add(90 * time.Second)},
		{Kind: KindSubagent, ID: "s4", Agent: "review", Text: "review", Status: "budget", Started: epoch, Ended: epoch.Add(3 * time.Second)},
	}
	return m
}

func TestElapsedRunsUntilNowWhileASubagentRunsAndUntilItEndedAfterwards(t *testing.T) {
	running := Block{Started: epoch}
	if got := running.Elapsed(epoch.Add(12 * time.Second)); got != 12*time.Second {
		t.Errorf("a running subagent has run %v, want 12s", got)
	}
	done := Block{Started: epoch, Ended: epoch.Add(5 * time.Second)}
	if got := done.Elapsed(epoch.Add(time.Hour)); got != 5*time.Second {
		t.Errorf("a finished subagent has run %v, want 5s whatever the time is", got)
	}
	if got := (Block{}).Elapsed(epoch); got != 0 {
		t.Errorf("a block without a start has run %v, want 0", got)
	}
	if got := (Block{Started: epoch.Add(time.Minute)}).Elapsed(epoch); got != 0 {
		t.Errorf("a start in the future gives %v, want 0", got)
	}
}

func TestFormatElapsedReadsSecondsMinutesAndHours(t *testing.T) {
	for d, want := range map[time.Duration]string{
		0: "0s", 12400 * time.Millisecond: "12s", 59 * time.Second: "59s", 60 * time.Second: "1m 00s",
		64 * time.Second: "1m 04s", 59*time.Minute + 59*time.Second: "59m 59s", 2*time.Hour + 5*time.Minute: "2h 05m",
	} {
		if got := FormatElapsed(d); got != want {
			t.Errorf("FormatElapsed(%v) = %q, want %q", d, got, want)
		}
	}
}

func TestSubagentsCountsAndListsThemInOrder(t *testing.T) {
	m := agentsModel()
	var names []string
	for _, b := range m.Subagents() {
		names = append(names, b.Agent)
	}
	if want := []string{"explore", "lint", "general", "review"}; !reflect.DeepEqual(names, want) {
		t.Errorf("subagents = %v, want %v", names, want)
	}
	if n := m.RunningSubagents(); n != 2 {
		t.Errorf("RunningSubagents = %d, want 2", n)
	}
}

func TestWaitingNeedsAnApprovalFromAnAgentBelowTheMainOne(t *testing.T) {
	m := agentsModel()
	explore := m.Blocks[1]
	if m.Waiting(explore) {
		t.Error("a subagent waits with no approval pending")
	}
	m.Confirm = &Confirm{Agent: "explore", Depth: 0}
	if m.Waiting(explore) {
		t.Error("an approval of the main agent is not a subagent's")
	}
	m.Confirm = &Confirm{Agent: "explore", Depth: 1}
	if !m.Waiting(explore) {
		t.Error("the subagent that asked does not wait")
	}
	if m.Waiting(m.Blocks[4]) || m.Waiting(m.Blocks[5]) {
		t.Error("a subagent that did not ask, or that is done, waits")
	}
}

func TestToolCallsAreTheToolCardsDirectlyUnderASubagent(t *testing.T) {
	m := agentsModel()
	if got := ToolCalls(m.Blocks, "s1"); len(got) != 2 || got[0].ID != "t1" || got[1].ID != "t2" {
		t.Errorf("ToolCalls(s1) = %+v, want t1 and t2 and not the nested subagent", got)
	}
	if got := ToolCalls(m.Blocks, ""); got != nil {
		t.Errorf("ToolCalls(\"\") = %+v, want none", got)
	}
}

func TestTasksPanelGroupsBySituationWithWhatNeedsTheUserFirst(t *testing.T) {
	m := agentsModel()
	m.Confirm = &Confirm{Agent: "lint", Depth: 1}
	p := TasksPanel(m, epoch.Add(20*time.Second))
	if !p.Open || p.Kind != "tasks" || !p.IsList() || p.Title != "Subagents" {
		t.Fatalf("panel = %+v, want an open list titled Subagents", p)
	}
	var got []string
	for _, it := range p.Visible() {
		got = append(got, it.Category+"/"+it.Key)
	}
	want := []string{"Needs you/s2", "Working/s1", "Done/s3", "Failed/s4"}
	if !reflect.DeepEqual(got, want) {
		t.Errorf("rows = %v, want %v", got, want)
	}
}

func TestTaskRowsSayWhoWhatAndHowLong(t *testing.T) {
	m := agentsModel()
	p := TasksPanel(m, epoch.Add(20*time.Second))
	byKey := map[string]int{}
	for i, it := range p.Visible() {
		byKey[it.Key] = i
	}
	explore := p.Visible()[byKey["s1"]]
	if explore.Title != "explore" || explore.Detail != "find the callers" || explore.Badge != "20s · 2 tools" || explore.Status != "running" {
		t.Errorf("explore row = %+v", explore)
	}
	lint := p.Visible()[byKey["s2"]]
	if lint.Title != "  lint" || lint.Badge != "15s" {
		t.Errorf("lint row = %+v, want it indented under explore with 15s", lint)
	}
	if done := p.Visible()[byKey["s3"]]; done.Status != "ok" || done.Badge != "1m 30s" {
		t.Errorf("general row = %+v", done)
	}
	if failed := p.Visible()[byKey["s4"]]; failed.Status != "error" || failed.Badge != "budget · 3s" {
		t.Errorf("review row = %+v, want how it ended in the badge", failed)
	}
}

func TestWithTasksKeepsTheHighlightOnTheSameSubagent(t *testing.T) {
	m := agentsModel()
	p := TasksPanel(m, epoch)
	p.Selected = 2
	if id, _ := p.SubagentID(); id != "s3" {
		t.Fatalf("highlighted = %q, want s3", id)
	}
	m.Blocks[1].Status = "completed"
	m.Blocks[1].Ended = epoch
	p = p.WithTasks(m, epoch)
	if id, ok := p.SubagentID(); !ok || id != "s3" {
		t.Errorf("highlighted = %q after the rows moved, want s3 kept", id)
	}
	m.Blocks = m.Blocks[:1]
	p = p.WithTasks(m, epoch)
	if _, ok := p.SubagentID(); ok || p.Selected != 0 {
		t.Errorf("Selected = %d with no subagent left, want 0 and nothing highlighted", p.Selected)
	}
}
