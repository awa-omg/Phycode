package protocol

import "encoding/json"

const Version = 2

const (
	TypeReady         = "ready"
	TypeTurnStart     = "turn_start"
	TypeText          = "text"
	TypeToolStart     = "tool_start"
	TypeToolEnd       = "tool_end"
	TypeSubagentStart = "subagent_start"
	TypeSubagentEnd   = "subagent_end"
	TypeNotice        = "notice"
	TypeOutput        = "output"
	TypeConfirm       = "confirm"
	TypeTurnEnd       = "turn_end"
	TypeBye           = "bye"
	TypeError         = "error"
	TypeModels        = "models"
	TypeModelChanged  = "model_changed"
	TypePanel         = "panel"
	TypeAsk           = "ask"
	TypeConfig        = "config"
	TypeSessions      = "sessions"
	TypeContext       = "context"
	TypeEffort        = "effort"
	TypeProgress      = "progress"
	TypeExec          = "exec"
	TypeEndpoints     = "endpoints"
	TypeAgentChanged  = "agent_changed"
)

const TypeSearchProviders = "search_providers"

const (
	CmdListSearchProviders  = "list_search_providers"
	CmdAddSearchProvider    = "add_search_provider"
	CmdUpdateSearchProvider = "update_search_provider"
	CmdRemoveSearchProvider = "remove_search_provider"
	CmdSetSearchProvider    = "set_search_provider"
)

const (
	CmdInput           = "input"
	CmdConfirmReply    = "confirm_reply"
	CmdInterrupt       = "interrupt"
	CmdQuit            = "quit"
	CmdListModels      = "list_models"
	CmdSetModel        = "set_model"
	CmdFavorite        = "favorite"
	CmdCustomModel     = "custom_model"
	CmdAddProvider     = "add_provider"
	CmdSetProviderKey  = "set_provider_key"
	CmdRemoveProvider  = "remove_provider"
	CmdDetectEndpoints = "detect_endpoints"
	CmdAskReply        = "ask_reply"
	CmdConfigSet       = "config_set"
	CmdSessionList     = "session_list"
	CmdSessionSwitch   = "session_switch"
	CmdSessionNew      = "session_new"
	CmdSetEffort       = "set_effort"
	CmdSetContext      = "set_context"
	CmdCompact         = "compact"
	CmdBtw             = "btw"
	CmdDeepResearch    = "deepresearch"
)

const (
	ConfigBool   = "bool"
	ConfigChoice = "choice"
	ConfigText   = "text"
)

const (
	CustomAdd    = "add"
	CustomRemove = "remove"
)

const (
	DecisionOnce    = "once"
	DecisionSession = "session"
	DecisionDeny    = "deny"
)

type AppInfo struct {
	Version        string  `json:"version"`
	Cwd            string  `json:"cwd"`
	Agent          string  `json:"agent"`
	Profile        string  `json:"profile"`
	Model          string  `json:"model"`
	ToolMode       string  `json:"toolMode"`
	Sandbox        string  `json:"sandbox"`
	PermissionMode string  `json:"permissionMode"`
	Transcript     string  `json:"transcript"`
	Git            bool    `json:"git"`
	Branch         *string `json:"branch"`
	Thinking       string  `json:"thinking"`
}

type Agent struct {
	Name        string `json:"name"`
	Mode        string `json:"mode"`
	Description string `json:"description"`
}

type Command struct {
	Name        string `json:"name"`
	Description string `json:"description"`
	Turn        bool   `json:"turn,omitempty"`
}

type Ready struct {
	Type         string    `json:"type"`
	Protocol     int       `json:"protocol"`
	App          AppInfo   `json:"app"`
	Agents       []Agent   `json:"agents"`
	Commands     []Command `json:"commands"`
	Capabilities []string  `json:"capabilities"`
	Theme        string    `json:"theme"`
	Editor       *string   `json:"editor"`
}

type TurnStart struct {
	Type string `json:"type"`
}

type Text struct {
	Type   string `json:"type"`
	Agent  string `json:"agent"`
	Chunk  string `json:"chunk"`
	Parent string `json:"parent,omitempty"`
}

type ToolStart struct {
	Type    string `json:"type"`
	ID      string `json:"id"`
	Agent   string `json:"agent"`
	Depth   int    `json:"depth"`
	Tool    string `json:"tool"`
	Summary string `json:"summary"`
	Parent  string `json:"parent,omitempty"`
}

type ToolEnd struct {
	Type    string `json:"type"`
	ID      string `json:"id"`
	OK      bool   `json:"ok"`
	Summary string `json:"summary"`
	Diff    *Diff  `json:"diff"`
	Parent  string `json:"parent,omitempty"`
}

const (
	DiffCtx = "ctx"
	DiffAdd = "add"
	DiffDel = "del"
)

type Diff struct {
	Path    string `json:"path"`
	Added   int    `json:"added"`
	Removed int    `json:"removed"`
	Binary  bool   `json:"binary"`
	Hunks   []Hunk `json:"hunks"`
}

type Hunk struct {
	OldStart int        `json:"oldStart"`
	OldLines int        `json:"oldLines"`
	NewStart int        `json:"newStart"`
	NewLines int        `json:"newLines"`
	Lines    []DiffLine `json:"lines"`
}

type DiffLine struct {
	Kind  string `json:"kind"`
	Text  string `json:"text"`
	OldNo *int   `json:"oldNo"`
	NewNo *int   `json:"newNo"`
}

type SubagentStart struct {
	Type   string `json:"type"`
	ID     string `json:"id"`
	Parent string `json:"parent"`
	Agent  string `json:"agent"`
	Task   string `json:"task"`
}

type SubagentEnd struct {
	Type    string `json:"type"`
	ID      string `json:"id"`
	Agent   string `json:"agent"`
	Status  string `json:"status"`
	Summary string `json:"summary"`
}

type Notice struct {
	Type    string `json:"type"`
	Level   string `json:"level"`
	Message string `json:"message"`
	Parent  string `json:"parent,omitempty"`
}

type Output struct {
	Type   string `json:"type"`
	Text   string `json:"text"`
	Parent string `json:"parent,omitempty"`
}

type Confirm struct {
	Type     string `json:"type"`
	ID       string `json:"id"`
	Tool     string `json:"tool"`
	Category string `json:"category"`
	Agent    string `json:"agent"`
	Depth    int    `json:"depth"`
	Summary  string `json:"summary"`
	Preview  *Diff  `json:"preview"`
	Parent   string `json:"parent,omitempty"`
}

type Usage struct {
	PromptTokens     *int     `json:"promptTokens"`
	CompletionTokens *int     `json:"completionTokens"`
	TokensPerSecond  *float64 `json:"tokensPerSecond"`
	ContextWindow    *int     `json:"contextWindow"`
}

type TurnEnd struct {
	Type    string   `json:"type"`
	Status  string   `json:"status"`
	Reason  *string  `json:"reason"`
	Changes []string `json:"changes"`
	Usage   *Usage   `json:"usage"`
	Branch  *string  `json:"branch"`
}

type Bye struct {
	Type   string `json:"type"`
	Reason string `json:"reason"`
}

type Fatal struct {
	Type    string `json:"type"`
	Message string `json:"message"`
}

type ModelEntry struct {
	Profile         string   `json:"profile"`
	Model           string   `json:"model"`
	Protocol        string   `json:"protocol"`
	ContextWindow   *int     `json:"contextWindow"`
	Tools           *bool    `json:"tools"`
	Thinking        []string `json:"thinking"`
	ThinkingDefault *string  `json:"thinkingDefault"`
	Loaded          *bool    `json:"loaded"`
	Error           *string  `json:"error"`
	Custom          bool     `json:"custom"`
	Kind            string   `json:"kind"`
}

type Provider struct {
	Profile    string  `json:"profile"`
	Protocol   string  `json:"protocol"`
	Endpoint   string  `json:"endpoint"`
	Count      int     `json:"count"`
	Error      *string `json:"error"`
	UpdatedAt  *string `json:"updatedAt"`
	Refreshing bool    `json:"refreshing"`
}

type ModelRef struct {
	Profile  string `json:"profile"`
	Model    string `json:"model"`
	Thinking string `json:"thinking,omitempty"`
}

type Preset struct {
	Name      string  `json:"name"`
	Protocol  string  `json:"protocol"`
	BaseURL   string  `json:"baseUrl"`
	APIKeyEnv *string `json:"apiKeyEnv"`
	KeyInEnv  bool    `json:"keyInEnv"`
}

type Models struct {
	Type      string       `json:"type"`
	Entries   []ModelEntry `json:"entries"`
	Providers []Provider   `json:"providers"`
	Current   ModelRef     `json:"current"`
	Favorites []ModelRef   `json:"favorites"`
	Recents   []ModelRef   `json:"recents"`
	Presets   []Preset     `json:"presets"`
	Parent    string       `json:"parent,omitempty"`
}

func (m *Models) ProviderEndpoint(profile string) string {
	for _, pr := range m.Providers {
		if pr.Profile == profile {
			return pr.Endpoint
		}
	}
	return ""
}

type ModelChanged struct {
	Type     string `json:"type"`
	Profile  string `json:"profile"`
	Model    string `json:"model"`
	Thinking string `json:"thinking"`
	Saved    bool   `json:"saved"`
	Parent   string `json:"parent,omitempty"`
}

type Panel struct {
	Type   string      `json:"type"`
	Title  string      `json:"title"`
	Kind   string      `json:"kind"`
	Text   string      `json:"text"`
	Hint   string      `json:"hint"`
	Items  []PanelItem `json:"items"`
	Parent string      `json:"parent,omitempty"`
}

type PanelItem struct {
	Category string `json:"category"`
	Title    string `json:"title"`
	Detail   string `json:"detail"`
	Badge    string `json:"badge"`
	Status   string `json:"status"`
	Path     string `json:"path"`
	Key      string `json:"key"`
}

type Ask struct {
	Type          string   `json:"type"`
	ID            string   `json:"id"`
	Question      string   `json:"question"`
	Options       []string `json:"options"`
	AllowFreeText bool     `json:"allowFreeText"`
	Parent        string   `json:"parent,omitempty"`
}

type ConfigItem struct {
	Key      string   `json:"key"`
	Label    string   `json:"label"`
	Value    any      `json:"value"`
	Kind     string   `json:"kind"`
	Choices  []string `json:"choices"`
	Category string   `json:"category"`
}

func (c ConfigItem) Bool() bool {
	v, _ := c.Value.(bool)
	return v
}

func (c ConfigItem) Text() string {
	v, _ := c.Value.(string)
	return v
}

type Config struct {
	Type   string       `json:"type"`
	Items  []ConfigItem `json:"items"`
	Parent string       `json:"parent,omitempty"`
}

type Session struct {
	ID        string `json:"id"`
	Title     string `json:"title"`
	UpdatedAt string `json:"updatedAt"`
	Messages  int    `json:"messages"`
	Current   bool   `json:"current"`
}

type Sessions struct {
	Type   string    `json:"type"`
	Items  []Session `json:"items"`
	Parent string    `json:"parent,omitempty"`
}

type Context struct {
	Type              string        `json:"type"`
	Used              *int          `json:"used"`
	Limit             *int          `json:"limit"`
	Percent           *float64      `json:"percent"`
	Max               *int          `json:"max"`
	Threshold         *int          `json:"threshold"`
	AutoCompact       bool          `json:"autoCompact"`
	AutoCompactSwitch *bool         `json:"autoCompactSwitch"`
	Messages          *int          `json:"messages"`
	Breakdown         []ContextPart `json:"breakdown"`
	Parent            string        `json:"parent,omitempty"`
}

type ContextPart struct {
	Category string `json:"category"`
	Tokens   int    `json:"tokens"`
}

type EffortLevel struct {
	Name string
}

func (l *EffortLevel) UnmarshalJSON(b []byte) error {
	return json.Unmarshal(b, &l.Name)
}

type Effort struct {
	Type     string        `json:"type"`
	Profile  string        `json:"profile"`
	Model    string        `json:"model"`
	Level    string        `json:"level"`
	Levels   []EffortLevel `json:"levels"`
	Accepted []string      `json:"accepted"`
	Note     string        `json:"note"`
	Parent   string        `json:"parent,omitempty"`
}

func CanonicalLevel(name string) string {
	switch name {
	case "off":
		return "none"
	case "on":
		return "medium"
	}
	return name
}

type Progress struct {
	Type   string `json:"type"`
	ID     string `json:"id"`
	Step   string `json:"step"`
	Detail string `json:"detail"`
	Done   bool   `json:"done"`
	Parent string `json:"parent,omitempty"`
}

type Exec struct {
	Type    string   `json:"type"`
	Title   string   `json:"title"`
	Argv    []string `json:"argv"`
	Confirm bool     `json:"confirm"`
	Parent  string   `json:"parent,omitempty"`
}

type Unknown struct {
	Type string
	Raw  []byte
}

type Input struct {
	Type string `json:"type"`
	Text string `json:"text"`
}

type ConfirmReply struct {
	Type     string `json:"type"`
	ID       string `json:"id"`
	Decision string `json:"decision"`
}

type Interrupt struct {
	Type string `json:"type"`
}

type Quit struct {
	Type string `json:"type"`
}

type ListModels struct {
	Type    string `json:"type"`
	Refresh bool   `json:"refresh"`
}

type SetModel struct {
	Type     string `json:"type"`
	Profile  string `json:"profile"`
	Model    string `json:"model"`
	Thinking string `json:"thinking,omitempty"`
	Save     bool   `json:"save"`
}

type Favorite struct {
	Type    string `json:"type"`
	Profile string `json:"profile"`
	Model   string `json:"model"`
	On      bool   `json:"on"`
}

type CustomModel struct {
	Type    string `json:"type"`
	Profile string `json:"profile"`
	Model   string `json:"model"`
	Action  string `json:"action"`
}

type AddProvider struct {
	Type      string  `json:"type"`
	Name      string  `json:"name"`
	Protocol  string  `json:"protocol"`
	BaseURL   string  `json:"base_url"`
	APIKeyEnv *string `json:"api_key_env"`
	Model     *string `json:"model"`
	APIKey    *string `json:"api_key,omitempty"`
}

func (c AddProvider) WithAPIKey(key string) AddProvider {
	c.APIKey = nullIfEmpty(key)
	return c
}

type Endpoint struct {
	Name       string   `json:"name"`
	Protocol   string   `json:"protocol"`
	BaseURL    string   `json:"baseUrl"`
	Server     string   `json:"server"`
	Count      int      `json:"count"`
	Models     []string `json:"models"`
	NeedsKey   bool     `json:"needsKey"`
	Configured *string  `json:"configured"`
}

type AgentChanged struct {
	Type  string `json:"type"`
	Agent string `json:"agent"`
}

type Endpoints struct {
	Type  string     `json:"type"`
	Items []Endpoint `json:"items"`
}

type SearchProvider struct {
	Name    string  `json:"name"`
	Backend string  `json:"backend"`
	URL     *string `json:"url"`
	Key     string  `json:"key"`
	Active  bool    `json:"active"`
}

type SearchPreset struct {
	Backend     string  `json:"backend"`
	URL         *string `json:"url"`
	KeyRequired bool    `json:"keyRequired"`
	URLRequired bool    `json:"urlRequired"`
	URLMeans    string  `json:"urlMeans"`
}

type SearchLegacy struct {
	Backend string `json:"backend"`
	Key     string `json:"key"`
}

type SearchProviders struct {
	Type    string           `json:"type"`
	Active  *string          `json:"active"`
	Legacy  *SearchLegacy    `json:"legacy"`
	Items   []SearchProvider `json:"items"`
	Presets []SearchPreset   `json:"presets"`
}

type ListSearchProviders struct {
	Type string `json:"type"`
}

type AddSearchProvider struct {
	Type    string  `json:"type"`
	Name    string  `json:"name"`
	Backend string  `json:"backend"`
	URL     *string `json:"url"`
	APIKey  *string `json:"api_key"`
}

type UpdateSearchProvider struct {
	Type   string  `json:"type"`
	Name   string  `json:"name"`
	URL    *string `json:"url"`
	APIKey *string `json:"api_key"`
}

type RemoveSearchProvider struct {
	Type string `json:"type"`
	Name string `json:"name"`
}

type SetSearchProvider struct {
	Type string  `json:"type"`
	Name *string `json:"name"`
}

func NewListSearchProviders() ListSearchProviders {
	return ListSearchProviders{Type: CmdListSearchProviders}
}

func NewAddSearchProvider(name, backend, url, apiKey string) AddSearchProvider {
	return AddSearchProvider{Type: CmdAddSearchProvider, Name: name, Backend: backend, URL: nullIfEmpty(url), APIKey: nullIfEmpty(apiKey)}
}

func NewUpdateSearchProvider(name, url, apiKey string) UpdateSearchProvider {
	return UpdateSearchProvider{Type: CmdUpdateSearchProvider, Name: name, URL: nullIfEmpty(url), APIKey: nullIfEmpty(apiKey)}
}

func NewRemoveSearchProvider(name string) RemoveSearchProvider {
	return RemoveSearchProvider{Type: CmdRemoveSearchProvider, Name: name}
}

func NewSetSearchProvider(name *string) SetSearchProvider {
	return SetSearchProvider{Type: CmdSetSearchProvider, Name: name}
}

type SetProviderKey struct {
	Type    string `json:"type"`
	Profile string `json:"profile"`
	APIKey  string `json:"api_key"`
}

type AskReply struct {
	Type   string  `json:"type"`
	ID     string  `json:"id"`
	Answer *string `json:"answer"`
}

type ConfigSet struct {
	Type  string `json:"type"`
	Key   string `json:"key"`
	Value any    `json:"value"`
}

type SessionList struct {
	Type string `json:"type"`
}

type SessionSwitch struct {
	Type string `json:"type"`
	ID   string `json:"id"`
}

type SessionNew struct {
	Type string `json:"type"`
}

type SetEffort struct {
	Type  string `json:"type"`
	Level string `json:"level"`
}

type SetContext struct {
	Type string `json:"type"`
	Max  *int   `json:"max"`
}

type Compact struct {
	Type         string  `json:"type"`
	Instructions *string `json:"instructions"`
}

type Btw struct {
	Type     string `json:"type"`
	Question string `json:"question"`
}

type DeepResearch struct {
	Type     string `json:"type"`
	Question string `json:"question"`
	Rounds   int    `json:"rounds"`
}

func NewInput(text string) Input {
	return Input{Type: CmdInput, Text: text}
}

func NewConfirmReply(id, decision string) ConfirmReply {
	return ConfirmReply{Type: CmdConfirmReply, ID: id, Decision: decision}
}

func NewInterrupt() Interrupt {
	return Interrupt{Type: CmdInterrupt}
}

func NewQuit() Quit {
	return Quit{Type: CmdQuit}
}

func NewListModels(refresh bool) ListModels {
	return ListModels{Type: CmdListModels, Refresh: refresh}
}

func NewSetModel(profile, model, thinking string, save bool) SetModel {
	return SetModel{Type: CmdSetModel, Profile: profile, Model: model, Thinking: thinking, Save: save}
}

func NewFavorite(profile, model string, on bool) Favorite {
	return Favorite{Type: CmdFavorite, Profile: profile, Model: model, On: on}
}

func NewCustomModel(profile, model, action string) CustomModel {
	return CustomModel{Type: CmdCustomModel, Profile: profile, Model: model, Action: action}
}

func NewAddProvider(name, protocol, baseURL, apiKeyEnv, model string) AddProvider {
	return AddProvider{
		Type: CmdAddProvider, Name: name, Protocol: protocol, BaseURL: baseURL,
		APIKeyEnv: nullIfEmpty(apiKeyEnv), Model: nullIfEmpty(model),
	}
}

func NewSetProviderKey(profile, key string) SetProviderKey {
	return SetProviderKey{Type: CmdSetProviderKey, Profile: profile, APIKey: key}
}

type DetectEndpoints struct {
	Type string `json:"type"`
}

func NewDetectEndpoints() DetectEndpoints { return DetectEndpoints{Type: CmdDetectEndpoints} }

func NewRemoveProvider(profile string) RemoveProvider {
	return RemoveProvider{Type: CmdRemoveProvider, Profile: profile}
}

type RemoveProvider struct {
	Type    string `json:"type"`
	Profile string `json:"profile"`
}

func NewAskReply(id string, answer *string) AskReply {
	return AskReply{Type: CmdAskReply, ID: id, Answer: answer}
}

func NewConfigSet(key string, value any) ConfigSet {
	return ConfigSet{Type: CmdConfigSet, Key: key, Value: value}
}

func NewSessionList() SessionList {
	return SessionList{Type: CmdSessionList}
}

func NewSessionSwitch(id string) SessionSwitch {
	return SessionSwitch{Type: CmdSessionSwitch, ID: id}
}

func NewSessionNew() SessionNew {
	return SessionNew{Type: CmdSessionNew}
}

func NewSetEffort(level string) SetEffort {
	return SetEffort{Type: CmdSetEffort, Level: level}
}

func NewSetContext(max *int) SetContext {
	return SetContext{Type: CmdSetContext, Max: max}
}

func NewCompact(instructions string) Compact {
	return Compact{Type: CmdCompact, Instructions: nullIfEmpty(instructions)}
}

func NewBtw(question string) Btw {
	return Btw{Type: CmdBtw, Question: question}
}

func NewDeepResearch(question string, rounds int) DeepResearch {
	return DeepResearch{Type: CmdDeepResearch, Question: question, Rounds: rounds}
}

func nullIfEmpty(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func Parse(line []byte) (any, error) {
	var head struct {
		Type string `json:"type"`
	}
	if err := json.Unmarshal(line, &head); err != nil {
		return nil, err
	}

	var target any
	switch head.Type {
	case TypeReady:
		target = &Ready{}
	case TypeTurnStart:
		target = &TurnStart{}
	case TypeText:
		target = &Text{}
	case TypeToolStart:
		target = &ToolStart{}
	case TypeToolEnd:
		target = &ToolEnd{}
	case TypeSubagentStart:
		target = &SubagentStart{}
	case TypeSubagentEnd:
		target = &SubagentEnd{}
	case TypeNotice:
		target = &Notice{}
	case TypeOutput:
		target = &Output{}
	case TypeConfirm:
		target = &Confirm{}
	case TypeTurnEnd:
		target = &TurnEnd{}
	case TypeBye:
		target = &Bye{}
	case TypeError:
		target = &Fatal{}
	case TypeModels:
		target = &Models{}
	case TypeModelChanged:
		target = &ModelChanged{}
	case TypePanel:
		target = &Panel{}
	case TypeAsk:
		target = &Ask{}
	case TypeConfig:
		target = &Config{}
	case TypeSessions:
		target = &Sessions{}
	case TypeContext:
		target = &Context{}
	case TypeEffort:
		target = &Effort{}
	case TypeProgress:
		target = &Progress{}
	case TypeExec:
		target = &Exec{}
	case TypeEndpoints:
		target = &Endpoints{}
	case TypeAgentChanged:
		target = &AgentChanged{}
	case TypeSearchProviders:
		target = &SearchProviders{}
	default:
		raw := make([]byte, len(line))
		copy(raw, line)
		return &Unknown{Type: head.Type, Raw: raw}, nil
	}

	if err := json.Unmarshal(line, target); err != nil {
		return nil, err
	}
	return target, nil
}

func Encode(command any) ([]byte, error) {
	data, err := json.Marshal(command)
	if err != nil {
		return nil, err
	}
	return append(data, '\n'), nil
}
