<?php

declare(strict_types=1);

namespace Phycode\Cli;

use Phycode\Agent\AgentDefinition;
use Phycode\Agent\Conversation;
use Phycode\Agent\OutcomeStatus;
use Phycode\Agent\RunPolicy;
use Phycode\Agent\RunSetup;
use Phycode\Checkpoint\CheckpointStore;
use Phycode\Cli\Commands\CommandContext;
use Phycode\Config\Config;
use Phycode\Config\ConfigWriter;
use Phycode\Config\Profile;
use Phycode\Diff\FileDiff;
use Phycode\Diff\TextDiff;
use Phycode\Gentle\Approval;
use Phycode\Gentle\GentleAi;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\ChatClient;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\Image;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\PromptCache;
use Phycode\Mcp\McpCatalog;
use Phycode\Security\PathException;
use Phycode\Session\ContextBudget;
use Phycode\Session\SessionStore;
use Phycode\Session\Transcript;
use Phycode\Skills\SkillCatalog;
use Phycode\Support\Cancellation;
use Phycode\Support\Files;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\Text;
use Phycode\Tools\CustomTool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use Phycode\Web\WebTrigger;

final class ChatSession
{
    private const COMMANDS = [
        ['name' => '/models', 'description' => 'list models and switch the model for this session'],
        ['name' => '/effort [LEVEL]', 'description' => 'show or set the thinking effort of the next turn: none, minimal, low, medium, high, xhigh or max'],
        ['name' => '/context', 'description' => 'show the context: the tokens used against the window, the autocompact threshold and the tokens by category'],
        ['name' => '/context max N|Nk|off [--save]', 'description' => 'set the window the autocompact threshold is measured against; off uses the window of the model, and --save writes it to the config'],
        ['name' => '/compact [INSTRUCTIONS]', 'description' => 'summarise the older turns with the model, keeping the last quarter of the window; the summary replaces them'],
        ['name' => '/sessions [switch ID|new]', 'description' => 'list the saved sessions, switch to one, or start a new one'],
        ['name' => '/clear', 'description' => 'start a new conversation'],
        ['name' => '/handoff', 'description' => 'write a handoff of this session with the model and show it; on a yes it is saved in .phycode/handoffs and a new session starts with it'],
        ['name' => '/usage', 'description' => 'show the tokens this session has spent, by model, and what they cost when the config has prices (pricing)'],
        ['name' => '/status', 'description' => 'show the version, profile, model, sandbox, permissions and transcript'],
        ['name' => '/diff', 'description' => 'show the changes of the last turn that changed files, file by file'],
        ['name' => '/undo', 'description' => 'put back the files the last turn changed; the files are listed first and the change needs a yes'],
        ['name' => '/redo', 'description' => 'apply again the turn that /undo put back; the files are listed first and the change needs a yes'],
        ['name' => '/rewind TURN', 'description' => 'put back the files as they were before a turn, undoing it and the later ones; the files are listed first and need a yes'],
        ['name' => '/agent NAME', 'description' => 'switch the primary agent'],
        ['name' => '/image PATH [TEXT]', 'description' => 'send an image (PNG, JPEG, GIF or WebP, up to 3.5 MB) with a question; the profile needs vision: true'],
        ['name' => '/plan [TASK]', 'description' => 'switch to the plan agent, which investigates and proposes a plan without changing anything; you approve the plan to have it built'],
        ['name' => '/todo [clear]', 'description' => 'show the task list of this session, or clear it'],
        ['name' => '/agents', 'description' => 'list the agents, their tools and restrictions'],
        ['name' => '/reload', 'description' => 'read the configuration again: profiles, theme and the other settings, without restarting'],
        ['name' => '/init', 'description' => 'explore the project and write or update AGENTS.md; the change is shown for approval'],
        ['name' => '/skill [NAME]', 'description' => 'list the installed skills, or show one of them'],
        ['name' => '/skills', 'description' => 'list the gentle-ai skills for this project, read-only'],
        ['name' => '/mcp', 'description' => 'list the MCP servers and their tools'],
        ['name' => '/help', 'description' => 'show the commands'],
        ['name' => '/exit', 'description' => 'leave phycode'],
    ];

    private const PLAN_BUILD_ACCEPT = 'Yes, build it and accept the edits';
    private const PLAN_BUILD_ASK = 'Yes, build it and ask me about each edit';
    private const PLAN_BUILD = 'Yes, build it';
    private const PLAN_KEEP = 'No, keep planning';
    private const BUILD_PROMPT = 'The plan above was approved. Write its steps with the todo tool, then carry them out one by one, marking each step completed when it is done. '
        . 'If a step turns out to be wrong or impossible, stop and say what you found instead of improvising.';

    private const SIDE_MESSAGES = 6;
    private const SUMMARY_ROOM = 1000;

    public const INIT_PROMPT = 'Explore this project and write AGENTS.md at the project root: short, actionable instructions for a coding agent that works in this project. '
        . 'Read the README, the build and test setup and the main source folders with the read, list and grep tools before you write. '
        . 'Cover how to build, test and lint the project, how the code is laid out, and the conventions and pitfalls that the code does not show. '
        . 'Leave out what the code already makes clear, and generic advice. When you are done, reply in one sentence saying what you wrote.';

    public const INIT_UPDATE = 'AGENTS.md already exists. Read it first, and do not overwrite it: propose a change to it with the edit tool, '
        . 'for what is wrong, missing or out of date, and keep what is still right.';

    private const HANDOFF_INSTRUCTIONS = 'This summary is the handoff to a new session that has none of this conversation: write it so that the new session can go on from it alone, '
        . 'with the exact file paths, the commands that were run and what they showed, and the next step under Pending.';

    private string $profile;
    private ?string $model = null;
    private string $thinking;
    private ?string $resolvedMode = null;
    private Conversation $conversation;
    private ?Cancellation $turn = null;
    private ?string $lastStatus = null;
    private ?string $lastReason = null;
    private ?Usage $lastUsage = null;
    private ?int $conversationTokens = null;
    private ?array $cacheStats = null;
    private array $windows = [];
    private ?int $contextMax = null;
    private readonly SessionStore $store;
    private readonly CheckpointStore $checkpoints;
    private string $sessionId;
    private string $createdAt;
    private string $title = '';
    private ?\Closure $frame = null;
    private ?\Closure $autoCompacted = null;
    private readonly PromptTemplates $templates;

    public function __construct(
        private readonly Kernel $kernel,
        string $profile,
        private AgentDefinition $primary,
        private readonly Transcript $transcript,
    ) {
        $this->profile = $profile;
        $default = $kernel->config->effortDefault();
        $this->thinking = $default === 'none' ? $kernel->config->profile($profile)->thinking : $default;
        $this->contextMax = $kernel->config->contextMax();
        $this->conversation = $this->freshConversation();
        $this->store = new SessionStore($kernel->workspace->root);
        $this->checkpoints = $kernel->checkpoints;
        $this->sessionId = SessionStore::newId();
        $this->createdAt = SessionStore::now();
        $taken = [...array_map(self::wordOf(...), array_column(self::COMMANDS, 'name')), '/quit', '/btw', '/config', '/deepresearch'];
        foreach ($kernel->commands->commands() as $command) {
            $taken[] = self::wordOf($command['name']);
        }
        $this->templates = new PromptTemplates($kernel->workspace->root, $taken);
        foreach ($this->templates->warnings() as $warning) {
            $kernel->ui->notice('warn', $this->redact($warning));
        }
        $this->persist();
    }

    public function primary(): AgentDefinition
    {
        return $this->primary;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function toolMode(): string
    {
        return $this->resolvedMode ?? $this->kernel->config->profile($this->profile)->toolMode;
    }

    public function effort(): string
    {
        return $this->thinking;
    }

    public function model(): string
    {
        return $this->model ?? $this->kernel->config->profile($this->profile)->model;
    }

    public function thinking(): string
    {
        return $this->thinking;
    }

    public function contextWindow(): ?int
    {
        $profile = $this->kernel->config->profile($this->profile);
        if ($profile->protocol === 'ollama' || $profile->contextLimitSet) {
            return $profile->contextLimit;
        }

        $model = $this->model();
        if (array_key_exists($model, $this->windows)) {
            return $this->windows[$model];
        }
        try {
            $info = $this->kernel->providers->forProfile($this->profile, $this->model, $this->thinking)->describe($model);
        } catch (PhycodeException) {
            $this->windows[$model] = null;

            return null;
        }
        if ($info->loaded !== false) {
            $this->windows[$model] = $info->contextWindow;
        }

        return $info->contextWindow;
    }

    public function transcriptPath(): string
    {
        return $this->transcript->path;
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    public function messages(): array
    {
        return $this->conversation->messages();
    }

    public function sendWithImages(string $text, array $images): void
    {
        $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn($text, $cancel, $images));
    }

    public function visionSupported(): bool
    {
        return $this->kernel->config->profile($this->profile)->supportsVision();
    }

    public function commands(): array
    {
        $words = array_map(self::wordOf(...), array_column(self::COMMANDS, 'name'));
        $added = array_values(array_filter(
            $this->kernel->commands->commands(),
            static fn (array $command): bool => !in_array(self::wordOf($command['name']), $words, true),
        ));

        return [...self::COMMANDS, ...$added, ...$this->templates->commands(), ...($this->kernel->mcp?->prompts()->commands() ?? [])];
    }

    public function isCommand(string $input): bool
    {
        $line = trim($input);
        if ($line === '/quit') {
            return true;
        }
        foreach (self::COMMANDS as $command) {
            [$word, $argument] = explode(' ', $command['name'], 2) + [1 => null];
            if ($line === $word || ($argument !== null && str_starts_with($line, $word . ' '))) {
                return true;
            }
        }

        return $this->kernel->commands->match($line) !== null || $this->templates->prompt($line) !== null || $this->kernel->mcp?->prompts()->match($line) !== null;
    }

    private static function wordOf(string $name): string
    {
        return explode(' ', $name, 2)[0];
    }

    public function lastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function lastReason(): ?string
    {
        return $this->lastReason;
    }

    public function lastUsage(): ?Usage
    {
        return $this->lastUsage;
    }

    public function redact(string $message): string
    {
        return Redaction::text($message, $this->kernel->secrets());
    }

    public function banner(): string
    {
        return "phycode chat | agent: {$this->primary->name} | profile: {$this->profile} | tools: {$this->toolMode()}"
            . ' | sandbox: ' . $this->kernel->sandboxes->describe()
            . ' | /help for commands, Ctrl+C stops a running turn';
    }

    public function interrupt(): void
    {
        $this->turn?->cancel('interrupted by the user');
    }

    public function switchModel(string $profile, ?string $model, string $thinking): void
    {
        $this->guardIdle();
        $this->applySettings($profile, $model, $thinking);
        $this->conversation->setSystem($this->systemPrompt());
        $this->persist();
    }

    public function effortLevels(): array
    {
        return Profile::EFFORTS;
    }

    public function effortAccepted(): ?array
    {
        $model = $this->model();

        return EffortMapping::accepted($this->kernel->config->profile($this->profile), $model, $this->kernel->clients->reportedLevels($this->profile, $model));
    }

    public function effortNote(): string
    {
        $model = $this->model();

        return EffortMapping::note($this->kernel->config->profile($this->profile), $model, $this->kernel->clients->reportedLevels($this->profile, $model));
    }

    public function setEffort(string $level): void
    {
        $this->guardIdle();
        $canonical = Profile::canonical($level);
        if ($canonical === null) {
            throw new PhycodeException('effort must be ' . Profile::levelsText());
        }
        $this->thinking = $canonical;
        $this->persist();
    }

    public function setContextMax(?int $max): void
    {
        $this->guardIdle();
        if ($max !== null && ($max < Config::CONTEXT_MIN_TOKENS || $max > Config::CONTEXT_MAX_TOKENS)) {
            throw new PhycodeException('the context max must be off or a number of tokens from ' . Config::CONTEXT_MIN_TOKENS . ' to ' . Config::CONTEXT_MAX_TOKENS);
        }
        $this->contextMax = $max;
        $this->warnIfMaxIsBelowFixed($max);
    }

    public function onAutoCompact(\Closure $listener): void
    {
        $this->autoCompacted = $listener;
    }

    public function contextState(): array
    {
        $messages = $this->conversation->messages();
        $used = $this->usedTokens($messages);
        $limit = $this->contextLimit();
        $percent = $limit === null || $limit < 1 ? null : intdiv($used * 100, $limit);

        return [
            'used' => $used,
            'limit' => $limit,
            'threshold' => $limit === null ? null : Compaction::threshold($limit, $this->kernel->config->contextBuffer()),
            'percent' => $percent,
            'messages' => count($messages),
            'breakdown' => $this->breakdown($messages),
            'autoCompact' => $this->autoCompactOn(),
            'autoCompactSwitch' => $this->kernel->config->autoCompact(),
            'estimated' => $this->conversationTokens === null,
            'cache' => $this->cacheStats,
        ];
    }

    private function usedTokens(array $messages): int
    {
        return max($this->conversationTokens ?? 0, array_sum(array_column($this->breakdown($messages), 'tokens')));
    }

    private function warnIfMaxIsBelowFixed(?int $max): void
    {
        if ($max === null) {
            return;
        }
        $rows = array_column($this->breakdown($this->conversation->messages()), 'tokens', 'category');
        $fixed = $rows['system'] + $rows['tools'];
        if ($max >= $fixed + self::SUMMARY_ROOM) {
            return;
        }
        $suggested = (int) ceil(($fixed + self::SUMMARY_ROOM) / 1000) * 1000;
        $this->kernel->ui->notice('warn', 'context max of ' . ContextReport::tokensText($max) . ' tokens is below the ' . ContextReport::tokensText($fixed)
            . ' tokens of the system prompt and the tools, with room for a summary: a summary cannot bring the context under the max, so it stays above it. Set context.max to '
            . ContextReport::tokensText($suggested) . ' or more');
    }

    private function breakdown(array $messages): array
    {
        [$builtin, $custom] = $this->toolTokens($this->kernel->policyFor($this->primary));
        $system = ($messages[0]['role'] ?? null) === 'system' ? ContextReport::tokens((string) $messages[0]['content']) : 0;
        $listed = $this->toolMode() === 'text' ? $builtin + $custom : 0;

        return ContextReport::breakdown($messages, max(0, $system - $listed), $builtin + $custom);
    }

    private function toolTokens(RunPolicy $policy): array
    {
        $mode = $this->toolMode();
        $builtin = 0;
        $custom = 0;
        if ($mode === 'none') {
            return [0, 0];
        }
        if ($mode === 'text') {
            foreach (explode("\n", $this->kernel->tools->signatures($policy)) as $line) {
                if (preg_match('/^- ([A-Za-z][A-Za-z0-9_-]*)\(/', $line, $match) !== 1) {
                    continue;
                }
                if ($this->kernel->tools->get($match[1]) instanceof CustomTool) {
                    $custom += ContextReport::tokens($line);
                } else {
                    $builtin += ContextReport::tokens($line);
                }
            }

            return [$builtin, $custom];
        }
        foreach ($this->kernel->tools->specs($policy) as $spec) {
            $tokens = ContextReport::tokens((string) json_encode($spec, JSON_UNESCAPED_UNICODE));
            if ($this->kernel->tools->get((string) ($spec['function']['name'] ?? '')) instanceof CustomTool) {
                $custom += $tokens;
            } else {
                $builtin += $tokens;
            }
        }

        return [$builtin, $custom];
    }

    public function sideContext(): array
    {
        $messages = [];
        foreach ($this->conversation->messages() as $message) {
            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;
            if (in_array($role, ['user', 'assistant'], true) && is_string($content) && trim($content) !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
        $messages = array_slice($messages, -self::SIDE_MESSAGES);

        return ['messages' => $messages, 'profile' => $this->profile, 'model' => $this->model(), 'thinking' => $this->thinking];
    }

    public function compact(?string $instructions = null): array
    {
        $this->guardIdle();
        $cancel = new Cancellation();
        $this->turn = $cancel;
        try {
            return $this->summarise($instructions, $cancel);
        } finally {
            $this->turn = null;
        }
    }

    private function summarise(?string $instructions, Cancellation $cancel): array
    {
        $messages = $this->conversation->messages();
        $limit = $this->contextLimit();
        $cut = $this->cutOf($messages);
        if ($cut === null) {
            throw new PhycodeException($limit === null
                ? 'nothing to compact: the conversation has two turns or fewer'
                : 'nothing to compact: every turn fits in the last quarter of the window');
        }
        $head = Compaction::headLength($messages);
        $summary = $this->summaryOf(array_slice($messages, 0, $cut), $instructions, $cancel);
        if ($summary === '') {
            throw new PhycodeException('the model gave an empty summary; the conversation is unchanged');
        }
        $compacted = Compaction::apply($messages, $cut, $summary);
        $this->conversation->replaceMessages($compacted);
        $this->conversationTokens = null;
        $this->cacheStats = null;
        $this->persist();

        return [
            'before' => ContextBudget::estimate($messages),
            'after' => ContextBudget::estimate($compacted),
            'summarised' => $cut - $head,
            'kept' => count($messages) - $cut,
        ];
    }

    private function summaryOf(array $upTo, ?string $instructions, Cancellation $cancel): string
    {
        $client = $this->kernel->providers->forProfile($this->profile, $this->model, $this->thinking);
        $shared = $this->kernel->config->profile($this->profile)->compactsShared() ? $this->sharedPrefix($upTo) : null;
        if ($shared !== null) {
            try {
                $response = $client->chat(Compaction::sharedRequest($shared[0], $instructions), $shared[1], new ChatOptions(null, $cancel, [], PromptCache::key($this->transcript->path, $this->primary->name)));
                $this->kernel->usage->record($this->profile, $this->model, $response->usage);
                $text = trim($response->content);
                if ($text !== '' && $response->toolCalls === [] && !str_contains($text, '<tool_call>')) {
                    return $text;
                }
            } catch (PhycodeException $error) {
                if ($cancel->isCancelled()) {
                    throw $error;
                }
            }
        }

        $response = $client->chat(Compaction::request(array_slice($upTo, Compaction::headLength($upTo)), $instructions), [], new ChatOptions(null, $cancel));
        $this->kernel->usage->record($this->profile, $this->model, $response->usage);

        return trim($response->content);
    }

    private function sharedPrefix(array $upTo): ?array
    {
        $mode = $this->resolvedMode;
        if ($mode === null || $mode === 'auto' || ($upTo[0]['role'] ?? null) !== 'system') {
            return null;
        }
        $offered = $this->kernel->webOffered();
        $tools = $this->kernel->turnTools($mode !== 'none' && $offered['search'], $mode !== 'none' && $offered['fetch']);
        if ($mode === 'text' && $tools !== $this->kernel->tools) {
            $upTo[0]['content'] = $this->systemPrompt($tools);
        }

        return [$upTo, $mode === 'native' ? $tools->specs($this->kernel->policyFor($this->primary)) : []];
    }

    public function listSessions(): array
    {
        $sessions = [];
        foreach ($this->store->all() as $session) {
            $sessions[] = $session + ['current' => $session['id'] === $this->sessionId];
        }

        return $sessions;
    }

    public function switchSession(string $id): void
    {
        $this->guardIdle();
        if ($id === $this->sessionId) {
            return;
        }
        $session = $this->store->load($id);
        $this->checkSettings($session['profile'], $session['model'], $session['thinking']);
        $this->persist();
        $this->applySettings($session['profile'], $session['model'], $session['thinking']);
        $this->conversation->replaceMessages($session['messages']);
        $this->conversation->setSystem($this->systemPrompt());
        $this->kernel->todos->restore($session['todos'] ?? []);
        $this->sessionId = $session['id'];
        $this->createdAt = $session['createdAt'];
        $this->title = $session['title'];
        $this->conversationTokens = null;
        $this->cacheStats = null;
    }

    public function newSession(): void
    {
        $this->guardIdle();
        $this->persist();
        $this->sessionId = SessionStore::newId();
        $this->createdAt = SessionStore::now();
        $this->title = '';
        $this->conversation = $this->freshConversation();
        $this->kernel->todos->clear();
        $this->conversationTokens = null;
        $this->cacheStats = null;
        $this->persist();
    }

    public function init(): void
    {
        $this->guardIdle();
        $prompt = self::INIT_PROMPT;
        if (is_file($this->kernel->workspace->root . '/AGENTS.md')) {
            $prompt .= ' ' . self::INIT_UPDATE;
        }
        $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn($prompt, $cancel));
    }

    private function shellCommand(string $input): void
    {
        $keep = !str_starts_with($input, '!!');
        $command = trim(substr($input, $keep ? 1 : 2));
        if ($command === '') {
            $this->kernel->ui->notice('warn', 'type a command after ! (!! keeps its output from the model)');

            return;
        }
        $this->refusable(function () use ($command, $keep): void {
            $this->guardIdle();
            $this->runAsTurn(fn (Cancellation $cancel) => $this->runShell($command, $keep, $cancel), true);
        });
    }

    private function runShell(string $command, bool $keep, Cancellation $cancel): void
    {
        $context = $this->context($cancel);
        $result = $context->sandbox->run(['bash', '-c', $command], $context->workspace->root, $context->limits->maxBashTimeout, $context->limits->toolOutputBytes, $cancel);

        $lines = ['$ ' . $command];
        if (trim($result->stdout) !== '') {
            $lines[] = rtrim($result->stdout);
        }
        if (trim($result->stderr) !== '') {
            $lines[] = rtrim($result->stderr);
        }
        if ($result->interrupted !== null) {
            $lines[] = 'interrupted (' . $result->interrupted . ') after ' . round($result->seconds, 1) . ' seconds';
        } elseif ($result->exitCode !== 0) {
            $lines[] = "exit code: {$result->exitCode}";
        }
        $report = Text::headTail(implode("\n", $lines), $context->limits->toolOutputBytes);
        $this->kernel->ui->output($report);

        if ($keep) {
            $this->conversation->addUser("I ran this command in the project with the ! prefix, and this is what it printed:\n" . Redaction::text($report));
            $this->conversationTokens = null;
            $this->cacheStats = null;
        }
        $this->lastStatus = match ($result->interrupted) {
            'cancelled' => 'cancelled',
            'timeout' => 'error',
            default => 'completed',
        };
        if ($result->interrupted === 'timeout') {
            $this->lastReason = 'the command ran out of time';
        }
    }

    private function refusable(\Closure $command): void
    {
        try {
            $command();
        } catch (PhycodeException $error) {
            $this->kernel->ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    public function handle(string $line, ?\Closure $frame = null): bool
    {
        $this->frame = $frame;
        try {
            return $this->answer($line);
        } finally {
            $this->frame = null;
        }
    }

    private function answer(string $line): bool
    {
        $input = trim($line);
        $this->lastStatus = null;
        $this->lastReason = null;
        $this->lastUsage = null;
        if ($input === '') {
            return true;
        }
        if ($input === '/exit' || $input === '/quit') {
            return false;
        }

        $ui = $this->kernel->ui;
        if (str_starts_with($input, '!')) {
            $this->shellCommand($input);

            return true;
        }
        if ($input === '/help') {
            $ui->panel('Help', $this->helpText());

            return true;
        }
        if ($input === '/status') {
            $ui->panel('Status', $this->redact($this->statusText()));

            return true;
        }
        if ($input === '/context' || str_starts_with($input, '/context ')) {
            $this->contextCommand(trim(substr($input, strlen('/context'))));

            return true;
        }
        if ($input === '/compact' || str_starts_with($input, '/compact ')) {
            $instructions = trim(substr($input, strlen('/compact')));
            $this->refusable(function () use ($instructions): void {
                $this->guardIdle();
                $this->runAsTurn(fn (Cancellation $cancel) => $this->compactCommand($instructions, $cancel), true);
            });

            return true;
        }
        if ($input === '/effort' || str_starts_with($input, '/effort ')) {
            $this->effortCommand(trim(substr($input, strlen('/effort'))));

            return true;
        }
        if ($input === '/sessions' || str_starts_with($input, '/sessions ')) {
            $this->sessionsCommand(trim(substr($input, strlen('/sessions'))));

            return true;
        }
        if ($input === '/agents') {
            $ui->panel('Agents', $this->kernel->agentListing());

            return true;
        }
        if ($input === '/models') {
            $ui->listPanel('Models', $this->modelItems(), 'the model in use is marked');

            return true;
        }
        if ($input === '/skill' || str_starts_with($input, '/skill ')) {
            $this->skillPanel(trim(substr($input, 6)));

            return true;
        }
        if ($input === '/mcp') {
            $this->mcpPanel();

            return true;
        }
        if ($input === '/skills') {
            try {
                $ui->output($this->redact($this->skillsText()));
            } catch (PhycodeException $error) {
                $ui->notice('error', $this->redact($error->getMessage()));
            }

            return true;
        }
        if ($input === '/clear') {
            $this->refusable(fn () => $this->newSession());

            return true;
        }
        if ($input === '/init') {
            $this->refusable(fn () => $this->init());

            return true;
        }
        if ($input === '/handoff') {
            $this->refusable(fn () => $this->handoff());

            return true;
        }
        if ($input === '/diff') {
            $this->diffCommand();

            return true;
        }
        if ($input === '/undo') {
            $this->undoCommand();

            return true;
        }
        if ($input === '/redo') {
            $this->redoCommand();

            return true;
        }
        if ($input === '/rewind' || str_starts_with($input, '/rewind ')) {
            $this->rewindCommand(trim(substr($input, strlen('/rewind'))));

            return true;
        }
        if ($input === '/reload') {
            $this->refusable(function (): void {
                $this->guardIdle();
                try {
                    $this->kernel->reloadConfig();
                    $this->kernel->ui->notice('info', 'configuration read again');
                } catch (PhycodeException $error) {
                    $this->kernel->ui->notice('error', 'the configuration is as it was: ' . $this->redact($error->getMessage()));
                }
            });

            return true;
        }
        if ($input === '/image' || str_starts_with($input, '/image ')) {
            $this->imageCommand(trim(substr($input, 6)));

            return true;
        }
        if ($input === '/usage') {
            $ui->panel('Usage', $this->redact($this->kernel->usage->text($this->kernel->pricing())));

            return true;
        }
        if ($input === '/todo' || $input === '/todo clear') {
            $this->todoCommand($input === '/todo clear');

            return true;
        }
        if ($input === '/plan' || str_starts_with($input, '/plan ')) {
            $this->planCommand(trim(substr($input, 5)));

            return true;
        }
        if ($input === '/agent' || str_starts_with($input, '/agent ')) {
            $name = trim(substr($input, 6));
            if ($name === '') {
                $ui->notice('error', 'usage: /agent NAME');

                return true;
            }
            try {
                $agent = $this->kernel->primaryAgent($name);
            } catch (PhycodeException $error) {
                $ui->notice('error', $this->redact($error->getMessage()));

                return true;
            }
            $this->primary = $agent;
            $this->conversation->setSystem($this->systemPrompt());
            $ui->notice('info', "primary agent is now {$agent->name}");

            return true;
        }

        $command = $this->kernel->commands->match($input);
        if ($command !== null) {
            $this->dispatchCommand($command[0], $command[1]);

            return true;
        }
        $prompt = $this->templates->prompt($input);
        if ($prompt !== null) {
            $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn($prompt, $cancel));

            return true;
        }

        $mcpPrompts = $this->kernel->mcp?->prompts();
        if ($mcpPrompts !== null && $mcpPrompts->match($input) !== null) {
            $this->runAsTurn(function (Cancellation $cancel) use ($mcpPrompts, $input): void {
                try {
                    $prompt = $mcpPrompts->render($input);
                } catch (PhycodeException $error) {
                    $this->kernel->ui->notice('error', $this->redact($error->getMessage()));

                    return;
                }
                $this->runTurn($prompt, $cancel);
            });

            return true;
        }

        $this->runAsTurn(function (Cancellation $cancel) use ($input): void {
            if (str_starts_with($input, '@')) {
                $this->runDirect(substr($input, 1), $cancel);
            } else {
                $this->runTurn($input, $cancel);
            }
        });
        $this->planHandoff();

        return true;
    }

    private function imageCommand(string $argument): void
    {
        $ui = $this->kernel->ui;
        if ($argument === '' || preg_match('/^(?:"([^"]+)"|\'([^\']+)\'|(\S+))\s*(.*)$/s', $argument, $match) !== 1) {
            $ui->notice('error', 'usage: /image PATH [TEXT]');

            return;
        }
        $path = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]);
        $text = trim($match[4]) !== '' ? trim($match[4]) : 'What do you see in this image?';
        if (!$this->kernel->config->profile($this->profile)->supportsVision()) {
            $ui->notice('error', "the profile {$this->profile} is not set to read images: put \"vision\": true in it if its model does (a llama-server with --mmproj, or a vision model of Ollama)");

            return;
        }
        $home = (string) getenv('HOME');
        $absolute = str_starts_with($path, '~/') ? $home . substr($path, 1) : (str_starts_with($path, '/') ? $path : $this->kernel->workspace->root . '/' . $path);
        $real = realpath($absolute);
        try {
            if ($real === false) {
                throw new PhycodeException("{$path} does not exist");
            }
            $image = Image::fromFile($real, basename($path));
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));

            return;
        }
        $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn($text, $cancel, [$image]));
    }

    private function todoCommand(bool $clear): void
    {
        $ui = $this->kernel->ui;
        if ($clear) {
            $this->kernel->todos->clear();
            $ui->notice('info', 'the task list is empty');

            return;
        }
        $counts = $this->kernel->todos->counts();
        $ui->panel('Todo', $this->kernel->todos->isEmpty() ? 'No tasks yet. The model writes them with the todo tool when a job has several steps.' : $this->redact($this->kernel->todos->text()) . "\n\n{$counts['done']} of {$counts['total']} done");
    }

    private function planCommand(string $task): void
    {
        $ui = $this->kernel->ui;
        try {
            $plan = $this->kernel->primaryAgent('plan');
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));

            return;
        }
        if ($this->primary->name !== 'plan') {
            $this->primary = $plan;
            $this->conversation->setSystem($this->systemPrompt());
            $ui->notice('info', 'primary agent is now plan: it investigates and proposes a plan, and changes nothing until you approve it');
        }
        if ($task !== '') {
            $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn($task, $cancel));
            $this->planHandoff();
        }
    }

    private function planHandoff(): void
    {
        $ui = $this->kernel->ui;
        while ($this->primary->name === 'plan' && $this->lastStatus === 'completed' && $ui->interactive() && $this->lastAssistantText() !== '') {
            $options = $this->kernel->permissionMode === 'ask'
                ? [self::PLAN_BUILD_ACCEPT, self::PLAN_BUILD_ASK, self::PLAN_KEEP]
                : [self::PLAN_BUILD, self::PLAN_KEEP];
            $answer = $ui->ask('The plan is ready. Build it now?', $options, true);
            if ($answer === null || $answer === self::PLAN_KEEP) {
                return;
            }
            if (!in_array($answer, $options, true)) {
                $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn($answer, $cancel));
                continue;
            }
            $name = $this->kernel->config->defaultAgent;
            try {
                $this->primary = $this->kernel->primaryAgent($name === null || $name === 'plan' ? 'build' : $name);
            } catch (PhycodeException $error) {
                $ui->notice('error', $this->redact($error->getMessage()));

                return;
            }
            if ($answer === self::PLAN_BUILD_ACCEPT) {
                $this->kernel->setPermissionMode('accept_edits');
                $ui->notice('info', 'edits are accepted without asking for the rest of this session; commands still ask');
            }
            $this->conversation->setSystem($this->systemPrompt());
            $ui->notice('info', "primary agent is now {$this->primary->name}");
            $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn(self::BUILD_PROMPT, $cancel));

            return;
        }
    }

    private function lastAssistantText(): string
    {
        $messages = $this->conversation->messages();
        $last = $messages === [] ? [] : $messages[array_key_last($messages)];

        return ($last['role'] ?? '') === 'assistant' && is_string($last['content'] ?? null) ? trim($last['content']) : '';
    }

    private function dispatchCommand(string $name, string $args): void
    {
        try {
            $this->kernel->commands->dispatch($name, $args, $this->commandContext());
            $this->conversation->setSystem($this->systemPrompt());
        } catch (\Throwable $error) {
            $this->kernel->ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function commandContext(): CommandContext
    {
        return new CommandContext(
            $this->kernel->ui,
            $this->kernel->workspace->root,
            $this->kernel->config,
            fn (string $prompt) => $this->runAsTurn(fn (Cancellation $cancel) => $this->runTurn($prompt, $cancel)),
            fn (string $text): string => $this->redact($text),
        );
    }

    private function helpText(): string
    {
        $lines = [];
        foreach ($this->commands() as $command) {
            $lines[] = sprintf('%-24s %s', $command['name'], $command['description']);
        }
        $lines[] = sprintf('%-24s %s', '@NAME TASK', 'run a subagent directly; its report is added to the conversation');

        return implode("\n", $lines);
    }

    private function skillsText(): string
    {
        $gentle = new GentleAi();
        $bin = $gentle->detect($this->kernel->config->gentleAiBin());
        if ($bin === null) {
            return GentleAi::NOT_INSTALLED;
        }
        $reason = null;
        $skills = $gentle->skills($bin, $this->kernel->workspace->root, $reason);
        if ($reason !== null) {
            return "skills unavailable: {$reason}";
        }
        if ($skills === []) {
            return 'gentle-ai reports no skills for this project';
        }
        $width = max(array_map(static fn (array $skill): int => mb_strlen($skill['name']), $skills));
        $lines = [];
        foreach ($skills as $skill) {
            $lines[] = rtrim($skill['name'] . str_repeat(' ', $width - mb_strlen($skill['name'])) . '  ' . $skill['description']);
        }

        return implode("\n", $lines);
    }

    private function modelItems(): array
    {
        $items = [];
        foreach ($this->kernel->catalog->entries(true) as $entry) {
            $item = ['category' => $entry['profile'], 'title' => $entry['model'], 'detail' => '', 'status' => 'ok'];
            if ($entry['error'] !== null) {
                $item['detail'] = $this->redact($entry['error']);
                $item['status'] = 'error';
            } else {
                $item['detail'] = sprintf(
                    'context %s  tools %s  thinking %s  loaded %s',
                    $entry['contextWindow'] === null ? '-' : (string) $entry['contextWindow'],
                    self::flag($entry['tools']),
                    $entry['thinking'] === [] ? '-' : implode(',', $entry['thinking']),
                    self::flag($entry['loaded']),
                );
            }
            if ($entry['profile'] === $this->profile && $entry['model'] === $this->model()) {
                $item['badge'] = 'in use';
            }
            $items[] = $item;
        }

        return $items;
    }

    private function statusText(): string
    {
        return self::table([
            'version' => Application::VERSION,
            'profile' => $this->profile,
            'model' => $this->model(),
            'protocol' => $this->kernel->config->profile($this->profile)->protocol,
            'thinking' => $this->thinking,
            'tool mode' => $this->toolMode(),
            'sandbox' => $this->kernel->sandboxes->describe(),
            'permissions' => $this->kernel->permissionMode,
            'cwd' => $this->kernel->workspace->root,
            'transcript' => $this->transcript->path,
        ]);
    }

    private function contextText(): string
    {
        return self::table(ContextReport::summaryRows($this->contextState()));
    }

    private function contextCommand(string $argument): void
    {
        $ui = $this->kernel->ui;
        try {
            $words = preg_split('/\s+/', $argument, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                $ui->panel('Context', $this->contextText());

                return;
            }
            $save = count($words) === 3 && $words[2] === '--save';
            if (($words[0] ?? null) !== 'max' || count($words) !== ($save ? 3 : 2)) {
                $ui->notice('error', 'usage: /context, or /context max N|Nk|off [--save]');

                return;
            }
            $this->maxCommand($words[1], $save);
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function maxCommand(string $word, bool $save): void
    {
        $ui = $this->kernel->ui;
        $max = $word === 'off' ? null : self::tokensOf($word);
        if ($word !== 'off' && $max === null) {
            $ui->notice('error', 'usage: /context, or /context max N|Nk|off [--save]');

            return;
        }
        $this->setContextMax($max);
        $ui->notice('info', $this->maxText($max));
        if (!$save) {
            return;
        }
        try {
            ConfigWriter::setContextMax($this->kernel->configFile, $max);
        } catch (PhycodeException $error) {
            $ui->notice('error', 'the max is set for this session only; saving it failed: ' . $this->redact($error->getMessage()));

            return;
        }
        try {
            $this->kernel->reloadConfig();
        } catch (PhycodeException $error) {
            $ui->notice('error', 'the max is saved, but the configuration cannot be read again; restart phycode to use it: ' . $this->redact($error->getMessage()));

            return;
        }
        $ui->notice('info', "saved context.max in {$this->kernel->configFile}");
    }

    private function maxText(?int $max): string
    {
        $limit = $this->contextLimit();
        $text = $max !== null
            ? 'context max is ' . ContextReport::tokensText($max) . ' tokens'
            : 'context max is the window of the model' . ($limit === null ? ', which is unknown' : ': ' . ContextReport::tokensText($limit) . ' tokens');
        $threshold = $limit === null ? null : Compaction::threshold($limit, $this->kernel->config->contextBuffer());
        if ($threshold !== null && $this->autoCompactOn()) {
            $text .= '; autocompact runs at ' . ContextReport::tokensText($threshold);
        }

        return $text;
    }

    private static function tokensOf(string $word): ?int
    {
        if (preg_match('/^(\d{1,9})(k?)$/i', $word, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * ($match[2] === '' ? 1 : 1000);
    }

    private function compactCommand(string $instructions, Cancellation $cancel): void
    {
        $ui = $this->kernel->ui;
        try {
            $result = $this->summarise($instructions === '' ? null : $instructions, $cancel);
        } catch (\Throwable $error) {
            if ($cancel->isCancelled()) {
                $this->lastStatus = 'cancelled';
                $this->lastReason = null;

                return;
            }
            $this->lastStatus = 'error';
            $this->lastReason = $this->redact($error->getMessage());
            $ui->notice('error', "compaction failed: {$this->lastReason}");

            return;
        }
        $this->lastStatus = 'completed';
        $ui->panel('compact', self::table([
            'tokens before' => (string) $result['before'],
            'tokens after' => (string) $result['after'],
            'summarised' => "{$result['summarised']} messages",
            'kept' => "{$result['kept']} messages",
        ]));
        $ui->notice('info', 'prompt cache resets: the older turns are now one summary');
    }

    private function handoff(): void
    {
        $ui = $this->kernel->ui;
        $this->guardIdle();
        $messages = $this->conversation->messages();
        if (count($messages) === Compaction::headLength($messages)) {
            $ui->notice('info', 'there is nothing to hand off yet: the conversation has no messages');

            return;
        }
        $handoff = null;
        $this->runAsTurn(function (Cancellation $cancel) use (&$handoff): void {
            try {
                $handoff = $this->redact($this->handoffText($cancel));
            } catch (\Throwable $error) {
                if (!$cancel->isCancelled()) {
                    throw $error;
                }
                $this->lastStatus = 'cancelled';

                return;
            }
            $this->lastStatus = 'completed';
        }, true);
        if ($handoff === null) {
            return;
        }

        $approve = new Approval($ui, fn (string $heading, string $text) => $ui->panel($heading, $this->redact($text)));
        if (!$approve('Handoff', $handoff, 'save this handoff and start a new session with it')) {
            $ui->notice('info', 'nothing was saved; the session goes on');

            return;
        }
        try {
            $path = $this->saveHandoff($handoff);
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));

            return;
        }
        $from = $this->sessionId;
        $this->newSession();
        $this->conversation->addUser($handoff);
        $this->persist();
        $ui->notice('info', "started session {$this->sessionId} with the handoff from {$from}, saved in {$path}");
    }

    private function handoffText(Cancellation $cancel): string
    {
        $text = $this->summaryOf($this->conversation->messages(), self::HANDOFF_INSTRUCTIONS, $cancel);
        if ($text === '') {
            throw new PhycodeException('the model gave an empty handoff; nothing was saved');
        }

        return $text;
    }

    private function saveHandoff(string $handoff): string
    {
        $folder = Files::runtimeFolder($this->kernel->workspace->root, 'handoffs');
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new PhycodeException("cannot create the handoff folder {$folder}");
        }
        Files::writeAtomic($folder . '/' . $this->sessionId . '.md', $handoff . "\n", 0600);

        return ".phycode/handoffs/{$this->sessionId}.md";
    }

    private function effortCommand(string $argument): void
    {
        $ui = $this->kernel->ui;
        try {
            if ($argument === '') {
                $ui->panel('Effort', self::table([
                    'effort' => $this->thinking,
                    'levels' => implode(', ', $this->effortLevels()),
                    'note' => $this->effortNote(),
                ]));

                return;
            }
            $this->setEffort($argument);
            $ui->notice('info', "effort is now {$this->thinking}; it applies to the next turn");
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function diffCommand(): void
    {
        $ui = $this->kernel->ui;
        try {
            $turns = $this->checkpoints->turns();
            $number = self::lastApplied($turns);
            if ($number === null) {
                $ui->notice('info', 'there is no turn with changes to show');

                return;
            }
            $files = $this->checkpoints->turnContents($turns[$number - 1]['id']);
            if ($files === []) {
                $ui->notice('info', "turn {$number} changed no files");

                return;
            }
            $parts = ["turn {$number}: " . self::plural(count($files), 'file')];
            foreach ($files as $file) {
                $diff = TextDiff::change($file['path'], $file['before'], $file['after']);
                $parts[] = $diff === null ? "{$file['path']}: too large to show" : self::diffText($diff);
            }
            $ui->panel('Diff', $this->redact(implode("\n\n", $parts)));
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function undoCommand(): void
    {
        $ui = $this->kernel->ui;
        try {
            $this->guardIdle();
            $turns = $this->checkpoints->turns();
            $number = self::lastApplied($turns);
            if ($number === null) {
                $ui->notice('info', 'there is no turn to undo');

                return;
            }
            $id = $turns[$number - 1]['id'];
            $this->changeFiles(
                'Undo',
                "undo turn {$number}",
                $this->contentsOf([$id], 'before'),
                fn () => $this->checkpoints->undo($id),
                "undid turn {$number}",
            );
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function redoCommand(): void
    {
        $ui = $this->kernel->ui;
        try {
            $this->guardIdle();
            $turns = $this->checkpoints->turns();
            $number = self::firstUndone($turns);
            if ($number === null) {
                $ui->notice('info', 'there is no turn to redo');

                return;
            }
            $id = $turns[$number - 1]['id'];
            $this->changeFiles(
                'Redo',
                "redo turn {$number}",
                $this->contentsOf([$id], 'after'),
                fn () => $this->checkpoints->redo($id),
                "redid turn {$number}",
            );
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function rewindCommand(string $argument): void
    {
        $ui = $this->kernel->ui;
        try {
            $this->guardIdle();
            $turns = $this->checkpoints->turns();
            if ($argument === '') {
                $ui->panel('Turns', $this->redact($this->turnsText($turns)));

                return;
            }
            if (!ctype_digit($argument)) {
                $ui->notice('error', 'usage: /rewind TURN, with the number of a turn from /rewind');

                return;
            }
            $number = (int) $argument;
            if ($number < 1 || $number > count($turns)) {
                $ui->notice('error', $turns === [] ? 'there is no turn to rewind to' : "there is no turn {$argument}; the turns are 1 to " . count($turns));

                return;
            }
            if ($turns[$number - 1]['undone']) {
                $ui->notice('info', "turn {$number} is already undone, so there is nothing to rewind");

                return;
            }
            $ids = [];
            foreach (array_slice($turns, $number - 1) as $turn) {
                if (!$turn['undone']) {
                    $ids[] = $turn['id'];
                }
            }
            $turnCount = self::plural(count($ids), 'turn');
            $this->changeFiles(
                'Rewind',
                "rewind to before turn {$number}, which undoes {$turnCount}",
                $this->contentsOf($ids, 'before'),
                fn () => $this->checkpoints->rewind($turns[$number - 1]['id']),
                "rewound to before turn {$number}: {$turnCount} undone",
            );
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function changeFiles(string $title, string $question, array $contents, \Closure $action, string $done): void
    {
        $ui = $this->kernel->ui;
        $approve = new Approval($ui, fn (string $heading, string $text) => $ui->panel($heading, $this->redact($text)));
        if (!$approve($title, self::fileLines($contents), $question)) {
            $ui->notice('info', 'nothing was changed');

            return;
        }
        $action();
        $ui->notice('info', $done);
    }

    private function contentsOf(array $ids, string $side): array
    {
        $contents = [];
        foreach ($ids as $id) {
            foreach ($this->checkpoints->turnContents($id) as $file) {
                if (!array_key_exists($file['path'], $contents)) {
                    $contents[$file['path']] = $file[$side];
                }
            }
        }

        return $contents;
    }

    private static function fileLines(array $contents): string
    {
        if ($contents === []) {
            return 'no file changes';
        }
        $lines = [];
        foreach ($contents as $path => $content) {
            $lines[] = "{$path}: " . ($content === null ? 'removed' : 'restored');
        }

        return implode("\n", $lines);
    }

    private static function diffText(array $diff): string
    {
        if ($diff['binary']) {
            return "{$diff['path']}: binary file, not shown";
        }
        if ($diff['hunks'] === []) {
            return "{$diff['path']}: no change in content";
        }
        $lines = [$diff['path']];
        foreach ($diff['hunks'] as $hunk) {
            $lines[] = "@@ -{$hunk['oldStart']},{$hunk['oldLines']} +{$hunk['newStart']},{$hunk['newLines']} @@";
            foreach ($hunk['lines'] as $line) {
                $mark = match ($line['kind']) {
                    FileDiff::ADDED => '+',
                    FileDiff::REMOVED => '-',
                    default => ' ',
                };
                $lines[] = $mark . $line['text'];
            }
        }

        return implode("\n", $lines);
    }

    private function turnsText(array $turns): string
    {
        if ($turns === []) {
            return 'there are no turns yet';
        }
        $lines = ['usage: /rewind TURN, with a number from this list'];
        foreach ($turns as $position => $turn) {
            $state = $turn['undone'] ? 'undone' : 'applied';
            $lines[] = ($position + 1) . "  {$state}  " . implode(', ', $this->checkpoints->changedFiles($turn['id']));
        }

        return implode("\n", $lines);
    }

    private static function lastApplied(array $turns): ?int
    {
        $position = array_find_key(array_reverse($turns, true), static fn (array $turn): bool => !$turn['undone']);

        return $position === null ? null : $position + 1;
    }

    private static function firstUndone(array $turns): ?int
    {
        $position = array_find_key($turns, static fn (array $turn): bool => $turn['undone']);

        return $position === null ? null : $position + 1;
    }

    private static function plural(int $count, string $noun): string
    {
        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }

    private function sessionsCommand(string $argument): void
    {
        $ui = $this->kernel->ui;
        try {
            if ($argument === '') {
                $this->sessionPanel();
            } elseif ($argument === 'new') {
                $this->newSession();
                $ui->notice('info', "started session {$this->sessionId}");
            } elseif (str_starts_with($argument, 'switch ')) {
                $id = trim(substr($argument, strlen('switch')));
                $this->switchSession($id);
                $ui->notice('info', "switched to session {$id}");
            } else {
                $ui->notice('error', 'usage: /sessions [switch ID|new]');
            }
        } catch (PhycodeException $error) {
            $ui->notice('error', $this->redact($error->getMessage()));
        }
    }

    private function sessionPanel(): void
    {
        $items = [];
        foreach ($this->listSessions() as $session) {
            $item = [
                'category' => 'Sessions',
                'title' => $session['id'],
                'detail' => $this->redact(sprintf(
                    '%s | %d messages | updated %s',
                    $session['title'] === '' ? 'no message yet' : $session['title'],
                    $session['messages'],
                    $session['updatedAt'],
                )),
                'status' => '',
            ];
            if ($session['current']) {
                $item['badge'] = 'current';
            }
            $items[] = $item;
        }
        if ($items === []) {
            $this->kernel->ui->panel('Sessions', 'no saved sessions');

            return;
        }
        $this->kernel->ui->listPanel('Sessions', $items, '/sessions switch ID switches to a session; /sessions new starts a fresh one');
    }

    private function skillPanel(string $name): void
    {
        $ui = $this->kernel->ui;
        $this->kernel->skillIndex->refresh($this->kernel->config);
        $this->conversation->setSystem($this->systemPrompt());
        $items = $this->kernel->skillIndex->catalog();
        $notes = array_map(fn (string $note): string => $this->redact($note), $this->kernel->skillIndex->notes());

        if ($name !== '') {
            $item = null;
            foreach ($items as $candidate) {
                if ($candidate['title'] === $name) {
                    $item = $candidate;
                    break;
                }
            }
            if ($item === null) {
                $ui->notice('error', $this->redact("no skill named {$name}; /skill lists the installed skills"));

                return;
            }
            $text = SkillCatalog::document($item['path']);
            if ($text === null) {
                $ui->notice('error', $this->redact("cannot read the skill file {$item['path']}"));

                return;
            }
            $ui->panel("Skill {$name}", $this->redact($text));

            return;
        }

        if ($items === []) {
            $ui->panel('Skills', SkillCatalog::none() . ($notes === [] ? '' : "\n\n" . implode("\n", $notes)));

            return;
        }
        $ui->listPanel('Skills', $items, implode(' ', ['/skill NAME shows one skill.', ...$notes]));
    }

    private function mcpPanel(): void
    {
        $ui = $this->kernel->ui;
        $servers = $this->kernel->config->mcpServers();
        if ($servers === []) {
            $ui->panel('MCP servers', $this->redact(McpCatalog::unconfigured($this->kernel->configFile)));

            return;
        }
        if ($this->kernel->mcp !== null) {
            $mcp = $this->kernel->mcp;
            $ui->listPanel('MCP servers', $mcp->items(fn (string $message): string => $this->redact($message)), $mcp->searching()
                ? 'the tools are found with mcp_search and run with mcp_call; prompts are the commands /mcp__SERVER__PROMPT'
                : 'the tools of the connected servers are offered to the model; prompts are the commands /mcp__SERVER__PROMPT');

            return;
        }
        $items = McpCatalog::items($servers, $this->kernel->workspace->root, fn (string $message): string => $this->redact($message));
        $ui->listPanel('MCP servers', $items, 'servers start for this list and stop again');
    }

    private static function table(array $rows): string
    {
        $width = max(array_map('strlen', array_keys($rows)));
        $lines = [];
        foreach ($rows as $label => $value) {
            $lines[] = str_pad((string) $label, $width) . '  ' . $value;
        }

        return implode("\n", $lines);
    }

    private static function flag(?bool $value): string
    {
        return $value === null ? '-' : ($value ? 'yes' : 'no');
    }

    private function runDirect(string $request, Cancellation $cancel): void
    {
        $parts = explode(' ', $request, 2);
        $result = $this->kernel->delegation->delegate($parts[0], $parts[1] ?? '', $this->context($cancel));
        $this->kernel->ui->output($result->content);
        $this->conversation->addUser("Report from subagent {$parts[0]}, requested by the user:\n" . $result->content);
        $this->conversationTokens = null;
        $this->cacheStats = null;

        $usage = $result->meta['usage'] ?? null;
        $this->lastUsage = $usage instanceof Usage ? $usage : null;
        if ($result->ok) {
            $this->lastStatus = 'completed';

            return;
        }
        $reason = $result->meta['reason'] ?? null;
        $this->lastStatus = ($result->meta['status'] ?? null) === OutcomeStatus::Cancelled->value ? 'cancelled' : 'error';
        $this->lastReason = $this->redact(is_string($reason) ? $reason : $result->content);
    }

    private function runTurn(string $input, Cancellation $cancel, array $images = []): void
    {
        $ui = $this->kernel->ui;
        $mark = $this->kernel->changes->mark();
        $mode = SmallTalk::isGreeting($input) && count($this->conversation->messages()) <= 1 ? 'none' : $this->resolveMode();
        [$message, $tools] = $this->webTurn($input, $mode);
        $hook = $this->kernel->runner->promptSubmitted($input, $this->context($cancel));
        if ($hook->block !== null) {
            $ui->notice('warn', 'the prompt was blocked by a hook: ' . $this->redact($hook->block));

            return;
        }
        if ($hook->context !== []) {
            $message .= "\n\nUserPromptSubmit hook:\n" . implode("\n", $hook->context);
        }
        $this->conversation->addUser($message, $images);
        $prompt = $tools === $this->kernel->tools ? null : $this->systemPrompt($tools);
        if ($prompt !== null) {
            $this->conversation->setSystem($prompt);
        }
        try {
            $outcome = $this->kernel->runner->run(
                $this->conversation,
                new RunSetup(
                    $this->client(),
                    $mode,
                    $tools,
                    $this->context($cancel),
                    $this->kernel->config->limits->primaryMaxTurns,
                    true,
                ),
            );
        } finally {
            if ($prompt !== null) {
                $this->conversation->setSystem($this->systemPrompt());
            }
        }
        $this->lastStatus = $outcome->status->value;
        $this->lastReason = $outcome->reason === null ? null : $this->redact($outcome->reason);
        $this->lastUsage = $outcome->lastUsage;
        $this->conversationTokens = self::tokensAfter($outcome->lastUsage);
        $usage = $outcome->lastUsage;
        $this->cacheStats = $usage !== null && $usage->promptTokens !== null && $usage->cachedTokens !== null ? ['cached' => $usage->cachedTokens, 'prompt' => $usage->promptTokens] : null;

        $ui->text("\n");
        if ($outcome->status !== OutcomeStatus::Completed) {
            $level = $outcome->status === OutcomeStatus::Error ? 'error' : 'warn';
            $ui->notice($level, "turn ended ({$outcome->status->value}): " . ($this->lastReason ?? 'no reason'));
        }
        $changed = $this->kernel->changes->since($mark);
        if ($changed !== []) {
            $ui->notice('info', 'changes: ' . implode(', ', $changed));
        }
    }

    private function context(Cancellation $cancel): ToolContext
    {
        return $this->kernel->context(
            $this->primary,
            $this->kernel->policyFor($this->primary),
            $this->transcript,
            $cancel,
            $this->profile,
            $this->kernel->delegatorFor(),
            $this->kernel->sandboxes->runnerFor($this->primary->sandbox),
            $this->model,
            $this->thinking,
        );
    }

    private function systemPrompt(?ToolRegistry $tools = null): string
    {
        $mode = $this->toolMode();

        return $this->kernel->systemPrompt($this->primary, $mode === 'auto' ? 'none' : $mode, $this->kernel->policyFor($this->primary), $tools);
    }

    private function webTurn(string $input, string $mode): array
    {
        $trigger = WebTrigger::detect($input);
        $ui = $this->kernel->ui;
        $offered = $this->kernel->webOffered();
        if ($trigger !== null && $mode === 'none') {
            $ui->notice('warn', 'this profile cannot call tools, so the web triggers do nothing in this turn');
        }
        if ($trigger !== null && $mode !== 'none' && $trigger['search'] && !$offered['search']) {
            $ui->notice('warn', 'web search is off: no search is in use; choose one with /web, or set web.search.backend in the config');
        }
        if ($trigger !== null && $mode !== 'none' && $trigger['fetch'] && !$offered['fetch']) {
            $ui->notice('warn', 'web fetch is off: set the web section in the config');
        }
        $search = $mode !== 'none' && $offered['search'];
        $fetch = $mode !== 'none' && $offered['fetch'];

        $message = $input;
        if ($trigger !== null) {
            $lines = [$trigger['text']];
            if ($search && $trigger['query'] !== null) {
                $lines[] = "Web search query: {$trigger['query']}";
            }
            if ($fetch && $trigger['urls'] !== []) {
                $lines[] = "Read these pages with web_fetch:\n" . implode("\n", $trigger['urls']);
            }
            $message = trim(implode("\n\n", array_filter($lines, static fn (string $line): bool => $line !== '')));
            $message = $message === '' ? $input : $message;

            $asked = [];
            if ($search && $trigger['search']) {
                $asked[] = 'web search ON';
            }
            if ($fetch && $trigger['fetch']) {
                $asked[] = 'web fetch ON';
            }
            if ($asked !== []) {
                $ui->notice('info', implode(', ', $asked) . ' · this turn');
            }
            if ($fetch && $trigger['urls'] !== []) {
                $ui->notice('info', 'web fetch reads: ' . implode(' ', $trigger['urls']));
            }
        }

        return [$message, $this->kernel->turnTools($search, $fetch)];
    }

    private function freshConversation(): Conversation
    {
        $conversation = new Conversation();
        $conversation->setSystem($this->systemPrompt());

        return $conversation;
    }

    private function applySettings(string $profile, ?string $model, string $thinking): void
    {
        $model = $this->checkSettings($profile, $model, $thinking);
        $before = $this->model();
        $after = $model ?? $this->kernel->config->profile($profile)->model;
        $this->profile = $profile;
        $this->model = $model;
        $this->thinking = self::canonicalLevel($thinking);
        $this->resolvedMode = null;
        $this->windows = [];
        $this->conversationTokens = null;
        $this->cacheStats = null;
        if ($after !== $before && $this->kernel->config->profile($profile)->isLocal()) {
            $this->kernel->ui->notice('info', 'prompt cache resets when the model changes');
        }
    }

    private function checkSettings(string $profile, ?string $model, string $thinking): ?string
    {
        $this->kernel->config->profile($profile);
        self::canonicalLevel($thinking);
        $model = $model === null ? null : trim($model);
        if ($model === '') {
            throw new PhycodeException('the model name cannot be empty');
        }

        return $model;
    }

    private static function canonicalLevel(string $thinking): string
    {
        return Profile::canonical($thinking) ?? throw new PhycodeException('thinking must be ' . Profile::levelsText() . ' (off and on are also accepted)');
    }

    private function resolveMode(): string
    {
        if ($this->resolvedMode === null) {
            $this->resolvedMode = $this->kernel->toolMode($this->profile, $this->model, $this->thinking);
            $this->conversation->setSystem($this->systemPrompt());
        }

        return $this->resolvedMode;
    }

    private function client(): ChatClient
    {
        return $this->kernel->providers->forProfile($this->profile, $this->model, $this->thinking);
    }

    private function contextLimit(): ?int
    {
        return $this->contextMax ?? $this->contextWindow();
    }

    private function cutOf(array $messages): ?int
    {
        $limit = $this->contextLimit();

        return Compaction::cut($messages, $limit === null ? null : intdiv($limit, 4));
    }

    private function autoCompactOn(): bool
    {
        return $this->kernel->config->autoCompact() && $this->contextMax !== null;
    }

    private function autoCompact(Cancellation $cancel): bool
    {
        if (!$this->autoCompactOn()) {
            return false;
        }
        $limit = $this->contextLimit();
        if ($limit === null) {
            return false;
        }
        $threshold = Compaction::threshold($limit, $this->kernel->config->contextBuffer());
        $messages = $this->conversation->messages();
        $used = $this->usedTokens($messages);
        if ($threshold === null || $used < $threshold || $this->cutOf($messages) === null) {
            return false;
        }
        try {
            $this->summarise(null, $cancel);
        } catch (\Throwable $error) {
            if ($cancel->isCancelled()) {
                return false;
            }
            $this->kernel->ui->notice('warn', 'autocompact failed: ' . $this->redact($error->getMessage()));

            return true;
        }
        $this->kernel->ui->notice('info', 'autocompact: context at ' . ContextReport::tokensText($used) . ' of ' . ContextReport::tokensText($limit) . ', older messages summarised');
        if ($this->autoCompacted !== null) {
            ($this->autoCompacted)();
        }

        return false;
    }

    private static function tokensAfter(?Usage $usage): ?int
    {
        if ($usage?->promptTokens === null) {
            return null;
        }

        return $usage->promptTokens + ($usage->completionTokens ?? 0);
    }

    private function guardIdle(): void
    {
        if ($this->turn !== null) {
            throw new PhycodeException('a turn is running; try again when it finishes');
        }
    }

    private function runAsTurn(\Closure $turn, bool $summarises = false): void
    {
        $run = function () use ($turn, $summarises): void {
            $cancel = new Cancellation();
            $this->turn = $cancel;
            $this->lastStatus = null;
            $this->lastReason = null;
            $this->lastUsage = null;
            $mark = $this->kernel->changes->mark();
            try {
                if ($this->model() === '') {
                    throw new PhycodeException("profile '{$this->profile}' has no model: choose one in /models, or set its model in the config");
                }
                try {
                    $this->checkpoints->begin(SessionStore::newId());
                } catch (\Throwable $error) {
                    $this->kernel->ui->notice('warn', 'no checkpoint for this turn, so its file changes are refused: ' . $this->redact($error->getMessage()));
                }
                if ($summarises) {
                    $turn($cancel);
                } else {
                    $failed = $this->autoCompact($cancel);
                    $turn($cancel);
                    if (!$failed && $this->lastStatus === 'completed') {
                        $this->autoCompact($cancel);
                    }
                }
            } catch (\Throwable $error) {
                $this->lastStatus = 'error';
                $this->lastReason = $this->redact($error->getMessage());
                $this->kernel->ui->notice('error', "turn ended (error): {$this->lastReason}");
            } finally {
                $this->turn = null;
                try {
                    $this->skillsChanged($mark);
                } finally {
                    $this->persist();
                }
            }
        };
        if ($this->frame === null) {
            $run();

            return;
        }
        ($this->frame)($run);
    }

    private function skillsChanged(int $mark): void
    {
        $folder = SkillCatalog::userFolder($this->kernel->config->configDir) . '/';
        foreach ($this->kernel->changes->since($mark) as $path) {
            if (str_starts_with($path, $folder)) {
                $this->kernel->skillIndex->invalidate();
                $this->conversation->setSystem($this->systemPrompt());

                return;
            }
        }
    }

    private function persist(): void
    {
        $messages = $this->conversation->messages();
        if ($this->title === '') {
            $this->title = self::titleOf($messages);
        }
        $secrets = $this->kernel->secrets();
        try {
            $this->store->save([
                'id' => $this->sessionId,
                'title' => Redaction::text($this->title, $secrets),
                'createdAt' => $this->createdAt,
                'updatedAt' => SessionStore::now(),
                'profile' => $this->profile,
                'model' => $this->model(),
                'thinking' => $this->thinking,
                'todos' => $this->kernel->todos->toArray(),
                'messages' => self::redacted($messages, $secrets),
            ]);
        } catch (PathException) {
        } catch (PhycodeException $error) {
            $this->kernel->ui->notice('warn', 'the session is not saved: ' . $this->redact($error->getMessage()));
        }
    }

    public static function redacted(array $messages, array $secrets): array
    {
        foreach ($messages as $index => $message) {
            if (isset($message['images'])) {
                $message['content'] = Image::textWithPlaceholders($message);
                unset($message['images']);
                $messages[$index] = $message;
            }
            if (is_string($message['content'] ?? null)) {
                $messages[$index]['content'] = Redaction::text($message['content'], $secrets);
            }
            foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $position => $call) {
                $function = is_array($call['function'] ?? null) ? $call['function'] : [];
                if (is_string($function['arguments'] ?? null)) {
                    $messages[$index]['tool_calls'][$position]['function']['arguments'] = Redaction::text($function['arguments'], $secrets);
                }
            }
        }

        return $messages;
    }

    public static function titleOf(array $messages): string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'user') {
                $line = trim((string) preg_replace('/\s+/u', ' ', mb_scrub((string) $message['content'], 'UTF-8')));

                return mb_substr($line, 0, 60);
            }
        }

        return '';
    }
}
