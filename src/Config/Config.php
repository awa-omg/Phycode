<?php

declare(strict_types=1);

namespace Phycode\Config;

use Phycode\Agent\Checks;
use Phycode\Agent\Hooks;
use Phycode\Deploy\DeployTarget;
use Phycode\Exec\DomainAllowlist;
use Phycode\Mcp\McpHttpClient;
use Phycode\Ssh\SshHost;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;

final readonly class Config
{
    private const PROJECT_KEYS = ['limits', 'default_agent', 'subagents'];
    private const SANDBOXES = ['host', 'podman', 'docker', 'bwrap'];
    private const NETWORKS = ['none', 'bridge'];
    public const PERMISSION_MODES = ['ask', 'accept_edits', 'plan', 'dont_ask', 'yolo'];
    public const THEMES = ['phycode', 'phycode-light', 'tokyo-night', 'catppuccin', 'gruvbox', 'rose-pine', 'nord', 'solarized-dark', 'paper', 'mono'];
    public const DIFF_VIEWS = ['auto', 'split', 'unified'];
    public const CONTEXT_MIN_TOKENS = 512;
    public const CONTEXT_MAX_TOKENS = 1000000;
    public const BUILTIN_TOOLS = ['ask_user', 'bash', 'delete', 'deploy', 'deploy_info', 'edit', 'gh_read', 'gh_write', 'git_read', 'git_remote', 'git_write', 'glob', 'grep', 'list', 'move', 'read', 'repo_map', 'run_code', 'session_search', 'skill', 'skill_manage', 'ssh', 'ssh_copy', 'task', 'todo', 'web_fetch', 'web_search', 'write'];
    private const HOOK_EVENTS = ['PreToolUse', 'PostToolUse', 'UserPromptSubmit', 'Stop', 'SubagentStop'];
    private const CHECK_KEYS = ['name', 'match', 'command', 'kind', 'timeout'];
    public const SEARCH_BACKENDS = ['none', 'searxng', 'brave', 'tavily', 'exa', 'perplexity', 'ollama', 'firecrawl'];
    private const KEYED_SEARCH_BACKENDS = ['brave', 'tavily', 'exa', 'perplexity', 'ollama', 'firecrawl'];
    private const SEARCH_NAME = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';
    private const CUSTOM_KEYS = ['name', 'description', 'command', 'stdin'];
    private const CUSTOM_NAME = '/^[A-Za-z][A-Za-z0-9_-]{0,47}$/D';

    public function __construct(
        public array $profiles,
        public string $defaultProfile,
        public Limits $limits,
        public string $permissionMode,
        public ?string $defaultAgent,
        public bool $subagentsEnabled,
        public array $warnings,
        public string $configDir,
        public Sandbox $sandbox,
        private array $gentleAi = [],
        private ?array $web = null,
        private array $rules = ['allow' => [], 'ask' => [], 'deny' => []],
        private array $hooks = ['PreToolUse' => [], 'PostToolUse' => [], 'UserPromptSubmit' => [], 'Stop' => [], 'SubagentStop' => []],
        private array $mcpServers = [],
        private array $ui = ['subagents' => ['auto_expand' => ['write', 'web']], 'diff_view' => 'auto'],
        private ?string $serviceSocket = null,
        private bool $skillsEnabled = true,
        private string $theme = 'phycode',
        private ?string $editor = null,
        private array $disabledTools = [],
        private string $effort = 'none',
        private ?int $contextMax = null,
        private ?int $contextBuffer = null,
        private bool $autoCompact = true,
        private array $customTools = [],
        private string $skillsIndex = 'on',
        private string $skillsAutonomy = 'ask',
        private array $sshHosts = [],
        private array $deployTargets = [],
        private array $checks = [],
        private string $mcpToolSearch = 'auto',
        private array $pricing = [],
        private ?array $oracle = null,
    ) {
    }

    public function profile(?string $name = null): Profile
    {
        $name ??= $this->defaultProfile;
        if (!isset($this->profiles[$name])) {
            throw new ConfigException("unknown profile '{$name}'. Known profiles: " . implode(', ', array_keys($this->profiles)));
        }

        return $this->profiles[$name];
    }

    public function hasProfile(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    public function webConfig(): ?array
    {
        return $this->web;
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function permissionMode(): string
    {
        return $this->permissionMode;
    }

    public function hooks(): array
    {
        return $this->hooks;
    }

    public function checks(): array
    {
        return $this->checks;
    }

    public function mcpServers(): array
    {
        return $this->mcpServers;
    }

    public function pricing(): array
    {
        return $this->pricing;
    }

    public function oracle(): ?array
    {
        return $this->oracle;
    }

    public function mcpToolSearch(): string
    {
        return $this->mcpToolSearch;
    }

    public function sshHosts(): array
    {
        return $this->sshHosts;
    }

    public function deployTargets(): array
    {
        return $this->deployTargets;
    }

    public function uiConfig(): array
    {
        return $this->ui;
    }

    public function serviceSocket(): ?string
    {
        return $this->serviceSocket;
    }

    public function skillsEnabled(): bool
    {
        return $this->skillsEnabled;
    }

    public function skillsIndexEnabled(): bool
    {
        return $this->skillsIndex === 'on';
    }

    public function skillsAutonomy(): string
    {
        return $this->skillsAutonomy;
    }

    public function uiTheme(): string
    {
        return $this->theme;
    }

    public function editorCommand(): ?string
    {
        return $this->editor;
    }

    public function disabledTools(): array
    {
        return $this->disabledTools;
    }

    public function effortDefault(): string
    {
        return $this->effort;
    }

    public function contextMax(): ?int
    {
        return $this->contextMax;
    }

    public function contextBuffer(): ?int
    {
        return $this->contextBuffer;
    }

    public function autoCompact(): bool
    {
        return $this->autoCompact;
    }

    public function customTools(): array
    {
        return $this->customTools;
    }

    public function fallbackChain(string $profile): array
    {
        $queue = $this->profile($profile)->fallback();
        $seen = [$profile => true];
        $chain = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $chain[] = $name;
            array_push($queue, ...$this->profiles[$name]->fallback());
        }

        return $chain;
    }

    public function gentleAiBin(): ?string
    {
        $bin = $this->gentleAi['bin'] ?? null;
        if ($bin === null) {
            return null;
        }
        if (!is_string($bin) || !str_starts_with($bin, '/')) {
            throw new ConfigException('gentle_ai.bin must be an absolute path to an executable file');
        }
        if (!is_file($bin) || !is_executable($bin)) {
            throw new ConfigException("gentle_ai.bin is not an executable file: {$bin}");
        }

        return $bin;
    }

    public static function load(string $cwd, string $configDir, array $env): self
    {
        return self::fromArrays(
            self::readJson($configDir . '/config.json'),
            self::readJson($cwd . '/.phycode/config.json'),
            $env,
            $configDir,
        );
    }

    public static function fromArrays(?array $global, ?array $project, array $env, string $configDir): self
    {
        $global ??= [];
        $project ??= [];
        $warnings = [];
        foreach (array_keys($project) as $key) {
            if (!in_array($key, self::PROJECT_KEYS, true)) {
                $warnings[] = "project config key '{$key}' ignored: only the global config can set it";
                unset($project[$key]);
            }
        }

        $limits = Limits::fromArray(array_merge(self::section($global, 'limits'), self::section($project, 'limits')), $warnings);

        $vault = new Vault($configDir);
        $profiles = [];
        foreach (self::section($global, 'profiles') as $name => $raw) {
            $profiles[(string) $name] = self::buildProfile((string) $name, $raw, $vault);
        }

        $envBase = self::envValue($env, 'PHYCODE_BASE_URL');
        $envModel = self::envValue($env, 'PHYCODE_MODEL');
        $envOnly = $profiles === [];
        if ($envOnly) {
            if ($envBase === null || $envModel === null) {
                throw new ConfigException("no profiles configured. Set PHYCODE_BASE_URL and PHYCODE_MODEL, or create {$configDir}/config.json");
            }
            if (self::isPlainHttp($envBase) && self::envValue($env, 'PHYCODE_API_KEY') !== null) {
                throw new ConfigException('PHYCODE_BASE_URL must be an https:// URL when PHYCODE_API_KEY is set, because the key is sent to it (http is allowed for localhost, 127.0.0.1 and ::1 only)');
            }
            $profiles['env'] = new Profile('env', $envBase, $envModel, 'auto', 16384, 2048, self::defaultSampling($envBase), [], null, 'PHYCODE_API_KEY');
        }
        foreach ($profiles as $name => $profile) {
            foreach ($profile->fallback() as $target) {
                if (!isset($profiles[$target])) {
                    throw new ConfigException("profile '{$name}': fallback '{$target}' is not a known profile");
                }
            }
        }

        $configured = $global['default_profile'] ?? null;
        $defaultName = self::envValue($env, 'PHYCODE_PROFILE') ?? (is_string($configured) ? $configured : (string) array_key_first($profiles));
        if (!isset($profiles[$defaultName])) {
            throw new ConfigException("default profile '{$defaultName}' does not exist");
        }
        if ($envBase !== null && !$envOnly && self::isPlainHttp($envBase) && $profiles[$defaultName]->hasKeySource()) {
            throw new ConfigException("PHYCODE_BASE_URL must be an https:// URL, because profile '{$defaultName}' sends its key to it (http is allowed for localhost, 127.0.0.1 and ::1 only)");
        }
        if ($envBase !== null || $envModel !== null) {
            $profiles[$defaultName] = $profiles[$defaultName]->override($envBase, $envModel);
        }

        $skills = self::skillsSection($global['skills'] ?? null);
        $appearance = self::appearanceSection($global['ui'] ?? null);
        $context = self::contextSection($global['context'] ?? null);
        $mode = self::permissions($global['permissions'] ?? null);
        $defaultAgent = $project['default_agent'] ?? $global['default_agent'] ?? null;
        if ($defaultAgent !== null && !is_string($defaultAgent)) {
            throw new ConfigException('default_agent must be a string');
        }
        $globalSubagents = $global['subagents'] ?? true;
        $projectSubagents = $project['subagents'] ?? true;
        if (!is_bool($globalSubagents) || !is_bool($projectSubagents)) {
            throw new ConfigException('subagents must be true or false');
        }

        $web = self::webSection($global['web'] ?? null, $warnings);
        $sshHosts = self::sshSection($global['ssh'] ?? null);
        $deployTargets = self::deploySection($global['deploy'] ?? null, $sshHosts);

        return new self(
            $profiles,
            $defaultName,
            $limits,
            $mode,
            $defaultAgent,
            $globalSubagents && $projectSubagents,
            $warnings,
            $configDir,
            self::sandbox(self::section($global, 'sandbox')),
            self::section($global, 'gentle_ai'),
            $web,
            self::rulesSection($global['rules'] ?? null),
            self::hooksSection($global['hooks'] ?? null),
            self::mcpSection($global['mcp_servers'] ?? null),
            self::uiSection($global['ui'] ?? null),
            self::serviceSection($global['service'] ?? null),
            $skills['enabled'],
            theme: $appearance['theme'],
            editor: $appearance['editor'],
            disabledTools: self::toolsSection($global['tools'] ?? null),
            effort: self::effortSection($global['effort'] ?? null),
            contextMax: $context['max'],
            contextBuffer: $context['buffer'],
            autoCompact: $context['auto_compact'],
            customTools: self::customToolsSection($global['custom_tools'] ?? null),
            skillsIndex: $skills['index'],
            skillsAutonomy: $skills['autonomy'],
            sshHosts: $sshHosts,
            deployTargets: $deployTargets,
            checks: self::checksSection($global['checks'] ?? null),
            mcpToolSearch: self::mcpOptions($global['mcp'] ?? null),
            pricing: self::pricingSection($global['pricing'] ?? null),
            oracle: self::oracleSection($global['oracle'] ?? null, $profiles),
        );
    }

    private static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ConfigException("{$path} is not valid JSON: " . $error->getMessage());
        }
        if (!is_array($data)) {
            throw new ConfigException("{$path} must contain a JSON object");
        }

        return $data;
    }

    private static function section(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new ConfigException("'{$key}' must be an object");
        }

        return $value;
    }

    private static function sandbox(array $raw): Sandbox
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['default', 'image', 'network', 'allow_domains'], true)) {
                throw new ConfigException("sandbox.{$key} is not a known setting");
            }
        }
        $default = $raw['default'] ?? 'host';
        if (!is_string($default) || !in_array($default, self::SANDBOXES, true)) {
            throw new ConfigException('sandbox.default must be host, podman, docker or bwrap');
        }
        $image = $raw['image'] ?? null;
        if ($image !== null && (!is_string($image) || trim($image) === '' || preg_match('/\s/', trim($image)) === 1)) {
            throw new ConfigException('sandbox.image must be an image name without spaces');
        }
        $network = $raw['network'] ?? 'none';
        if (!is_string($network) || !in_array($network, self::NETWORKS, true)) {
            throw new ConfigException('sandbox.network must be none or bridge');
        }
        if (in_array($default, ['podman', 'docker'], true) && $image === null) {
            throw new ConfigException("sandbox.image is required when sandbox.default is {$default}");
        }
        $allow = self::stringList($raw['allow_domains'] ?? null, 'sandbox.allow_domains', '/\S/', 'host names such as github.com or *.npmjs.org');
        try {
            $list = new DomainAllowlist($allow);
        } catch (PhycodeException $error) {
            throw new ConfigException($error->getMessage());
        }
        if (!$list->isEmpty() && $network === 'bridge') {
            throw new ConfigException('sandbox.allow_domains narrows the network, so sandbox.network must be none; bridge is the whole network');
        }
        if (!$list->isEmpty() && $default !== 'bwrap') {
            throw new ConfigException('sandbox.allow_domains works with sandbox.default bwrap only; the others have no filter');
        }

        return new Sandbox($default, $image === null ? null : trim((string) $image), $network, array_map('trim', $allow));
    }

    private static function webSection(mixed $raw, array &$warnings): ?array
    {
        if ($raw === null) {
            return null;
        }
        $web = self::block($raw, 'web');
        self::only($web, 'web', ['search', 'fetch', 'allow_hosts', 'cache_minutes']);

        $search = self::searchSection(self::block($web['search'] ?? null, 'web.search'), $warnings);
        $fetch = self::block($web['fetch'] ?? null, 'web.fetch');
        self::only($fetch, 'web.fetch', ['max_bytes', 'timeout_seconds']);

        return [
            'search' => $search['search'],
            'providers' => $search['providers'],
            'legacy' => $search['legacy'],
            'fetch' => [
                'max_bytes' => self::integer($fetch['max_bytes'] ?? 1048576, 'web.fetch.max_bytes', 1, 16777216),
                'timeout_seconds' => self::integer($fetch['timeout_seconds'] ?? 20, 'web.fetch.timeout_seconds', 1, 300),
            ],
            'allow_hosts' => self::stringList($web['allow_hosts'] ?? null, 'web.allow_hosts', '/^(\*\.)?[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*$/D', 'host names such as example.com or *.example.com'),
            'cache_minutes' => self::integer($web['cache_minutes'] ?? 15, 'web.cache_minutes', 0, 1440),
        ];
    }

    private static function searchSection(array $search, array &$warnings): array
    {
        self::only($search, 'web.search', ['backend', 'url', 'api_key_file', 'providers', 'active']);
        $providers = self::providers(self::block($search['providers'] ?? null, 'web.search.providers'));
        if (!array_key_exists('active', $search)) {
            return ['search' => self::olderSearch($search), 'providers' => $providers, 'legacy' => true];
        }
        if (array_intersect_key($search, array_flip(['backend', 'url', 'api_key_file'])) !== []) {
            $warnings[] = 'web.search.backend, url and api_key_file are ignored because web.search.active is set: take them out of the config';
        }
        $active = $search['active'];
        if ($active === null) {
            return ['search' => ['name' => null, 'backend' => 'none', 'url' => null, 'api_key_store' => false, 'api_key_file' => null], 'providers' => $providers, 'legacy' => false];
        }
        if (!is_string($active) || !isset($providers[$active])) {
            throw new ConfigException('web.search.active must be null or the name of a provider in web.search.providers');
        }

        return ['search' => ['name' => $active] + $providers[$active], 'providers' => $providers, 'legacy' => false];
    }

    private static function olderSearch(array $search): array
    {
        $backend = self::choice($search['backend'] ?? 'none', 'web.search.backend', self::SEARCH_BACKENDS);
        $url = $search['url'] ?? null;
        if ($url !== null && (!is_string($url) || preg_match('#^https?://[^\s/]+(/\S*)?$#D', $url) !== 1)) {
            throw new ConfigException('web.search.url must be an http:// or https:// URL');
        }
        if ($backend === 'searxng' && $url === null) {
            throw new ConfigException('web.search.url is required when web.search.backend is searxng');
        }
        $keyFile = $search['api_key_file'] ?? null;
        if ($keyFile === null && in_array($backend, self::KEYED_SEARCH_BACKENDS, true)) {
            throw new ConfigException("web.search.api_key_file is required when web.search.backend is {$backend}");
        }

        return [
            'name' => null,
            'backend' => $backend,
            'url' => $backend === 'searxng' ? rtrim((string) $url, '/') : null,
            'api_key_store' => false,
            'api_key_file' => $keyFile === null ? null : self::expandHome(self::text($keyFile, 'web.search.api_key_file')),
        ];
    }

    private static function providers(array $raw): array
    {
        $providers = [];
        foreach ($raw as $name => $entry) {
            $name = (string) $name;
            if (preg_match(self::SEARCH_NAME, $name) !== 1) {
                throw new ConfigException("web.search.providers name '{$name}' is 1 to 32 characters: lowercase letters, digits, - and _, starting with a letter or a digit");
            }
            $label = "web.search.providers.{$name}";
            $provider = self::block($entry, $label);
            self::only($provider, $label, ['backend', 'url', 'api_key_store', 'api_key_file']);
            $backend = self::choice($provider['backend'] ?? null, "{$label}.backend", array_values(array_diff(self::SEARCH_BACKENDS, ['none'])));
            $preset = SearchPresets::of($backend) ?? throw new ConfigException("{$label}.backend is not a search backend");

            $url = $provider['url'] ?? null;
            if ($url !== null && (!is_string($url) || preg_match('~^https?://[^\s/@?#]+(/[^\s?#]*)?$~D', $url) !== 1)) {
                throw new ConfigException("{$label}.url must be an http:// or https:// URL, without a user name or a query");
            }
            if ($url === null && $preset['urlRequired']) {
                throw new ConfigException("{$label}.url is required for {$backend}");
            }
            if ($url !== null && $preset['keyRequired'] && !str_starts_with($url, 'https://')) {
                throw new ConfigException("{$label}.url must be an https:// URL, because the key is sent to it");
            }

            $storeKey = $provider['api_key_store'] ?? false;
            if (!is_bool($storeKey)) {
                throw new ConfigException("{$label}.api_key_store must be true or false");
            }
            $keyFile = $provider['api_key_file'] ?? null;
            $sources = ($storeKey ? 1 : 0) + ($keyFile === null ? 0 : 1);
            if ($preset['keyRequired'] && $sources !== 1) {
                throw new ConfigException("{$label} needs one key source: api_key_store true, or api_key_file");
            }
            if (!$preset['keyRequired'] && $sources !== 0) {
                throw new ConfigException("{$label}: {$backend} takes no key");
            }

            $providers[$name] = [
                'backend' => $backend,
                'url' => $url === null ? null : rtrim($url, '/'),
                'api_key_store' => $storeKey,
                'api_key_file' => $keyFile === null ? null : self::expandHome(self::text($keyFile, "{$label}.api_key_file")),
            ];
        }

        return $providers;
    }

    private static function rulesSection(mixed $raw): array
    {
        $rules = self::block($raw, 'rules');
        self::only($rules, 'rules', ['allow', 'ask', 'deny']);
        $lists = [];
        foreach (['allow', 'ask', 'deny'] as $list) {
            $lists[$list] = self::stringList($rules[$list] ?? null, "rules.{$list}", '/^(\*|[A-Za-z][A-Za-z0-9_-]*:\S(?:.*\S)?)$/D', 'tool:glob entries or "*"');
        }

        return $lists;
    }

    private static function hooksSection(mixed $raw): array
    {
        $hooks = self::block($raw, 'hooks');
        self::only($hooks, 'hooks', self::HOOK_EVENTS);
        $events = array_fill_keys(self::HOOK_EVENTS, []);
        foreach (self::HOOK_EVENTS as $event) {
            $entries = $hooks[$event] ?? [];
            if (!is_array($entries) || !array_is_list($entries)) {
                throw new ConfigException("hooks.{$event} must be a list");
            }
            foreach ($entries as $index => $entry) {
                $label = "hooks.{$event}[{$index}]";
                $hook = self::block($entry, $label);
                self::only($hook, $label, ['command', 'match', 'timeout']);
                $match = $hook['match'] ?? null;
                $timeout = $hook['timeout'] ?? null;
                $events[$event][] = [
                    'command' => self::text($hook['command'] ?? null, "{$label}.command"),
                    'match' => $match === null ? null : self::text($match, "{$label}.match"),
                    'timeout' => $timeout === null ? null : self::integer($timeout, "{$label}.timeout", 1, Hooks::MAX_TIMEOUT_SECONDS),
                ];
            }
        }

        return $events;
    }

    private static function checksSection(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ConfigException('checks must be a list');
        }
        $checks = [];
        foreach ($raw as $index => $entry) {
            $label = "checks[{$index}]";
            $check = self::block($entry, $label);
            self::only($check, $label, self::CHECK_KEYS);
            $command = self::text($check['command'] ?? null, "{$label}.command");
            if (!str_contains($command, '{file}')) {
                throw new ConfigException("{$label}.command must have {file} in it, where the path of the edited file goes");
            }
            try {
                Hooks::split($command);
            } catch (PhycodeException $error) {
                throw new ConfigException("{$label}.command: " . $error->getMessage());
            }
            $match = self::text($check['match'] ?? null, "{$label}.match");
            $checks[] = [
                'name' => isset($check['name']) ? self::text($check['name'], "{$label}.name") : basename(explode(' ', $command)[0]),
                'match' => $match,
                'command' => $command,
                'kind' => isset($check['kind']) ? self::choice($check['kind'], "{$label}.kind", Checks::KINDS) : 'check',
                'timeout' => isset($check['timeout']) ? self::integer($check['timeout'], "{$label}.timeout", 1, Checks::MAX_TIMEOUT_SECONDS) : Checks::TIMEOUT_SECONDS,
            ];
        }

        return $checks;
    }

    private static function deploySection(mixed $raw, array $sshHosts): array
    {
        $deploy = self::block($raw, 'deploy');
        self::only($deploy, 'deploy', ['targets']);
        $targets = [];
        foreach (self::block($deploy['targets'] ?? null, 'deploy.targets') as $name => $entry) {
            $target = DeployTarget::fromArray((string) $name, self::block($entry, "deploy.targets.{$name}"));
            $host = $target->options['host'] ?? null;
            if ($host !== null && !isset($sshHosts[$host])) {
                throw new ConfigException("deploy.targets.{$name}.host '{$host}' is not a host of the ssh section");
            }
            $targets[(string) $name] = $target;
        }

        return $targets;
    }

    private static function sshSection(mixed $raw): array
    {
        $ssh = self::block($raw, 'ssh');
        self::only($ssh, 'ssh', ['hosts']);
        $hosts = [];
        foreach (self::block($ssh['hosts'] ?? null, 'ssh.hosts') as $name => $entry) {
            $hosts[(string) $name] = SshHost::fromArray((string) $name, self::block($entry, "ssh.hosts.{$name}"));
        }

        return $hosts;
    }

    private static function mcpSection(mixed $raw): array
    {
        $servers = [];
        foreach (self::block($raw, 'mcp_servers') as $name => $entry) {
            $name = (string) $name;
            if (preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
                throw new ConfigException("mcp_servers name '{$name}' may use only letters, digits, '-' and '_'");
            }
            $label = "mcp_servers.{$name}";
            $server = self::block($entry, $label);
            self::only($server, $label, ['command', 'url', 'headers', 'token_env', 'oauth', 'enabled', 'approval']);
            $common = [
                'enabled' => self::flag($server['enabled'] ?? true, "{$label}.enabled"),
                'approval' => self::choice($server['approval'] ?? 'ask', "{$label}.approval", ['ask', 'allow']),
            ];
            if (isset($server['url'])) {
                if (isset($server['command'])) {
                    throw new ConfigException("{$label} has a command and a url; a server is one or the other");
                }
                $servers[$name] = ['command' => [], ...$common, ...self::mcpRemote($server, $label)];
                continue;
            }
            foreach (['headers', 'token_env', 'oauth'] as $remoteOnly) {
                if (isset($server[$remoteOnly])) {
                    throw new ConfigException("{$label}.{$remoteOnly} is for a server with a url");
                }
            }
            $command = self::stringList($server['command'] ?? null, "{$label}.command", null, 'strings, the first being the program');
            if ($command === [] || trim($command[0]) === '') {
                throw new ConfigException("{$label}.command must be a list of strings, the first being the program");
            }
            $servers[$name] = ['command' => $command, ...$common];
        }

        return $servers;
    }

    private static function mcpRemote(array $server, string $label): array
    {
        $url = self::text($server['url'], "{$label}.url");
        try {
            McpHttpClient::checkUrl($url, $label);
            $headers = self::block($server['headers'] ?? null, "{$label}.headers");
            foreach ($headers as $header => $value) {
                McpHttpClient::checkHeader((string) $header, $value, $label);
            }
        } catch (PhycodeException $error) {
            throw new ConfigException($error->getMessage());
        }
        $tokenEnv = $server['token_env'] ?? null;
        if ($tokenEnv !== null && (!is_string($tokenEnv) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $tokenEnv) !== 1)) {
            throw new ConfigException("{$label}.token_env must be the name of an environment variable");
        }
        $oauth = null;
        if (isset($server['oauth']) && $server['oauth'] !== false) {
            if ($tokenEnv !== null) {
                throw new ConfigException("{$label} has token_env and oauth; a server gets its credentials one way");
            }
            $raw = $server['oauth'] === true ? [] : self::block($server['oauth'], "{$label}.oauth");
            self::only($raw, "{$label}.oauth", ['client_id', 'client_secret_env', 'scopes', 'callback_port']);
            $secretEnv = $raw['client_secret_env'] ?? null;
            if ($secretEnv !== null && (!is_string($secretEnv) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $secretEnv) !== 1)) {
                throw new ConfigException("{$label}.oauth.client_secret_env must be the name of an environment variable");
            }
            $oauth = [
                'client_id' => isset($raw['client_id']) ? self::text($raw['client_id'], "{$label}.oauth.client_id") : null,
                'client_secret_env' => $secretEnv,
                'scopes' => self::stringList($raw['scopes'] ?? [], "{$label}.oauth.scopes", '/^[\x21\x23-\x5B\x5D-\x7E]+$/D', 'scope names'),
                'callback_port' => self::integer($raw['callback_port'] ?? 0, "{$label}.oauth.callback_port", 0, 65535),
            ];
        }

        return ['url' => $url, 'headers' => array_map('strval', $headers), 'token_env' => $tokenEnv, 'oauth' => $oauth];
    }

    private static function pricingSection(mixed $raw): array
    {
        $prices = [];
        foreach (self::block($raw, 'pricing') as $model => $entry) {
            $label = "pricing.{$model}";
            if (trim((string) $model) === '' || preg_match('/[\x00-\x1F\x7F]/', (string) $model) === 1) {
                throw new ConfigException('pricing names a model by its id, one line of text');
            }
            $price = self::block($entry, $label);
            self::only($price, $label, ['input', 'output', 'cached_input', 'cache_write']);
            $number = static function (string $key, bool $required) use ($price, $label): ?float {
                if (!array_key_exists($key, $price)) {
                    if ($required) {
                        throw new ConfigException("{$label}.{$key} is needed: the dollars for a million tokens");
                    }

                    return null;
                }
                $value = $price[$key];
                if (!(is_int($value) || is_float($value)) || $value < 0 || $value > 100000) {
                    throw new ConfigException("{$label}.{$key} must be a number of dollars for a million tokens, from 0 to 100000");
                }

                return (float) $value;
            };
            $prices[(string) $model] = array_filter([
                'input' => $number('input', true),
                'output' => $number('output', true),
                'cached_input' => $number('cached_input', false),
                'cache_write' => $number('cache_write', false),
            ], static fn (?float $value): bool => $value !== null);
        }

        return $prices;
    }

    private static function oracleSection(mixed $raw, array $profiles): ?array
    {
        if ($raw === null) {
            return null;
        }
        $oracle = self::block($raw, 'oracle');
        self::only($oracle, 'oracle', ['profile', 'max_calls', 'approval']);
        $profile = self::text($oracle['profile'] ?? null, 'oracle.profile');
        if (!isset($profiles[$profile])) {
            throw new ConfigException("oracle.profile '{$profile}' is not a profile of the config");
        }

        return [
            'profile' => $profile,
            'max_calls' => self::integer($oracle['max_calls'] ?? 10, 'oracle.max_calls', 1, 100),
            'approval' => self::choice($oracle['approval'] ?? 'ask', 'oracle.approval', ['ask', 'allow']),
        ];
    }

    private static function mcpOptions(mixed $raw): string
    {
        $mcp = self::block($raw, 'mcp');
        self::only($mcp, 'mcp', ['tool_search']);

        return self::choice($mcp['tool_search'] ?? 'auto', 'mcp.tool_search', ['auto', 'on', 'off']);
    }

    private static function uiSection(mixed $raw): array
    {
        $ui = self::block($raw, 'ui');
        self::only($ui, 'ui', ['subagents', 'diff_view', 'theme', 'editor']);
        $subagents = self::block($ui['subagents'] ?? null, 'ui.subagents');
        self::only($subagents, 'ui.subagents', ['auto_expand']);

        return [
            'subagents' => [
                'auto_expand' => self::stringList($subagents['auto_expand'] ?? ['write', 'web'], 'ui.subagents.auto_expand', '/^[A-Za-z0-9_-]+$/D', 'category names such as write or web'),
            ],
            'diff_view' => self::choice($ui['diff_view'] ?? 'auto', 'ui.diff_view', ['auto', 'split', 'unified']),
        ];
    }

    private static function appearanceSection(mixed $raw): array
    {
        $ui = self::block($raw, 'ui');
        $editor = $ui['editor'] ?? null;
        if ($editor !== null && (!is_string($editor) || trim($editor) === '' || preg_match('/[\x00-\x1F\x7F]/', $editor) === 1)) {
            throw new ConfigException('ui.editor must be one line of text, such as nvim or code --wait');
        }

        return [
            'theme' => self::choice($ui['theme'] ?? 'phycode', 'ui.theme', self::THEMES),
            'editor' => $editor === null ? null : trim($editor),
        ];
    }

    private static function toolsSection(mixed $raw): array
    {
        $tools = self::block($raw, 'tools');
        self::only($tools, 'tools', ['disabled']);
        $names = self::stringList($tools['disabled'] ?? null, 'tools.disabled', '/^(' . implode('|', self::BUILTIN_TOOLS) . ')$/D', 'built-in tool names: ' . implode(', ', self::BUILTIN_TOOLS));

        return array_values(array_unique($names));
    }

    private static function customToolsSection(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ConfigException('custom_tools must be a list of tools');
        }
        $tools = [];
        $names = [];
        foreach ($raw as $index => $entry) {
            $label = "custom_tools[{$index}]";
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw new ConfigException("{$label} must be an object");
            }
            foreach (array_keys($entry) as $key) {
                if (!in_array($key, self::CUSTOM_KEYS, true)) {
                    throw new ConfigException("{$label}.{$key} is not a known setting");
                }
            }
            $name = $entry['name'] ?? null;
            if (!is_string($name) || preg_match(self::CUSTOM_NAME, $name) !== 1) {
                throw new ConfigException("{$label}.name must start with a letter, then letters, digits, '-' or '_' (48 characters at most)");
            }
            if (isset($names[$name])) {
                throw new ConfigException("custom tool {$name} is listed twice");
            }
            $names[$name] = true;
            $description = $entry['description'] ?? null;
            if (!is_string($description) || trim($description) === '' || strlen($description) > 500) {
                throw new ConfigException("{$label}.description must be text of 1 to 500 bytes");
            }
            $command = $entry['command'] ?? null;
            if (!is_array($command) || !array_is_list($command) || $command === [] || count($command) > 32) {
                throw new ConfigException("{$label}.command must be a list of 1 to 32 strings, the program first");
            }
            foreach ($command as $part) {
                if (!is_string($part) || trim($part) === '' || str_contains($part, "\0") || strlen($part) > 4096) {
                    throw new ConfigException("{$label}.command must be a list of non-empty strings without NUL bytes");
                }
            }
            $stdin = $entry['stdin'] ?? false;
            if (!is_bool($stdin)) {
                throw new ConfigException("{$label}.stdin must be true or false");
            }
            $tools[] = ['name' => $name, 'description' => trim($description), 'command' => $command, 'stdin' => $stdin];
        }

        return $tools;
    }

    private static function effortSection(mixed $raw): string
    {
        $effort = self::block($raw, 'effort');
        self::only($effort, 'effort', ['default']);
        $default = $effort['default'] ?? 'none';
        $level = is_string($default) ? Profile::canonical($default) : null;
        if ($level === null) {
            throw new ConfigException('effort.default must be ' . Profile::levelsText() . ' (off and on are also accepted)');
        }

        return $level;
    }

    private static function contextSection(mixed $raw): array
    {
        $context = self::block($raw, 'context');
        self::only($context, 'context', ['max', 'buffer', 'auto_compact']);
        $max = $context['max'] ?? null;
        $buffer = $context['buffer'] ?? null;

        return [
            'max' => $max === null ? null : self::integer($max, 'context.max', self::CONTEXT_MIN_TOKENS, self::CONTEXT_MAX_TOKENS),
            'buffer' => $buffer === null ? null : self::integer($buffer, 'context.buffer', 0, self::CONTEXT_MAX_TOKENS),
            'auto_compact' => self::flag($context['auto_compact'] ?? true, 'context.auto_compact'),
        ];
    }

    private static function serviceSection(mixed $raw): ?string
    {
        $service = self::block($raw, 'service');
        self::only($service, 'service', ['socket']);
        if (($service['socket'] ?? null) === null) {
            return null;
        }
        $socket = self::expandHome(self::text($service['socket'], 'service.socket'));
        if (!str_starts_with($socket, '/')) {
            throw new ConfigException('service.socket must be an absolute path');
        }

        return $socket;
    }

    private static function skillsSection(mixed $raw): array
    {
        $skills = self::block($raw, 'skills');
        self::only($skills, 'skills', ['enabled', 'index', 'autonomy']);

        return [
            'enabled' => self::flag($skills['enabled'] ?? true, 'skills.enabled'),
            'index' => self::choice($skills['index'] ?? 'on', 'skills.index', ['on', 'off']),
            'autonomy' => self::choice($skills['autonomy'] ?? 'ask', 'skills.autonomy', ['auto', 'ask']),
        ];
    }

    private static function permissions(mixed $raw): string
    {
        if (is_array($raw)) {
            return self::choice($raw['mode'] ?? 'ask', 'permissions.mode', self::PERMISSION_MODES);
        }

        return self::choice($raw ?? 'ask', 'permissions', self::PERMISSION_MODES);
    }

    private static function llamaSection(mixed $raw, string $profile): ?array
    {
        if ($raw === null) {
            return null;
        }
        $label = "profile '{$profile}': llama";
        $llama = self::block($raw, $label);
        self::only($llama, $label, ['bin', 'model', 'args', 'env']);
        $env = self::block($llama['env'] ?? null, "{$label}.env");
        foreach ($env as $variable => $value) {
            if (!is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $variable) !== 1) {
                throw new ConfigException("{$label}.env must map variable names to strings");
            }
        }

        return [
            'bin' => self::expandHome(self::text($llama['bin'] ?? null, "{$label}.bin")),
            'model' => self::expandHome(self::text($llama['model'] ?? null, "{$label}.model")),
            'args' => self::stringList($llama['args'] ?? null, "{$label}.args", null, 'strings'),
            'env' => $env,
        ];
    }

    private static function buildProfile(string $name, mixed $raw, Vault $vault): Profile
    {
        if (!is_array($raw)) {
            throw new ConfigException("profile '{$name}' must be an object");
        }
        if (array_key_exists('api_key', $raw)) {
            throw new ConfigException("profile '{$name}': api_key cannot be written in the config; run phycode keys set {$name}, or name a variable in api_key_env or a file in api_key_file");
        }

        $baseUrl = self::string($raw, 'base_url', $name, true);
        if (!preg_match('#^(https?://[^\s/]+|unix:///[^\s]+\.sock/?$)#', (string) $baseUrl)) {
            throw new ConfigException("profile '{$name}': base_url must be an http:// or https:// URL, or unix:///absolute/path.sock");
        }
        $toolMode = self::string($raw, 'tool_mode', $name, false) ?? 'auto';
        if (!in_array($toolMode, ['auto', 'native', 'text', 'none'], true)) {
            throw new ConfigException("profile '{$name}': tool_mode must be auto, native, text or none");
        }
        $protocol = self::string($raw, 'protocol', $name, false) ?? 'openai';
        if (!in_array($protocol, Profile::PROTOCOLS, true)) {
            throw new ConfigException("profile '{$name}': protocol must be " . self::choices(Profile::PROTOCOLS));
        }
        foreach (['responses_store' => 'openai-responses', 'anthropic_version' => 'anthropic', 'anthropic_beta' => 'anthropic', 'effort_format' => 'openai', 'effort_levels' => 'openai'] as $key => $owner) {
            if (($raw[$key] ?? null) !== null && $protocol !== $owner) {
                throw new ConfigException("profile '{$name}': {$key} is only used with protocol {$owner}");
            }
        }
        $responsesStore = $raw['responses_store'] ?? false;
        if (!is_bool($responsesStore)) {
            throw new ConfigException("profile '{$name}': responses_store must be true or false");
        }
        $anthropicVersion = self::string($raw, 'anthropic_version', $name, false) ?? '2023-06-01';
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $anthropicVersion) !== 1) {
            throw new ConfigException("profile '{$name}': anthropic_version must look like 2023-06-01");
        }
        $anthropicBeta = self::stringList($raw['anthropic_beta'] ?? null, "profile '{$name}': anthropic_beta", '/^[A-Za-z0-9._-]+$/D', 'beta feature names');
        $keyFiles = self::stringList($raw['api_key_files'] ?? null, "profile '{$name}': api_key_files", '/\S/', 'file paths');
        if ($keyFiles !== [] && isset($raw['api_key_file'])) {
            throw new ConfigException("profile '{$name}': set api_key_file or api_key_files, not both");
        }
        $keyInVault = $raw['api_key_store'] ?? false;
        if (!is_bool($keyInVault)) {
            throw new ConfigException("profile '{$name}': api_key_store must be true or false");
        }
        if ($keyInVault && (isset($raw['api_key_env']) || isset($raw['api_key_file']) || $keyFiles !== [])) {
            throw new ConfigException("profile '{$name}': api_key_store cannot be combined with api_key_env, api_key_file or api_key_files: a profile has one key source");
        }
        $keyed = $keyInVault || isset($raw['api_key_env']) || isset($raw['api_key_file']) || $keyFiles !== [];
        if ($keyed && self::isPlainHttp((string) $baseUrl)) {
            throw new ConfigException("profile '{$name}': base_url must be an https:// URL, because the key is sent to it (http is allowed for localhost, 127.0.0.1 and ::1 only)");
        }
        $fallback = self::stringList($raw['fallback'] ?? null, "profile '{$name}': fallback", '/\S/', 'profile names');
        if (in_array($name, $fallback, true)) {
            throw new ConfigException("profile '{$name}': fallback may not name the profile itself");
        }
        if (count(array_unique($fallback)) !== count($fallback)) {
            throw new ConfigException("profile '{$name}': fallback lists a profile more than once");
        }
        $llama = self::llamaSection($raw['llama'] ?? null, $name);
        $thinking = Profile::canonical(self::string($raw, 'thinking', $name, false) ?? 'none');
        if ($thinking === null) {
            throw new ConfigException("profile '{$name}': thinking must be " . Profile::levelsText() . ' (off and on are also accepted)');
        }
        $tokenParam = self::string($raw, 'token_param', $name, false) ?? (parse_url((string) $baseUrl, PHP_URL_HOST) === 'api.openai.com' ? 'max_completion_tokens' : 'max_tokens');
        if (!in_array($tokenParam, Profile::TOKEN_PARAMS, true)) {
            throw new ConfigException("profile '{$name}': token_param must be max_tokens or max_completion_tokens");
        }
        $sampling = self::section($raw, 'sampling');
        $effortFormat = self::choice($raw['effort_format'] ?? 'auto', "profile '{$name}': effort_format", Profile::EFFORT_FORMATS);
        $effortLevels = self::effortLevelsOf($raw['effort_levels'] ?? null, $name);
        $promptCache = self::flag($raw['prompt_cache'] ?? true, "profile '{$name}': prompt_cache");
        $promptCacheTtl = self::choice($raw['prompt_cache_ttl'] ?? '5m', "profile '{$name}': prompt_cache_ttl", Profile::CACHE_TTLS);
        $retention = $raw['prompt_cache_retention'] ?? null;
        $promptCacheRetention = $retention === null ? null : self::choice($retention, "profile '{$name}': prompt_cache_retention", Profile::CACHE_RETENTIONS);
        $compactMode = self::choice($raw['compact_mode'] ?? 'auto', "profile '{$name}': compact_mode", Profile::COMPACT_MODES);
        $vision = isset($raw['vision']) ? self::flag($raw['vision'], "profile '{$name}': vision") : null;

        return new Profile(
            name: $name,
            baseUrl: rtrim((string) $baseUrl, '/'),
            model: self::string($raw, 'model', $name, false) ?? '',
            toolMode: $toolMode,
            contextLimit: self::int($raw, 'context_limit', $name, 512, 1000000, 16384),
            contextLimitSet: isset($raw['context_limit']),
            maxOutputTokens: self::int($raw, 'max_output_tokens', $name, 16, 100000, 2048),
            sampling: $sampling === [] ? self::defaultSampling((string) $baseUrl) : $sampling,
            extraBody: self::section($raw, 'extra_body'),
            apiKeyFile: isset($raw['api_key_file']) ? self::expandHome((string) $raw['api_key_file']) : null,
            apiKeyEnv: isset($raw['api_key_env']) ? (string) $raw['api_key_env'] : null,
            protocol: $protocol,
            thinking: $thinking,
            tokenParam: $tokenParam,
            keepAlive: self::keepAlive($raw, $name),
            responsesStore: $responsesStore,
            anthropicVersion: $anthropicVersion,
            anthropicBeta: $anthropicBeta,
            apiKeyFiles: array_map(static fn (string $path): string => self::expandHome($path), $keyFiles),
            fallback: $fallback,
            llama: $llama,
            vault: $keyInVault ? $vault : null,
            effortFormat: $effortFormat,
            effortLevels: $effortLevels,
            promptCache: $promptCache,
            promptCacheTtl: $promptCacheTtl,
            promptCacheRetention: $promptCacheRetention,
            compactMode: $compactMode,
            vision: $vision,
        );
    }

    public static function isPlainHttp(string $url): bool
    {
        if (!str_starts_with($url, 'http://')) {
            return false;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        return !in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function effortLevelsOf(mixed $raw, string $profile): ?array
    {
        if ($raw === null) {
            return null;
        }
        $message = "profile '{$profile}': effort_levels must be a non-empty list of levels: " . Profile::levelsText();
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new ConfigException($message);
        }
        foreach ($raw as $level) {
            if (!is_string($level) || !in_array($level, Profile::EFFORTS, true)) {
                throw new ConfigException($message);
            }
        }
        if (count(array_unique($raw)) !== count($raw)) {
            throw new ConfigException("profile '{$profile}': effort_levels lists a level more than once");
        }

        return array_values(array_filter(Profile::EFFORTS, static fn (string $level): bool => in_array($level, $raw, true)));
    }

    private static function defaultSampling(string $baseUrl): array
    {
        return Profile::localUrl($baseUrl) ? ['temperature' => 0.6, 'top_p' => 0.95, 'top_k' => 20, 'min_p' => 0.0, 'presence_penalty' => 0.0] : [];
    }

    private static function keepAlive(array $raw, string $profile): string
    {
        $value = $raw['keep_alive'] ?? '5m';
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^-?\d+(\.\d+)?(ms|s|m|h)?$/', $value) !== 1) {
            throw new ConfigException("profile '{$profile}': keep_alive must look like 5m, 30s or 1h, or be a number of seconds");
        }

        return $value;
    }

    private static function string(array $raw, string $key, string $profile, bool $required): ?string
    {
        if (!isset($raw[$key])) {
            if ($required) {
                throw new ConfigException("profile '{$profile}': '{$key}' is required");
            }

            return null;
        }
        if (!is_string($raw[$key]) || trim($raw[$key]) === '') {
            throw new ConfigException("profile '{$profile}': '{$key}' must be a non-empty string");
        }

        return trim($raw[$key]);
    }

    private static function int(array $raw, string $key, string $profile, int $min, int $max, int $default): int
    {
        $value = $raw[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new ConfigException("profile '{$profile}': '{$key}' must be an integer from {$min} to {$max}");
        }

        return $value;
    }

    private static function block(mixed $value, string $label): array
    {
        $value ??= [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConfigException("{$label} must be an object");
        }

        return $value;
    }

    private static function only(array $raw, string $label, array $keys): void
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, $keys, true)) {
                throw new ConfigException("{$label}.{$key} is not a known setting");
            }
        }
    }

    private static function choice(mixed $value, string $label, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ConfigException("{$label} must be " . self::choices($allowed));
        }

        return $value;
    }

    private static function choices(array $allowed): string
    {
        $last = (string) array_pop($allowed);

        return $allowed === [] ? $last : implode(', ', $allowed) . " or {$last}";
    }

    private static function flag(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new ConfigException("{$label} must be true or false");
        }

        return $value;
    }

    private static function integer(mixed $value, string $label, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new ConfigException("{$label} must be an integer from {$min} to {$max}");
        }

        return $value;
    }

    private static function text(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigException("{$label} must be a non-empty string");
        }

        return trim($value);
    }

    private static function stringList(mixed $value, string $label, ?string $pattern, string $expected): array
    {
        $value ??= [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigException("{$label} must be a list of {$expected}");
        }
        foreach ($value as $item) {
            if (!is_string($item) || ($pattern !== null && preg_match($pattern, $item) !== 1)) {
                throw new ConfigException("{$label} must be a list of {$expected}");
            }
        }

        return $value;
    }

    private static function envValue(array $env, string $key): ?string
    {
        $value = $env[$key] ?? '';

        return $value === '' ? null : $value;
    }

    private static function expandHome(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return (getenv('HOME') ?: '') . substr($path, 1);
        }

        return $path;
    }
}
