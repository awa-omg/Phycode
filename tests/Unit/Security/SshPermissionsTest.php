<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Exec\ProcessRunner;
use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Security\Rules;
use Phycode\Ssh\SshHost;
use Phycode\Ssh\SshHosts;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\SshCopyTool;
use Phycode\Tools\SshTool;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class SshPermissionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Kit::folder('ssh-permissions');
    }

    private function tool(string $confirm): SshTool
    {
        return new SshTool(new SshHosts([
            'prod' => SshHost::fromArray('prod', ['destination' => 'deploy@h', 'confirm' => $confirm]),
            'stage' => SshHost::fromArray('stage', ['destination' => 'deploy@s', 'confirm' => 'rules']),
        ], new ProcessRunner()));
    }

    private function context(RecordingUi $ui, string $mode): ToolContext
    {
        return Kit::context($this->root, Kit::agent(), Kit::policy($mode), $ui);
    }

    private function confirmations(RecordingUi $ui): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
    }

    public function testAHostWithConfirmAlwaysAsksInEveryModeIncludingYolo(): void
    {
        $tool = $this->tool('always');
        $args = ['host' => 'prod', 'command' => 'ls'];

        foreach (['ask', 'accept_edits', 'yolo'] as $mode) {
            $ui = new RecordingUi();
            $ui->decisions = [Decision::AllowOnce];

            $reason = (new Permissions($ui))->check($tool, $this->context($ui, $mode), $tool->summarize($args), $args);

            self::assertNull($reason, $mode);
            self::assertCount(1, $this->confirmations($ui), "{$mode}: the call is asked");
        }
    }

    public function testAnAllowRuleDoesNotSkipTheQuestionOfAHostWithConfirmAlways(): void
    {
        $tool = $this->tool('always');
        $args = ['host' => 'prod', 'command' => 'ls -la'];
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];

        $reason = (new Permissions($ui, new Rules(['allow' => ['ssh:*']])))->check($tool, $this->context($ui, 'ask'), $tool->summarize($args), $args);

        self::assertSame('the user denied this action', $reason);
        self::assertCount(1, $this->confirmations($ui));
    }

    public function testAllowForTheSessionDoesNotOpenAHostWithConfirmAlways(): void
    {
        $tool = $this->tool('always');
        $args = ['host' => 'prod', 'command' => 'ls'];
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowSession, Decision::AllowOnce];
        $permissions = new Permissions($ui);
        $context = $this->context($ui, 'ask');

        self::assertNull($permissions->check($tool, $context, $tool->summarize($args), $args));
        self::assertNull($permissions->check($tool, $context, $tool->summarize($args), $args));
        self::assertCount(2, $this->confirmations($ui), 'the second call is asked too');

        $other = ['host' => 'stage', 'command' => 'ls'];
        $ui->decisions = [Decision::AllowOnce];
        self::assertNull($permissions->check($tool, $context, $tool->summarize($other), $other));
        self::assertCount(3, $this->confirmations($ui), 'and the session allow did not open the other host either');
    }

    public function testAHostWithConfirmAlwaysIsRefusedWhereNobodyCanAnswer(): void
    {
        $tool = $this->tool('always');
        $args = ['host' => 'prod', 'command' => 'ls'];

        $ui = new RecordingUi();
        $ui->terminal = false;
        self::assertStringContainsString('no terminal', (string) (new Permissions($ui))->check($tool, $this->context($ui, 'yolo'), $tool->summarize($args), $args));

        $ui = new RecordingUi();
        self::assertStringContainsString('dont_ask', (string) (new Permissions($ui))->check($tool, $this->context($ui, 'dont_ask'), $tool->summarize($args), $args));
        self::assertSame([], $this->confirmations($ui));
    }

    public function testAHostWithConfirmRulesFollowsTheModesAndTheRules(): void
    {
        $tool = $this->tool('rules');
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $permissions = new Permissions($ui, new Rules(['allow' => ['ssh:stage: ls*']]));
        $context = $this->context($ui, 'ask');

        $ls = ['host' => 'stage', 'command' => 'ls -la'];
        self::assertNull($permissions->check($tool, $context, $tool->summarize($ls), $ls), 'allowed by the rule');
        $chained = ['host' => 'stage', 'command' => 'ls && rm -rf build'];
        self::assertSame('the user denied this action', $permissions->check($tool, $context, $tool->summarize($chained), $chained), 'a chained command is not allowed by it');
        self::assertCount(1, $this->confirmations($ui));

        $yolo = $this->context($ui, 'yolo');
        self::assertNull($permissions->check($tool, $yolo, $tool->summarize($chained), $chained), 'yolo runs it');
    }

    public function testADenyRuleForAHostStopsItsCommands(): void
    {
        $tool = $this->tool('rules');
        $ui = new RecordingUi();
        $permissions = new Permissions($ui, new Rules(['deny' => ['ssh:stage: systemctl*', 'ssh:prod: *']]));
        $context = $this->context($ui, 'yolo');

        $restart = ['host' => 'stage', 'command' => 'cd /srv && systemctl restart app'];
        self::assertStringContainsString("rules.deny", (string) $permissions->check($tool, $context, $tool->summarize($restart), $restart));
        $anything = ['host' => 'prod', 'command' => 'ls'];
        self::assertStringContainsString("'ssh:prod: *'", (string) $permissions->check($tool, $context, $tool->summarize($anything), $anything));
        $fine = ['host' => 'stage', 'command' => 'ls'];
        self::assertNull($permissions->check($tool, $context, $tool->summarize($fine), $fine));
    }

    public function testACommandThatDestroysTheHostIsRefusedEvenInYoloAndEvenWithConfirmRules(): void
    {
        $tool = $this->tool('rules');
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $args = ['host' => 'stage', 'command' => 'cd /srv && sudo rm -rf /'];

        foreach (['yolo', 'ask'] as $mode) {
            $reason = (new Permissions($ui, new Rules(['allow' => ['*']])))->check($tool, $this->context($ui, $mode), $tool->summarize($args), $args);

            self::assertStringContainsString('never run', (string) $reason, $mode);
        }
        self::assertSame([], $this->confirmations($ui));
    }

    public function testTheHardLineAppliesOnAHostEvenWhenBashRunsInAContainer(): void
    {
        \Phycode\Tests\Support\FakeRuntime::install();
        $tool = $this->tool('rules');
        $ui = new RecordingUi();
        $container = new \Phycode\Exec\ContainerRunner(new ProcessRunner(), 'podman', 'debian:trixie-slim', 'none', realpath($this->root));
        $context = $this->context($ui, 'yolo')->forAgent(Kit::agent(), Kit::policy('yolo'), new \Phycode\Support\Cancellation(), 'test', $container);
        $args = ['host' => 'stage', 'command' => 'rm -rf /'];

        self::assertNotNull((new Permissions($ui))->check($tool, $context, $tool->summarize($args), $args));
    }

    public function testTheCopyToolAsksTheSameWay(): void
    {
        $hosts = new SshHosts(['prod' => SshHost::fromArray('prod', ['destination' => 'h'])], new ProcessRunner());
        $tool = new SshCopyTool($hosts);
        $args = ['host' => 'prod', 'direction' => 'put', 'local' => 'a.txt', 'remote' => '/srv/a.txt'];
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];

        self::assertTrue($tool->forcesApproval($args));
        self::assertSame('ssh_copy prod: put a.txt -> /srv/a.txt', $tool->summarize($args));
        self::assertNull((new Permissions($ui))->check($tool, $this->context($ui, 'yolo'), $tool->summarize($args), $args));
        self::assertCount(1, $this->confirmations($ui));
    }
}
