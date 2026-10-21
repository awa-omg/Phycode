<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Deploy;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\RunSetup;
use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\Deployments;
use Phycode\Exec\ProcessRunner;
use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Security\Rules;
use Phycode\Ssh\SshHosts;
use Phycode\Support\PhycodeException;
use Phycode\Support\Vault;
use Phycode\Tests\Support\FakeCli;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\DeployInfoTool;
use Phycode\Tools\DeployTool;
use Phycode\Tools\ToolContext;
use Phycode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class DeployToolsTest extends TestCase
{
    private string $root;
    private Vault $vault;
    private FakeCli $cli;
    private Deployments $deployments;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('deploy-tools'));
        $this->vault = new Vault(Kit::folder('deploy-tools-vault'));
        $this->vault->storeDeploySecret('web', 'VERCEL_TOKEN', 'tok_abc_123456');
        $this->cli = FakeCli::install(['vercel' => 'echo https://web-9.vercel.app', 'modal' => 'echo modal'], ['VERCEL_TOKEN']);
        $targets = [];
        foreach ([
            'web' => ['kind' => 'vercel', 'secrets' => ['VERCEL_TOKEN']],
            'stage' => ['kind' => 'vercel', 'environment' => 'preview', 'confirm' => 'rules'],
            'cautious' => ['kind' => 'vercel', 'environment' => 'preview'],
            'gpu' => ['kind' => 'modal', 'app' => 'app.py', 'environment' => 'staging', 'confirm' => 'rules'],
            'nokey' => ['kind' => 'vercel', 'secrets' => ['NOT_SET_ANYWHERE']],
        ] as $name => $raw) {
            $targets[$name] = DeployTarget::fromArray($name, $raw);
        }
        $this->deployments = new Deployments($targets, $this->vault, new ProcessRunner(), new SshHosts([], new ProcessRunner()), $this->root, null, 1, 0);
    }

    protected function tearDown(): void
    {
        $this->cli->end();
    }

    private function context(RecordingUi $ui, string $mode = 'yolo'): ToolContext
    {
        return Kit::context($this->root, Kit::agent(), Kit::policy($mode), $ui);
    }

    private function confirmations(RecordingUi $ui): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
    }

    public function testTheSchemasHoldTheTargetsAndOnlyTheActionsOfEachTool(): void
    {
        $deploy = new DeployTool($this->deployments);
        $info = new DeployInfoTool($this->deployments);

        self::assertSame(['web', 'stage', 'cautious', 'gpu', 'nokey'], $deploy->parameters()['properties']['target']['enum']);
        self::assertSame(['dry_run', 'apply', 'rollback'], $deploy->parameters()['properties']['action']['enum']);
        self::assertSame(['plan', 'status', 'logs'], $info->parameters()['properties']['action']['enum']);
        self::assertStringContainsString('Targets: web (vercel, production), stage (vercel, preview)', $deploy->description());
        self::assertSame(\Phycode\Tools\ToolCategory::Exec, $deploy->category());
        self::assertSame(\Phycode\Tools\ToolCategory::Read, $info->category());
    }

    public function testTheQuestionToThePersonShowsTheTargetTheEnvironmentAndTheCommand(): void
    {
        $deploy = new DeployTool($this->deployments);

        self::assertSame('deploy web: apply [production] vercel deploy --prod --yes --non-interactive', $deploy->summarize(['target' => 'web', 'action' => 'apply']));
        self::assertSame('deploy web: rollback [production] vercel rollback dpl_7 --non-interactive', $deploy->summarize(['target' => 'web', 'action' => 'rollback', 'version' => 'dpl_7']));
        self::assertSame('deploy ?: ?', $deploy->summarize([]));
    }

    public function testAProductionTargetAsksInEveryModeAndAStagingTargetWithRulesFollowsThem(): void
    {
        $deploy = new DeployTool($this->deployments);

        foreach (['ask', 'accept_edits', 'yolo'] as $mode) {
            $ui = new RecordingUi();
            $ui->decisions = [Decision::AllowOnce];
            $args = ['target' => 'web', 'action' => 'apply'];

            self::assertNull((new Permissions($ui, new Rules(['allow' => ['*']])))->check($deploy, $this->context($ui, $mode), $deploy->summarize($args), $args), $mode);
            self::assertCount(1, $this->confirmations($ui), "{$mode}: production is asked even with an allow rule");
        }

        $ui = new RecordingUi();
        $args = ['target' => 'stage', 'action' => 'apply'];
        self::assertNull((new Permissions($ui))->check($deploy, $this->context($ui, 'yolo'), $deploy->summarize($args), $args), 'yolo runs a staging target with confirm rules');
        self::assertSame([], $this->confirmations($ui));

        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $permissions = new Permissions($ui, new Rules(['allow' => ['deploy:stage: apply*']]));
        self::assertNull($permissions->check($deploy, $this->context($ui, 'ask'), $deploy->summarize($args), $args), 'an allow rule runs it in ask mode');
        $other = ['target' => 'stage', 'action' => 'rollback'];
        self::assertSame('the user denied this action', $permissions->check($deploy, $this->context($ui, 'ask'), $deploy->summarize($other), $other));

        $args = ['target' => 'cautious', 'action' => 'apply'];
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        (new Permissions($ui))->check($deploy, $this->context($ui, 'yolo'), $deploy->summarize($args), $args);
        self::assertCount(1, $this->confirmations($ui), 'a preview target keeps the default confirm: always');
    }

    public function testADenyRuleStopsATargetAndNobodyIsAskedWhereNobodyCanAnswer(): void
    {
        $deploy = new DeployTool($this->deployments);
        $args = ['target' => 'web', 'action' => 'rollback'];

        $ui = new RecordingUi();
        self::assertStringContainsString('rules.deny', (string) (new Permissions($ui, new Rules(['deny' => ['deploy:web: rollback*']])))->check($deploy, $this->context($ui, 'yolo'), $deploy->summarize($args), $args));

        $ui = new RecordingUi();
        $ui->terminal = false;
        self::assertStringContainsString('no terminal', (string) (new Permissions($ui))->check($deploy, $this->context($ui, 'yolo'), $deploy->summarize($args), $args));
        $ui = new RecordingUi();
        self::assertStringContainsString('dont_ask', (string) (new Permissions($ui))->check($deploy, $this->context($ui, 'dont_ask'), $deploy->summarize($args), $args));
        self::assertSame([], $this->confirmations($ui));
    }

    public function testReadingThePlanTheStatusAndTheLogsNeedsNoApproval(): void
    {
        $info = new DeployInfoTool($this->deployments);
        $ui = new RecordingUi();
        $args = ['target' => 'web', 'action' => 'status'];

        self::assertNull((new Permissions($ui))->check($info, $this->context($ui, 'ask'), $info->summarize($args), $args));
        self::assertNull((new Permissions($ui))->check($info, $this->context($ui, 'plan'), $info->summarize($args), $args));
        self::assertSame([], $this->confirmations($ui));
        self::assertFalse($info->isAvailable(Kit::policy('ask', null, ['exec'])), 'a read-only agent does not get it');
        self::assertTrue($info->isAvailable(Kit::policy('ask')));
    }

    public function testACallThatCannotRunIsRefusedBeforeThePersonIsAsked(): void
    {
        $deploy = new DeployTool($this->deployments);
        $context = $this->context(new RecordingUi());

        foreach ([
            [['target' => 'nope', 'action' => 'apply'], "no deploy target named 'nope'"],
            [['target' => 'web', 'action' => 'status'], 'deploy_info reads'],
            [['target' => 'web', 'action' => 'apply', 'version' => 'v1'], 'rollback only'],
            [['target' => 'web', 'action' => 'rollback', 'version' => '--token=x'], 'version'],
            [['target' => 'gpu', 'action' => 'dry_run'], 'modal has no dry run'],
            [['target' => 'nokey', 'action' => 'apply'], 'lacks NOT_SET_ANYWHERE'],
            [['action' => 'apply'], "missing required argument 'target'"],
        ] as [$args, $message]) {
            try {
                $deploy->validate($args, $context);
                self::fail('accepted ' . json_encode($args));
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $deploy->validate(['target' => 'web', 'action' => 'apply'], $context);
        $deploy->validate(['target' => 'web', 'action' => 'rollback', 'version' => 'dpl_1'], $context);
    }

    public function testApplyRunsAndTheReportCarriesTheUrlButNotTheToken(): void
    {
        $deploy = new DeployTool($this->deployments);
        $ui = new RecordingUi();

        $result = $deploy->execute(['target' => 'web', 'action' => 'apply'], $this->context($ui));

        self::assertTrue($result->ok, $result->content);
        self::assertSame('https://web-9.vercel.app', $result->meta['url']);
        self::assertStringContainsString('deploy web: apply ok', $result->content);
        self::assertStringContainsString('url: https://web-9.vercel.app', $result->content);
        self::assertStringNotContainsString('tok_abc_123456', $result->content);
        self::assertSame(['tok_abc_123456'], $this->cli->values('VERCEL_TOKEN'));
        self::assertContains('deploy web: 1/1 deploy', array_map(static fn (array $event): string => (string) ($event[2] ?? ''), array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice'))));
    }

    public function testAFailedDeployIsAFailedToolResult(): void
    {
        $this->cli->end();
        $this->cli = FakeCli::install(['vercel' => 'echo "Error: build failed" >&2; exit 1']);
        $deploy = new DeployTool($this->deployments);

        $result = $deploy->execute(['target' => 'web', 'action' => 'apply'], $this->context(new RecordingUi()));

        self::assertFalse($result->ok);
        self::assertStringContainsString('deploy web: apply FAILED', $result->content);
        self::assertStringContainsString('Error: build failed', $result->content);
    }

    public function testThePlanAsksForNothingAndShowsWhatIsMissing(): void
    {
        $info = new DeployInfoTool($this->deployments);
        $context = $this->context(new RecordingUi());

        $plan = $info->execute(['target' => 'nokey', 'action' => 'plan'], $context);

        self::assertTrue($plan->ok);
        self::assertStringContainsString("deploy nokey: plan for apply (vercel, production)\n1. vercel deploy --prod --yes --non-interactive", $plan->content);
        self::assertStringContainsString('missing: NOT_SET_ANYWHERE', $plan->content);
        self::assertStringContainsString('apply asks the person every time.', $plan->content);
        self::assertStringContainsString('apply follows the permission mode and rules.', $info->execute(['target' => 'stage', 'action' => 'plan'], $context)->content);
        self::assertSame([], $this->cli->calls('vercel'), 'a plan runs nothing');
    }

    public function testStatusAndLogsRunTheReadingCommands(): void
    {
        $info = new DeployInfoTool($this->deployments);
        $context = $this->context(new RecordingUi());

        $status = $info->execute(['target' => 'web', 'action' => 'status'], $context);
        $logs = $info->execute(['target' => 'web', 'action' => 'logs', 'lines' => 25], $context);

        self::assertTrue($status->ok, $status->content);
        self::assertStringContainsString('https://web-9.vercel.app', $status->content);
        self::assertSame([['list', '--non-interactive'], ['logs', '--since', '30m', '--limit', '25', '--environment', 'production', '--non-interactive']], $this->cli->calls('vercel'));
        self::assertTrue($logs->ok);

        $missing = $info->execute(['target' => 'nokey', 'action' => 'status'], $context);
        self::assertFalse($missing->ok);
        self::assertStringContainsString('missing: NOT_SET_ANYWHERE', $missing->content);
        try {
            $info->validate(['target' => 'web', 'action' => 'apply'], $context);
            self::fail('deploy_info applied');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('plan, status or logs', $error->getMessage());
        }
    }

    public function testTheModelPlansThenDeploysThroughTheAgentLoopAndThePersonApproves(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new DeployInfoTool($this->deployments));
        $registry->register(new DeployTool($this->deployments));
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowOnce];
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('ask'), $ui);
        $context = new ToolContext(
            $context->workspace,
            new Permissions($ui),
            $context->processes,
            $ui,
            $context->transcript,
            $context->limits,
            $context->agent,
            $context->policy,
            $context->cancel,
            'test',
            null,
            $context->changes,
            $context->sandbox,
        );
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('ship it');
        $client = new ScriptedClient([
            ScriptedClient::call('deploy_info', ['target' => 'web', 'action' => 'plan']),
            ScriptedClient::call('deploy', ['target' => 'web', 'action' => 'apply']),
            ScriptedClient::text('deployed'),
        ]);

        $outcome = (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 6, false));

        self::assertSame('deployed', $outcome->text);
        self::assertSame(['deploy_info', 'deploy'], $ui->startedTools());
        self::assertCount(1, $this->confirmations($ui), 'the plan is free and the deploy is asked');
        $replies = array_values(array_filter($client->requests[2]['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        self::assertStringContainsString('url: https://web-9.vercel.app', $replies[1]['content']);
        self::assertSame(1, count($this->cli->calls('vercel')));
    }

    public function testWhenThePersonDeniesNothingRuns(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new DeployTool($this->deployments));
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $context = new ToolContext(
            Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), $ui)->workspace,
            new Permissions($ui),
            new ProcessRunner(),
            $ui,
            \Phycode\Session\Transcript::memory(),
            new \Phycode\Config\Limits(),
            Kit::agent(),
            Kit::policy('yolo'),
            new \Phycode\Support\Cancellation(),
            'test',
            null,
            new \Phycode\Tools\ChangeLog(),
            new ProcessRunner(),
        );
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('ship it');
        $client = new ScriptedClient([ScriptedClient::call('deploy', ['target' => 'web', 'action' => 'apply']), ScriptedClient::text('stopped')]);

        (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 4, false));

        self::assertSame([], $this->cli->calls('vercel'));
        $replies = array_values(array_filter($client->requests[1]['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        self::assertStringContainsString('permission denied: the user denied this action', $replies[0]['content']);
    }
}
