<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\AgentRunner;
use Phycode\Agent\Conversation;
use Phycode\Agent\RunSetup;
use Phycode\Exec\BackgroundJobs;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tools\BashTool;
use Phycode\Tools\JobTool;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class BackgroundBashTest extends TestCase
{
    private string $root;
    private BackgroundJobs $jobs;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('bg-bash'));
        $this->jobs = new BackgroundJobs(Kit::folder('bg-logs') . '/out');
    }

    protected function tearDown(): void
    {
        $this->jobs->stopAll();
    }

    public function testBashStartsAJobAndTheJobToolReadsItAndWaits(): void
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
        $bash = new BashTool($this->jobs);
        $job = new JobTool($this->jobs);

        $started = $bash->execute(['command' => 'echo ready; sleep 1; echo done', 'background' => true], $context);
        self::assertTrue($started->ok);
        self::assertStringStartsWith('started job 1.', $started->content);

        $first = $job->execute(['wait' => 5], $context);
        self::assertTrue($first->ok);
        self::assertStringContainsString("job 1: running", $first->content);
        self::assertStringContainsString('ready', $first->content);

        $second = $job->execute(['id' => 1, 'wait' => 10], $context);
        self::assertStringContainsString('done', $second->content);
        $last = $job->execute(['id' => 1, 'wait' => 5], $context);
        self::assertStringContainsString('exited with code 0', $last->content);
        self::assertStringContainsString('[no new output]', $last->content);
    }

    public function testTheJobToolListsAndStops(): void
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
        $bash = new BashTool($this->jobs);
        $job = new JobTool($this->jobs);

        self::assertSame('no jobs', $job->execute(['action' => 'list'], $context)->content);
        $bash->execute(['command' => 'sleep 34', 'background' => true], $context);
        $bash->execute(['command' => 'sleep 35', 'background' => true], $context);

        $list = $job->execute(['action' => 'list'], $context)->content;
        self::assertStringContainsString('job 1: running', $list);
        self::assertStringContainsString('job 2: running', $list);
        self::assertFalse($job->execute(['action' => 'stop'], $context)->ok, 'two jobs: the id is needed');

        $stopped = $job->execute(['action' => 'stop', 'id' => 1], $context);
        self::assertTrue($stopped->ok);
        self::assertStringContainsString('stopped: by the agent', $stopped->content);
        self::assertStringContainsString('job 2: running', $job->execute(['action' => 'list'], $context)->content);
    }

    public function testMistakesAreAnsweredInPlainWords(): void
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), new RecordingUi());
        $job = new JobTool($this->jobs);

        self::assertSame('no job is running; start one with bash and background=true', $job->execute([], $context)->content);
        self::assertSame('no job 7; action=list shows the jobs', $job->execute(['id' => 7], $context)->content);
        self::assertSame("unknown action 'kill'; use output, list or stop", $job->execute(['action' => 'kill'], $context)->content);
    }

    public function testBashWithoutAJobRegistryHasNoBackgroundOption(): void
    {
        $plain = new BashTool();
        $withJobs = new BashTool($this->jobs);

        self::assertArrayNotHasKey('background', $plain->parameters()['properties']);
        self::assertArrayHasKey('background', $withJobs->parameters()['properties']);
        self::assertStringNotContainsString('background', $plain->description());
    }

    public function testTheJobToolFollowsBash(): void
    {
        $registry = static fn (array $disabled): ToolRegistry => (function () use ($disabled): ToolRegistry {
            $registry = new ToolRegistry($disabled);
            $jobs = new BackgroundJobs(Kit::folder('bg-reg') . '/out');
            foreach ([new ReadTool(), new BashTool($jobs), new JobTool($jobs)] as $tool) {
                $registry->register($tool);
            }

            return $registry;
        })();

        self::assertSame(['read', 'bash', 'job'], $registry([])->available(Kit::policy('yolo')));
        self::assertSame(['read'], $registry(['bash'])->available(Kit::policy('yolo')), 'bash off: no job tool');
        self::assertSame(['read', 'bash', 'job'], $registry([])->available(Kit::policy('yolo', ['read', 'bash'])), 'an agent that has bash has job');
        self::assertSame(['read'], $registry([])->available(Kit::policy('yolo', ['read'])), 'an agent without bash has no job');
        self::assertSame(['read'], $registry([])->available(Kit::policy('ask', null, ['exec'])), 'a read-only run has neither');
    }

    public function testAModelCanStartAServerAndStopItThroughTheAgentLoop(): void
    {
        $registry = new ToolRegistry();
        foreach ([new BashTool($this->jobs), new JobTool($this->jobs)] as $tool) {
            $registry->register($tool);
        }
        $ui = new RecordingUi();
        $context = Kit::context($this->root, Kit::agent(), Kit::policy('yolo'), $ui);
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('task');
        $client = new ScriptedClient([
            ScriptedClient::call('bash', ['command' => 'echo up; sleep 36', 'background' => true]),
            ScriptedClient::call('job', ['wait' => 5]),
            ScriptedClient::call('job', ['action' => 'stop']),
            ScriptedClient::text('done'),
        ]);

        $outcome = (new AgentRunner())->run($conversation, new RunSetup($client, 'native', $registry, $context, 8, false));

        self::assertSame('done', $outcome->text);
        self::assertSame(['bash', 'job', 'job'], $ui->startedTools());
        self::assertFalse($this->jobs->get(1)?->running());
        $toolReplies = array_values(array_filter($client->requests[3]['messages'], static fn (array $m): bool => ($m['role'] ?? '') === 'tool'));
        self::assertStringContainsString('up', $toolReplies[1]['content']);
    }
}
