<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Config;
use Phycode\Config\Profile;
use Phycode\Config\ProviderPresets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderPresetsTest extends TestCase
{
    public static function endpoints(): array
    {
        return [
            'openai' => ['openai', '/v1/chat/completions', 'https://api.openai.com/v1/chat/completions'],
            'anthropic' => ['anthropic', '/v1/messages', 'https://api.anthropic.com/v1/messages'],
            'openrouter' => ['openrouter', '/v1/chat/completions', 'https://openrouter.ai/api/v1/chat/completions'],
            'groq' => ['groq', '/v1/chat/completions', 'https://api.groq.com/openai/v1/chat/completions'],
            'lmstudio' => ['lmstudio', '/v1/chat/completions', 'http://127.0.0.1:1234/v1/chat/completions'],
            'vllm' => ['vllm', '/v1/chat/completions', 'http://127.0.0.1:8000/v1/chat/completions'],
            'ollama-cloud' => ['ollama-cloud', '/api/chat', 'https://ollama.com/api/chat'],
        ];
    }

    public function testTheSevenPresetsAreOfferedInOrder(): void
    {
        self::assertSame(
            ['openai', 'anthropic', 'openrouter', 'groq', 'lmstudio', 'vllm', 'ollama-cloud'],
            array_column(ProviderPresets::all(), 'name'),
        );
    }

    public function testEachPresetHasTheFourFields(): void
    {
        foreach (ProviderPresets::all() as $preset) {
            self::assertSame(['name', 'protocol', 'baseUrl', 'apiKeyEnv'], array_keys($preset), $preset['name']);
        }
    }

    public function testEveryPresetNamesAProtocolTheConfigKnows(): void
    {
        foreach (ProviderPresets::all() as $preset) {
            self::assertContains($preset['protocol'], Profile::PROTOCOLS, $preset['name']);
        }
    }

    public function testTheAnthropicAndTheOllamaCloudPresetsUseTheirOwnProtocols(): void
    {
        self::assertSame(['anthropic', 'ollama'], [self::preset('anthropic')['protocol'], self::preset('ollama-cloud')['protocol']]);
    }

    public function testTheLocalServersHaveNoKeyAndTheCloudOnesNameTheirVariable(): void
    {
        self::assertNull(self::preset('lmstudio')['apiKeyEnv']);
        self::assertNull(self::preset('vllm')['apiKeyEnv']);
        self::assertSame(['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'OLLAMA_API_KEY'], [
            self::preset('openai')['apiKeyEnv'],
            self::preset('anthropic')['apiKeyEnv'],
            self::preset('ollama-cloud')['apiKeyEnv'],
        ]);
    }

    public function testNoPresetCarriesAKeyValue(): void
    {
        foreach (ProviderPresets::all() as $preset) {
            self::assertTrue($preset['apiKeyEnv'] === null || preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $preset['apiKeyEnv']) === 1, $preset['name']);
        }
    }

    #[DataProvider('endpoints')]
    public function testTheConfigAcceptsThePresetAndItsClientReachesTheDocumentedEndpoint(string $name, string $path, string $url): void
    {
        $preset = self::preset($name);
        $raw = ['base_url' => $preset['baseUrl'], 'model' => 'model-x', 'protocol' => $preset['protocol']];
        if ($preset['apiKeyEnv'] !== null) {
            $raw['api_key_env'] = $preset['apiKeyEnv'];
        }

        $profile = Config::fromArrays(['profiles' => [$name => $raw]], null, [], '/nonexistent')->profile($name);

        self::assertSame([$preset['protocol'], $preset['baseUrl']], [$profile->protocol, $profile->baseUrl]);
        self::assertSame($url, $profile->url($path));
    }

    private static function preset(string $name): array
    {
        foreach (ProviderPresets::all() as $preset) {
            if ($preset['name'] === $name) {
                return $preset;
            }
        }

        self::fail("no preset named {$name}");
    }
}
