<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\Canonical\Usage;
use Phycode\Llm\Pricing;
use Phycode\Llm\UsageMeter;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    private function pricing(): Pricing
    {
        return new Pricing([
            'big-model' => ['input' => 3.0, 'output' => 15.0, 'cached_input' => 0.3, 'cache_write' => 3.75],
            'claude-*' => ['input' => 1.0, 'output' => 5.0],
            'claude-opus-*' => ['input' => 5.0, 'output' => 25.0],
        ]);
    }

    public function testTheCostCountsFreshCachedAndWrittenInputAtTheirOwnPrices(): void
    {
        $cost = $this->pricing()->cost('big-model', 1000, 200, 300, 100);

        self::assertEqualsWithDelta((600 * 3.0 + 300 * 0.3 + 100 * 3.75 + 200 * 15.0) / 1_000_000, $cost, 1e-12);
    }

    public function testWithoutAPriceForTheCacheTheInputPriceApplies(): void
    {
        self::assertEqualsWithDelta((1000 * 1.0 + 100 * 5.0) / 1_000_000, $this->pricing()->cost('claude-haiku-9', 1000, 100, 400, 0), 1e-12);
    }

    public function testTheModelIdWinsAndThenTheLongestPattern(): void
    {
        $pricing = $this->pricing();

        self::assertSame(3.0, $pricing->for('big-model')['input']);
        self::assertSame(1.0, $pricing->for('claude-sonnet-1')['input']);
        self::assertSame(5.0, $pricing->for('claude-opus-9')['input'], 'the longer pattern');
        self::assertNull($pricing->for('qwen35-2b'));
        self::assertNull($pricing->cost('qwen35-2b', 10, 10, 0, 0));
        self::assertTrue((new Pricing())->isEmpty());
    }

    public function testMoreCachedTokensThanPromptNeverGiveANegativeCost(): void
    {
        self::assertGreaterThanOrEqual(0.0, $this->pricing()->cost('big-model', 100, 0, 500, 0));
    }

    public function testTheMeterAddsUpRequestsByModelAndCountsServersThatReportNothing(): void
    {
        $meter = new UsageMeter(static fn (string $profile, ?string $model): string => $model ?? 'default-' . $profile);
        $meter->record('a', null, new Usage(1000, 200, 400, null, 100));
        $meter->record('a', null, new Usage(500, 100, 0));
        $meter->record('a', 'big-model', new Usage(100, 10));
        $meter->record('a', 'big-model', null);
        $meter->record('a', 'big-model', new Usage());

        $rows = $meter->rows($this->pricing());

        self::assertSame(['default-a', 'big-model'], array_column($rows, 'model'));
        self::assertSame([2, 1500, 300, 400, 100, 0], [$rows[0]['requests'], $rows[0]['prompt'], $rows[0]['completion'], $rows[0]['cached'], $rows[0]['cacheWrite'], $rows[0]['unreported']]);
        self::assertNull($rows[0]['cost'], 'no price for the default model');
        self::assertSame([3, 2], [$rows[1]['requests'], $rows[1]['unreported']]);
        self::assertEqualsWithDelta((100 * 3.0 + 10 * 15.0) / 1_000_000, $rows[1]['cost'], 1e-12);
        $total = $meter->totals($this->pricing());
        self::assertSame([5, 1600, 310, 400, false, 2], [$total['requests'], $total['prompt'], $total['completion'], $total['cached'], $total['priced'], $total['unreported']]);
    }

    public function testTheTextShowsEachModelTheTotalAndWhatToDoWithoutPrices(): void
    {
        $meter = new UsageMeter(static fn (string $profile, ?string $model): string => $model ?? 'qwen');
        self::assertSame('No model requests yet in this session.', $meter->text(new Pricing()));

        $meter->record('local', null, new Usage(12300, 450, 11000));
        $meter->record('local', 'big-model', new Usage(2000, 1500));

        $without = $meter->text(new Pricing());
        self::assertStringContainsString('qwen (local): 1 requests, 12.3k in (11k cached), 450 out, no price', $without);
        self::assertStringContainsString('total: 2 requests, 14.3k in (11k cached), 2k out, no cost', $without);
        self::assertStringContainsString('Set "pricing" in the config', $without);

        $with = $meter->text($this->pricing());
        self::assertStringContainsString('big-model (local): 1 requests, 2k in (0 cached), 1.5k out, $0.0285', $with);
        self::assertStringContainsString('total: 2 requests, 14.3k in (11k cached), 2k out, $0.0285 (without the models that have no price)', $with);
        self::assertStringNotContainsString('Set "pricing"', $with);
    }

    public function testTokensAndMoneyAreShortAndClear(): void
    {
        self::assertSame(['0', '999', '1k', '12.3k', '1.2M'], array_map(UsageMeter::tokens(...), [0, 999, 1000, 12300, 1_200_000]));
        self::assertSame(['$0.0034', '$1.50', '$12.00'], array_map(UsageMeter::money(...), [0.0034, 1.5, 12.0]));
    }
}
