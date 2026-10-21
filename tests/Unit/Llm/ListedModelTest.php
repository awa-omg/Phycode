<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Llm\Canonical\ListedModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListedModelTest extends TestCase
{
    public static function entries(): iterable
    {
        yield 'openai: the id alone' => [['id' => 'gpt-5', 'object' => 'model', 'created' => 1, 'owned_by' => 'openai'], null, null, null];
        yield 'llama.cpp: meta.n_ctx' => [['id' => 'qwen', 'meta' => ['n_ctx' => 32768]], 32768, null, null];
        yield 'openrouter: context_length and supported parameters' => [
            ['id' => 'a/b', 'context_length' => 200000, 'supported_parameters' => ['temperature', 'tools', 'top_k'], 'architecture' => ['output_modalities' => ['text']]],
            200000, true, null,
        ];
        yield 'openrouter: a model that does not list tools' => [['id' => 'a/c', 'context_length' => 8192, 'supported_parameters' => ['temperature']], 8192, false, null];
        yield 'openrouter: the window of its provider' => [['id' => 'a/d', 'top_provider' => ['context_length' => 64000]], 64000, null, null];
        yield 'openrouter: an image model' => [['id' => 'a/img', 'architecture' => ['output_modalities' => ['image']]], null, null, 'image'];
        yield 'groq: context_window' => [['id' => 'llama-3.3-70b-versatile', 'context_window' => 131072, 'active' => true], 131072, null, null];
        yield 'vllm: max_model_len' => [['id' => 'meta/llama', 'max_model_len' => 16384], 16384, null, null];
        yield 'mistral: function calling' => [['id' => 'mistral-large', 'max_context_length' => 128000, 'capabilities' => ['function_calling' => true]], 128000, true, null];
        yield 'fireworks: supports_tools false' => [['id' => 'f/x', 'context_length' => 4096, 'supports_tools' => false], 4096, false, null];
        yield 'a window that is zero or text is not a window' => [['id' => 'z', 'context_length' => 0, 'context_window' => '8k'], null, null, null];
        yield 'llama.cpp window wins over the rest' => [['id' => 'w', 'meta' => ['n_ctx' => 4096], 'context_length' => 999999], 4096, null, null];
    }

    #[DataProvider('entries')]
    public function testTheEntryGivesWhatItSays(array $entry, ?int $window, ?bool $tools, ?string $kind): void
    {
        $info = ListedModel::from($entry, 'p', 'openai');

        self::assertSame([$entry['id'], 'p', 'openai', $window, $tools, $kind, 'v1/models'], [$info->id, $info->profile, $info->protocol, $info->contextWindow, $info->tools, $info->kind, $info->source]);
    }

    public static function ids(): iterable
    {
        yield 'a chat model' => ['gpt-5', null];
        yield 'a reasoning model' => ['o3-mini', null];
        yield 'an open model' => ['llama-3.3-70b-versatile', null];
        yield 'an embedding model' => ['text-embedding-3-small', 'embedding'];
        yield 'an embedding model of another name' => ['nomic-embed-text:latest', 'embedding'];
        yield 'whisper' => ['whisper-1', 'audio'];
        yield 'speech' => ['tts-1-hd', 'audio'];
        yield 'transcription' => ['gpt-4o-mini-transcribe', 'audio'];
        yield 'realtime' => ['gpt-4o-realtime-preview', 'audio'];
        yield 'dall-e' => ['dall-e-3', 'image'];
        yield 'gpt-image' => ['gpt-image-1', 'image'];
        yield 'moderation' => ['omni-moderation-latest', 'moderation'];
        yield 'a chat model that has a 4o in its name' => ['gpt-4o-mini', null];
    }

    #[DataProvider('ids')]
    public function testAModelThatIsNotForChatIsToldByItsId(string $id, ?string $kind): void
    {
        self::assertSame($kind, ListedModel::kindOfId($id));
        self::assertSame($kind, ListedModel::from(['id' => $id], 'p', 'openai')->kind);
    }

    public static function reportedEfforts(): iterable
    {
        yield 'OpenRouter lists its levels in descending order: they come in order of the levels' => [
            ['id' => 'a', 'reasoning' => ['supported_efforts' => ['high', 'medium', 'low', 'minimal'], 'mandatory' => false]],
            ['effort' => ['minimal', 'low', 'medium', 'high'], 'mandatory' => false],
        ];
        yield 'OpenRouter, mandatory: none is taken out, since the model rejects it' => [
            ['id' => 'b', 'reasoning' => ['supported_efforts' => ['none', 'low', 'high'], 'mandatory' => true]],
            ['effort' => ['low', 'high'], 'mandatory' => true],
        ];
        yield 'OpenRouter, null: every level, none among them' => [
            ['id' => 'c', 'reasoning' => ['supported_efforts' => null]],
            ['effort' => ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], 'mandatory' => false],
        ];
        yield 'OpenRouter with no list names no effort' => [['id' => 'd', 'reasoning' => ['supports_max_tokens' => true]], null];
        yield 'a name that is not a level, such as default, is dropped' => [['id' => 'e', 'reasoning' => ['supported_efforts' => ['default', 'low']]], ['effort' => ['low'], 'mandatory' => false]];
        yield 'a mandatory model with none alone takes no level' => [['id' => 'f', 'reasoning' => ['supported_efforts' => ['none'], 'mandatory' => true]], null];
        yield 'xAI: the reasoning_effort of the capabilities' => [['id' => 'g', 'capabilities' => ['reasoning_effort' => ['low', 'xhigh']]], ['effort' => ['low', 'xhigh'], 'mandatory' => false]];
        yield 'xAI: a value of another type is not an error' => [['id' => 'h', 'capabilities' => ['reasoning_effort' => 'high']], null];
        yield 'DeepSeek: its supported levels and none, which turns thinking off' => [['id' => 'i', 'effort' => ['supported_levels' => ['high', 'max']]], ['effort' => ['none', 'high', 'max'], 'mandatory' => false]];
        yield 'a model with no field names no levels' => [['id' => 'j'], null];
    }

    #[DataProvider('reportedEfforts')]
    public function testTheLevelsAListingEntryReportsAreReadAndAnyOtherShapeIsNoLevels(array $entry, ?array $levels): void
    {
        self::assertSame($levels, ListedModel::efforts($entry));
    }
}
