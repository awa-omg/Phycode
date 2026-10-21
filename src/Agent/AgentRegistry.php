<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Support\Entry;
use Phycode\Support\PhycodeException;

final class AgentRegistry
{
    private array $agents = [];

    private array $warnings = [];

    private array $notices = [];

    private array $builtins = [];

    private function __construct(private readonly Workspace $workspace)
    {
    }

    public static function load(string $cwd, string $root, string $configDir, string $home, string $builtinDir): self
    {
        $cwd = realpath($cwd) ?: $cwd;
        $root = realpath($root) ?: $root;
        $registry = new self(new Workspace($root));
        $registry->builtins = self::builtinNames($builtinDir);

        $chain = [];
        $folder = $cwd;
        while (true) {
            $chain[] = $folder;
            if ($folder === $root || dirname($folder) === $folder) {
                break;
            }
            $folder = dirname($folder);
        }

        $sources = [];
        foreach ($chain as $project) {
            $sources[] = [$project . '/.phycode/agents', 'project'];
            $sources[] = [$project . '/.claude/agents', 'project-claude'];
        }
        $sources[] = [$configDir . '/agents', 'user'];
        $sources[] = [$home . '/.claude/agents', 'user-claude'];
        $sources[] = [$builtinDir, 'builtin'];

        foreach ($sources as [$folder, $source]) {
            $registry->addFolder($folder, $source);
        }

        return $registry;
    }

    public function addFolder(string $folder, string $source): void
    {
        if (!is_dir($folder)) {
            return;
        }
        $project = in_array($source, ['project', 'project-claude'], true);
        $files = Entry::markdownFiles($folder);
        foreach ($files as $file) {
            $path = $file;
            if ($project) {
                try {
                    $path = $this->workspace->resolve($file);
                } catch (PathException) {
                    $this->notice("{$file}: skipped: it is a symbolic link that does not lead to a file inside the project");
                    continue;
                }
            }
            $dropped = [];
            try {
                $definition = AgentFactory::fromFile($path, $source, $dropped);
            } catch (PhycodeException $error) {
                $this->warnings[] = $error->getMessage();
                continue;
            }
            foreach ($dropped as $notice) {
                $this->notice($notice);
            }
            if ($definition === null) {
                continue;
            }
            if ($project && in_array($definition->name, $this->builtins, true)) {
                $this->warnings[] = "{$file}: project agent {$definition->name} is ignored: built-in agents cannot be replaced by project files";
                continue;
            }
            if (isset($this->agents[$definition->name])) {
                $this->warnings[] = "agent '{$definition->name}' in {$file} is hidden by {$this->agents[$definition->name]->path}";
                continue;
            }
            foreach ($definition->warnings as $warning) {
                $this->warnings[] = "{$file}: {$warning}";
            }
            $this->agents[$definition->name] = $definition;
        }
    }

    public function get(string $name): ?AgentDefinition
    {
        return $this->agents[$name] ?? null;
    }

    public function all(): array
    {
        return $this->agents;
    }

    public function subagents(): array
    {
        return array_filter($this->agents, static fn (AgentDefinition $agent): bool => $agent->isSubagent());
    }

    public function primaries(): array
    {
        return array_filter($this->agents, static fn (AgentDefinition $agent): bool => $agent->isPrimary());
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function notices(): array
    {
        return $this->notices;
    }

    private function notice(string $message): void
    {
        $this->warnings[] = $message;
        $this->notices[] = $message;
    }

    private static function builtinNames(string $builtinDir): array
    {
        $names = [];
        foreach (Entry::markdownFiles($builtinDir) as $file) {
            try {
                $definition = AgentFactory::fromFile($file, 'builtin');
            } catch (PhycodeException) {
                continue;
            }
            if ($definition !== null) {
                $names[] = $definition->name;
            }
        }

        return $names;
    }
}
