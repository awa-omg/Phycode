<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Agent\Conversation;
use Phycode\Llm\Anthropic\AnthropicMessages;
use Phycode\Llm\Image;
use Phycode\Llm\Ollama\OllamaMessages;
use Phycode\Llm\Responses\ResponsesInput;
use Phycode\Tests\Support\Pictures;
use PHPUnit\Framework\TestCase;

final class ImageProtocolsTest extends TestCase
{
    private static function messages(): array
    {
        $conversation = new Conversation();
        $conversation->setSystem('system');
        $conversation->addUser('What is wrong here?', [Image::fromBytes(Pictures::bytes('png'), 'login.png'), Image::fromBytes(Pictures::bytes('jpg'), 'form.jpg')]);

        return $conversation->messages();
    }

    public function testAnthropicGetsTheImagesFirstAsBase64BlocksThenTheText(): void
    {
        $converted = AnthropicMessages::convert(self::messages(), false, true);

        self::assertSame([[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => Pictures::base64('png')]],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => Pictures::base64('jpg')]],
                ['type' => 'text', 'text' => 'What is wrong here?'],
            ],
        ]], $converted);
    }

    public function testAnImageAfterAToolResultJoinsTheSameUserTurnAfterTheResult(): void
    {
        $conversation = new Conversation();
        $conversation->setSystem('s');
        $conversation->addUser('look at shot.png');
        $conversation->addAssistant('', [new \Phycode\Llm\ToolCall('call_1', 'read', '{"path":"shot.png"}')]);
        $conversation->addToolResult('call_1', 'image shot.png: PNG 1x1');
        $conversation->addUser('Image from the tool call above: shot.png', [Image::fromBytes(Pictures::bytes(), 'shot.png')]);

        $converted = AnthropicMessages::convert($conversation->messages(), true, true);

        self::assertSame(['user', 'assistant', 'user'], array_column($converted, 'role'));
        self::assertSame(['tool_result', 'image', 'text'], array_column($converted[2]['content'], 'type'), 'the result comes first, as the API wants');
    }

    public function testWithoutVisionTheTextCarriesALineForEachImage(): void
    {
        $converted = AnthropicMessages::convert(self::messages(), false, false);

        self::assertSame([['type' => 'text', 'text' => "What is wrong here?\n[image: login.png, PNG 1x1]\n[image: form.jpg, JPEG 1x1]"]], $converted[0]['content']);
    }

    public function testOllamaGetsPlainBase64InTheImagesFieldOfTheMessage(): void
    {
        $converted = OllamaMessages::convert(self::messages(), true);

        self::assertSame(['role' => 'user', 'content' => 'What is wrong here?', 'images' => [Pictures::base64('png'), Pictures::base64('jpg')]], $converted[1]);
        $blind = OllamaMessages::convert(self::messages(), false);
        self::assertSame(['role' => 'user', 'content' => "What is wrong here?\n[image: login.png, PNG 1x1]\n[image: form.jpg, JPEG 1x1]"], $blind[1]);
    }

    public function testTheResponsesApiGetsInputTextAndInputImageParts(): void
    {
        $items = ResponsesInput::items(self::messages(), true);

        self::assertSame([[
            'type' => 'message',
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'What is wrong here?'],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,' . Pictures::base64('png')],
                ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . Pictures::base64('jpg')],
            ],
        ]], $items);
        $blind = ResponsesInput::items(self::messages(), false);
        self::assertSame("What is wrong here?\n[image: login.png, PNG 1x1]\n[image: form.jpg, JPEG 1x1]", $blind[0]['content']);
    }
}
