<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Support\Json;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class TranscriptWarningTest extends TestCase
{
    private const WARNING = 'transcripts are not written: .phycode/sessions leads outside the project';

    public function testTheConsoleOfChatShowsTheWarningAndTheSessionCarriesOn(): void
    {
        $run = PhycodeRun::run(['chat', '--cwd', $this->projectWithLinkedPhycode()], $this->unreachableProfile());

        self::assertSame(0, $run->status, $run->stderr);
        self::assertStringContainsString('phycode warn: ' . self::WARNING, $run->stderr);
        self::assertStringNotContainsString('phycode: transcripts', $run->stderr, 'the warning does not also go to the PHP error log');
    }

    public function testTheConsoleOfRunShowsTheWarning(): void
    {
        $run = PhycodeRun::run(['run', '--cwd', $this->projectWithLinkedPhycode(), 'hello'], $this->unreachableProfile());

        self::assertStringContainsString('phycode warn: ' . self::WARNING, $run->stderr);
        self::assertStringNotContainsString('phycode: transcripts', $run->stderr);
    }

    public function testTheRpcClientReceivesTheWarningAsANotice(): void
    {
        $run = PhycodeRun::run(['rpc', '--cwd', $this->projectWithLinkedPhycode()], $this->unreachableProfile(), "{\"type\":\"quit\"}\n");

        self::assertSame(0, $run->status, $run->stderr);
        $notices = array_values(array_filter(self::events($run->stdout), static fn (array $event): bool => ($event['type'] ?? null) === 'notice'));
        self::assertCount(1, $notices, $run->stdout);
        self::assertSame('warn', $notices[0]['level']);
        self::assertStringContainsString(self::WARNING, (string) $notices[0]['message']);
        self::assertStringNotContainsString('transcripts are not written', $run->stderr, 'the protocol carries the warning, and stderr stays clean');
    }

    private function projectWithLinkedPhycode(): string
    {
        $project = Kit::folder('transcript-warning');
        symlink(Kit::folder('transcript-warning-outside'), $project . '/.phycode');

        return $project;
    }

    private function unreachableProfile(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return ['base_url' => 'http://' . $address, 'model' => 'qwen35-2b', 'tool_mode' => 'native'];
    }

    private static function events(string $stdout): array
    {
        return array_values(array_map(static fn (string $line): array => Json::object($line) ?? [], array_filter(explode("\n", $stdout))));
    }
}
