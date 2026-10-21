<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Rpc;

use Phycode\Support\Redaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedactionTest extends TestCase
{
    public static function messages(): iterable
    {
        yield 'the user part of a URL' => [
            'connection to https://user:hunter2@models.example:8082/v1 failed',
            'connection to https://models.example:8082/v1 failed',
        ];
        yield 'an authorization header' => [
            'request failed; Authorization: Bearer abc.def-123',
            'request failed; Authorization: [redacted]',
        ];
        yield 'an api key header with an equals sign' => [
            'x-api-key=sk-live-123456789 rejected',
            'x-api-key=[redacted] rejected',
        ];
        yield 'a bearer token' => [
            'token Bearer abc123xyz was refused',
            'token Bearer [redacted] was refused',
        ];
        yield 'a key written in the sk form' => [
            'the key sk-proj-ABCDEFGH1234 is not valid',
            'the key [redacted] is not valid',
        ];
        yield 'an authorization header with another scheme' => [
            'request failed; Authorization: token abc123xyz',
            'request failed; Authorization: [redacted]',
        ];
        yield 'an api-key header' => [
            'api-key: 9f8e7d6c5b4a rejected',
            'api-key: [redacted] rejected',
        ];
        yield 'an api_key in a query string' => [
            'GET https://models.example/v1/models?api_key=9f8e7d6c5b4a&limit=2 failed',
            'GET https://models.example/v1/models?api_key=[redacted]&limit=2 failed',
        ];
        yield 'a secret of the configuration' => [
            'value hunter2-secret-value here',
            'value [redacted] here',
            ['hunter2-secret-value'],
        ];
        yield 'an at sign in a path or an scp address stays' => [
            'see http://host.example:8080/path@v2 and git@host:repo.git',
            'see http://host.example:8080/path@v2 and git@host:repo.git',
        ];
        yield 'a quoted json api key' => [
            '{"api_key": "fake-json-key-1234"} was refused',
            '{"api_key": "[redacted]"} was refused',
        ];
        yield 'a quoted json authorization member' => [
            '{"Authorization":"Bearer abc123xyz"}',
            '{"Authorization":"[redacted]"}',
        ];
        yield 'a password that holds an at sign' => [
            'connection to http://user:p@ss@models.example:8082/v1 failed',
            'connection to http://models.example:8082/v1 failed',
        ];
        yield 'plain text stays' => [
            'connection to the model server failed',
            'connection to the model server failed',
        ];
    }

    #[DataProvider('messages')]
    public function testSecretsAndCredentialsAreTakenOut(string $message, string $expected, array $secrets = []): void
    {
        self::assertSame($expected, Redaction::text($message, $secrets));
    }

    public static function endpoints(): iterable
    {
        yield 'a user name and a password' => ['http://u:p@127.0.0.1:9', 'http://127.0.0.1:9'];
        yield 'a password with an at sign, and a path' => ['https://user:p@ss@models.example:8443/v1/', 'https://models.example:8443'];
        yield 'an ipv6 host' => ['http://[::1]:8082', 'http://[::1]:8082'];
        yield 'no port' => ['https://api.example.com/v1', 'https://api.example.com'];
        yield 'a unix socket keeps its path' => ['unix:///tmp/llama.sock', 'unix:///tmp/llama.sock'];
    }

    #[DataProvider('endpoints')]
    public function testTheEndpointHasOnlySchemeHostAndPort(string $url, string $endpoint): void
    {
        self::assertSame($endpoint, Redaction::endpoint($url));
    }
}
