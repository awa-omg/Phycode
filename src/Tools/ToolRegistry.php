<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Support\PhycodeException;

final class ToolRegistry
{
    private array $tools = [];

    public function __construct(private readonly array $disabled = [])
    {
    }

    public function register(Tool $tool): void
    {
        if (isset($this->tools[$tool->name()])) {
            throw new PhycodeException("tool {$tool->name()} is registered twice");
        }
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    public function resolve(string $name): ?string
    {
        if (isset($this->tools[$name])) {
            return $name;
        }
        $plain = strtolower(str_replace('-', '_', trim($name)));
        $plain = preg_replace('/^(?:functions|tools?|default_api|api)[._:]/', '', $plain) ?? $plain;

        return isset($this->tools[$plain]) ? $plain : null;
    }

    public function available(RunPolicy $policy): array
    {
        $names = [];
        foreach ($this->tools as $name => $tool) {
            if (!in_array(RunPolicy::COMPANIONS[$name] ?? $name, $this->disabled, true) && $policy->allows($name) && !$policy->denies($tool->category()) && $tool->isAvailable($policy)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function specs(RunPolicy $policy): array
    {
        $specs = [];
        foreach ($this->available($policy) as $name) {
            $tool = $this->tools[$name];
            $specs[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ];
        }

        return $specs;
    }

    public function signatures(RunPolicy $policy): string
    {
        $lines = [];
        foreach ($this->available($policy) as $name) {
            $tool = $this->tools[$name];
            $schema = $tool->parameters();
            $required = $schema['required'] ?? [];
            $parameters = [];
            foreach ($schema['properties'] ?? [] as $parameter => $definition) {
                $type = isset($definition['enum']) ? implode('|', $definition['enum']) : ($definition['type'] ?? 'string');
                $parameters[] = $parameter . (in_array($parameter, $required, true) ? '' : '?') . ': ' . $type;
            }
            $summary = explode("\n", $tool->description())[0];
            $lines[] = "- {$name}(" . implode(', ', $parameters) . '): ' . $summary;
        }

        return implode("\n", $lines);
    }
}
