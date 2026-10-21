<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Llm\ChatOptions;
use Phycode\Llm\LlmException;
use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Tests\Support\OllamaServer;
use PHPUnit\Framework\TestCase;

final class OllamaErrorTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'hello']];

    private ?OllamaServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    public function testAnErrorStatusIsReportedWithTheServerMessageAndNotRetried(): void
    {
        $server = $this->serve(['status' => 404, 'body' => '{"error":"model \'no-such:latest\' not found"}', 'type' => 'application/json']);

        $error = $this->failure($server);

        self::assertSame("HTTP 404: model 'no-such:latest' not found", $error->getMessage());
        self::assertCount(1, $server->requests());
    }

    public function testAnUnknownModelIsRefusedWithTheLiveMessageAndNotRetried(): void
    {
        $server = $this->serve(['status' => 404, 'fixture' => 'live/chat-not-found.json', 'type' => 'application/json']);

        self::assertSame("HTTP 404: model 'no-such-model:latest' not found", $this->failure($server)->getMessage());
        self::assertCount(1, $server->requests());
    }

    public function testAnErrorObjectWithAMessageIsReportedToo(): void
    {
        $server = $this->serve(['status' => 400, 'body' => '{"error":{"message":"bad request here"}}', 'type' => 'application/json']);

        self::assertSame('HTTP 400: bad request here', $this->failure($server)->getMessage());
    }

    public function testAnErrorBodyThatIsNotJsonIsQuotedInTheMessage(): void
    {
        $server = $this->serve(['status' => 500, 'body' => 'boom', 'type' => 'text/plain']);

        self::assertSame('HTTP 500: boom', $this->failure($server)->getMessage());
    }

    public function testAnErrorStatusIsNotParsedAsAReply(): void
    {
        $server = $this->serve(['status' => 500, 'body' => "{\"error\":\"runner crashed\"}\n", 'type' => 'application/x-ndjson']);

        self::assertSame('HTTP 500: runner crashed', $this->failure($server)->getMessage());
    }

    public function testAnErrorLineInAnOkReplyIsAServerError(): void
    {
        $server = $this->serve(['body' => '{"error":"out of memory"}' . "\n", 'type' => 'application/x-ndjson']);

        self::assertSame('server error: out of memory', $this->failure($server)->getMessage());
    }

    public function testAStreamThatEndsBeforeItsFinalLineIsAnError(): void
    {
        $partial = '{"message":{"role":"assistant","content":"The"},"done":false}' . "\n"
            . '{"message":{"role":"assistant","content":" three"},"done":false}' . "\n";
        $server = $this->serve(['body' => $partial, 'type' => 'application/x-ndjson']);

        self::assertSame('server error: the reply ended before its final line', $this->failure($server)->getMessage());
    }

    public function testAnEmptyOkReplyIsAnError(): void
    {
        $server = $this->serve(['body' => '', 'type' => 'application/x-ndjson']);

        self::assertSame('server error: the reply ended before its final line', $this->failure($server)->getMessage());
    }

    private function failure(OllamaServer $server): LlmException
    {
        try {
            (new OllamaClient($server->profile('chat')))->chat(self::MESSAGES, [], new ChatOptions());
        } catch (LlmException $error) {
            return $error;
        }
        self::fail('the reply should have failed');
    }

    private function serve(array $step): OllamaServer
    {
        $this->server?->stop();

        return $this->server = new OllamaServer(['chat' => ['/api/chat' => [$step]]]);
    }
}
