<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Llm\Canonical\ModelInfo;
use Phycode\Llm\LlmException;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class ContextWindowTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'llama',
        'profiles' => [
            'llama' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
            'limited' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
            'ollama' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen3', 'tool_mode' => 'text', 'protocol' => 'ollama'],
            'ollama-limited' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen3', 'tool_mode' => 'text', 'protocol' => 'ollama', 'context_limit' => 8192],
        ],
    ];

    private SessionHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testALlamaServerReportsTheWindowOfTheModel(): void
    {
        $provider = new DescribingProvider([new ModelInfo('qwen', 'llama', 'openai', 16384)]);

        self::assertSame(16384, $this->session($provider, 'llama')->contextWindow());
        self::assertSame(['qwen'], $provider->described);
    }

    public function testAnOllamaProfileUsesItsContextLimitOrTheDefaultAndNeverAsksTheServer(): void
    {
        $provider = new DescribingProvider([new ModelInfo('qwen3', 'ollama', 'ollama', 2048)]);

        self::assertSame(16384, $this->session($provider, 'ollama')->contextWindow());
        self::assertSame(8192, $this->session($provider, 'ollama-limited')->contextWindow());
        self::assertSame([], $provider->described);
    }

    public function testAContextLimitTheConfigSetsWinsOverWhatTheServerReports(): void
    {
        $provider = new DescribingProvider([new ModelInfo('qwen', 'limited', 'openai', 16384)]);

        self::assertSame(4096, $this->session($provider, 'limited')->contextWindow());
        self::assertSame([], $provider->described);
    }

    public function testAServerThatCannotDescribeTheModelGivesNull(): void
    {
        $provider = new DescribingProvider([new LlmException('connection to the model server failed: refused')]);

        self::assertNull($this->session($provider, 'llama')->contextWindow());
    }

    public function testAServerThatReportsNoWindowGivesNull(): void
    {
        $provider = new DescribingProvider([new ModelInfo('qwen', 'llama', 'openai')]);

        self::assertNull($this->session($provider, 'llama')->contextWindow());
    }

    public function testTheWindowOfAModelIsAskedOnceAndAskedAgainAfterASwitch(): void
    {
        $provider = new DescribingProvider([
            new ModelInfo('qwen', 'llama', 'openai', 16384),
            new ModelInfo('mistral', 'llama', 'openai', 32768),
        ]);
        $session = $this->session($provider, 'llama');

        self::assertSame(16384, $session->contextWindow());
        self::assertSame(16384, $session->contextWindow());
        $session->switchModel('llama', 'mistral', 'off');
        self::assertSame(32768, $session->contextWindow());
        self::assertSame(['qwen', 'mistral'], $provider->described);
    }

    public function testAModelThatIsNotLoadedYetIsAskedAgain(): void
    {
        $provider = new DescribingProvider([
            new ModelInfo('qwen', 'llama', 'openai', null, null, [], false),
            new ModelInfo('qwen', 'llama', 'openai', 16384, null, [], true),
        ]);
        $session = $this->session($provider, 'llama');

        self::assertNull($session->contextWindow());
        self::assertSame(16384, $session->contextWindow());
        self::assertSame(16384, $session->contextWindow());
        self::assertSame(['qwen', 'qwen'], $provider->described);
    }

    public function testAFailedLookupIsKeptUntilTheModelIsSwitched(): void
    {
        $provider = new DescribingProvider([
            new LlmException('connection to the model server failed: refused'),
            new ModelInfo('qwen', 'llama', 'openai', 16384),
        ]);
        $session = $this->session($provider, 'llama');

        self::assertNull($session->contextWindow());
        self::assertNull($session->contextWindow(), 'the server is not asked again');
        self::assertSame(['qwen'], $provider->described);

        $session->switchModel('llama', null, 'off');
        self::assertSame(16384, $session->contextWindow());
        self::assertSame(['qwen', 'qwen'], $provider->described);
    }

    public function testAFailedLookupIsNotRepeatedForTheNextTurn(): void
    {
        $provider = new DescribingProvider(
            [new LlmException('connection to the model server failed: refused')],
            [ScriptedClient::text('one'), ScriptedClient::text('two')],
        );
        $session = $this->session($provider, 'llama');

        $session->contextWindow();
        $session->handle('first question');
        self::assertNull($session->contextWindow());
        $session->handle('second question');

        self::assertSame('completed', $session->lastStatus());
        self::assertSame(['qwen'], $provider->described, 'one lookup for both turns');
    }

    private function session(DescribingProvider $provider, string $profile): ChatSession
    {
        return $this->harness->session($this->harness->kernel($provider, new RecordingUi()), $profile);
    }
}
