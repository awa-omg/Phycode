<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\BraveBackend;
use Phycode\Web\ExaBackend;
use Phycode\Web\SearchBackend;
use Phycode\Web\SsrfGuard;
use Phycode\Web\TavilyBackend;
use Phycode\Web\Transport;
use Phycode\Web\WebException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchKeyTest extends TestCase
{
    private const KEY = 'secret-key-never-shown-42';

    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public static function keyedBackends(): iterable
    {
        yield 'Brave' => ['brave'];
        yield 'Tavily' => ['tavily'];
        yield 'Exa' => ['exa'];
    }

    #[DataProvider('keyedBackends')]
    public function testAnErrorReplyThatRepeatsTheKeyIsNotEchoedInTheFailure(string $name): void
    {
        $this->server = new FixtureServer(['/' . $name => [[
            'status' => 500,
            'body' => 'internal failure while checking ' . self::KEY,
            'type' => 'text/plain',
        ]]]);
        $backend = self::make($name, new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"])), $this->server->url('/' . $name));

        try {
            $backend->search('x', 3);
            self::fail('a 500 reply must fail');
        } catch (WebException $error) {
            self::assertSame(ucfirst($name) . ' search failed: HTTP 500', $error->getMessage());
        }
    }

    #[DataProvider('keyedBackends')]
    public function testAReplyThatIsNotJsonButRepeatsTheKeyIsNotEchoedInTheFailure(string $name): void
    {
        $this->server = new FixtureServer(['/' . $name => [[
            'body' => 'the request with ' . self::KEY . ' was not understood',
            'type' => 'application/json',
        ]]]);
        $backend = self::make($name, new Transport(new SsrfGuard(["127.0.0.1:{$this->server->port()}"])), $this->server->url('/' . $name));

        try {
            $backend->search('x', 3);
            self::fail('a reply that is not JSON must fail');
        } catch (WebException $error) {
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }

    #[DataProvider('keyedBackends')]
    public function testAnEndpointThatIsRefusedDoesNotEchoTheKeyEither(string $name): void
    {
        $backend = self::make($name, new Transport(new SsrfGuard()), 'http://10.0.0.1/' . $name);

        try {
            $backend->search('x', 3);
            self::fail('a private endpoint must be refused');
        } catch (WebException $error) {
            self::assertSame('refused: 10.0.0.1 is a private address', $error->getMessage());
        }
    }

    private static function make(string $name, Transport $transport, string $url): SearchBackend
    {
        return match ($name) {
            'brave' => new BraveBackend(self::KEY, $transport, $url),
            'tavily' => new TavilyBackend(self::KEY, $transport, $url),
            'exa' => new ExaBackend(self::KEY, $transport, $url),
        };
    }
}
