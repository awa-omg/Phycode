<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\Http;
use PHPUnit\Framework\TestCase;

final class HttpErrorTest extends TestCase
{
    public function testTheStartOfABodyThatIsNotJsonHasNoControlSequences(): void
    {
        $error = Http::error(502, "\e[2J\e]52;c;SGVsbG8=\x07bad gateway\r\n\e[1A");

        self::assertSame('HTTP 502: bad gateway', $error->getMessage());
    }

    public function testTheMessageOfAnErrorObjectHasNoControlSequences(): void
    {
        $body = json_encode(['error' => ['message' => "rate limit\u{1b}[2J exceeded"]]);

        self::assertSame('HTTP 429: rate limit exceeded', Http::error(429, $body)->getMessage());
    }
}
