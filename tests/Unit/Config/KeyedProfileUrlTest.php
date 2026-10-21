<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyedProfileUrlTest extends TestCase
{
    private const ENV = ['api_key_env' => 'PHYCODE_TEST_KEY'];
    private const REFUSED = "profile 'remote': base_url must be an https:// URL, because the key is sent to it";

    public static function urls(): iterable
    {
        yield 'https with a variable' => ['https://models.example/v1', self::ENV, true];
        yield 'http to a public address with a variable' => ['http://203.0.113.5:8000/v1', self::ENV, false];
        yield 'http to a private address with the vault' => ['http://192.168.1.10:8080', ['api_key_store' => true], false];
        yield 'http to a public name with a key file' => ['http://models.example/v1', ['api_key_file' => '/keys/remote'], false];
        yield 'http to a public name with a list of key files' => ['http://models.example/v1', ['api_key_files' => ['/keys/a', '/keys/b']], false];
        yield 'http to 127.0.0.1 with a variable' => ['http://127.0.0.1:8000/v1', self::ENV, true];
        yield 'http to localhost with a key file' => ['http://localhost:8000/v1', ['api_key_file' => '/keys/remote'], true];
        yield 'http to the IPv6 loopback with the vault' => ['http://[::1]:8000/v1', ['api_key_store' => true], true];
        yield 'a name that begins with 127.0.0.1' => ['http://127.0.0.1.example.test/v1', self::ENV, false];
        yield 'a user name in front of a public address' => ['http://127.0.0.1@models.example/v1', self::ENV, false];
        yield 'a unix socket with a variable' => ['unix:///run/phycode/llm.sock', self::ENV, true];
        yield 'http to a public address with no key' => ['http://203.0.113.5:8000/v1', [], true];
        yield 'http to a private address with no key' => ['http://192.168.1.10:8080', [], true];
    }

    #[DataProvider('urls')]
    public function testAKeyedProfileIsAcceptedOnlyOverHttpsOrToThisMachine(string $url, array $keySource, bool $accepted): void
    {
        $config = ['profiles' => ['remote' => ['base_url' => $url, 'model' => 'm'] + $keySource]];

        if ($accepted) {
            self::assertSame($url, Config::fromArrays($config, null, [], Kit::folder('keyed-url'))->profile('remote')->baseUrl);

            return;
        }
        try {
            Config::fromArrays($config, null, [], Kit::folder('keyed-url'));
            self::fail("{$url} was accepted with a key");
        } catch (ConfigException $error) {
            self::assertSame(self::REFUSED . ' (http is allowed for localhost, 127.0.0.1 and ::1 only)', $error->getMessage());
        }
    }
}
