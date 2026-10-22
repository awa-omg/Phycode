<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Support\PhycodeException;

final class AgentFactory
{
    private const TOOL_NAMES = ['read', 'list', 'glob', 'grep', 'repo_map', 'write', 'edit', 'move', 'delete', 'bash', 'run_code', 'ssh', 'ssh_copy', 'deploy', 'deploy_info', 'gh_read', 'gh_write', 'task', 'todo', 'session_search'];

    private const TOOL_ALIASES = [
        'ls' => 'list',
        'multiedit' => 'edit',
        'agent' => 'task',
    ];

    private const KNOWN_FIELDS = [
        'name', 'description', 'mode', 'tools', 'disallowedTools', 'permission', 'permissionMode',
        'model', 'profile', 'max_turns', 'maxTurns', 'steps', 'temperature', 'disable', 'sandbox',
    ];

    private const MAX_PROMPT_BYTES = 20000;

    public static function fromFile(string $path, string $source, array &$notices = []): ?AgentDefinition
    {
        $document = file_get_contents($path);
        if ($document === false) {
            throw new PhycodeException("cannot read {$path}");
        }
        [$data, $body] = Frontmatter::split($document, $path);

        if (($data['disable'] ?? false) === true) {
            return null;
        }

        $warnings = [];
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, self::KNOWN_FIELDS, true)) {
                $warnings[] = "field '{$key}' is not supported and was ignored";
            }
        }

        $name = self::requiredString($data, 'name', $path);
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/', $name) !== 1) {
            throw new PhycodeException("{$path}: name '{$name}' must use lowercase letters, digits and hyphens, and start with a letter");
        }
        $description = self::requiredString($data, 'description', $path);

        $mode = self::stringField($data, 'mode', $path) ?? 'subagent';
        if (!in_array($mode, ['primary', 'subagent', 'all'], true)) {
            throw new PhycodeException("{$path}: mode must be primary, subagent or all");
        }

        $tools = self::toolList($data['tools'] ?? null, $warnings, $path, strict: true);
        $excluded = self::toolList($data['disallowedTools'] ?? null, $warnings, $path, strict: false) ?? [];

        $prompt = trim($body);
        if (strlen($prompt) > self::MAX_PROMPT_BYTES) {
            throw new PhycodeException("{$path}: the instructions are longer than 20 KB");
        }

        $profile = self::profile($data, $path);
        if ($profile !== null && in_array($source, ['project', 'project-claude'], true)) {
            $notices[] = "{$path}: profile '{$profile}' is ignored: a project agent cannot choose the profile that receives its conversation";
            $profile = null;
        }

        return new AgentDefinition(
            name: $name,
            description: $description,
            mode: $mode,
            tools: $tools,
            excludedTools: $excluded,
            deniedCategories: self::deniedCategories($data, $warnings, $path),
            profile: $profile,
            maxTurns: self::maxTurns($data, $path),
            temperature: self::temperature($data, $path),
            prompt: $prompt,
            source: $source,
            path: $path,
            warnings: $warnings,
            sandbox: self::sandboxField($data, $path),
        );
    }

    private static function requiredString(array $data, string $key, string $path): string
    {
        $value = self::stringField($data, $key, $path);
        if ($value === null || $value === '') {
            throw new PhycodeException("{$path}: '{$key}' is required");
        }

        return $value;
    }

    private static function stringField(array $data, string $key, string $path): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new PhycodeException("{$path}: '{$key}' must be text");
        }

        return trim($value);
    }

    private static function toolList(mixed $raw, array &$warnings, string $path, bool $strict): ?array
    {
        if ($raw === null) {
            return null;
        }
        $items = match (true) {
            is_string($raw) => array_map('trim', explode(',', $raw)),
            is_array($raw) && array_is_list($raw) => $raw,
            is_array($raw) => array_keys(array_filter($raw, static fn (mixed $enabled): bool => $enabled === true)),
            default => throw new PhycodeException("{$path}: tools must be a list, a comma-separated string or a map"),
        };

        $resolved = [];
        $requested = 0;
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            $requested++;
            $base = strtolower(trim((string) preg_replace('/\(.*\)$/', '', trim($item))));
            $name = self::TOOL_ALIASES[$base] ?? $base;
            if (!in_array($name, self::TOOL_NAMES, true)) {
                $warnings[] = "tool '{$item}' does not exist in phycode and was ignored";
                continue;
            }
            $resolved[$name] = true;
        }
        if ($strict && $requested > 0 && $resolved === []) {
            throw new PhycodeException("{$path}: none of the listed tools exist in phycode, so the agent would have no tools");
        }

        return array_map('strval', array_keys($resolved));
    }

    private static function deniedCategories(array $data, array &$warnings, string $path): array
    {
        $denied = [];
        $permission = $data['permission'] ?? null;
        if (is_string($permission)) {
            if ($permission === 'readonly') {
                $denied = ['write', 'exec'];
            } elseif ($permission !== 'inherit') {
                $warnings[] = "permission '{$permission}' is not supported; use inherit or readonly";
            }
        } elseif (is_array($permission)) {
            if (($permission['edit'] ?? null) === 'deny') {
                $denied[] = 'write';
            }
            if (($permission['bash'] ?? null) === 'deny') {
                $denied[] = 'exec';
            }
        }
        if (($data['permissionMode'] ?? null) === 'plan') {
            array_push($denied, 'write', 'exec');
        }

        return array_values(array_unique($denied));
    }

    private static function profile(array $data, string $path): ?string
    {
        $value = self::stringField($data, 'profile', $path) ?? self::stringField($data, 'model', $path);

        return $value === null || $value === '' || $value === 'inherit' ? null : $value;
    }

    private static function sandboxField(array $data, string $path): ?string
    {
        $value = self::stringField($data, 'sandbox', $path);
        if ($value === null || $value === 'inherit') {
            return null;
        }
        if (!in_array($value, ['host', 'podman', 'docker', 'bwrap'], true)) {
            throw new PhycodeException("{$path}: sandbox must be host, podman, docker, bwrap or inherit");
        }

        return $value;
    }

    private static function maxTurns(array $data, string $path): ?int
    {
        $value = $data['max_turns'] ?? $data['maxTurns'] ?? $data['steps'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 1 || $value > 100) {
            throw new PhycodeException("{$path}: max_turns must be a whole number from 1 to 100");
        }

        return $value;
    }

    private static function temperature(array $data, string $path): ?float
    {
        $value = $data['temperature'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new PhycodeException("{$path}: temperature must be a number");
        }
        if ($value < 0 || $value > 2) {
            throw new PhycodeException("{$path}: temperature must be between 0 and 2");
        }

        return (float) $value;
    }
}
