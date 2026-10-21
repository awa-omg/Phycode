<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Llm\Canonical\FinishReason;
use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\LlmResponse;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class UsageCommandTest extends TestCase
{
    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private static function reply(string $text, Usage $usage): LlmResponse
    {
        return new LlmResponse($text, '', [], FinishReason::STOP, $usage);
    }

    private function session(CountingProvider $provider, array $extra = []): \Phycode\Cli\ChatSession
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']]] + $extra);

        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    private function panel(): string
    {
        $panels = array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel' && $event[1] === 'Usage'));

        return (string) end($panels)[2];
    }

    public function testBeforeAnyRequestThereIsNothingToShow(): void
    {
        $session = $this->session(new CountingProvider(['local' => 'qwen']));

        $session->handle('/usage');

        self::assertSame('No model requests yet in this session.', $this->panel());
    }

    public function testEveryRequestOfATurnIsCountedWithItsCostWhenTheModelHasAPrice(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [
            self::reply('first', new Usage(1000, 200, 400)),
            self::reply('second', new Usage(1500, 100, 1000)),
        ]);
        $session = $this->session($provider, ['pricing' => ['qwen' => ['input' => 2, 'output' => 10, 'cached_input' => 0.5]]]);

        $session->handle('one');
        $session->handle('two');
        $session->handle('/usage');

        self::assertStringContainsString('qwen (local): 2 requests, 2.5k in (1.4k cached), 300 out, $0.0059', $this->panel());
        self::assertStringContainsString('total: 2 requests, 2.5k in (1.4k cached), 300 out, $0.0059', $this->panel());
        self::assertStringNotContainsString('Set "pricing"', $this->panel());
    }

    public function testWithoutPricesTheTokensShowAndTheConfigIsPointedTo(): void
    {
        $session = $this->session(new CountingProvider(['local' => 'qwen'], ['native'], replies: [self::reply('x', new Usage(10, 5))]));

        $session->handle('one');
        $session->handle('/usage');

        self::assertStringContainsString('qwen (local): 1 requests, 10 in (0 cached), 5 out, no price', $this->panel());
        self::assertStringContainsString('Set "pricing" in the config', $this->panel());
    }

    public function testAServerThatReportsNoTokensIsSaidSo(): void
    {
        $session = $this->session(new CountingProvider(['local' => 'qwen'], ['native'], replies: [self::reply('x', new Usage())]));

        $session->handle('one');
        $session->handle('/usage');

        self::assertStringContainsString('1 of the requests came without token counts from the server', $this->panel());
    }

    public function testTheCommandIsListed(): void
    {
        $session = $this->session(new CountingProvider(['local' => 'qwen']));

        self::assertContains('/usage', array_column($session->commands(), 'name'));
        self::assertTrue($session->isCommand('/usage'));
    }
}
