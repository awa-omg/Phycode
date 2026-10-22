<?php

declare(strict_types=1);

namespace Phycode\Cli;

use Phycode\Agent\AgentDefinition;
use Phycode\Agent\AgentRegistry;
use Phycode\Agent\AgentRunner;
use Phycode\Agent\Delegation;
use Phycode\Agent\Hooks;
use Phycode\Agent\PromptBuilder;
use Phycode\Agent\RunPolicy;
use Phycode\Checkpoint\CheckpointStore;
use Phycode\Cli\Commands\CommandRegistry;
use Phycode\Config\Config;
use Phycode\Deploy\Deployments;
use Phycode\Context\AgentsMd;
use Phycode\Exec\BackgroundJobs;
use Phycode\Exec\CommandRunner;
use Phycode\Exec\ProcessRunner;
use Phycode\Exec\SandboxSelector;
use Phycode\Gentle\GlobalConfig;
use Phycode\Gentle\Persona;
use Phycode\Llm\CapabilityCache;
use Phycode\Llm\ClientFactory;
use Phycode\Llm\ClientProvider;
use Phycode\Llm\ModelCatalog;
use Phycode\Llm\Pricing;
use Phycode\Llm\UsageMeter;
use Phycode\Mcp\McpServers;
use Phycode\Review\ReviewCommands;
use Phycode\Security\Permissions;
use Phycode\Security\Rules;
use Phycode\Security\Workspace;
use Phycode\Ssh\SshHosts;
use Phycode\Session\SessionSearch;
use Phycode\Session\SessionStore;
use Phycode\Session\Transcript;
use Phycode\Skills\SkillCatalog;
use Phycode\Skills\SkillIndex;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;
use Phycode\Support\StateStore;
use Phycode\Support\Text;
use Phycode\Support\Vault;
use Phycode\Tools\BashTool;
use Phycode\Tools\ChangeLog;
use Phycode\Tools\Delegator;
use Phycode\Tools\DeleteTool;
use Phycode\Tools\DeployInfoTool;
use Phycode\Tools\DeployTool;
use Phycode\Tools\EditTool;
use Phycode\Tools\GhReadTool;
use Phycode\Tools\GhWriteTool;
use Phycode\Tools\GitReadTool;
use Phycode\Tools\GitRemoteTool;
use Phycode\Tools\GitWriteTool;
use Phycode\Tools\GlobTool;
use Phycode\Tools\GrepTool;
use Phycode\Tools\JobTool;
use Phycode\Tools\ListTool;
use Phycode\Tools\MoveTool;
use Phycode\Tools\OracleTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\RepoMapTool;
use Phycode\Tools\RunCodeTool;
use Phycode\Tools\SessionSearchTool;
use Phycode\Tools\SshCopyTool;
use Phycode\Tools\SshTool;
use Phycode\Tools\TaskTool;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\TodoList;
use Phycode\Tools\TodoTool;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolSettings;
use Phycode\Tools\WriteTool;
use Phycode\Ui\Ui;
use Phycode\Web\SearchKeys;

final class Kernel
{
    public Config $config;
    public readonly Workspace $workspace;
    public readonly AgentRegistry $agents;
    public ToolRegistry $tools;
    public ClientFactory $clients;
    public ClientProvider $providers;
    public ModelCatalog $catalog;
    public readonly string $configFile;
    public readonly ProcessRunner $processes;
    public readonly SandboxSelector $sandboxes;
    public readonly Permissions $permissions;
    public readonly ChangeLog $changes;
    public readonly CheckpointStore $checkpoints;
    public readonly BackgroundJobs $jobs;
    public readonly SshHosts $ssh;
    public readonly UsageMeter $usage;
    public readonly TodoList $todos;
    public readonly Deployments $deployments;
    public readonly ?McpServers $mcp;
    public readonly AgentRunner $runner;
    public readonly PromptBuilder $prompts;
    public readonly SkillIndex $skillIndex;
    public Delegation $delegation;
    public readonly Ui $ui;
    public readonly ?string $instructions;
    public string $permissionMode;
    public readonly StateStore $state;
    public readonly CommandRegistry $commands;
    private readonly array $fixedTools;
    private readonly array $laterTools;
    private array $webTools = [];
    private bool $mcpReported = false;
    private readonly array $disabledTools;
    private array $webOffered = ['search' => false, 'fetch' => false];
    private readonly ToolSettings $settings;
    private readonly string $cwd;
    private readonly string $configDir;
    private readonly string $cacheDir;
    private readonly CapabilityCache $capabilities;
    private readonly ?ClientProvider $suppliedProviders;

    public function __construct(string $cwd, bool $yolo, Ui $ui, ?string $sandbox, ?ClientProvider $providers = null, array $readOnly = [], bool $mcp = false, array $extraMcp = [])
    {
        $home = (string) (getenv('HOME') ?: '/root');
        $configDir = rtrim((string) (getenv('XDG_CONFIG_HOME') ?: $home . '/.config'), '/') . '/phycode';
        $cacheDir = rtrim((string) (getenv('XDG_CACHE_HOME') ?: $home . '/.cache'), '/') . '/phycode';

        $this->cwd = $cwd;
        $this->configDir = $configDir;
        $this->config = Config::load($cwd, $configDir, getenv());
        $this->configFile = $configDir . '/config.json';
        $this->workspace = new Workspace($cwd, $readOnly);
        $this->checkpoints = new CheckpointStore($this->workspace);
        $this->permissionMode = $yolo ? 'yolo' : $this->config->permissionMode();
        $this->ui = $ui;
        $this->permissions = new Permissions($this->ui, new Rules($this->config->rules()));
        $this->changes = new ChangeLog();
        $this->processes = new ProcessRunner();
        $root = $this->workspace->root;
        $this->sandboxes = new SandboxSelector($this->config->sandbox, $sandbox, $this->processes, $root);
        $this->agents = AgentRegistry::load($root, $root, $configDir, $home, dirname(__DIR__, 2) . '/resources/agents');
        foreach ($this->agents->notices() as $notice) {
            $this->ui->notice('warn', $notice);
        }

        $this->settings = new ToolSettings($this->config->disabledTools(), $this->config->customTools());
        $this->disabledTools = $this->settings->disabled;
        $this->cacheDir = $cacheDir;
        $this->jobs = new BackgroundJobs($cacheDir . '/jobs', $this->config->limits->backgroundJobs, $this->config->limits->backgroundLifetime);
        $this->ssh = new SshHosts($this->config->sshHosts(), $this->processes);
        $this->todos = new TodoList();
        $this->deployments = new Deployments($this->config->deployTargets(), new Vault($configDir), $this->processes, $this->ssh, $root);
        $remote = [
            ...($this->ssh->isEmpty() ? [] : [new SshTool($this->ssh, $this->jobs), new SshCopyTool($this->ssh)]),
            ...($this->deployments->isEmpty() ? [] : [new DeployTool($this->deployments), new DeployInfoTool($this->deployments)]),
        ];
        $shell = $this->workspace->readOnly === [] ? [new BashTool($this->jobs), new JobTool($this->jobs), new RunCodeTool(), ...$remote] : [];
        $this->fixedTools = [
            new ReadTool(),
            new ListTool(),
            new GlobTool(),
            new GrepTool(),
            new RepoMapTool(),
            new WriteTool(),
            new EditTool(),
            new MoveTool(),
            new DeleteTool(),
            ...$shell,
            new GitReadTool($root),
            new GitWriteTool($root),
            new GitRemoteTool($root),
            new GhReadTool($root),
            new GhWriteTool($root),
            new TaskTool($this->agents, $this->config->subagentsEnabled),
            new TodoTool($this->todos),
            new SessionSearchTool(new SessionSearch(new SessionStore($root))),
            ...($this->config->oracle() === null ? [] : [new OracleTool(fn () => $this->config->oracle(), fn () => $this->providers, fn () => $this->usage)]),
        ];
        $this->laterTools = $this->settings->optionalTools($this->config, $this->ui, $root);
        $servers = new McpServers($this->config->mcpServers() + $extraMcp, $root, new Vault($configDir), $this->config->mcpToolSearch());
        $this->mcp = $mcp && !$servers->isEmpty() ? $servers : null;
        $this->refreshWeb($this->config);
        $this->commands = new CommandRegistry();
        $this->commands->add(new \Phycode\Gentle\GentleCommands($this->configFile));
        $this->commands->add(ReviewCommands::fromKernel($this));

        $this->state = new StateStore(StateStore::defaultFile());
        $this->capabilities = new CapabilityCache($cacheDir . '/capabilities.json');
        $this->suppliedProviders = $providers;
        $this->usage = new UsageMeter(fn (string $profile, ?string $model): string => $model ?? ($this->config->hasProfile($profile) ? $this->config->profile($profile)->model : $profile));
        $this->runner = new AgentRunner(new Hooks($this->config->hooks()), new \Phycode\Agent\Checks($this->config->checks()), $this->usage);
        $this->prompts = new PromptBuilder();
        $skillsHome = getenv('HOME');
        $this->skillIndex = new SkillIndex($root, is_string($skillsHome) && $skillsHome !== '' ? $skillsHome : null, SkillCatalog::userFolder($configDir));
        $instructionNotices = [];
        $this->instructions = AgentsMd::load($root, $root, $configDir, $this->config->limits->agentsMdBytes, $instructionNotices);
        foreach ($instructionNotices as $notice) {
            $this->ui->notice('warn', $notice);
        }
        $this->wireProfiles($this->config);
    }

    public function reloadConfig(): void
    {
        $config = Config::load($this->cwd, $this->configDir, getenv());
        $this->refreshWeb($config);
        $this->wireProfiles($config);
        $this->skillIndex->invalidate();
    }

    private function refreshWeb(Config $config): void
    {
        $this->webTools = $this->settings->webTools($config, $this->cacheDir);
        $this->tools = $this->registry([...$this->disabledTools, 'web_search', 'web_fetch']);
        $this->reportMcp();
        $web = $config->webConfig();
        $this->webOffered = ['search' => $web !== null && $web['search']['backend'] !== 'none', 'fetch' => $web !== null];
    }

    private function wireProfiles(Config $config): void
    {
        $this->config = $config;
        $this->clients = new ClientFactory($config, $this->capabilities);
        $this->providers = $this->suppliedProviders ?? $this->clients;
        $this->catalog = new ModelCatalog($this->providers, $config, $this->state);
        $this->delegation = new Delegation(
            $this->agents,
            $this->providers,
            $this->tools,
            $this->runner,
            $this->prompts,
            $config,
            $this->workspace->root,
            $this->instructions,
            $this->sandboxes,
        );
    }

    public function primaryAgent(?string $name): AgentDefinition
    {
        $name ??= $this->config->defaultAgent ?? 'build';
        $agent = $this->agents->get($name);
        if ($agent === null || !$agent->isPrimary()) {
            $names = implode(', ', array_keys($this->agents->primaries()));

            throw new PhycodeException("no primary agent named '{$name}'. Primary agents: {$names}");
        }

        return $agent;
    }

    public function toolMode(string $profile, ?string $model = null, ?string $thinking = null): string
    {
        $mode = $this->providers->toolMode($profile, $model, $thinking);
        if ($mode === 'none') {
            $why = $this->providers instanceof ClientFactory ? $this->providers->whyNoTools($profile, $model, $thinking) : null;
            $reason = $why === null ? '' : ' (' . Text::headTail(Redaction::text($why), 200) . ')';
            $this->ui->notice('warn', "profile {$profile} cannot call tools{$reason}; the agent runs as a plain chat");
        }

        return $mode;
    }

    public function secrets(): array
    {
        $secrets = [];
        foreach ($this->config->profiles as $profile) {
            try {
                $key = $profile->apiKey();
            } catch (PhycodeException) {
                continue;
            }
            if ($key !== null) {
                $secrets[] = $key;
            }
        }
        $web = $this->config->webConfig();
        $searchKeys = $web === null ? [] : SearchKeys::secrets($web, new Vault($this->configDir));

        $github = [];
        foreach (['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $github[] = $value;
            }
        }

        return [...$secrets, ...$searchKeys, ...$this->deployments->secretValues(), ...$github];
    }

    public function delegatorFor(): ?Delegator
    {
        return $this->config->subagentsEnabled ? $this->delegation : null;
    }

    public function headlessRestricted(): bool
    {
        return $this->permissionMode === 'ask' && !$this->ui->interactive();
    }

    public function pricing(): Pricing
    {
        return new Pricing($this->config->pricing());
    }

    public function setPermissionMode(string $mode): void
    {
        if (!in_array($mode, Config::PERMISSION_MODES, true)) {
            throw new PhycodeException("'{$mode}' is not a permission mode; use " . implode(', ', Config::PERMISSION_MODES));
        }
        $this->permissionMode = $mode;
    }

    public function policyFor(AgentDefinition $agent): RunPolicy
    {
        $policy = RunPolicy::primary($agent, $this->permissionMode);

        return $this->headlessRestricted() ? $policy->withDenied(['write', 'exec']) : $policy;
    }

    public function webOffered(): array
    {
        return $this->webOffered;
    }

    public function turnTools(bool $search, bool $fetch): ToolRegistry
    {
        if (!$search && !$fetch) {
            return $this->tools;
        }
        $disabled = $this->disabledTools;
        if (!$search) {
            $disabled[] = 'web_search';
        }
        if (!$fetch) {
            $disabled[] = 'web_fetch';
        }

        return $this->registry($disabled);
    }

    private function registry(array $disabled): ToolRegistry
    {
        $registry = new ToolRegistry($disabled);
        foreach ([...$this->fixedTools, ...$this->webTools, ...$this->laterTools, ...($this->mcp?->tools() ?? [])] as $tool) {
            $registry->register($tool);
        }

        return $registry;
    }

    private function reportMcp(): void
    {
        if ($this->mcp === null || $this->mcpReported) {
            return;
        }
        $this->mcpReported = true;
        foreach ($this->mcp->problems() as $name => $why) {
            $this->ui->notice('warn', "MCP server {$name} did not start: " . Redaction::text($why, $this->secrets()));
        }
        foreach ($this->mcp->warnings() as $warning) {
            $this->ui->notice('warn', Redaction::text($warning, $this->secrets()));
        }
    }

    public function systemPrompt(AgentDefinition $agent, string $toolMode, RunPolicy $policy, ?ToolRegistry $tools = null): string
    {
        $prompt = $this->prompts->primary(
            $agent,
            $toolMode,
            $tools ?? $this->tools,
            $policy,
            $this->workspace->root,
            $this->instructions,
            $this->config->subagentsEnabled ? $this->agents->subagents() : [],
            $this->config->limits->maxTasksPerTurn,
            in_array('skill', ($tools ?? $this->tools)->available($policy), true) ? $this->skillBlock() : null,
            $this->config->skillsAutonomy(),
        );
        $persona = Persona::promptBlock(Persona::configured(new GlobalConfig($this->configFile)));
        $prompt = $persona === null ? $prompt : $prompt . "\n\n" . $persona;
        $mcp = $this->mcp?->instructions();

        return $mcp === null ? $prompt : $prompt . "\n\n" . $mcp;
    }

    private function skillBlock(): ?string
    {
        if (!$this->config->skillsIndexEnabled()) {
            return null;
        }
        if ($this->skillIndex->stale()) {
            $this->skillIndex->refresh($this->config);
        }

        return $this->skillIndex->block();
    }

    public function agentListing(): string
    {
        $lines = [];
        foreach ($this->agents->all() as $agent) {
            $tools = $agent->tools === null ? 'all' : implode(', ', $agent->tools);
            $denied = $agent->deniedCategories === [] ? '' : ' | denies: ' . implode(', ', $agent->deniedCategories);
            $lines[] = sprintf(
                '%-14s %-9s %-16s tools: %s%s | profile: %s | sandbox: %s',
                $agent->name,
                $agent->mode,
                $agent->source,
                $tools,
                $denied,
                $agent->profile ?? 'inherit',
                $agent->sandbox ?? 'inherit',
            );
            $lines[] = '    ' . $agent->description;
        }
        foreach ($this->agents->warnings() as $warning) {
            $lines[] = "warning: {$warning}";
        }

        return implode("\n", $lines);
    }

    public function context(
        AgentDefinition $agent,
        RunPolicy $policy,
        Transcript $transcript,
        Cancellation $cancel,
        string $profile,
        ?Delegator $delegator,
        CommandRunner $sandbox,
        ?string $model = null,
        ?string $thinking = null,
    ): ToolContext {
        return new ToolContext(
            $this->workspace,
            $this->permissions,
            $this->processes,
            $this->ui,
            $transcript,
            $this->config->limits,
            $agent,
            $policy,
            $cancel,
            $profile,
            $delegator,
            $this->changes,
            $sandbox,
            $this->checkpoints,
            $this->secrets(),
            $model,
            $thinking,
        );
    }

    public function sessionDirectory(): string
    {
        return $this->workspace->root . '/.phycode/sessions';
    }
}
