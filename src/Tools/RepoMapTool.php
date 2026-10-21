<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\RepoMap\RepoMap;
use Phycode\Support\PhycodeException;

final class RepoMapTool extends BaseTool
{
    private ?RepoMap $map = null;
    private ?string $mapRoot = null;

    public function name(): string
    {
        return 'repo_map';
    }

    public function description(): string
    {
        return 'Map of the code: files with their classes, functions and methods (signature and line), most used first. '
            . 'Use it to find where things are before you read or grep. query focuses on words of paths or names; path picks a folder.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Words to focus on, such as "session persistence"'],
                'path' => ['type' => 'string', 'description' => 'A folder to map; default the project'],
                'tokens' => ['type' => 'integer', 'description' => 'Size of the map, 500 to 8000 (default 1500)'],
            ],
        ];
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function validate(array $args, ToolContext $context): void
    {
        $path = Args::string($args, 'path', false);
        if ($path !== '') {
            $folder = $context->workspace->resolve($path);
            if (!is_dir($folder)) {
                throw new PhycodeException("{$path} is not a folder of the project");
            }
        }
        $tokens = $args['tokens'] ?? null;
        if ($tokens !== null && (!is_int($tokens) || $tokens < 500 || $tokens > 8000)) {
            throw new PhycodeException('tokens must be a whole number from 500 to 8000');
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $path = Args::string($args, 'path', false);
        $relative = $path === '' ? '.' : $context->workspace->relative($context->workspace->resolve($path));
        if ($this->map === null || $this->mapRoot !== $context->workspace->root) {
            $this->map = new RepoMap($context->workspace->root, $context->processes);
            $this->mapRoot = $context->workspace->root;
        }
        $result = $this->map->build(Args::string($args, 'query', false), $relative, is_int($args['tokens'] ?? null) ? $args['tokens'] : 1500);
        if ($result['files'] === 0) {
            return ToolResult::ok('No files with classes or functions were found' . ($relative === '.' ? '' : " in {$relative}") . ' (the map reads PHP, JavaScript, TypeScript, Python, Go, Rust, Java, Kotlin, Scala, C#, Ruby, C and C++).');
        }

        return ToolResult::ok($result['text'], ['files' => $result['files'], 'shown' => $result['shown'], 'symbols' => $result['symbols']]);
    }
}
