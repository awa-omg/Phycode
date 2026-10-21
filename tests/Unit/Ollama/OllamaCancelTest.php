<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Config\Config;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\OllamaServer;
use PHPUnit\Framework\TestCase;

final class OllamaCancelTest extends TestCase
{
    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
    }

    public function testAnInterruptEndsARequestTheServerLeavesUnanswered(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://' . stream_socket_get_name($socket, false), 'model' => 'qwen3:4b', 'protocol' => 'ollama']]], null, [], '/nonexistent')->profile('test');
        $cancel = new Cancellation();
        $started = microtime(true);
        Cancellation::setPoll(static function () use ($cancel, $started): void {
            if (microtime(true) - $started >= 0.5) {
                $cancel->cancel('interrupted by the user');
            }
        });

        try {
            (new OllamaClient($profile))->chat([['role' => 'user', 'content' => 'hello']], [], new ChatOptions(null, $cancel));
            self::fail('the request should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: interrupted by the user', $error->getMessage());
        } finally {
            fclose($socket);
        }
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testAnInterruptInTheMiddleOfTheStreamEndsTheReplyAtOnce(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($listener, $message);
        stream_set_blocking($listener, false);
        $head = implode("\n", array_slice(explode("\n", OllamaServer::fixture('live/chat-text.ndjson')), 0, 2)) . "\n";
        $connections = [];
        Cancellation::setPoll(static function () use ($listener, $head, &$connections): void {
            $connection = $connections === [] ? @stream_socket_accept($listener, 0) : false;
            if (!is_resource($connection)) {
                return;
            }
            $connections[] = $connection;
            stream_set_timeout($connection, 2);
            $length = 0;
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                if (stripos($line, 'content-length:') === 0) {
                    $length = (int) substr($line, 15);
                }
            }
            if ($length > 0) {
                fread($connection, $length);
            }
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/x-ndjson\r\nConnection: close\r\n\r\n" . $head);
        });
        $profile = Config::fromArrays(['profiles' => ['test' => ['base_url' => 'http://' . stream_socket_get_name($listener, false) . '/chat', 'model' => 'qwen3:4b', 'protocol' => 'ollama']]], null, [], '/nonexistent')->profile('test');
        $cancel = new Cancellation();
        $texts = [];
        $options = new ChatOptions(
            onEvent: static function (string $type, string $text) use ($cancel, &$texts): void {
                $texts[] = $text;
                $cancel->cancel('stopped by the user');
            },
            cancel: $cancel,
        );
        $started = microtime(true);

        try {
            (new OllamaClient($profile))->chat([['role' => 'user', 'content' => 'colours?']], [], $options);
            self::fail('the reply should have been cancelled');
        } catch (LlmException $error) {
            self::assertSame('cancelled: stopped by the user', $error->getMessage());
        } finally {
            foreach ($connections as $connection) {
                fclose($connection);
            }
            fclose($listener);
        }

        self::assertLessThan(2.0, microtime(true) - $started, 'the reply was not waited for: the server never finishes it');
        self::assertSame('**', $texts[0] ?? null, 'the first line was read before the interrupt');
    }
}
