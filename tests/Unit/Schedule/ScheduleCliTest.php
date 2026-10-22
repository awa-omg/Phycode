<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Schedule;

use Phycode\Schedule\ScheduleCli;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ScheduleCliTest extends TestCase
{
    private string $config;
    private string $state;
    private string $project;

    protected function setUp(): void
    {
        $this->config = Kit::folder('schedule-cli-config');
        $this->state = Kit::folder('schedule-cli-state');
        $this->project = realpath(Kit::folder('schedule-cli-project'));
    }

    private function schedule(array $args, array $options = []): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = ScheduleCli::run($args, $options + ['cwd' => $this->project, 'yolo' => false, 'profile' => null, 'agent' => null], $this->config, $this->state, $out, $err, fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-21 14:57:30'));
        rewind($out);
        rewind($err);

        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testAddingAJobShowsWhenItRunsFirstAndWarnsAboutYolo(): void
    {
        [$status, $out] = $this->schedule(['add', 'nightly', '0 3 * * *', 'run the tests'], ['yolo' => true]);

        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^added nightly: 0 3 \* \* \* in .+, next run \d{4}-\d{2}-\d{2} 03:00\nthis job runs with --yolo: it writes files and runs commands without asking\n$/', $out);
    }

    public function testTheListShowsTheNextRunTheLastRunAndTheTask(): void
    {
        $this->schedule(['add', 'nightly', '0 3 * * *', 'run the tests and report what failed'], []);
        file_put_contents($this->state . '/state.json', json_encode(['nightly' => ['started' => '2026-10-21T03:00:00+00:00', 'exit' => 1, 'log' => 'x']]));

        [$status, $out] = $this->schedule(['list']);

        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/^nightly\s+0 3 \* \* \*\s+next \d{4}-\d{2}-\d{2} 03:00\s+last 2026-10-21T03:00 failed \(exit 1\)$/m', $out);
        self::assertStringContainsString("    {$this->project}\n    run the tests and report what failed\n", $out);
        self::assertSame($out, $this->schedule([])[1], 'list is the default');
    }

    public function testWithoutJobsTheListSaysHowToAddOne(): void
    {
        self::assertSame([0, "No jobs. phycode schedule add NAME \"30 8 * * 1-5\" \"run the tests and report\" adds one.\n", ''], $this->schedule(['list']));
    }

    public function testRemovingAnUnknownJobAndMistakesAreReported(): void
    {
        [$status, , $err] = $this->schedule(['remove', 'ghost']);
        self::assertSame(1, $status);
        self::assertStringContainsString('no job named ghost', $err);

        [$status, , $err] = $this->schedule(['add', 'only-a-name']);
        self::assertSame(1, $status);
        self::assertStringContainsString('give the name, the cron expression in quotes and the task in quotes', $err);

        [$status, , $err] = $this->schedule(['add', 'x', '99 * * * *', 'task']);
        self::assertSame(1, $status);
        self::assertStringContainsString('the minute field', $err);

        [$status, , $err] = $this->schedule(['frobnicate']);
        self::assertSame(1, $status);
        self::assertStringContainsString("unknown schedule command 'frobnicate'", $err);
    }

    public function testRemovingDeletesTheJob(): void
    {
        $this->schedule(['add', 'temp', '@hourly', 'x']);

        self::assertSame([0, "removed temp\n", ''], $this->schedule(['remove', 'temp']));
        self::assertStringContainsString('No jobs.', $this->schedule(['list'])[1]);
    }
}
