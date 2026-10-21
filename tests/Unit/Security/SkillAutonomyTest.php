<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Security\Rules;
use Phycode\Skills\SkillWriter;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\SkillManageTool;
use PHPUnit\Framework\TestCase;

final class SkillAutonomyTest extends TestCase
{
    private const CALL = ['action' => 'create', 'name' => 'go-testing', 'description' => 'Write Go tests.', 'body' => "Use table tests.\n"];
    private const SUMMARY = 'skill_manage create go-testing';

    private string $config;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->config = Kit::folder('skill-autonomy') . '/phycode';
        $this->ui = new RecordingUi();
    }

    private function check(string $autonomy, string $mode, array $ask = [], array $deny = [], array $denied = []): ?string
    {
        $permissions = new Permissions($this->ui, new Rules(['allow' => [], 'ask' => $ask, 'deny' => $deny]));
        $context = Kit::context(Kit::folder('skill-autonomy-project'), Kit::agent(), Kit::policy($mode, null, $denied), $this->ui);

        return $permissions->check(new SkillManageTool(new SkillWriter($this->config), true, $autonomy), $context, self::SUMMARY, self::CALL);
    }

    public function testInAskModeAutoRunsTheChangeWithoutAsking(): void
    {
        self::assertNull($this->check('auto', 'ask'));
        self::assertSame([], $this->ui->requests);
    }

    public function testInAskModeAskWaitsForTheUserAsBefore(): void
    {
        $this->ui->decisions = [Decision::Deny];

        self::assertSame('the user denied this action', $this->check('ask', 'ask'));
        self::assertCount(1, $this->ui->requests);
    }

    public function testPlanAndDontAskRefuseEvenWithAutoAndNobodyIsAsked(): void
    {
        self::assertSame('plan mode allows only read tools, so skill_manage is refused', $this->check('auto', 'plan'));
        self::assertSame('dont_ask mode refuses skill_manage without asking; it needs approval', $this->check('auto', 'dont_ask'));
        self::assertSame([], $this->ui->requests);
    }

    public function testAReadOnlyAgentIsRefusedEvenWithAuto(): void
    {
        self::assertSame("agent 'build' is read-only and may not run skill_manage", $this->check('auto', 'ask', [], [], ['write']));
        self::assertSame([], $this->ui->requests);
    }

    public function testADenyRuleStillWinsOverAuto(): void
    {
        self::assertSame("refused by the rule 'skill_manage:*' in rules.deny", $this->check('auto', 'ask', [], ['skill_manage:*']));
    }

    public function testAnAskRuleOfTheUserStillAsksEvenWithAuto(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];

        self::assertNull($this->check('auto', 'ask', ['skill_manage:*']));
        self::assertCount(1, $this->ui->requests, 'the rule of the user asked');
    }

    public function testAcceptEditsRunsTheChangeWithoutAsking(): void
    {
        self::assertNull($this->check('ask', 'accept_edits'));
        self::assertSame([], $this->ui->requests);
    }
}
