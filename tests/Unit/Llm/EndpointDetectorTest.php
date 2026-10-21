<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\EndpointDetector;
use PHPUnit\Framework\TestCase;

final class EndpointDetectorTest extends TestCase
{
    private function detector(array $answers, string $processes = ''): EndpointDetector
    {
        return new EndpointDetector(static fn (string $url): ?array => $answers[$url] ?? null, static fn (): string => $processes);
    }

    private static function json(mixed $value, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($value, JSON_THROW_ON_ERROR)];
    }

    public function testNothingListeningFindsNothing(): void
    {
        self::assertSame([], $this->detector([])->detect());
    }

    public function testOllamaIsFoundByItsTagsWithItsModels(): void
    {
        $found = $this->detector([
            'http://127.0.0.1:11434/api/tags' => self::json(['models' => [['name' => 'gemma4:e2b'], ['name' => 'qwen3:4b']]]),
        ])->detect();

        self::assertSame([[
            'name' => 'ollama', 'protocol' => 'ollama', 'baseUrl' => 'http://127.0.0.1:11434', 'server' => 'ollama',
            'count' => 2, 'models' => ['gemma4:e2b', 'qwen3:4b'], 'needsKey' => false,
        ]], $found);
    }

    public function testLlamaCppIsToldFromOtherOpenAiServersByItsProps(): void
    {
        $found = $this->detector([
            'http://127.0.0.1:9931/v1/models' => self::json(['data' => [['id' => 'qwen35-2b']]]),
            'http://127.0.0.1:9931/props' => self::json(['default_generation_settings' => ['n_ctx' => 4096]]),
        ])->detect();

        self::assertSame('llama.cpp', $found[0]['server']);
        self::assertSame(['llama-cpp', 'openai', 'http://127.0.0.1:9931', 1, ['qwen35-2b']], [$found[0]['name'], $found[0]['protocol'], $found[0]['baseUrl'], $found[0]['count'], $found[0]['models']]);
    }

    public function testAnOpenAiServerOnThePortOfLmStudioIsLmStudioAndOneThatAnswersVersionIsVllm(): void
    {
        $found = $this->detector([
            'http://127.0.0.1:1234/v1/models' => self::json(['data' => [['id' => 'phi']]]),
            'http://127.0.0.1:8000/v1/models' => self::json(['data' => [['id' => 'llama']]]),
            'http://127.0.0.1:8000/version' => self::json(['version' => '0.8.1']),
        ])->detect();

        self::assertSame(['lm-studio', 'vllm'], array_column($found, 'name'));
    }

    public function testAServerThatWantsAKeyIsFoundWithoutItsModels(): void
    {
        $found = $this->detector([
            'http://127.0.0.1:8080/v1/models' => ['status' => 401, 'body' => '{"error":{"message":"Invalid API Key"}}'],
        ])->detect();

        self::assertSame([['openai-compatible-8080', 'openai', 0, [], true]], array_map(
            static fn (array $e): array => [$e['name'], $e['protocol'], $e['count'], $e['models'], $e['needsKey']],
            $found,
        ));
    }

    public function testThePortOfARunningLlamaServerIsAskedToo(): void
    {
        $found = $this->detector([
            'http://127.0.0.1:8082/v1/models' => self::json(['data' => [['id' => 'qwen35-2b']]]),
            'http://127.0.0.1:8082/props' => self::json(['default_generation_settings' => []]),
        ], "/usr/bin/zsh\n/opt/llama/llama-server -m /models/q.gguf --port 8082 --jinja\nphp vendor/bin/phpunit\n")->detect();

        self::assertSame('http://127.0.0.1:8082', $found[0]['baseUrl']);
        self::assertSame('llama.cpp', $found[0]['server']);
    }

    public function testTwoServersOfAKindGetTheirPortInTheName(): void
    {
        $found = $this->detector([
            'http://127.0.0.1:9931/v1/models' => self::json(['data' => [['id' => 'a']]]),
            'http://127.0.0.1:9931/props' => self::json(['default_generation_settings' => []]),
            'http://127.0.0.1:8082/v1/models' => self::json(['data' => [['id' => 'b']]]),
            'http://127.0.0.1:8082/props' => self::json(['default_generation_settings' => []]),
        ], "llama-server --port 8082\n")->detect();

        self::assertSame(['llama-cpp-9931', 'llama-cpp-8082'], array_column($found, 'name'));
    }

    public function testAnAnswerThatIsNotAModelListIsNotAServer(): void
    {
        $found = $this->detector([
            'http://127.0.0.1:8080/v1/models' => ['status' => 200, 'body' => '<html>hello</html>'],
            'http://127.0.0.1:8000/v1/models' => self::json(['error' => 'x'], 404),
        ])->detect();

        self::assertSame([], $found);
    }

    public function testOnlyTheFirstModelsAreListedButAllAreCounted(): void
    {
        $many = array_map(static fn (int $i): array => ['name' => "m{$i}"], range(1, 20));

        $found = $this->detector(['http://127.0.0.1:11434/api/tags' => self::json(['models' => $many])])->detect();

        self::assertSame(20, $found[0]['count']);
        self::assertCount(6, $found[0]['models']);
    }
}
