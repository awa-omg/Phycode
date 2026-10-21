<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Profile;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Support\Cancellation;
use PHPUnit\Framework\TestCase;

final class OpenAiClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
    }

    public function testAnInterruptEndsARequestTheServerLeavesUnanswered(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($server, $message);
        $profile = new Profile(
            name: 'test',
            baseUrl: 'http://' . stream_socket_get_name($server, false),
            model: 'model',
            toolMode: 'native',
            contextLimit: 8192,
            maxOutputTokens: 64,
            sampling: [],
            extraBody: [],
            apiKeyFile: null,
            apiKeyEnv: null,
        );
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.5) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            (new OpenAiClient($profile))->chat([['role' => 'user', 'content' => 'hello']], [], new ChatOptions(null, $cancel));
            self::fail('the request should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: interrupted by the user', $error->getMessage());
        } finally {
            fclose($server);
        }
        self::assertLessThan(2.0, microtime(true) - $started);
    }
}
