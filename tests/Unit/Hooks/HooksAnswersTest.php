<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Hooks;

use Phycode\Agent\Hooks;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HooksAnswersTest extends TestCase
{
    private const SCRIPT = <<<'PHP'
<?php
$name = $argv[1];
file_put_contents(__DIR__ . "/{$name}.ran", '1');
if (is_file(__DIR__ . "/{$name}.sleep")) {
    sleep((int) file_get_contents(__DIR__ . "/{$name}.sleep"));
}
echo is_file(__DIR__ . "/{$name}.out") ? file_get_contents(__DIR__ . "/{$name}.out") : '';
fwrite(STDERR, is_file(__DIR__ . "/{$name}.err") ? file_get_contents(__DIR__ . "/{$name}.err") : '');
exit(is_file(__DIR__ . "/{$name}.code") ? (int) file_get_contents(__DIR__ . "/{$name}.code") : 0);
PHP;

    private string $folder;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('hooks-answers');
        file_put_contents($this->folder . '/hook.php', self::SCRIPT);
    }

    private function hook(string $name, array|string $stdout = '', int $code = 0, string $stderr = ''): string
    {
        file_put_contents("{$this->folder}/{$name}.out", is_array($stdout) ? json_encode($stdout) : $stdout);
        file_put_contents("{$this->folder}/{$name}.code", (string) $code);
        file_put_contents("{$this->folder}/{$name}.err", $stderr);

        return "'" . PHP_BINARY . "' '{$this->folder}/hook.php' {$name}";
    }

    private function dispatch(string $event, array $entries, ?RecordingUi $ui = null, string $subject = 'read'): \Phycode\Agent\HookOutcome
    {
        return (new Hooks([$event => $entries]))->dispatch($event, ['hook_event_name' => $event], $this->folder, $subject, $ui ?? new RecordingUi());
    }

    public function testADecisionToBlockStopsAPreToolUseCallWithItsReason(): void
    {
        $outcome = $this->dispatch('PreToolUse', [['command' => $this->hook('a', ['decision' => 'block', 'reason' => 'not on friday']), 'match' => null]]);

        self::assertSame('not on friday', $outcome->block);
    }

    public function testADenyingPermissionDecisionBlocksAndAnAllowingOneIsIgnored(): void
    {
        $deny = ['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'permissionDecision' => 'deny', 'permissionDecisionReason' => 'database writes are off']];
        $allow = ['hookSpecificOutput' => ['hookEventName' => 'PreToolUse', 'permissionDecision' => 'allow']];

        self::assertSame('database writes are off', $this->dispatch('PreToolUse', [['command' => $this->hook('d', $deny), 'match' => null]])->block);
        self::assertNull($this->dispatch('PreToolUse', [['command' => $this->hook('e', $allow), 'match' => null]])->block, 'no hook can lift a rule or an approval');
        self::assertSame('the PreToolUse hook denied the call', $this->dispatch('PreToolUse', [['command' => $this->hook('f', ['hookSpecificOutput' => ['permissionDecision' => 'deny']]), 'match' => null]])->block);
    }

    public function testUpdatedInputReplacesTheArgumentsOfAPreToolUseCallAndTheLastHookWins(): void
    {
        $outcome = $this->dispatch('PreToolUse', [
            ['command' => $this->hook('a', ['hookSpecificOutput' => ['updatedInput' => ['path' => 'one']]]), 'match' => null],
            ['command' => $this->hook('b', ['hookSpecificOutput' => ['updatedInput' => ['path' => 'two']]]), 'match' => null],
        ]);

        self::assertSame(['path' => 'two'], $outcome->updatedInput);
        self::assertNull($this->dispatch('PreToolUse', [['command' => $this->hook('c', ['hookSpecificOutput' => ['updatedInput' => ['x', 'y']]]), 'match' => null]])->updatedInput, 'a list is not an arguments object');
        self::assertNull($this->dispatch('PostToolUse', [['command' => $this->hook('d', ['hookSpecificOutput' => ['updatedInput' => ['path' => 'x']]]), 'match' => null]])->updatedInput, 'only before a call');
    }

    public function testAdditionalContextReachesTheModelFromAnyEventAndIsBounded(): void
    {
        $outcome = $this->dispatch('PostToolUse', [['command' => $this->hook('a', ['hookSpecificOutput' => ['additionalContext' => 'tests last ran at noon']]), 'match' => null]]);
        self::assertSame(['tests last ran at noon'], $outcome->context);

        $long = $this->dispatch('PostToolUse', [['command' => $this->hook('b', ['hookSpecificOutput' => ['additionalContext' => str_repeat('x', 12000)]]), 'match' => null]]);
        self::assertLessThan(10200, strlen($long->context[0]));
    }

    public function testAPostToolUseBlockIsFeedbackBecauseTheToolAlreadyRan(): void
    {
        $outcome = $this->dispatch('PostToolUse', [['command' => $this->hook('a', ['decision' => 'block', 'reason' => 'lint failed on line 3']), 'match' => null]]);

        self::assertNull($outcome->block);
        self::assertSame(['lint failed on line 3'], $outcome->feedback);
    }

    public function testExitTwoOfAPostToolUseHookGivesItsStderrToTheModel(): void
    {
        $outcome = $this->dispatch('PostToolUse', [['command' => $this->hook('a', '', 2, "error: unused variable\nerror: missing return\n"), 'match' => null]]);

        self::assertNull($outcome->block);
        self::assertSame(["error: unused variable\nerror: missing return"], $outcome->feedback);
        self::assertSame(['the PostToolUse hook exited with code 2 and gave no reason'], $this->dispatch('PostToolUse', [['command' => $this->hook('b', '', 2), 'match' => null]])->feedback);
    }

    public function testAStopHookThatBlocksSaysWhyTheRunShouldGoOn(): void
    {
        $byExit = $this->dispatch('Stop', [['command' => $this->hook('a', '', 2, 'run the tests first'), 'match' => null]]);
        $byJson = $this->dispatch('SubagentStop', [['command' => $this->hook('b', ['decision' => 'block', 'reason' => 'the report has no files']), 'match' => null]]);

        self::assertSame(['run the tests first'], $byExit->feedback);
        self::assertSame(['the report has no files'], $byJson->feedback);
    }

    public function testAPromptHookBlocksByExitTwoOrByDecisionAndAddsPlainStdoutAsContext(): void
    {
        self::assertSame('no secrets in prompts', $this->dispatch('UserPromptSubmit', [['command' => $this->hook('a', '', 2, 'no secrets in prompts'), 'match' => null]])->block);
        self::assertSame('too long', $this->dispatch('UserPromptSubmit', [['command' => $this->hook('b', ['decision' => 'block', 'reason' => 'too long']), 'match' => null]])->block);

        $plain = $this->dispatch('UserPromptSubmit', [['command' => $this->hook('c', "branch: feature/x\n"), 'match' => null]]);
        self::assertNull($plain->block);
        self::assertSame(['branch: feature/x'], $plain->context);

        self::assertSame([], $this->dispatch('PostToolUse', [['command' => $this->hook('d', "chatter\n"), 'match' => null]])->context, 'plain stdout is context for a prompt only');
    }

    public function testASystemMessageIsShownToThePersonAndStdoutThatIsNotAnObjectIsIgnored(): void
    {
        $ui = new RecordingUi();
        $this->dispatch('PostToolUse', [['command' => $this->hook('a', ['systemMessage' => 'formatter missing']), 'match' => null]], $ui);

        self::assertSame([['notice', 'warn', 'formatter missing']], $ui->events);
        foreach (['not json', '["a","b"]', '"text"', '42'] as $index => $stdout) {
            self::assertTrue($this->dispatch('PostToolUse', [['command' => $this->hook("n{$index}", $stdout), 'match' => null]])->isEmpty(), $stdout);
        }
    }

    public function testTheFirstBlockStopsTheNextHooksAndFeedbackAddsUp(): void
    {
        $outcome = $this->dispatch('PreToolUse', [
            ['command' => $this->hook('a', ['hookSpecificOutput' => ['additionalContext' => 'first']]), 'match' => null],
            ['command' => $this->hook('b', '', 2, 'stop here'), 'match' => null],
            ['command' => $this->hook('c'), 'match' => null],
        ]);

        self::assertSame('stop here', $outcome->block);
        self::assertSame(['first'], $outcome->context);
        self::assertFileExists("{$this->folder}/b.ran");
        self::assertFileDoesNotExist("{$this->folder}/c.ran", 'a hook after a block does not run');

        $both = $this->dispatch('PostToolUse', [['command' => $this->hook('d', '', 2, 'one'), 'match' => null], ['command' => $this->hook('e', '', 2, 'two'), 'match' => null]]);
        self::assertSame(['one', 'two'], $both->feedback);
    }

    public static function globs(): iterable
    {
        yield 'no match' => [null, 'edit', true];
        yield 'star' => ['*', 'bash', true];
        yield 'exact' => ['edit', 'edit', true];
        yield 'exact other' => ['edit', 'write', false];
        yield 'bar list' => ['edit|write', 'write', true];
        yield 'comma list' => ['edit, write', 'write', true];
        yield 'list without it' => ['edit|write', 'bash', false];
        yield 'glob in a list' => ['git_*|bash', 'git_write', true];
        yield 'custom tools' => ['custom__*', 'custom__lint', true];
        yield 'empty parts' => ['|', 'edit', false];
    }

    #[DataProvider('globs')]
    public function testAMatchIsAGlobOrAListOfThem(?string $match, string $subject, bool $expected): void
    {
        self::assertSame($expected, Hooks::matches($match, $subject));
    }

    public function testAHookThatDoesNotMatchTheSubjectDoesNotRun(): void
    {
        $this->dispatch('PostToolUse', [['command' => $this->hook('a', '', 2, 'x'), 'match' => 'edit|write']], null, 'read');

        self::assertFileDoesNotExist("{$this->folder}/a.ran");
    }

    public function testAHookHasItsOwnTimeLimit(): void
    {
        file_put_contents("{$this->folder}/slow.sleep", '4');
        $command = $this->hook('slow');
        $started = microtime(true);

        $pre = $this->dispatch('PreToolUse', [['command' => $command, 'match' => null, 'timeout' => 1]]);

        self::assertLessThan(3.5, microtime(true) - $started);
        self::assertStringContainsString('timed out after 1 seconds', (string) $pre->block);

        $ui = new RecordingUi();
        $post = $this->dispatch('PostToolUse', [['command' => $command, 'match' => null, 'timeout' => 1]], $ui);
        self::assertTrue($post->isEmpty());
        self::assertStringContainsString('timed out after 1 seconds', (string) $ui->events[0][2]);

        $long = $this->dispatch('PostToolUse', [['command' => $command, 'match' => null, 'timeout' => 8]]);
        self::assertTrue($long->isEmpty(), 'with room to finish, the hook finishes');
    }
}
