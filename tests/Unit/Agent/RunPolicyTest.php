<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\RunPolicy;
use Phycode\Tests\Support\Kit;
use Phycode\Tools\ToolCategory;
use PHPUnit\Framework\TestCase;

final class RunPolicyTest extends TestCase
{
    public function testSubagentNarrowsToolsAndAddsDenials(): void
    {
        $parent = new RunPolicy(['read', 'list', 'grep', 'task'], [], [], 'yolo', 0);
        $child = RunPolicy::child($parent, Kit::agent('writer', 'subagent', ['read', 'write'], ['exec']));

        self::assertSame(['read'], $child->allowed);
        self::assertTrue($child->denies(ToolCategory::Exec));
        self::assertSame('yolo', $child->mode);
        self::assertSame(1, $child->depth);
    }

    public function testSubagentsNeverGetTheTaskTool(): void
    {
        $parent = new RunPolicy(null, [], [], 'ask', 0);
        $child = RunPolicy::child($parent, Kit::agent('general', 'subagent'));

        self::assertTrue($parent->canDelegate());
        self::assertFalse($child->allows('task'));
        self::assertFalse($child->canDelegate());
    }

    public function testParentRestrictionsCannotBeLiftedByAChild(): void
    {
        $parent = new RunPolicy(null, [], ['write'], 'yolo', 0);
        $child = RunPolicy::child($parent, Kit::agent('general', 'subagent', null, []));

        self::assertTrue($child->denies(ToolCategory::Write));
    }

    public function testExcludedToolsAccumulateAcrossLevels(): void
    {
        $parent = new RunPolicy(null, ['bash'], [], 'ask', 0);
        $agent = new \Phycode\Agent\AgentDefinition(
            name: 'x', description: 'x', mode: 'subagent', tools: null, excludedTools: ['run_code'],
            deniedCategories: [], profile: null, maxTurns: null, temperature: null, prompt: '',
            source: 'test', path: 'memory', warnings: [],
        );
        $child = RunPolicy::child($parent, $agent);

        self::assertFalse($child->allows('bash'));
        self::assertFalse($child->allows('run_code'));
        self::assertTrue($child->allows('read'));
    }

    public function testWithDeniedAddsCategoriesWithoutRemovingExisting(): void
    {
        $policy = (new RunPolicy(null, [], ['write'], 'ask', 0))->withDenied(['exec']);

        self::assertTrue($policy->denies(ToolCategory::Write));
        self::assertTrue($policy->denies(ToolCategory::Exec));
        self::assertFalse($policy->denies(ToolCategory::Read));
    }
}
