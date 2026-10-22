<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Support\Json;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Llama\LlamaFake;
use PHPUnit\Framework\TestCase;

final class WorktreeRunTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    private static function chunk(array $delta, ?string $finish = null): string
    {
        return 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]], JSON_THROW_ON_ERROR) . "\n\n";
    }

    private static function reply(string $text): array
    {
        return ['body' => self::chunk(['role' => 'assistant', 'content' => $text]) . self::chunk([], 'stop') . "data: [DONE]\n\n", 'type' => 'text/event-stream'];
    }

    private static function writeReply(string $path, string $content): array
    {
        $call = ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'write', 'arguments' => json_encode(['path' => $path, 'content' => $content], JSON_THROW_ON_ERROR)]]]];

        return ['body' => self::chunk($call) . self::chunk([], 'tool_calls') . "data: [DONE]\n\n", 'type' => 'text/event-stream'];
    }

    private function serve(array $replies): array
    {
        $this->server = new LlamaFake(['POST /v1/chat/completions' => $replies]);

        return ['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'tool_mode' => 'native'];
    }

    private function repository(): string
    {
        $root = realpath(Kit::folder('wt-run'));
        $git = static fn (string ...$arguments): string => (string) shell_exec('git -C ' . escapeshellarg($root) . ' -c user.name=Test -c user.email=test@example.invalid ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1');
        $git('init', '-q', '-b', 'main');
        Kit::write($root . '/a.txt', "one\n");
        $git('add', '.');
        $git('commit', '-q', '-m', 'first');

        return $root;
    }

    private function branches(string $root): string
    {
        return trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' for-each-ref --format="%(refname:short)" refs/heads/phycode 2>&1'));
    }

    public function testARunThatChangesNothingLeavesNoWorktreeAndNoBranch(): void
    {
        $profile = $this->serve([self::reply('Nothing to do.')]);
        $root = $this->repository();

        $run = PhycodeRun::run(['run', '--cwd', $root, '--worktree', 'idle', '--output-format', 'json', 'Look around.'], $profile);

        $result = Json::object($run->stdout) ?? [];
        self::assertSame(0, $run->status, $run->stderr);
        self::assertSame(['name' => 'idle', 'branch' => 'phycode/idle', 'path' => $root . '/.phycode/worktrees/idle', 'kept' => false], $result['worktree']);
        self::assertDirectoryDoesNotExist($root . '/.phycode/worktrees/idle');
        self::assertSame('', $this->branches($root));
        self::assertStringContainsString('worktree: idle changed nothing, so it was removed', $run->stderr);
    }

    public function testARunThatWritesKeepsTheWorktreeAndTheMainTreeIsUntouched(): void
    {
        $profile = $this->serve([self::writeReply('new.txt', "made\n"), self::reply('Wrote new.txt.')]);
        $root = $this->repository();

        $run = PhycodeRun::run(['run', '--cwd', $root, '--worktree', 'job', '--yolo', '--output-format', 'json', 'Write new.txt.'], $profile);

        $result = Json::object($run->stdout) ?? [];
        self::assertSame(0, $run->status, $run->stderr);
        self::assertTrue($result['worktree']['kept']);
        self::assertSame(['new.txt'], $result['changed']);
        self::assertSame("made\n", file_get_contents($root . '/.phycode/worktrees/job/new.txt'));
        self::assertFileDoesNotExist($root . '/new.txt');
        self::assertSame('phycode/job', $this->branches($root));
        self::assertStringContainsString('git merge phycode/job brings them in', $run->stderr);

        $list = PhycodeRun::run(['worktree', '--cwd', $root], $profile);
        self::assertSame(0, $list->status, $list->stderr);
        self::assertMatchesRegularExpression('/^job\s+phycode\/job\s+uncommitted changes$/m', $list->stdout);

        $remove = PhycodeRun::run(['worktree', 'remove', 'job', '--cwd', $root], $profile);
        self::assertSame(0, $remove->status, $remove->stderr);
        self::assertSame("removed job\n", $remove->stdout);
        self::assertDirectoryDoesNotExist($root . '/.phycode/worktrees/job');
        self::assertSame('', $this->branches($root));
    }

    public function testOutsideARepositoryAndWithABadNameTheRunStopsBeforeAskingTheModel(): void
    {
        $profile = $this->serve([self::reply('never')]);

        $none = PhycodeRun::run(['run', '--cwd', Kit::folder('wt-none'), '--worktree', 'x', 'Go.'], $profile);
        $bad = PhycodeRun::run(['run', '--cwd', Kit::folder('wt-bad'), '--worktree', 'a/b', 'Go.'], $profile);
        $chat = PhycodeRun::run(['chat', '--worktree', 'x'], $profile);

        self::assertSame(1, $none->status);
        self::assertStringContainsString('not a git repository', $none->stderr);
        self::assertSame(1, $bad->status);
        self::assertStringContainsString('--worktree needs a name', $bad->stderr);
        self::assertSame(1, $chat->status);
        self::assertStringContainsString('--worktree is an option of run', $chat->stderr);
        self::assertSame([], array_filter($this->server->requests(), static fn (array $request): bool => $request['method'] === 'POST'));
    }

    public function testTheWorktreeCommandListsNothingAndRefusesWhatItDoesNotKnow(): void
    {
        $profile = $this->serve([self::reply('never')]);
        $root = $this->repository();

        $empty = PhycodeRun::run(['worktree', '--cwd', $root], $profile);
        $unknown = PhycodeRun::run(['worktree', 'remove', 'ghost', '--cwd', $root], $profile);
        $usage = PhycodeRun::run(['worktree', 'prune', '--cwd', $root], $profile);

        self::assertSame("no worktrees; phycode run --worktree NAME \"task\" makes one\n", $empty->stdout);
        self::assertSame(1, $unknown->status);
        self::assertStringContainsString('no worktree named ghost', $unknown->stderr);
        self::assertSame(1, $usage->status);
        self::assertStringContainsString('usage: phycode worktree [list | remove NAME]', $usage->stderr);
    }
}
