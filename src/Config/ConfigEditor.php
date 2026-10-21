<?php

declare(strict_types=1);

namespace Phycode\Config;

use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class ConfigEditor
{
    private const SETTINGS = [
        'ui.theme' => ['Theme', 'UI', 'choice', ['ui', 'theme'], Config::THEMES],
        'ui.editor' => ['Editor command', 'UI', 'text', ['ui', 'editor']],
        'ui.diff_view' => ['Diff view', 'UI', 'choice', ['ui', 'diff_view'], Config::DIFF_VIEWS],
        'permissions' => ['Permissions', 'Permissions', 'choice', ['permissions'], Config::PERMISSION_MODES],
        'effort.default' => ['Effort default', 'Model', 'choice', ['effort', 'default'], Profile::EFFORTS],
        'context.max' => ['Context max', 'Model', 'text', ['context', 'max']],
        'context.buffer' => ['Context buffer', 'Model', 'text', ['context', 'buffer']],
        'context.auto_compact' => ['Autocompact', 'Model', 'bool', ['context', 'auto_compact']],
        'web.search.backend' => ['Web search backend', 'Web', 'choice', ['web', 'search', 'backend'], Config::SEARCH_BACKENDS],
        'skills.enabled' => ['Skills enabled', 'Skills', 'bool', ['skills', 'enabled']],
        'subagents' => ['Subagents enabled', 'Agents', 'bool', ['subagents']],
    ];
    private const TOOL = '/^tools\.disabled\.([a-z_]+)$/D';
    private const SERVER = '/^mcp\.([A-Za-z0-9_-]+)\.enabled$/D';
    private const EDITOR_BYTES = 200;

    public function __construct(private readonly string $file)
    {
    }

    public function items(Config $config): array
    {
        $items = [];
        foreach (self::SETTINGS as $key => $setting) {
            $item = ['key' => $key, 'label' => $setting[0], 'value' => self::current($config, $key), 'kind' => $setting[2]];
            if ($setting[2] === 'choice') {
                $item['choices'] = $setting[4];
            }
            $item['category'] = $setting[1];
            $items[] = $item;
        }
        foreach (Config::BUILTIN_TOOLS as $tool) {
            $items[] = ['key' => "tools.disabled.{$tool}", 'label' => "Disable {$tool}", 'value' => in_array($tool, $config->disabledTools(), true), 'kind' => 'bool', 'category' => 'Tools'];
        }
        foreach ($config->mcpServers() as $name => $server) {
            $items[] = ['key' => "mcp.{$name}.enabled", 'label' => "Enable MCP server {$name}", 'value' => $server['enabled'], 'kind' => 'bool', 'category' => 'MCP'];
        }

        return $items;
    }

    public function set(string $key, mixed $value): void
    {
        $stored = self::checked($key, $value);
        if (is_link($this->file)) {
            throw new PhycodeException("cannot change {$this->file}: it is a symbolic link");
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new PhycodeException("cannot read {$this->file}");
        }
        try {
            $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PhycodeException("{$this->file} is not valid JSON: " . $error->getMessage());
        }
        if (!$data instanceof \stdClass) {
            throw new PhycodeException("{$this->file} must contain a JSON object");
        }
        $search = self::valueAt($data, ['web', 'search']);
        if ($key === 'web.search.backend' && $search instanceof \stdClass && property_exists($search, 'active')) {
            throw new PhycodeException('web.search.backend is not used while web.search.active is set: choose the search provider in /web');
        }
        self::apply($data, $key, $stored);
        $this->validate($data, $key);
        ConfigWriter::save($this->file, $content, $data);
    }

    private static function current(Config $config, string $key): bool|string
    {
        return match ($key) {
            'ui.theme' => $config->uiTheme(),
            'ui.editor' => $config->editorCommand() ?? '',
            'ui.diff_view' => $config->uiConfig()['diff_view'],
            'permissions' => $config->permissionMode(),
            'effort.default' => $config->effortDefault(),
            'context.max' => (string) ($config->contextMax() ?? 'off'),
            'context.buffer' => (string) ($config->contextBuffer() ?? 'auto'),
            'context.auto_compact' => $config->autoCompact(),
            'web.search.backend' => $config->webConfig()['search']['backend'] ?? 'none',
            'skills.enabled' => $config->skillsEnabled(),
            'subagents' => $config->subagentsEnabled,
        };
    }

    private static function checked(string $key, mixed $value): mixed
    {
        if (preg_match(self::TOOL, $key, $match) === 1) {
            if (!in_array($match[1], Config::BUILTIN_TOOLS, true)) {
                throw new PhycodeException("{$key}: {$match[1]} is not a built-in tool");
            }

            return self::flag($key, $value);
        }
        if (preg_match(self::SERVER, $key) === 1) {
            return self::flag($key, $value);
        }
        if (!isset(self::SETTINGS[$key])) {
            throw new PhycodeException("'{$key}' is not a setting /config can change");
        }
        $setting = self::SETTINGS[$key];
        if ($setting[2] === 'bool') {
            return self::flag($key, $value);
        }
        if ($setting[2] === 'choice') {
            return $key === 'effort.default' ? self::effort($value) : self::choice($key, $value, $setting[4]);
        }

        return match ($key) {
            'context.max' => self::tokens($key, $value, 'off', Config::CONTEXT_MIN_TOKENS),
            'context.buffer' => self::tokens($key, $value, 'auto', 0),
            default => self::editor($value),
        };
    }

    private static function flag(string $key, mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new PhycodeException("{$key} must be true or false");
        }

        return $value;
    }

    private static function choice(string $key, mixed $value, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new PhycodeException("{$key} must be " . self::choices($choices));
        }

        return $value;
    }

    private static function effort(mixed $value): string
    {
        $level = is_string($value) ? Profile::canonical($value) : null;
        if ($level === null) {
            throw new PhycodeException('effort.default must be ' . Profile::levelsText() . ' (off and on are also accepted)');
        }

        return $level;
    }

    private static function tokens(string $key, mixed $value, string $unset, int $min): ?int
    {
        if ($value === null || $value === '' || $value === $unset) {
            return null;
        }
        $number = null;
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $number = (int) $value;
        }
        if ($number === null || $number < $min || $number > Config::CONTEXT_MAX_TOKENS) {
            throw new PhycodeException("{$key} must be {$unset} or a number of tokens from {$min} to " . Config::CONTEXT_MAX_TOKENS);
        }

        return $number;
    }

    private static function editor(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = is_string($value) ? trim($value) : null;
        if ($text === null || strlen($text) > self::EDITOR_BYTES || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            throw new PhycodeException('ui.editor must be one line of text up to 200 characters, such as nvim or code --wait');
        }

        return $text === '' ? null : $text;
    }

    private static function apply(\stdClass $data, string $key, mixed $value): void
    {
        if (preg_match(self::TOOL, $key, $match) === 1) {
            self::setToolDisabled($data, $match[1], $value === true);

            return;
        }
        if (preg_match(self::SERVER, $key, $match) === 1) {
            $server = self::valueAt($data, ['mcp_servers', $match[1]]);
            if (!$server instanceof \stdClass) {
                throw new PhycodeException("no MCP server named {$match[1]} is configured");
            }
            $server->enabled = $value;

            return;
        }
        $path = self::SETTINGS[$key][3];
        if ($key === 'permissions' && self::valueAt($data, ['permissions']) instanceof \stdClass) {
            $path = ['permissions', 'mode'];
        }
        self::setAt($data, $path, $value);
    }

    private static function setToolDisabled(\stdClass $data, string $tool, bool $disabled): void
    {
        $current = self::valueAt($data, ['tools', 'disabled']);
        if ($current !== null && !is_array($current)) {
            throw new PhycodeException('tools.disabled must be a list of built-in tool names');
        }
        $names = array_values(array_filter($current ?? [], static fn (mixed $name): bool => $name !== $tool));
        if ($disabled) {
            $names[] = $tool;
        }
        self::setAt($data, ['tools', 'disabled'], $names);
    }

    private static function valueAt(\stdClass $data, array $path): mixed
    {
        $node = $data;
        foreach ($path as $name) {
            if (!$node instanceof \stdClass) {
                return null;
            }
            $node = $node->{$name} ?? null;
        }

        return $node;
    }

    private static function setAt(\stdClass $data, array $path, mixed $value): void
    {
        $node = $data;
        $label = '';
        $last = array_pop($path);
        foreach ($path as $name) {
            $label = $label === '' ? $name : "{$label}.{$name}";
            $child = $node->{$name} ?? null;
            if ($child === null) {
                $child = new \stdClass();
                $node->{$name} = $child;
            }
            if (!$child instanceof \stdClass) {
                throw new PhycodeException("{$label} must be an object in the config");
            }
            $node = $child;
        }
        $node->{$last} = $value;
    }

    private function validate(\stdClass $data, string $key): void
    {
        $global = json_decode(Json::encode($data), true, 512, JSON_THROW_ON_ERROR);
        $profiles = $global['profiles'] ?? [];
        $environment = is_array($profiles) && $profiles !== [] ? [] : ['PHYCODE_BASE_URL' => 'http://127.0.0.1:8082', 'PHYCODE_MODEL' => 'check'];
        try {
            Config::fromArrays($global, null, $environment, dirname($this->file));
        } catch (PhycodeException $error) {
            throw new PhycodeException("the change to {$key} is refused: " . $error->getMessage());
        }
    }

    private static function choices(array $choices): string
    {
        $last = (string) array_pop($choices);

        return $choices === [] ? $last : implode(', ', $choices) . " or {$last}";
    }
}
