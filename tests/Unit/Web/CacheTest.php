<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Support\Json;
use Phycode\Tests\Support\Kit;
use Phycode\Web\Cache;
use Phycode\Web\WebException;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private const URL = 'https://example.test/page';

    public function testAnEntryIsReadBackUntilItExpires(): void
    {
        $cache = new Cache(Kit::folder('cache'));
        $key = Cache::key('GET', self::URL);

        $cache->put($key, "the value\nwith two lines");

        self::assertSame("the value\nwith two lines", $cache->get($key));
    }

    public function testAMissingEntryIsAMiss(): void
    {
        self::assertNull((new Cache(Kit::folder('cache')))->get(Cache::key('GET', self::URL)));
    }

    public function testKeysAreTheSha256OfTheRequest(): void
    {
        $key = Cache::key('GET', self::URL);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        self::assertSame(hash('sha256', Json::encode(['GET', self::URL, ''])), $key);
        self::assertSame($key, Cache::key('GET', self::URL), 'the same request has the same key');
        self::assertNotSame($key, Cache::key('POST', self::URL), 'another method is another request');
        self::assertNotSame($key, Cache::key('GET', self::URL . '?x=1'), 'another URL is another request');
        self::assertNotSame(Cache::key('POST', self::URL, '{"a":1}'), Cache::key('POST', self::URL, '{"a":2}'), 'another body is another request');
    }

    public function testAnEntryExpiresAfterTheTtlInMinutes(): void
    {
        $now = 1_000;
        $cache = new Cache(Kit::folder('cache'), 2, static function () use (&$now): int {
            return $now;
        });
        $key = Cache::key('GET', self::URL);
        $cache->put($key, 'fresh');

        $now = 1_000 + 119;
        self::assertSame('fresh', $cache->get($key), 'still inside the two minutes');

        $now = 1_000 + 120;
        self::assertNull($cache->get($key), 'expired at the two minutes');
    }

    public function testAnExpiredEntryIsRemoved(): void
    {
        $folder = Kit::folder('cache');
        $now = 10;
        $cache = new Cache($folder, 0, static function () use (&$now): int {
            return $now;
        });
        $key = Cache::key('GET', self::URL);
        $cache->put($key, 'gone at once');

        self::assertNull($cache->get($key));
        self::assertFileDoesNotExist($folder . '/' . $key . '.cache');
    }

    public function testFilesAreWrittenWithModeZeroSixHundred(): void
    {
        $folder = Kit::folder('cache');
        $cache = new Cache($folder);
        $key = Cache::key('GET', self::URL);

        $cache->put($key, 'secret page text');

        self::assertSame(0600, fileperms($folder . '/' . $key . '.cache') & 0777);
    }

    public function testAWriteReplacesTheEntryAndLeavesNoTemporaryFile(): void
    {
        $folder = Kit::folder('cache');
        $cache = new Cache($folder);
        $key = Cache::key('GET', self::URL);

        $cache->put($key, str_repeat('a', 5_000));
        $cache->put($key, 'second');

        self::assertSame('second', $cache->get($key));
        self::assertSame([], glob($folder . '/.phycode-*.tmp') ?: []);
        self::assertSame([$key . '.cache'], array_map('basename', glob($folder . '/*') ?: []));
    }

    public function testTheFolderIsCreatedWhenItIsMissing(): void
    {
        $folder = Kit::folder('cache') . '/nested/web';
        $cache = new Cache($folder);

        $cache->put(Cache::key('GET', self::URL), 'value');

        self::assertSame(0700, fileperms($folder) & 0777);
        self::assertSame('value', $cache->get(Cache::key('GET', self::URL)));
    }

    public function testAFolderThatCannotBeCreatedIsAWebException(): void
    {
        $blocker = Kit::folder('cache') . '/a-file';
        Kit::write($blocker, 'not a folder');
        $cache = new Cache($blocker . '/inside');

        $this->expectException(WebException::class);
        $this->expectExceptionMessage('cannot create the cache folder');

        $cache->put(Cache::key('GET', self::URL), 'value');
    }

    public function testAKeyThatIsNotASha256IsRefusedSoItCannotLeaveTheFolder(): void
    {
        $this->expectException(WebException::class);
        $this->expectExceptionMessage('invalid cache key');

        (new Cache(Kit::folder('cache')))->get('../../etc/passwd');
    }

    public function testACorruptEntryIsAMiss(): void
    {
        $folder = Kit::folder('cache');
        $key = Cache::key('GET', self::URL);
        Kit::write($folder . '/' . $key . '.cache', "not a time\nvalue");

        self::assertNull((new Cache($folder))->get($key));
    }
}
