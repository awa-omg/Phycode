<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Diff\TextDiff;
use Phycode\Skills\SkillChange;
use Phycode\Skills\SkillWriter;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class SkillManageTool extends BaseTool
{
    private const ACTIONS = ['create', 'patch', 'write_file', 'remove_file', 'archive', 'merge', 'restore'];

    public function __construct(
        private readonly SkillWriter $writer,
        private readonly bool $enabled,
        private readonly string $autonomy = 'ask',
    ) {
    }

    public function name(): string
    {
        return 'skill_manage';
    }

    public function description(): string
    {
        return 'Save a reusable procedure as a skill, or change one. Actions: create (name, description, body); patch (name, old_string, '
            . 'new_string, optional file_path and replace_all) replaces exact text in SKILL.md or a support file; write_file and remove_file '
            . '(name, file_path) work on files under references/, templates/, scripts/ or assets/; archive (name) moves a skill to the trash; '
            . 'merge (into, from: two or more saved skills, content: the whole SKILL.md of into) joins skills and archives those in from; '
            . 'restore (name) brings back the newest archived copy. Nothing is deleted for good.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Write;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $this->enabled;
    }

    public function autoApproved(): bool
    {
        return $this->autonomy === 'auto';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'What to do with the skills'],
                'name' => ['type' => 'string', 'description' => 'The skill name: lowercase letters, digits and single hyphens, 1 to 64 characters, such as api-review. Not for merge'],
                'into' => ['type' => 'string', 'description' => 'merge: the name of the skill that the others are joined into'],
                'from' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'merge: the names of two or more skills saved by phycode, which are archived'],
                'description' => ['type' => 'string', 'description' => 'create: one line that says when to use the skill'],
                'body' => ['type' => 'string', 'description' => 'create: the instructions in Markdown, written after the frontmatter'],
                'file_path' => ['type' => 'string', 'description' => 'patch, write_file or remove_file: a path under references/, templates/, scripts/ or assets/, such as references/api.md. patch uses SKILL.md when this is omitted'],
                'old_string' => ['type' => 'string', 'description' => 'patch: exact text to replace, including indentation'],
                'new_string' => ['type' => 'string', 'description' => 'patch: the replacement text'],
                'replace_all' => ['type' => 'boolean', 'description' => 'patch: replace every occurrence instead of exactly one'],
                'content' => ['type' => 'string', 'description' => 'write_file: the full content of the file, up to 1 MB. merge: the whole SKILL.md of into, with its frontmatter'],
            ],
            'required' => ['action'],
        ];
    }

    public function summarize(array $args): string
    {
        $action = is_string($args['action'] ?? null) ? $args['action'] : '?';
        if ($action === 'merge') {
            $into = is_string($args['into'] ?? null) ? $args['into'] : '?';
            $from = is_array($args['from'] ?? null) ? implode(', ', array_filter($args['from'], 'is_string')) : '?';

            return Text::oneLine("skill_manage merge {$into} from {$from}", 120);
        }
        $name = is_string($args['name'] ?? null) ? $args['name'] : '?';
        $file = is_string($args['file_path'] ?? null) && $args['file_path'] !== '' ? ' ' . $args['file_path'] : '';

        return 'skill_manage ' . Text::oneLine("{$action} {$name}{$file}", 120);
    }

    public function validate(array $args, ToolContext $context): void
    {
        $this->plan($args);
    }

    public function preview(array $args, ToolContext $context): ?array
    {
        try {
            return self::diff($this->plan($args));
        } catch (PhycodeException) {
            return null;
        }
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        $change = $this->plan($args);
        $message = $this->writer->apply($change);
        foreach (self::touched($change) as $path) {
            $context->changes->add($context->workspace->relative($path));
        }

        return ToolResult::ok($message)->withDiff(self::diff($change));
    }

    private function plan(array $args): SkillChange
    {
        $action = Args::string($args, 'action');
        if (!in_array($action, self::ACTIONS, true)) {
            throw new PhycodeException('action must be one of ' . implode(', ', self::ACTIONS));
        }
        if ($action === 'merge') {
            return $this->writer->merge(Args::string($args, 'into'), Args::stringList($args, 'from'), Args::string($args, 'content'));
        }
        $name = Args::string($args, 'name');
        $file = Args::string($args, 'file_path', false);

        return match ($action) {
            'create' => $this->writer->create($name, Args::string($args, 'description'), Args::string($args, 'body')),
            'patch' => $this->writer->patch($name, $file, Args::string($args, 'old_string'), Args::string($args, 'new_string'), Args::bool($args, 'replace_all', false)),
            'write_file' => $this->writer->writeFile($name, $file, Args::string($args, 'content')),
            'remove_file' => $this->writer->removeFile($name, $file),
            'archive' => $this->writer->archive($name),
            'restore' => $this->writer->restore($name),
        };
    }

    private static function touched(SkillChange $change): array
    {
        $root = dirname($change->path, 2);
        $paths = [$change->path];
        foreach ($change->from as $name) {
            $paths[] = $root . '/' . $name . '/SKILL.md';
        }

        return $paths;
    }

    private static function diff(SkillChange $change): ?array
    {
        if ($change->action === 'remove_file' || $change->action === 'archive') {
            return $change->before === null ? null : TextDiff::change($change->path, $change->before, null);
        }
        if ($change->before === null && $change->after === null) {
            return null;
        }

        return TextDiff::change($change->path, $change->before, $change->after);
    }
}
