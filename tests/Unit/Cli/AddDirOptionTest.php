<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Llama\LlamaFake;
use PHPUnit\Framework\TestCase;

final class AddDirOptionTest extends TestCase
{
    private const PROFILE = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b', 'tool_mode' => 'native'];

    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testRepeatedFoldersAreAcceptedAndNamedAtStartup(): void
    {
        $project = Kit::folder('add-dir-project');
        $first = Kit::folder('add-dir-first');
        $second = Kit::folder('add-dir-second');

        $run = PhycodeRun::run(['agents', '--cwd', $project, '--add-dir', $first, '--add-dir=' . $second], self::PROFILE);

        self::assertSame(0, $run->status, $run->stderr);
        self::assertStringContainsString('phycode info: read-only folders (--add-dir): ' . realpath($first) . ', ' . realpath($second), $run->stderr);
        self::assertStringContainsString('build', $run->stdout);
    }

    public function testTheShellIsSaidToBeDisabledWhenAFolderIsAdded(): void
    {
        $project = Kit::folder('add-dir-shell');
        $folder = Kit::folder('add-dir-shell-folder');

        $run = PhycodeRun::run(['agents', '--cwd', $project, '--add-dir', $folder], self::PROFILE);

        self::assertSame(0, $run->status, $run->stderr);
        self::assertStringContainsString('phycode info: the shell is disabled: bash and run_code are not offered', $run->stderr);

        $plain = PhycodeRun::run(['agents', '--cwd', $project], self::PROFILE);

        self::assertStringNotContainsString('the shell is disabled', $plain->stderr, 'without --add-dir nothing is said about the shell');
    }

    public function testTheStartupNoticeAlsoComesWithRun(): void
    {
        $body = json_encode(['error' => ['message' => 'no model is loaded']], JSON_THROW_ON_ERROR);
        $this->server = new LlamaFake(['POST /v1/chat/completions' => [['status' => 400, 'body' => $body]]]);
        $project = Kit::folder('add-dir-run');
        $folder = Kit::folder('add-dir-run-folder');

        $run = PhycodeRun::run(
            ['run', '--cwd', $project, '--add-dir', $folder, '--json', 'Say hello.'],
            ['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'tool_mode' => 'native'],
        );

        self::assertSame(2, $run->status, $run->stderr);
        self::assertStringContainsString('read-only folders (--add-dir): ' . realpath($folder), $run->stderr);
        self::assertSame('error', json_decode($run->stdout, true)['status'] ?? null, 'the JSON result stays alone on stdout');
    }

    public function testAFolderThatDoesNotExistStopsTheStart(): void
    {
        $project = Kit::folder('add-dir-missing');
        $missing = $project . '/not-there';

        $run = PhycodeRun::run(['agents', '--cwd', $project, '--add-dir', $missing], self::PROFILE);

        self::assertSame(1, $run->status);
        self::assertStringContainsString('phycode: read-only folder does not exist or is not a folder: ' . $missing, $run->stderr);
        self::assertSame('', $run->stdout);
    }

    public function testAFolderThatOverlapsTheProjectStopsTheStart(): void
    {
        $project = Kit::folder('add-dir-overlap');

        $run = PhycodeRun::run(['agents', '--cwd', $project, '--add-dir', $project], self::PROFILE);

        self::assertSame(1, $run->status);
        self::assertStringContainsString('read-only folder overlaps the project folder', $run->stderr);
    }

    public function testAFolderWithoutAValueIsAnError(): void
    {
        foreach ([['agents', '--add-dir'], ['agents', '--add-dir=']] as $arguments) {
            $run = PhycodeRun::run($arguments, self::PROFILE);

            self::assertSame(1, $run->status, implode(' ', $arguments));
            self::assertStringContainsString('phycode: --add-dir needs a value', $run->stderr);
        }
    }

    public function testABadFolderStopsTheTerminalUiBeforeItStarts(): void
    {
        $project = Kit::folder('add-dir-tui');

        $run = PhycodeRun::run(['-t', '--cwd', $project, '--add-dir', $project . '/not-there'], self::PROFILE);

        self::assertSame(1, $run->status);
        self::assertStringContainsString('phycode: read-only folder does not exist', $run->stderr);
        self::assertStringNotContainsString('phycode-tui', $run->stderr, 'the UI binary is not started');
    }
}
