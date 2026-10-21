package state

import (
	"time"

	"phycode/tui/internal/protocol"
)

type BlockKind int

const (
	KindUser BlockKind = iota
	KindAssistant
	KindTool
	KindSubagent
	KindNotice
	KindOutput
)

type Block struct {
	Kind    BlockKind
	ID      string
	Agent   string
	Depth   int
	Tool    string
	Summary string
	Status  string
	Level   string
	Text    string
	Diff    *protocol.Diff
	Parent  string
	Folded  bool
	Started time.Time
	Ended   time.Time
}

type Confirm struct {
	ID       string
	Tool     string
	Category string
	Agent    string
	Depth    int
	Summary  string
	Preview  *protocol.Diff
}

type Ask struct {
	ID       string
	Question string
	Options  []string
	FreeText bool
	Selected int
	Input    string
}

type Exec struct {
	Title string
	Argv  []string
}

const (
	SuggestCommand  = "command"
	SuggestAgent    = "agent"
	SuggestSubagent = "subagent"
	SuggestChoice   = "choice"
)

type Suggestion struct {
	Insert      string
	Label       string
	Description string
	Kind        string
	Group       string
	Match       []int
}

type Picker struct {
	Open       bool
	Query      string
	Selected   int
	Loading    bool
	Pending    bool
	Applying   bool
	Known      bool
	Entries    []protocol.ModelEntry
	Providers  []protocol.Provider
	Favorites  []protocol.ModelRef
	Recents    []protocol.ModelRef
	Presets    []protocol.Preset
	Current    protocol.ModelRef
	Level      string
	Error      string
	Keep       *protocol.ModelRef
	CustomOpen bool
	CustomID   string
	CustomFor  string
	Form       *ProviderForm
	Connect    *Connect
	Detected   []protocol.Endpoint
	Detecting  bool
	Asked      time.Time
}

type Panel struct {
	Open     bool
	Title    string
	Kind     string
	Text     string
	Hint     string
	Items    []protocol.PanelItem
	Query    string
	Selected int
	Restore  string
	Detail   bool
	Scroll   int
	Loading  bool
	Run      string
	Config   []protocol.ConfigItem
	Note     string
	Editing  bool
	Input    string
	Web      *WebSearch
}

type Model struct {
	Width, Height int

	App            protocol.AppInfo
	Agents         []protocol.Agent
	Commands       []protocol.Command
	Capabilities   []string
	Theme          string
	Editor         string
	Branch         string
	Usage          *protocol.Usage
	Context        protocol.Context
	Effort         protocol.Effort
	Research       string
	Session        protocol.Session
	Compacting     bool
	Autocompacting bool
	Ready          bool
	Error          string
	Quit           bool
	Picker         Picker
	Panel          Panel
	Ask            *Ask
	Exec           *Exec

	Blocks []Block

	DiffView      string
	ToolsExpanded bool

	Input        string
	Cursor       int
	ShellMode    bool
	History      []string
	HistoryIndex int

	Busy        bool
	TurnStarted time.Time
	Frame       int
	Queue       []string
	QueuePaused bool

	Confirm     *Confirm
	Suggestions []Suggestion
	Selected    int
	NoMatch     string

	Scroll  int
	Changes []string
	Flash   string
}

func New() Model {
	return Model{HistoryIndex: -1}
}

func (m Model) Working() bool {
	if m.Busy {
		return true
	}
	for _, b := range m.Blocks {
		if b.Kind == KindSubagent && b.Status == "running" {
			return true
		}
	}
	return false
}
