<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Gentle\GentleAi;
use Phycode\Skills\SkillCatalog;
use Phycode\Skills\SkillLoader;
use Phycode\Skills\SkillRegistry;
use Phycode\Skills\SkillWriter;
use Phycode\Support\Vault;
use Phycode\Ui\Ui;
use Phycode\Web\WebServices;

final readonly class ToolSettings
{
    private const CUSTOM_KEYS = ['name', 'description', 'command', 'stdin'];
    private const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,47}$/D';

    public function __construct(
        public array $disabled,
        public array $custom,
    ) {
    }

    public static function fromFile(string $configFile): self
    {
        if (!is_file($configFile)) {
            return new self([], []);
        }
        try {
            $data = json_decode((string) file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ConfigException("{$configFile} is not valid JSON: " . $error->getMessage());
        }
        $data = is_array($data) ? $data : [];

        return new self(self::disabled($data['tools'] ?? null), self::custom($data['custom_tools'] ?? null));
    }

    public function webTools(Config $config, string $cacheDir): array
    {
        $web = $config->webConfig();
        if ($web === null) {
            return [];
        }
        $services = WebServices::fromConfig($web, rtrim($cacheDir, '/') . '/web', new Vault($config->configDir));

        return [new WebSearchTool($services->search, $services->searchOn), new WebFetchTool($services->fetcher, true)];
    }

    public function optionalTools(Config $config, Ui $ui, string $root): array
    {
        $tools = [];
        $bin = null;
        $gentleReady = true;
        try {
            $bin = $config->gentleAiBin();
        } catch (ConfigException) {
            $gentleReady = false;
        }
        $home = getenv('HOME');
        $homePath = is_string($home) && $home !== '' ? $home : null;
        $places = SkillCatalog::places($root, $homePath, SkillCatalog::userFolder($config->configDir));
        $loader = new SkillLoader(new SkillRegistry(new GentleAi(), $bin, $root), places: $places, root: $root, home: $homePath);
        $tools[] = new SkillTool($loader, $config->skillsEnabled() && $gentleReady);
        $tools[] = new SkillManageTool(new SkillWriter($config->configDir), $config->skillsEnabled(), $config->skillsAutonomy());

        $tools[] = new AskUserTool($ui->interactive());
        foreach ($this->custom as $entry) {
            $tools[] = new CustomTool($entry['name'], $entry['description'], $entry['command'], $entry['stdin']);
        }

        return $tools;
    }

    private static function disabled(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new ConfigException('tools must be an object');
        }
        foreach (array_keys($raw) as $key) {
            if ($key !== 'disabled') {
                throw new ConfigException("tools.{$key} is not a known setting");
            }
        }
        $names = $raw['disabled'] ?? [];
        if (!is_array($names) || !array_is_list($names)) {
            throw new ConfigException('tools.disabled must be a list of tool names');
        }
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name) !== 1) {
                throw new ConfigException('tools.disabled must be a list of tool names');
            }
        }

        return $names;
    }

    private static function custom(mixed $raw): array
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
            if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
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
}
