<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Hooks;

use Phycode\Agent\Hooks;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    private const SCRIPT = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/received.json', stream_get_contents(STDIN));
switch ($argv[1] ?? 'ok') {
    case 'block':
        fwrite(STDERR, "no writes to .git\n");
        exit(2);
    case 'block-silent':
        exit(2);
    case 'fail':
        fwrite(STDERR, "broken hook");
        exit(1);
    case 'sleep':
        sleep(3);
        exit(0);
    default:
        exit(0);
}
PHP;

    private string $folder;
    private string $script;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('hooks');
        $this->script = $this->folder . '/hook.php';
        file_put_contents($this->script, self::SCRIPT);
    }

    private function command(string $mode): string
    {
        return "'" . PHP_BINARY . "' '{$this->script}' {$mode}";
    }

    private function hooks(string $event, array $entries, int $timeout = Hooks::TIMEOUT_SECONDS): Hooks
    {
        return new Hooks([$event => $entries], $timeout);
    }

    public static function commands(): iterable
    {
        yield 'plain words' => ['git status', ['git', 'status']];
        yield 'extra spaces and tabs' => ["  git \t status  ", ['git', 'status']];
        yield 'single quotes keep spaces and dollars' => ["echo 'a b' '\$HOME'", ['echo', 'a b', '$HOME']];
        yield 'double quotes with escapes' => ['echo "say \"hi\" \\\\ ok"', ['echo', 'say "hi" \\ ok']];
        yield 'a backslash outside quotes' => ['echo a\ b', ['echo', 'a b']];
        yield 'an empty quoted word' => ["cmd '' end", ['cmd', '', 'end']];
        yield 'shell syntax is plain text' => ['tool a|b; c && d > out *', ['tool', 'a|b;', 'c', '&&', 'd', '>', 'out', '*']];
    }

    #[DataProvider('commands')]
    public function testACommandIsSplitIntoWordsWithoutAShell(string $command, array $words): void
    {
        self::assertSame($words, Hooks::split($command));
    }

    public static function badCommands(): iterable
    {
        yield 'an open quote' => ["echo 'unfinished", 'quote that is not closed'];
        yield 'nothing' => ['   ', 'the hook command is empty'];
    }

    #[DataProvider('badCommands')]
    public function testABadCommandIsRefused(string $command, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);

        Hooks::split($command);
    }

    public function testAHookThatExitsWithTwoBlocksWithItsStderrAsTheReason(): void
    {
        $ui = new RecordingUi();

        $reason = $this->hooks('PreToolUse', [['command' => $this->command('block'), 'match' => null]])
            ->run('PreToolUse', ['tool_name' => 'write'], $this->folder, 'write', $ui);

        self::assertSame('no writes to .git', $reason);
        self::assertSame([], $ui->events, 'a blocking hook is not a notice');
    }

    public function testAHookThatExitsWithTwoAndSaysNothingStillGivesAReason(): void
    {
        $reason = $this->hooks('PreToolUse', [['command' => $this->command('block-silent'), 'match' => null]])
            ->run('PreToolUse', ['tool_name' => 'bash'], $this->folder, 'bash', new RecordingUi());

        self::assertSame('the PreToolUse hook exited with code 2 and gave no reason', $reason);
    }

    public function testAnyOtherExitCodeIsIgnoredWithANotice(): void
    {
        $ui = new RecordingUi();

        $reason = $this->hooks('PreToolUse', [['command' => $this->command('fail'), 'match' => null]])
            ->run('PreToolUse', ['tool_name' => 'write'], $this->folder, 'write', $ui);

        self::assertNull($reason, 'exit 1 does not block');
        self::assertCount(1, $ui->events);
        self::assertSame('notice', $ui->events[0][0]);
        self::assertStringContainsString('PreToolUse hook', (string) $ui->events[0][2]);
        self::assertStringContainsString('hook.php fail exited with code 1; ignored: broken hook', (string) $ui->events[0][2]);
    }

    public function testExitTwoOfAnEventThatCannotBlockIsFeedbackForTheModel(): void
    {
        foreach (['PostToolUse', 'Stop', 'SubagentStop'] as $event) {
            $ui = new RecordingUi();

            $outcome = $this->hooks($event, [['command' => $this->command('block'), 'match' => null]])->dispatch($event, [], $this->folder, 'build', $ui);

            self::assertNull($outcome->block, $event);
            self::assertSame(["no writes to .git"], $outcome->feedback, $event);
            self::assertSame([], $ui->events, "{$event}: no notice, the model is told");
        }
    }

    public function testTheEventIsWrittenAsJsonOnStdin(): void
    {
        $payload = ['hook_event_name' => 'PreToolUse', 'tool_name' => 'bash', 'tool_input' => ['command' => 'ls']];

        $this->hooks('PreToolUse', [['command' => $this->command('ok'), 'match' => null]])
            ->run('PreToolUse', $payload, $this->folder, 'bash', new RecordingUi());

        self::assertSame($payload, json_decode((string) file_get_contents($this->folder . '/received.json'), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testAMatchLimitsAHookToTheToolsItNames(): void
    {
        $ui = new RecordingUi();
        $hooks = $this->hooks('PreToolUse', [['command' => $this->command('block'), 'match' => 'bash']]);

        self::assertNull($hooks->run('PreToolUse', [], $this->folder, 'write', $ui), 'a write is not matched by a bash hook');
        self::assertFileDoesNotExist($this->folder . '/received.json', 'the hook did not run');
        self::assertSame('no writes to .git', $hooks->run('PreToolUse', [], $this->folder, 'bash', $ui));
    }

    public function testHooksRunInOrderAndTheFirstThatBlocksStopsTheRest(): void
    {
        $hooks = new Hooks(['PreToolUse' => [
            ['command' => $this->command('ok'), 'match' => null],
            ['command' => $this->command('block'), 'match' => null],
            ['command' => $this->command('fail'), 'match' => null],
        ]]);

        self::assertSame('no writes to .git', $hooks->run('PreToolUse', ['tool_name' => 'write'], $this->folder, 'write', new RecordingUi()));
    }

    public function testAHookThatTakesLongerThanItsTimeoutBlocksAPreToolUseCall(): void
    {
        $started = microtime(true);

        $reason = $this->hooks('PreToolUse', [['command' => $this->command('sleep'), 'match' => null]], 1)
            ->run('PreToolUse', ['tool_name' => 'bash'], $this->folder, 'bash', new RecordingUi());

        self::assertStringContainsString('timed out after 1 seconds', (string) $reason);
        self::assertLessThan(3.0, microtime(true) - $started, 'the hook is stopped at its timeout, not when it would have ended');
    }

    public function testAHookThatTimesOutDoesNotBlockAStopEvent(): void
    {
        $ui = new RecordingUi();

        $reason = $this->hooks('Stop', [['command' => $this->command('sleep'), 'match' => null]], 1)
            ->run('Stop', [], $this->folder, 'build', $ui);

        self::assertNull($reason);
        self::assertSame('notice', $ui->events[0][0]);
        self::assertStringContainsString('timed out', (string) $ui->events[0][2]);
    }

    public function testAHookThatCannotStartBlocksAPreToolUseCall(): void
    {
        $reason = $this->hooks('PreToolUse', [['command' => 'phycode-no-such-hook-program', 'match' => null]])
            ->run('PreToolUse', [], $this->folder, 'bash', new RecordingUi());

        self::assertSame('the hook could not run: cannot start phycode-no-such-hook-program: it is not on PATH', $reason);
    }

    public function testTheReasonIsRedactedBeforeItIsShown(): void
    {
        $script = $this->folder . '/leak.php';
        file_put_contents($script, '<?php fwrite(STDERR, "denied for sk-abcdefghijklmnop"); exit(2);');

        $reason = $this->hooks('PreToolUse', [['command' => "'" . PHP_BINARY . "' '{$script}'", 'match' => null]])
            ->run('PreToolUse', [], $this->folder, 'bash', new RecordingUi());

        self::assertStringNotContainsString('sk-abcdefghijklmnop', (string) $reason);
        self::assertStringContainsString('denied for', (string) $reason);
    }

    public function testAnEventWithoutHooksDoesNothing(): void
    {
        $ui = new RecordingUi();

        self::assertNull((new Hooks())->run('PreToolUse', ['tool_name' => 'write'], $this->folder, 'write', $ui));
        self::assertSame([], $ui->events);
    }
}
