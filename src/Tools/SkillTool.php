<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Skills\SkillLoader;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class SkillTool extends BaseTool
{
    public function __construct(
        private readonly SkillLoader $loader,
        private readonly bool $enabled,
    ) {
    }

    public function name(): string
    {
        return 'skill';
    }

    public function description(): string
    {
        return 'Load the SKILL.md of an installed skill by its name, to follow its instructions. With unload=true, the earlier load of that skill is removed from this conversation to save context. Read-only.';
    }

    public function category(): ToolCategory
    {
        return ToolCategory::Read;
    }

    public function isAvailable(RunPolicy $policy): bool
    {
        return $this->enabled;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The skill name, as listed by /skill or in the skills index'],
                'unload' => ['type' => 'boolean', 'description' => 'true: remove the earlier load of this skill from the conversation, to save context'],
            ],
            'required' => ['name'],
        ];
    }

    public function summarize(array $args): string
    {
        $unload = ($args['unload'] ?? false) === true ? ' (unload)' : '';

        return 'skill: ' . Text::oneLine(is_string($args['name'] ?? null) ? $args['name'] : '?', 100) . $unload;
    }

    public function execute(array $args, ToolContext $context): ToolResult
    {
        try {
            $name = trim(Args::string($args, 'name'));
            if (Args::bool($args, 'unload', false)) {
                SkillLoader::checkName($name);

                return ToolResult::ok(SkillLoader::unloadedLine($name), ['unload' => $name]);
            }

            return ToolResult::ok($this->loader->load($name));
        } catch (PhycodeException $error) {
            return ToolResult::fail($error->getMessage());
        }
    }
}
