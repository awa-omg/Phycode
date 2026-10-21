<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Btw;

use Phycode\Btw\BtwJobs;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class BtwJobsTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = Kit::folder('btw-jobs');
    }

    public function testTheChildGetsTheRequestAndItsAnswerIsReportedOnce(): void
    {
        $jobs = $this->jobs();
        $jobs->start('what is two plus two?', $this->request('what is two plus two?', 2));

        $results = $this->drain($jobs);

        self::assertSame([[
            'question' => 'what is two plus two?', 'ok' => true,
            'answer' => 'answer to: what is two plus two? (2 messages)', 'error' => null,
        ]], $results);
        self::assertSame(0, $jobs->running());
        self::assertSame([], $jobs->poll(), 'a side question is reported once');
    }

    public function testAChildThatFailsIsReportedWithItsStatusAndTheLastLineOfItsErrors(): void
    {
        $jobs = $this->jobs();
        $jobs->start('crash now', $this->request('crash now'));

        self::assertSame(
            ['ok' => false, 'error' => 'the side question process ended with status 3: boom'],
            array_intersect_key($this->drain($jobs)[0], ['ok' => true, 'error' => true]),
        );
    }

    public function testAChildThatPrintsNoAnswerIsAFailure(): void
    {
        $jobs = $this->jobs();
        $jobs->start('garbage', $this->request('garbage'));

        self::assertSame(
            ['ok' => false, 'error' => 'the side question process printed no answer'],
            array_intersect_key($this->drain($jobs)[0], ['ok' => true, 'error' => true]),
        );
    }

    public function testTheErrorThatAChildReportsIsItsMessage(): void
    {
        $jobs = $this->jobs();
        $jobs->start('refuse', $this->request('refuse'));

        self::assertSame(['ok' => false, 'answer' => null, 'error' => 'the model refused'], array_intersect_key($this->drain($jobs)[0], ['ok' => true, 'answer' => true, 'error' => true]));
    }

    public function testAChildThatRunsTooLongIsStoppedAndReported(): void
    {
        $jobs = new BtwJobs([PHP_BINARY, self::fake()], 0.5);
        $jobs->start('slow', $this->request('slow'));

        $started = microtime(true);
        $results = $this->drain($jobs);

        self::assertSame(['ok' => false, 'error' => 'the side question took longer than 0.5 seconds'], array_intersect_key($results[0], ['ok' => true, 'error' => true]));
        self::assertLessThan(2.0, microtime(true) - $started, 'the child was stopped before it answered');
    }

    public function testNoMoreThanFourSideQuestionsRunAtOnce(): void
    {
        $jobs = $this->jobs();
        for ($index = 0; $index < BtwJobs::MAX_RUNNING; $index++) {
            $jobs->start("slow {$index}", $this->request("slow {$index}"));
        }

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('four side questions are already running');
        try {
            $jobs->start('slow 4', $this->request('slow 4'));
        } finally {
            self::assertSame(BtwJobs::MAX_RUNNING, $jobs->running());
            $jobs->close();
        }
    }

    public function testClosingStopsTheSideQuestionsThatStillRun(): void
    {
        $jobs = $this->jobs();
        $jobs->start('slow', $this->request('slow'));

        $jobs->close();

        self::assertSame(0, $jobs->running());
        self::assertSame([], $jobs->poll(), 'a stopped side question has no answer to report');
    }

    public function testAProgramThatCannotStartIsRefused(): void
    {
        $jobs = new BtwJobs(['/nonexistent/phycode-btw-child']);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('the side question process cannot be started');
        $jobs->start('hello', $this->request('hello'));
    }

    private function jobs(): BtwJobs
    {
        return new BtwJobs([PHP_BINARY, self::fake()], 30.0);
    }

    private static function fake(): string
    {
        return dirname(__DIR__, 2) . '/Support/fake-btw.php';
    }

    private function request(string $question, int $messages = 0): array
    {
        return [
            'question' => $question,
            'messages' => array_fill(0, $messages, ['role' => 'user', 'content' => 'earlier']),
            'profile' => 'local',
            'model' => null,
            'thinking' => null,
            'cwd' => $this->cwd,
        ];
    }

    private function drain(BtwJobs $jobs, int $expected = 1): array
    {
        $results = [];
        $deadline = microtime(true) + 10.0;
        while (count($results) < $expected && microtime(true) < $deadline) {
            array_push($results, ...$jobs->poll());
            usleep(10000);
        }
        self::assertCount($expected, $results, 'the side questions did not end in time');

        return $results;
    }
}
