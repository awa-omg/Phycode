<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\LlmException;
use Phycode\Llm\LlmResponse;
use Phycode\Llm\Pricing;
use Phycode\Llm\UsageMeter;
use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\ScriptedProvider;
use Phycode\Tools\OracleTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class OracleToolTest extends TestCase
{
    private string $root;
    private RecordingUi $ui;
    private UsageMeter $usage;

    protected function setUp(): void
    {
        $this->root = (string) realpath(Kit::folder('oracle-project'));
        $this->ui = new RecordingUi();
        $this->usage = new UsageMeter(static fn (string $profile, ?string $model): string => $model ?? $profile);
    }

    private function tool(array $replies, ?array $settings = ['profile' => 'strong', 'max_calls' => 3, 'approval' => 'ask'], ?ScriptedClient &$client = null): OracleTool
    {
        $client = new ScriptedClient($replies);
        $provider = new ScriptedProvider(['strong' => $client]);

        return new OracleTool(fn () => $settings, fn () => $provider, fn () => $this->usage);
    }

    private function context(string $mode = 'ask', array $secrets = [], array $denied = []): ToolContext
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy($mode, null, $denied), $this->ui);

        return new ToolContext(
            $context->workspace, new Permissions($this->ui), $context->processes, $this->ui, $context->transcript, $context->limits, $context->agent,
            $context->policy, $context->cancel, 'local', null, $context->changes, $context->sandbox, null, $secrets,
        );
    }

    public function testItAsksTheOracleProfileOnceWithoutToolsAndReturnsTheAnswer(): void
    {
        $tool = $this->tool([ScriptedClient::text('Use a lock file.')], client: $client);
        Kit::write($this->root . '/src/a.php', "<?php\necho 1;\n");

        $result = $tool->execute(['question' => 'How do I stop two runs?', 'context' => 'Two workers start together.', 'files' => ['src/a.php']], $this->context());

        self::assertTrue($result->ok, $result->content);
        self::assertSame('Use a lock file.', $result->content);
        self::assertCount(1, $client->requests);
        self::assertSame([], $client->requests[0]['tools']);
        [$system, $user] = $client->requests[0]['messages'];
        self::assertSame('system', $system['role']);
        self::assertStringContainsString('You are the oracle', $system['content']);
        self::assertStringContainsString("Question:\nHow do I stop two runs?", $user['content']);
        self::assertStringContainsString("Context from the agent:\nTwo workers start together.", $user['content']);
        self::assertStringContainsString("File src/a.php:\n<?php\necho 1;", $user['content']);
        self::assertSame(['profile' => 'strong', 'calls' => 1, 'max_calls' => 3, 'files' => ['src/a.php']], $result->meta);
    }

    public function testItsTokensCountUnderTheOraclesOwnProfile(): void
    {
        $tool = $this->tool([new LlmResponse('Yes.', '', [], FinishReason::STOP, new Usage(120, 30))]);

        $tool->execute(['question' => 'Is it safe?'], $this->context());

        $rows = $this->usage->rows(new Pricing([]));
        self::assertCount(1, $rows);
        self::assertSame('strong', $rows[0]['profile']);
        self::assertSame([1, 120, 30], [$rows[0]['requests'], $rows[0]['prompt'], $rows[0]['completion']]);
    }

    public function testAFileThatUsuallyHoldsSecretsIsNeverSentEvenWhenApproved(): void
    {
        $tool = $this->tool([ScriptedClient::text('x')], client: $client);
        Kit::write($this->root . '/.env', 'DB_PASSWORD=hunter2hunter2');
        Kit::write($this->root . '/deploy/id_rsa', 'PRIVATE');
        symlink($this->root . '/.env', $this->root . '/notes.txt');

        foreach (['.env', 'deploy/id_rsa', 'notes.txt'] as $path) {
            try {
                $tool->validate(['question' => 'q', 'files' => [$path]], $this->context());
                self::fail("{$path} was accepted");
            } catch (PhycodeException $error) {
                self::assertStringContainsString('never sent to the oracle', $error->getMessage(), $path);
            }
        }
        self::assertSame([], $client->requests);
    }

    public function testFilesOutsideTheProjectBinaryAndTooManyAreRefused(): void
    {
        $tool = $this->tool([]);
        Kit::write($this->root . '/logo.bin', "\0\1\2\3");
        $cases = [
            [['../outside.txt'], 'outside'],
            [['missing.php'], 'not a file'],
            [['logo.bin'], 'binary file'],
            [array_fill(0, OracleTool::MAX_FILES + 1, 'a.php'), 'at most'],
            [[7], 'only paths'],
        ];

        foreach ($cases as [$files, $why]) {
            try {
                $tool->validate(['question' => 'q', 'files' => $files], $this->context());
                self::fail('accepted ' . json_encode($files));
            } catch (PhycodeException $error) {
                self::assertStringContainsString($why, $error->getMessage());
            }
        }
    }

    public function testALongFileIsClippedAndTheTotalIsCapped(): void
    {
        $tool = $this->tool([ScriptedClient::text('ok')], client: $client);
        Kit::write($this->root . '/big.txt', str_repeat('a', OracleTool::MAX_FILE_BYTES + 500));

        $result = $tool->execute(['question' => 'q', 'files' => ['big.txt']], $this->context());

        self::assertTrue($result->ok);
        self::assertStringContainsString('[clipped: the first ' . OracleTool::MAX_FILE_BYTES . ' of ' . (OracleTool::MAX_FILE_BYTES + 500) . ' bytes]', $client->requests[0]['messages'][1]['content']);

        $names = [];
        for ($i = 0; $i < 4; $i++) {
            Kit::write($this->root . "/part{$i}.txt", str_repeat('b', OracleTool::MAX_FILE_BYTES));
            $names[] = "part{$i}.txt";
        }
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('larger than 300 KB together');
        $tool->validate(['question' => 'q', 'files' => $names], $this->context());
    }

    public function testTheValuesOfTheKeysAreTakenOutOfEverythingThatIsSent(): void
    {
        $tool = $this->tool([ScriptedClient::text('The key sk-live-0123456789abcdef is leaked, keep SECRETVALUE-1234 out.')], client: $client);
        Kit::write($this->root . '/c.php', "<?php\n\$k = 'SECRETVALUE-1234';\n");

        $result = $tool->execute(
            ['question' => 'Why does SECRETVALUE-1234 fail?', 'context' => 'curl -H "Authorization: Bearer abcdef123456"', 'files' => ['c.php']],
            $this->context('ask', ['SECRETVALUE-1234']),
        );

        $sent = $client->requests[0]['messages'][1]['content'];
        self::assertStringNotContainsString('SECRETVALUE-1234', $sent);
        self::assertStringNotContainsString('abcdef123456', $sent);
        self::assertStringContainsString("\$k = '[redacted]';", $sent);
        self::assertStringNotContainsString('SECRETVALUE-1234', $result->content, 'what the oracle sends back is cleaned too');
    }

    public function testTheCallsOfASessionAreCappedAndTheLimitIsSaid(): void
    {
        $tool = $this->tool([ScriptedClient::text('one'), ScriptedClient::text('two')], ['profile' => 'strong', 'max_calls' => 2, 'approval' => 'ask'], $client);

        self::assertTrue($tool->execute(['question' => 'q1'], $this->context())->ok);
        self::assertTrue($tool->execute(['question' => 'q2'], $this->context())->ok);
        $third = $tool->execute(['question' => 'q3'], $this->context());

        self::assertFalse($third->ok);
        self::assertStringContainsString('2 times in this session', $third->content);
        self::assertStringContainsString('oracle.max_calls', $third->content);
        self::assertCount(2, $client->requests);
    }

    public function testAFailureOfTheServerOrAnEmptyAnswerIsAFailedResultWithoutTheKey(): void
    {
        $tool = $this->tool([new LlmException('HTTP 500 for key sk-abcdefgh12345678'), ScriptedClient::text('   ')]);

        $failed = $tool->execute(['question' => 'q'], $this->context());
        $empty = $tool->execute(['question' => 'q'], $this->context());

        self::assertFalse($failed->ok);
        self::assertStringContainsString('the oracle (strong) could not answer: HTTP 500 for key [redacted]', $failed->content);
        self::assertFalse($empty->ok);
        self::assertStringContainsString('sent no answer', $empty->content);
    }

    public function testWithoutAnOracleSectionTheCallIsRefusedBeforeAnyApproval(): void
    {
        $tool = $this->tool([], null);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('no oracle is set up');
        $tool->validate(['question' => 'q'], $this->context());
    }

    public function testItAsksFirstByDefaultAndShowsWhereTheCallGoesAndWhichFilesLeave(): void
    {
        $tool = $this->tool([]);
        $this->ui->decisions = [Decision::AllowOnce];
        $args = ['question' => 'Is the migration safe?', 'files' => ['db/schema.sql']];

        $refusal = (new Permissions($this->ui))->check($tool, $this->context(), $tool->summarize($args), $args);

        self::assertNull($refusal);
        self::assertSame(ToolCategory::Exec, $this->ui->requests[0]->category);
        self::assertSame('oracle (strong): Is the migration safe? [files: db/schema.sql]', $this->ui->requests[0]->summary);
    }

    public function testTheConfigCanLetItRunWithoutAskingYetPlanAndReadOnlyAgentsStillCannot(): void
    {
        $tool = $this->tool([], ['profile' => 'strong', 'max_calls' => 3, 'approval' => 'allow']);
        $permissions = new Permissions($this->ui);

        self::assertNull($permissions->check($tool, $this->context('ask'), 'oracle (strong): q', []));
        self::assertSame([], $this->ui->requests, 'allow runs it without asking');
        self::assertStringContainsString('plan mode allows only read tools', (string) $permissions->check($tool, $this->context('plan'), 'oracle (strong): q', []));
        self::assertStringContainsString('read-only', (string) $permissions->check($tool, $this->context('ask', [], ['exec']), 'oracle (strong): q', []));
    }
}
