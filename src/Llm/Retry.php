<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\Cancellation;

final class Retry
{
    public const DELAYS = [0.5, 1.5];
    public const STATUSES = [429, 502, 503, 504];
    private const WAIT_SECONDS = 0.1;
    public const LOADING_STEPS = [1.0, 2.0, 4.0, 8.0, 16.0];
    public const LOADING_LIMIT = 60.0;

    public function __construct(private readonly array $delays = self::DELAYS, private readonly array $statuses = self::STATUSES)
    {
    }

    public function again(array $attempt, int $retries): bool
    {
        if ($attempt['streamed'] || $attempt['error'] !== null || $retries >= count($this->delays)) {
            return false;
        }

        return !$attempt['ok'] || in_array($attempt['status'], $this->statuses, true);
    }

    public function wait(int $retries, ?Cancellation $cancel): void
    {
        $until = microtime(true) + $this->delays[$retries];
        while (($left = $until - microtime(true)) > 0) {
            if ($cancel !== null && $cancel->isCancelled()) {
                return;
            }
            usleep((int) (min(self::WAIT_SECONDS, $left) * 1000000));
        }
    }

    public static function isLoading(int $status, string $body): bool
    {
        return $status === 503 && str_contains($body, 'Loading model');
    }

    public static function loadingPauses(array $steps = self::LOADING_STEPS, float $limit = self::LOADING_LIMIT): array
    {
        $pauses = [];
        $total = 0.0;
        while ($total < $limit) {
            $pause = min($steps[min(count($pauses), count($steps) - 1)], $limit - $total);
            $pauses[] = $pause;
            $total += $pause;
        }

        return $pauses;
    }

    public static function loadingTimeout(float $limit): LlmException
    {
        return new LlmException("HTTP 503: llama-server did not finish loading the model in {$limit} s; check the server");
    }

    public static function whileLoading(\Closure $get, array $steps = self::LOADING_STEPS, float $limit = self::LOADING_LIMIT): array
    {
        $reply = $get();
        foreach (self::loadingPauses($steps, $limit) as $pause) {
            if (!self::isLoading($reply['status'], $reply['body'])) {
                break;
            }
            usleep((int) round($pause * 1000000));
            $reply = $get();
        }

        return $reply;
    }
}
