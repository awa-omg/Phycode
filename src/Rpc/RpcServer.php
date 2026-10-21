<?php

declare(strict_types=1);

namespace Phycode\Rpc;

use Phycode\Agent\AgentDefinition;
use Phycode\Btw\BtwJobs;
use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;
use Phycode\Cli\PromptTemplates;
use Phycode\Config\ConfigEditor;
use Phycode\Config\ConfigWriter;
use Phycode\Config\ProviderPresets;
use Phycode\Config\SearchPresets;
use Phycode\Config\SearchProviderWriter;
use Phycode\Git\Branch;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\EndpointDetector;
use Phycode\Research\DeepResearch;
use Phycode\Support\Cancellation;
use Phycode\Support\Entry;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Web\SearchKeys;
use Phycode\Web\WebServices;

final class RpcServer
{
    private const PROTOCOL = 2;
    private const CAPABILITIES = [
        'panels', 'models.v2', 'diff', 'ask', 'btw', 'sessions', 'config', 'effort', 'context', 'deepresearch', 'editor', 'vault',
        'search.providers',
    ];
    private const ROUNDS = 2;
    private const MAX_ROUNDS = 5;
    private const GRACE_SECONDS = 10.0;
    private const SWEEP_SECONDS = 0.2;

    private readonly BtwJobs $btw;
    private readonly \Closure $research;
    private ?Cancellation $researching = null;
    private int $researchIds = 0;

    public function __construct(
        private readonly JsonLines $channel,
        private readonly RpcUi $ui,
        private readonly Kernel $kernel,
        private readonly ChatSession $session,
        private readonly string $version,
        ?BtwJobs $btw = null,
        ?\Closure $research = null,
    ) {
        $this->btw = $btw ?? new BtwJobs([...Entry::command(), 'btw-run']);
        $this->research = $research ?? fn (string $question, int $rounds, callable $progress, Cancellation $cancel): string => $this->runResearch($question, $rounds, $progress, $cancel);
        $this->ui->attachServer(
            fn () => $this->sweepSideQuestions(),
            fn (array $command): bool => $this->relay($command),
            fn () => $this->researching?->cancel('interrupted by the user'),
        );
        $this->session->onAutoCompact(fn () => $this->ui->event($this->contextEvent()));
    }

    public function run(): int
    {
        try {
            $this->ui->start($this->ready());
            while (($command = $this->next()) !== null) {
                $reason = $this->command($command);
                if ($reason !== null) {
                    return $this->bye($reason);
                }
            }
            $this->settle();

            return $this->bye('eof');
        } catch (\Throwable $error) {
            $this->channel->send(['type' => 'error', 'message' => $this->session->redact($error->getMessage())]);

            return 1;
        } finally {
            $this->btw->close();
        }
    }

    private function next(): ?array
    {
        while (true) {
            $command = $this->channel->next($this->btw->running() > 0 ? self::SWEEP_SECONDS : null);
            if ($command !== null || $this->channel->ended()) {
                return $command;
            }
            $this->ui->tick();
        }
    }

    private function settle(): void
    {
        $deadline = microtime(true) + self::GRACE_SECONDS;
        while ($this->btw->running() > 0 && microtime(true) < $deadline) {
            usleep(20000);
            $this->ui->tick();
        }
    }

    private function command(array $command): ?string
    {
        try {
            $type = $command['type'] ?? null;
            if ($type === 'quit') {
                return 'quit';
            }
            $reason = null;
            if ($type === 'input') {
                $reason = $this->input($command['text'] ?? null);
            } else {
                match ($type) {
                    'list_models' => $this->listModels($command),
                    'set_model' => $this->setModel($command),
                    'favorite' => $this->favorite($command),
                    'custom_model' => $this->customModel($command),
                    'add_provider' => $this->addProvider($command),
                    'set_provider_key' => $this->setProviderKey($command),
                    'remove_provider' => $this->removeProvider($command),
                    'list_search_providers' => $this->ui->event($this->searchProviders()),
                    'add_search_provider' => $this->addSearchProvider($command),
                    'update_search_provider' => $this->updateSearchProvider($command),
                    'remove_search_provider' => $this->removeSearchProvider($command),
                    'set_search_provider' => $this->setSearchProvider($command),
                    'detect_endpoints' => $this->detectEndpoints(),
                    'config' => $this->configEvent(),
                    'config_set' => $this->configSet($command),
                    'session_list' => $this->sessions(),
                    'session_switch' => $this->switchSession($command),
                    'session_new' => $this->newSession(),
                    'set_effort' => $this->setEffort($command),
                    'set_context' => $this->setContext($command),
                    'compact' => $this->compact($command),
                    'btw' => $this->sideQuestion($command),
                    'deepresearch' => $this->deepResearch($command),
                    default => null,
                };
            }

            return $reason ?? ($this->ui->quitRequested() ? 'quit' : null);
        } catch (\Throwable $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return null;
        }
    }

    private function input(mixed $text): ?string
    {
        if (!is_string($text) || trim($text) === '') {
            return null;
        }
        $line = trim($text);
        if (self::isWord($line, '/btw')) {
            $this->sideQuestion(['question' => self::rest($line, '/btw')]);

            return null;
        }
        if (self::isWord($line, '/deepresearch')) {
            $this->deepResearch(['question' => self::rest($line, '/deepresearch')]);

            return null;
        }
        if ($line === '/config') {
            $this->configEvent();

            return null;
        }
        if ($line === '/sessions') {
            $this->sessions();

            return null;
        }
        if ($this->session->isCommand($line)) {
            if ($line === '/models') {
                return null;
            }
            if ($this->stateLine($line)) {
                return null;
            }

            return $this->session->handle($line, $this->frame()) ? null : 'exit';
        }

        $this->session->handle($line, $this->frame());

        return $this->ui->quitRequested() ? 'quit' : null;
    }

    private function frame(): \Closure
    {
        return function (\Closure $run): void {
            $this->turn(function () use ($run): array {
                $run();

                return [$this->session->lastStatus() ?? 'error', $this->session->lastReason(), $this->session->lastUsage()];
            });
        };
    }

    private function ready(): array
    {
        $primary = $this->session->primary();
        $agents = [];
        foreach ($this->kernel->agents->all() as $agent) {
            $agents[] = ['name' => $agent->name, 'mode' => self::modeName($agent), 'description' => $agent->description];
        }
        $offered = $this->kernel->tools->available($this->kernel->policyFor($primary));

        return [
            'type' => 'ready',
            'protocol' => self::PROTOCOL,
            'capabilities' => self::CAPABILITIES,
            'theme' => $this->kernel->config->uiTheme(),
            'editor' => $this->kernel->config->editorCommand(),
            'contextMax' => $this->kernel->config->contextMax(),
            'app' => [
                'version' => $this->version,
                'cwd' => $this->kernel->workspace->root,
                'agent' => $primary->name,
                'profile' => $this->session->profile(),
                'model' => $this->session->model(),
                'thinking' => $this->session->thinking(),
                'toolMode' => $this->session->toolMode(),
                'sandbox' => $this->kernel->sandboxes->describe(),
                'permissionMode' => $this->kernel->permissionMode,
                'transcript' => $this->session->transcriptPath(),
                'git' => array_intersect(['git_read', 'git_write', 'git_remote'], $offered) !== [],
                'branch' => Branch::current($this->kernel->workspace->root),
            ],
            'agents' => $agents,
            'commands' => $this->commands(),
        ];
    }

    private function commands(): array
    {
        $templates = array_column((new PromptTemplates($this->kernel->workspace->root, []))->commands(), 'name');
        $commands = [
            ...$this->session->commands(),
            ['name' => '/config', 'description' => 'show and change the settings'],
            ['name' => '/btw QUESTION', 'description' => 'ask a side question about the conversation; the answer opens in a panel'],
            ['name' => '/deepresearch QUESTION', 'description' => 'research a question on the web and cite the sources'],
        ];
        foreach ($commands as $index => $command) {
            if (in_array($command['name'], ['/init', '/handoff', ...$templates], true)) {
                $commands[$index]['turn'] = true;
            }
        }

        return $commands;
    }

    private function listModels(array $command): void
    {
        $this->channel->send($this->modelsEvent(($command['refresh'] ?? false) === true));
    }

    private function setModel(array $command): void
    {
        $profile = $command['profile'] ?? null;
        $model = $command['model'] ?? null;
        $thinking = $command['thinking'] ?? null;
        if (!is_string($profile) || (!is_string($model) && $model !== null) || (!is_string($thinking) && $thinking !== null)) {
            $this->ui->notice('error', 'set_model needs a profile, a model (or null) and a thinking level (or null)');

            return;
        }
        try {
            $thinking ??= $this->kernel->config->profile($profile)->thinking;
            $this->session->switchModel($profile, $model, $thinking);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }

        $saved = false;
        if (($command['save'] ?? false) === true) {
            try {
                ConfigWriter::setProfileModel(
                    $this->kernel->configFile,
                    $this->session->profile(),
                    $this->session->model(),
                    $this->session->thinking(),
                );
                $saved = true;
            } catch (PhycodeException $error) {
                $this->ui->notice('error', 'the model is set for this session only; saving it failed: ' . $this->session->redact($error->getMessage()));
            }
        }
        try {
            $this->kernel->state->addRecent($this->session->profile(), $this->session->model());
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));
        }

        $this->modelChangedEvent($saved);
    }

    private function modelChangedEvent(bool $saved): void
    {
        $this->ui->event([
            'type' => 'model_changed',
            'profile' => $this->session->profile(),
            'model' => $this->session->model(),
            'thinking' => $this->session->thinking(),
            'saved' => $saved,
        ]);
    }

    private function favorite(array $command): void
    {
        $profile = $command['profile'] ?? null;
        $model = $command['model'] ?? null;
        $on = $command['on'] ?? null;
        if (!is_string($profile) || !is_string($model) || $model === '' || !is_bool($on)) {
            $this->ui->notice('error', 'favorite needs a profile, a model and on (true or false)');

            return;
        }
        try {
            $this->kernel->config->profile($profile);
            $this->kernel->state->setFavorite($profile, $model, $on);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }

        $this->channel->send($this->modelsEvent(false));
    }

    private function customModel(array $command): void
    {
        $profile = $command['profile'] ?? null;
        $model = $command['model'] ?? null;
        $action = $command['action'] ?? null;
        if (!is_string($profile) || !is_string($model) || !in_array($action, ['add', 'remove'], true)) {
            $this->ui->notice('error', 'custom_model needs a profile, a model and an action (add or remove)');

            return;
        }
        try {
            $this->kernel->config->profile($profile);
            if ($action === 'add') {
                $this->kernel->state->addCustomModel($profile, trim($model));
            } else {
                $this->kernel->state->removeCustomModel($profile, trim($model));
            }
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }

        $this->channel->send($this->modelsEvent(false));
    }

    private function addProvider(array $command): void
    {
        $name = $command['name'] ?? null;
        $protocol = $command['protocol'] ?? null;
        $baseUrl = $command['base_url'] ?? null;
        $apiKeyEnv = $command['api_key_env'] ?? null;
        $model = $command['model'] ?? null;
        if (!is_string($name) || !is_string($protocol) || !is_string($baseUrl)
            || (!is_string($apiKeyEnv) && $apiKeyEnv !== null) || (!is_string($model) && $model !== null)) {
            $this->ui->notice('error', 'add_provider needs a name, a protocol, a base_url, an api_key_env (or null) and a model (or null)');

            return;
        }
        try {
            ConfigWriter::addProfile($this->kernel->configFile, $name, [
                'protocol' => $protocol,
                'base_url' => $baseUrl,
                'api_key_env' => $apiKeyEnv,
                'api_key' => $command['api_key'] ?? null,
                'model' => $model,
            ]);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        try {
            $this->kernel->reloadConfig();
        } catch (PhycodeException $error) {
            $this->ui->notice('error', 'the provider is saved, but the configuration cannot be read again; restart phycode to use it: ' . $this->session->redact($error->getMessage()));
        }

        $this->channel->send($this->modelsEvent(false));
    }

    private function setProviderKey(array $command): void
    {
        $profile = $command['profile'] ?? null;
        $key = $command['api_key'] ?? null;
        if (!is_string($profile) || !is_string($key)) {
            $this->ui->notice('error', 'set_provider_key needs a profile and an api_key');

            return;
        }
        try {
            $this->kernel->config->profile($profile);
            ConfigWriter::setProfileKey($this->kernel->configFile, $profile, $key);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        try {
            $this->kernel->reloadConfig();
        } catch (PhycodeException $error) {
            $this->ui->notice('error', 'the key is saved, but the configuration cannot be read again; restart phycode to use it: ' . $this->session->redact($error->getMessage()));
        }

        $this->channel->send($this->modelsEvent(false));
    }

    private function removeProvider(array $command): void
    {
        $profile = $command['profile'] ?? null;
        if (!is_string($profile)) {
            $this->ui->notice('error', 'remove_provider needs a profile');

            return;
        }
        try {
            $this->kernel->config->profile($profile);
            if ($profile === $this->session->profile()) {
                throw new PhycodeException("{$profile} is the provider of this session: choose a model of another provider first");
            }
            ConfigWriter::removeProfile($this->kernel->configFile, $profile);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        try {
            $this->kernel->reloadConfig();
        } catch (PhycodeException $error) {
            $this->ui->notice('error', 'the provider is removed, but the configuration cannot be read again; restart phycode: ' . $this->session->redact($error->getMessage()));
        }

        $this->channel->send($this->modelsEvent(false));
    }

    private function searchProviders(): array
    {
        $web = $this->kernel->config->webConfig();
        $vault = new Vault($this->kernel->config->configDir);
        $active = $web['search']['name'] ?? null;
        $items = [];
        foreach ($web['providers'] ?? [] as $name => $provider) {
            $items[] = [
                'name' => (string) $name,
                'backend' => $provider['backend'],
                'url' => $provider['url'],
                'key' => SearchKeys::status((string) $name, $provider, $vault),
                'active' => (string) $name === $active,
            ];
        }
        $legacy = $web !== null && $web['legacy'] && $web['search']['backend'] !== 'none'
            ? ['backend' => $web['search']['backend'], 'key' => SearchKeys::status('', $web['search'], $vault)]
            : null;

        return [
            'type' => 'search_providers',
            'active' => $active,
            'legacy' => $legacy,
            'items' => $items,
            'presets' => SearchPresets::all(),
        ];
    }

    private function addSearchProvider(array $command): void
    {
        $name = $command['name'] ?? null;
        $backend = $command['backend'] ?? null;
        $url = $command['url'] ?? null;
        $apiKey = $command['api_key'] ?? null;
        if (!is_string($name) || !is_string($backend) || (!is_string($url) && $url !== null) || (!is_string($apiKey) && $apiKey !== null)) {
            $this->ui->notice('error', 'add_search_provider needs a name, a backend, a url (or null) and an api_key (or null)');

            return;
        }
        try {
            SearchProviderWriter::add($this->kernel->configFile, $name, $backend, $url, $apiKey, true);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        $this->reloadSearch('the provider is saved, but the configuration cannot be read again; restart phycode to use it');
    }

    private function updateSearchProvider(array $command): void
    {
        $name = $command['name'] ?? null;
        $url = $command['url'] ?? null;
        $apiKey = $command['api_key'] ?? null;
        if (!is_string($name) || (!is_string($url) && $url !== null) || (!is_string($apiKey) && $apiKey !== null)) {
            $this->ui->notice('error', 'update_search_provider needs a name, and a url or an api_key (or null)');

            return;
        }
        try {
            SearchProviderWriter::update($this->kernel->configFile, $name, $url, $apiKey);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        $this->reloadSearch('the provider is saved, but the configuration cannot be read again; restart phycode to use it');
    }

    private function removeSearchProvider(array $command): void
    {
        $name = $command['name'] ?? null;
        if (!is_string($name)) {
            $this->ui->notice('error', 'remove_search_provider needs a name');

            return;
        }
        try {
            SearchProviderWriter::remove($this->kernel->configFile, $name);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        $this->reloadSearch('the provider is removed, but the configuration cannot be read again; restart phycode to use it');
    }

    private function setSearchProvider(array $command): void
    {
        $name = $command['name'] ?? null;
        if (!array_key_exists('name', $command) || (!is_string($name) && $name !== null)) {
            $this->ui->notice('error', 'set_search_provider needs a name, or null for no search');

            return;
        }
        try {
            SearchProviderWriter::setActive($this->kernel->configFile, $name);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        $this->reloadSearch('the search is saved, but the configuration cannot be read again; restart phycode to use it');
    }

    private function reloadSearch(string $failure): void
    {
        try {
            $this->kernel->reloadConfig();
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $failure . ': ' . $this->session->redact($error->getMessage()));
        }
        foreach ($this->kernel->config->warnings as $warning) {
            if (str_starts_with($warning, 'web.search.')) {
                $this->ui->notice('warn', $warning);
            }
        }
        $this->ui->event($this->searchProviders());
    }

    private function detectEndpoints(): void
    {
        $same = static fn (string $url): string => rtrim(strtolower(str_replace(['localhost', 'https://', 'http://'], ['127.0.0.1', '', ''], $url)), '/');
        $configured = [];
        foreach ($this->kernel->config->profiles as $name => $profile) {
            $configured[$same($profile->baseUrl)] = (string) $name;
        }
        $taken = array_map('strval', array_keys($this->kernel->config->profiles));
        $items = [];
        foreach ((new EndpointDetector())->detect() as $endpoint) {
            $endpoint['configured'] = $configured[$same($endpoint['baseUrl'])] ?? null;
            if ($endpoint['configured'] === null) {
                $name = $endpoint['name'];
                for ($n = 2; in_array($name, $taken, true); $n++) {
                    $name = $endpoint['name'] . '-' . $n;
                }
                $endpoint['name'] = $name;
                $taken[] = $name;
            }
            $items[] = $endpoint;
        }

        $this->ui->event(['type' => 'endpoints', 'items' => $items]);
    }

    private function configSet(array $command): void
    {
        $key = $command['key'] ?? null;
        if (!is_string($key) || trim($key) === '' || !array_key_exists('value', $command)) {
            $this->ui->notice('error', 'config_set needs a key and a value');

            return;
        }
        try {
            $this->configEditor()->set(trim($key), $command['value']);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', $this->session->redact($error->getMessage()));

            return;
        }
        try {
            $this->kernel->reloadConfig();
        } catch (PhycodeException $error) {
            $this->ui->notice('error', 'the setting is saved, but the configuration cannot be read again; restart phycode to use it: ' . $this->session->redact($error->getMessage()));
        }

        $this->configEvent();
    }

    private function configEvent(): void
    {
        $this->ui->event(['type' => 'config', 'items' => $this->configEditor()->items($this->kernel->config)]);
    }

    private function configEditor(): ConfigEditor
    {
        return new ConfigEditor($this->kernel->configFile);
    }

    private function sessions(): void
    {
        $this->ui->event(['type' => 'sessions', 'items' => $this->sessionItems()]);
    }

    private function sessionItems(): array
    {
        $items = [];
        foreach ($this->session->listSessions() as $entry) {
            $items[] = [
                'id' => (string) ($entry['id'] ?? ''),
                'title' => $this->session->redact((string) ($entry['title'] ?? '')),
                'updatedAt' => is_string($entry['updatedAt'] ?? null) ? $entry['updatedAt'] : null,
                'messages' => (int) ($entry['messages'] ?? 0),
                'current' => ($entry['current'] ?? false) === true,
            ];
        }

        return $items;
    }

    private function switchSession(array $command): void
    {
        $id = $command['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            $this->ui->notice('error', 'session_switch needs the id of a session');

            return;
        }
        $this->session->switchSession(trim($id));
        $this->sessionChanged();
    }

    private function newSession(): void
    {
        $this->session->newSession();
        $this->sessionChanged();
    }

    private function sessionChanged(): void
    {
        $this->sessions();
        $this->modelChangedEvent(false);
        $this->effortEvent();
        $this->ui->event($this->contextEvent());
    }

    private function setEffort(array $command): void
    {
        $level = $command['level'] ?? null;
        if (!is_string($level) || trim($level) === '') {
            $this->ui->notice('error', 'set_effort needs a level');

            return;
        }
        $this->session->setEffort(trim($level));
        $this->effortEvent();
    }

    private function effortEvent(): void
    {
        $this->ui->event([
            'type' => 'effort',
            'profile' => $this->session->profile(),
            'model' => $this->session->model(),
            'level' => $this->session->thinking(),
            'levels' => $this->session->effortLevels(),
            'accepted' => $this->session->effortAccepted(),
            'note' => $this->session->effortNote(),
        ]);
    }

    private function stateLine(string $line): bool
    {
        if (self::isWord($line, '/effort')) {
            if ($line !== '/effort') {
                $this->session->handle($line);
            }
            $this->effortEvent();

            return true;
        }
        if (self::isWord($line, '/context')) {
            if ($line !== '/context') {
                $this->session->handle($line);
            }
            $this->ui->event($this->contextEvent());

            return true;
        }
        if (self::isWord($line, '/compact')) {
            $this->compactTurn(self::rest($line, '/compact'));

            return true;
        }
        if ($line === '/reload') {
            $this->session->handle($line);
            $this->channel->send($this->modelsEvent(false));
            $this->configEvent();

            return true;
        }
        if (self::isWord($line, '/agent')) {
            $this->session->handle($line);
            $this->ui->event(['type' => 'agent_changed', 'agent' => $this->session->primary()->name]);

            return true;
        }
        if (self::isWord($line, '/sessions')) {
            $this->session->handle($line);
            $this->sessionChanged();

            return true;
        }

        return false;
    }

    private function setContext(array $command): void
    {
        $max = $command['max'] ?? null;
        if (!array_key_exists('max', $command) || ($max !== null && !is_int($max))) {
            $this->ui->notice('error', 'set_context needs a max: a number of tokens, or null for the window of the model');

            return;
        }
        $this->session->setContextMax($max);
        $this->ui->event($this->contextEvent());
    }

    private function contextEvent(): array
    {
        $state = $this->session->contextState();

        return [
            'type' => 'context',
            'used' => self::number($state['used'] ?? null),
            'limit' => self::number($state['limit'] ?? null),
            'threshold' => self::number($state['threshold'] ?? null),
            'percent' => self::number($state['percent'] ?? null),
            'messages' => self::number($state['messages'] ?? null),
            'breakdown' => $state['breakdown'],
            'autoCompact' => $state['autoCompact'],
            'autoCompactSwitch' => $state['autoCompactSwitch'] ?? false,
        ];
    }

    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private function turn(\Closure $work): void
    {
        $mark = $this->kernel->changes->mark();
        $this->ui->beginTurn();
        $window = null;
        $usage = null;
        $status = 'error';
        $reason = null;
        try {
            $window = $this->session->contextWindow();
            [$status, $reason, $usage] = $work();
        } catch (\Throwable $error) {
            if ($this->ui->wasCancelled()) {
                $status = 'cancelled';
                $reason = null;
            } else {
                $status = 'error';
                $reason = $this->session->redact($error->getMessage());
                $this->ui->notice('error', "turn ended (error): {$reason}");
            }
        } finally {
            $this->ui->endTurn($status, $reason, $this->kernel->changes->since($mark), $this->usage($window, $usage), Branch::current($this->kernel->workspace->root));
        }
    }

    private function compact(array $command): void
    {
        $instructions = $command['instructions'] ?? null;
        if ($instructions !== null && !is_string($instructions)) {
            $this->ui->notice('error', 'compact needs instructions: a text, or null');

            return;
        }
        $this->compactTurn(is_string($instructions) ? trim($instructions) : '');
    }

    private function compactTurn(string $instructions): void
    {
        $this->session->handle(trim("/compact {$instructions}"), $this->frame());
        $this->ui->event($this->contextEvent());
    }

    private function deepResearch(array $command): void
    {
        $question = $command['question'] ?? null;
        $rounds = $command['rounds'] ?? self::ROUNDS;
        if (!is_string($question) || trim($question) === '' || !is_int($rounds) || $rounds < 1 || $rounds > self::MAX_ROUNDS) {
            $this->ui->notice('error', 'deepresearch needs a question, and rounds from 1 to ' . self::MAX_ROUNDS);

            return;
        }
        $question = trim($question);
        $id = 'r' . ++$this->researchIds;
        $this->turn(function () use ($question, $rounds, $id): array {
            $cancel = new Cancellation();
            $this->researching = $cancel;
            try {
                $answer = ($this->research)($question, $rounds, function (string $step, string $detail, bool $done) use ($id, $cancel): void {
                    $this->ui->progress($id, $this->session->redact($step), $this->session->redact($detail), $done);
                    if ($this->ui->wasCancelled()) {
                        $cancel->cancel('interrupted by the user');
                        throw new \RuntimeException('interrupted by the user');
                    }
                }, $cancel);
            } finally {
                $this->researching = null;
            }
            $this->ui->output($this->session->redact($answer));

            return ['completed', null, null];
        });
    }

    private function runResearch(string $question, int $rounds, callable $progress, Cancellation $cancel): string
    {
        $web = $this->kernel->config->webConfig();
        if ($web === null) {
            throw new PhycodeException('deep research needs the web: set web.search in the config');
        }
        $home = (string) (getenv('HOME') ?: '/root');
        $cacheDir = rtrim((string) (getenv('XDG_CACHE_HOME') ?: $home . '/.cache'), '/') . '/phycode';
        $services = WebServices::fromConfig($web, $cacheDir . '/web', new Vault($this->kernel->config->configDir));
        if (!$services->searchOn) {
            throw new PhycodeException('deep research needs a search: choose a search provider with /web, or set web.search.backend in the config');
        }

        return (new DeepResearch(
            $this->kernel->providers,
            $this->session->profile(),
            $services->search,
            $services->fetcher,
            $this->session->model(),
            $this->session->thinking(),
            $cancel,
        ))->run($question, $rounds, $progress);
    }

    private function sideQuestion(array $command): void
    {
        $question = $command['question'] ?? null;
        if (!is_string($question) || trim($question) === '') {
            $this->ui->notice('error', 'btw needs a question: /btw QUESTION');

            return;
        }
        $question = trim($question);
        try {
            $this->btw->start($question, [
                'question' => $question,
                ...$this->session->sideContext(),
                'cwd' => $this->kernel->workspace->root,
            ]);
        } catch (PhycodeException $error) {
            $this->ui->notice('error', 'btw failed: ' . $this->session->redact($error->getMessage()));
        }
    }

    private function sweepSideQuestions(): void
    {
        foreach ($this->btw->poll() as $result) {
            if ($result['ok']) {
                $this->ui->panel('btw', $this->session->redact((string) $result['answer']));
            } else {
                $this->ui->notice('error', 'btw failed: ' . $this->session->redact((string) $result['error']));
            }
        }
    }

    private function relay(array $command): bool
    {
        $type = $command['type'] ?? null;
        $line = $type === 'input' && is_string($command['text'] ?? null) ? trim($command['text']) : '';
        $answered = in_array($type, ['btw', 'session_list', 'config', 'list_search_providers'], true)
            || self::isWord($line, '/btw') || $line === '/config' || $line === '/sessions';
        if (!$answered) {
            return false;
        }
        $this->command($command);

        return true;
    }

    private static function isWord(string $line, string $word): bool
    {
        return $line === $word || str_starts_with($line, $word . ' ');
    }

    private static function rest(string $line, string $word): string
    {
        return trim(substr($line, strlen($word)));
    }

    private function modelsEvent(bool $refresh): array
    {
        $entries = [];
        foreach ($this->kernel->catalog->entries($refresh) as $entry) {
            $entry['error'] = $entry['error'] === null ? null : $this->session->redact($entry['error']);
            $entries[] = $entry;
        }
        $providers = [];
        foreach ($this->kernel->catalog->providers(false) as $provider) {
            $provider['error'] = $provider['error'] === null ? null : $this->session->redact($provider['error']);
            $providers[] = $provider;
        }

        return [
            'type' => 'models',
            'entries' => $entries,
            'providers' => $providers,
            'presets' => array_map(
                static fn (array $preset): array => $preset + ['keyInEnv' => $preset['apiKeyEnv'] !== null && (string) getenv($preset['apiKeyEnv']) !== ''],
                ProviderPresets::all(),
            ),
            'current' => [
                'profile' => $this->session->profile(),
                'model' => $this->session->model(),
                'thinking' => $this->session->thinking(),
            ],
            'favorites' => $this->kernel->state->favorites(),
            'recents' => $this->kernel->state->recents(),
        ];
    }

    private function usage(?int $contextWindow, ?Usage $usage): array
    {
        return [
            'promptTokens' => $usage?->promptTokens,
            'completionTokens' => $usage?->completionTokens,
            'tokensPerSecond' => $usage?->tokensPerSecond,
            'contextWindow' => $contextWindow,
        ];
    }

    private static function modeName(AgentDefinition $agent): string
    {
        return match ($agent->mode) {
            'primary' => 'primary',
            'subagent' => 'subagent',
            default => 'all',
        };
    }

    private function bye(string $reason): int
    {
        $this->channel->send(['type' => 'bye', 'reason' => $reason]);

        return 0;
    }
}
