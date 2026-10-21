<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Ollama;

use Phycode\Llm\Ollama\OllamaMessages;
use Phycode\Support\Json;
use PHPUnit\Framework\TestCase;

final class OllamaMessagesTest extends TestCase
{
    public function testSystemAndUserMessagesPassThrough(): void
    {
        $messages = [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hi']];

        self::assertSame($messages, $this->wire($messages));
    }

    public function testToolCallArgumentsGoOutAsAnObjectAndTheCallIdIsDropped(): void
    {
        $json = $this->json($this->assistant($this->call('read', '{"path":"src/a.php","limit":10}', 'call_1')));

        self::assertStringContainsString('"tool_calls":[{"function":{"name":"read","arguments":{"path":"src/a.php","limit":10}}}]', $json);
        self::assertStringNotContainsString('call_1', $json);
    }

    public function testEmptyOrBrokenArgumentsGoOutAsAnEmptyObject(): void
    {
        $json = $this->json($this->assistant(
            $this->call('time', '{}', 'call_1'),
            $this->call('read', '{"path":', 'call_2'),
        ));

        self::assertSame(2, substr_count($json, '"arguments":{}'), 'an empty object stays {}, and cut JSON becomes {}');
        self::assertStringNotContainsString('"arguments":[]', $json);
    }

    public function testNestedEmptyObjectsInTheArgumentsStayObjects(): void
    {
        $json = $this->json($this->assistant($this->call('edit', '{"options":{},"tags":[]}', 'call_1')));

        self::assertStringContainsString('"arguments":{"options":{},"tags":[]}', $json);
    }

    public function testAToolResultCarriesTheNameOfTheCallItAnswers(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Read it.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [$this->call('read', '{"path":"a"}', 'call_1')]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'file body'],
        ];

        $converted = $this->wire($messages);

        self::assertSame(['role' => 'tool', 'content' => 'file body', 'tool_name' => 'read'], $converted[2]);
    }

    public function testTheRecordedToolCallReplaysWithItsObjectArgumentsAndItsToolName(): void
    {
        $messages = [
            ['role' => 'user', 'content' => '¿qué tiempo hace en Madrid?'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [$this->call('get_weather', '{"city":"Madrid"}', 'call_meht2eww')]],
            ['role' => 'tool', 'tool_call_id' => 'call_meht2eww', 'content' => '{"city":"Madrid","condition":"clear","celsius":21}'],
        ];

        $converted = $this->wire($messages);

        self::assertSame(['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Madrid']]], $converted[1]['tool_calls'][0]);
        self::assertSame('get_weather', $converted[2]['tool_name']);
    }

    public function testAToolResultWithoutAKnownCallHasNoToolName(): void
    {
        $converted = $this->wire([['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => 'orphan']]);

        self::assertSame(['role' => 'tool', 'content' => 'orphan'], $converted[0]);
    }

    public function testIdsRestartEachReplyAndTheLatestCallWithTheIdNamesTheResult(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [$this->call('read', '{}', 'call_1')]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'first'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [$this->call('write', '{}', 'call_1')]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'second'],
        ];

        $converted = $this->wire($messages);

        self::assertSame('read', $converted[1]['tool_name']);
        self::assertSame('write', $converted[3]['tool_name']);
    }

    public function testAnAssistantMessageWithoutTextSendsAnEmptyContent(): void
    {
        $converted = $this->wire([['role' => 'assistant', 'tool_calls' => [$this->call('time', '{}', 'call_1')]]]);

        self::assertSame('', $converted[0]['content'], 'Ollama requires the content field on every message');
    }

    public function testThinkingAndOtherFieldsAreNotReplayed(): void
    {
        $converted = $this->wire([['role' => 'assistant', 'content' => 'Done.', 'thinking' => 'secret', 'reasoning_content' => 'secret']]);

        self::assertSame(['role' => 'assistant', 'content' => 'Done.'], $converted[0]);
    }

    private function wire(array $messages): array
    {
        return json_decode($this->json($messages), true, 512, JSON_THROW_ON_ERROR);
    }

    private function json(array $messages): string
    {
        return Json::encode(OllamaMessages::convert($messages));
    }

    private function call(string $name, string $arguments, string $id): array
    {
        return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]];
    }

    private function assistant(array ...$calls): array
    {
        return [['role' => 'assistant', 'content' => '', 'tool_calls' => $calls]];
    }
}
