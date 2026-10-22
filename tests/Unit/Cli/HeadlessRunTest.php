<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Support\Json;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Llama\LlamaFake;
use PHPUnit\Framework\TestCase;

final class HeadlessRunTest extends TestCase
{
    private ?LlamaFake $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
    }

    private static function reply(string $text): array
    {
        $chunk = static fn (array $delta, ?string $finish = null): string => 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]], JSON_THROW_ON_ERROR) . "\n\n";

        return ['body' => $chunk(['role' => 'assistant', 'content' => $text]) . $chunk([], 'stop') . "data: [DONE]\n\n", 'type' => 'text/event-stream'];
    }

    private static function replyWithUsage(string $text, int $prompt, int $completion, int $cached): array
    {
        $chunk = static fn (array $delta, ?string $finish = null): string => 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]], JSON_THROW_ON_ERROR) . "\n\n";
        $usage = 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'prompt_tokens_details' => ['cached_tokens' => $cached]]], JSON_THROW_ON_ERROR) . "\n\n";

        return ['body' => $chunk(['role' => 'assistant', 'content' => $text]) . $chunk([], 'stop') . $usage . "data: [DONE]\n\n", 'type' => 'text/event-stream'];
    }

    private static function toolReply(): array
    {
        $call = ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list', 'arguments' => '{"path":"."}']]]];
        $chunk = static fn (array $delta, ?string $finish = null): string => 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]], JSON_THROW_ON_ERROR) . "\n\n";

        return ['body' => $chunk($call) . $chunk([], 'tool_calls') . "data: [DONE]\n\n", 'type' => 'text/event-stream'];
    }

    private function serve(array $replies): array
    {
        $this->server = new LlamaFake(['POST /v1/chat/completions' => $replies]);

        return ['base_url' => $this->server->url(), 'model' => 'qwen35-2b', 'tool_mode' => 'native'];
    }

    private function chats(): array
    {
        return array_values(array_map(
            static fn (array $request): array => $request['json'],
            array_filter($this->server->requests(), static fn (array $request): bool => $request['method'] === 'POST'),
        ));
    }

    public function testTheModelOfTheRunReplacesTheOneOfTheProfile(): void
    {
        $profile = $this->serve([self::reply('hi')]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-model'), '--model', 'other-model-7b', 'Say hi.'], $profile);

        self::assertSame(0, $run->status, $run->stderr);
        self::assertSame('other-model-7b', $this->chats()[0]['model']);
    }

    public function testTheResultSaysWhatTheRunSpentAndWhatItCost(): void
    {
        $profile = $this->serve([self::replyWithUsage('hello', 1000, 200, 400)]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-usage'), '--output-format', 'json', 'Say hi.'], $profile, global: ['pricing' => ['qwen35-2b' => ['input' => 2, 'output' => 10, 'cached_input' => 0.5]]]);

        self::assertSame(0, $run->status, $run->stderr);
        $usage = Json::object($run->stdout)['usage'] ?? [];
        self::assertSame(['requests' => 1, 'prompt_tokens' => 1000, 'completion_tokens' => 200, 'cached_tokens' => 400, 'cost_usd' => 0.0034, 'priced' => true], $usage);
    }

    public function testWithoutAPriceTheCostIsNullAndNotInvented(): void
    {
        $profile = $this->serve([self::replyWithUsage('hello', 50, 5, 0)]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-usage-free'), '--output-format', 'json', 'Say hi.'], $profile);

        $usage = Json::object($run->stdout)['usage'] ?? [];
        self::assertSame([1, 50, 5], [$usage['requests'], $usage['prompt_tokens'], $usage['completion_tokens']]);
        self::assertNull($usage['cost_usd']);
        self::assertFalse($usage['priced']);
    }

    public function testAnImageGoesWithTheTaskAsPartsOfTheMessageOfAChatCompletion(): void
    {
        $profile = $this->serve([self::reply('A red button.')]) + ['vision' => true];
        $picture = \Phycode\Tests\Support\Pictures::file('login.png');

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-image'), '--image', $picture, 'Why is it red?'], $profile);

        self::assertSame(0, $run->status, $run->stderr);
        $user = $this->chats()[0]['messages'][1];
        self::assertSame('user', $user['role']);
        self::assertSame([
            ['type' => 'text', 'text' => 'Why is it red?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . \Phycode\Tests\Support\Pictures::base64()]],
        ], $user['content']);
    }

    public function testAnImageForAProfileThatCannotReadImagesStopsTheRunBeforeTheModelIsAsked(): void
    {
        $profile = $this->serve([self::reply('never')]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-image-blind'), '--image', \Phycode\Tests\Support\Pictures::file('a.png'), 'Why?'], $profile);

        self::assertSame(1, $run->status);
        self::assertStringContainsString('the profile test is not set to read images: put "vision": true in it', $run->stderr);
        self::assertSame([], $this->chats());

        $missing = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-image-missing'), '--image', '/nonexistent/x.png', 'Why?'], $profile + ['vision' => true]);
        self::assertSame(1, $missing->status);
        self::assertStringContainsString('is not a file', $missing->stderr);
    }

    public function testMaxTurnsStopsTheRunAndSaysSo(): void
    {
        $profile = $this->serve([self::toolReply(), self::reply('Here is my report.')]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-turns'), '--yolo', '--max-turns', '1', '--output-format', 'json', 'List the files.'], $profile);

        $result = Json::object($run->stdout) ?? [];
        self::assertSame('budget', $result['status'] ?? null, $run->stdout . $run->stderr);
        self::assertSame(3, $run->status);
        self::assertSame(1, $result['turns']);
        self::assertSame('Here is my report.', $result['text'], 'the model is asked for a report when the turns are used up');
    }

    public function testContinueGoesOnWithTheSavedSessionAndResumeNamesIt(): void
    {
        $profile = $this->serve([self::reply('The answer is 42.'), self::reply('Because I was asked.'), self::reply('Third.')]);
        $cwd = Kit::folder('hl-continue');
        $home = Kit::folder('hl-continue-home');

        $first = PhycodeRun::run(['run', '--cwd', $cwd, '--output-format', 'json', 'What is the answer?'], $profile, home: $home);
        $firstResult = Json::object($first->stdout) ?? [];
        self::assertSame(0, $first->status, $first->stderr);
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[0-9a-f]{6}$/', (string) $firstResult['session_id']);

        $second = PhycodeRun::run(['run', '--cwd', $cwd, '--continue', '--output-format', 'json', 'Why?'], $profile, home: $home);
        $secondResult = Json::object($second->stdout) ?? [];
        self::assertSame(0, $second->status, $second->stderr);
        self::assertSame($firstResult['session_id'], $secondResult['session_id'], 'the same session goes on');
        $contents = array_column($this->chats()[1]['messages'], 'content');
        self::assertContains('What is the answer?', $contents);
        self::assertContains('The answer is 42.', $contents);
        self::assertSame('Why?', end($contents));

        $third = PhycodeRun::run(['run', '--cwd', $cwd, '--resume', (string) $firstResult['session_id'], 'And then?'], $profile, home: $home);
        self::assertSame(0, $third->status, $third->stderr);
        self::assertContains('Because I was asked.', array_column($this->chats()[2]['messages'], 'content'));
        self::assertStringContainsString('session: ' . $firstResult['session_id'], $third->stderr);
    }

    public function testForkStartsFromACopyOfTheSessionAndLeavesTheOriginalAsItWas(): void
    {
        $profile = $this->serve([self::reply('The answer is 42.'), self::reply('Because I was asked.')]);
        $cwd = Kit::folder('hl-fork');
        $home = Kit::folder('hl-fork-home');
        $first = PhycodeRun::run(['run', '--cwd', $cwd, '--output-format', 'json', 'What is the answer?'], $profile, home: $home);
        $firstId = (string) (Json::object($first->stdout)['session_id'] ?? '');
        $before = (string) file_get_contents("{$cwd}/.phycode/sessions/{$firstId}.json");

        $fork = PhycodeRun::run(['run', '--cwd', $cwd, '--fork', $firstId, '--output-format', 'json', 'Why?'], $profile, home: $home);

        $forkId = (string) (Json::object($fork->stdout)['session_id'] ?? '');
        self::assertSame(0, $fork->status, $fork->stderr);
        self::assertNotSame($firstId, $forkId);
        self::assertSame($before, (string) file_get_contents("{$cwd}/.phycode/sessions/{$firstId}.json"), 'the original is not touched');
        $saved = Json::object((string) file_get_contents("{$cwd}/.phycode/sessions/{$forkId}.json")) ?? [];
        self::assertSame($firstId, $saved['parent']);
        self::assertSame(count(Json::object($before)['messages']), $saved['forkAt']);
        $contents = array_column($this->chats()[1]['messages'], 'content');
        self::assertContains('The answer is 42.', $contents);
        self::assertSame('Why?', end($contents));
    }

    public function testContinueWithoutASessionAndResumeOfAnUnknownOneFail(): void
    {
        $profile = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b'];

        $none = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-none'), '--continue', 'Go on.'], $profile);
        $unknown = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-unknown'), '--resume', '20990101-000000-abcdef', 'Go on.'], $profile);
        $both = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-both'), '--continue', '--resume', 'x1', 'Go on.'], $profile);

        self::assertSame(1, $none->status);
        self::assertStringContainsString('there is no session to continue in this project', $none->stderr);
        self::assertSame(1, $unknown->status);
        self::assertStringContainsString('no session named 20990101-000000-abcdef', $unknown->stderr);
        self::assertSame(1, $both->status);
        self::assertStringContainsString('--continue and --resume cannot be used together', $both->stderr);
    }

    public function testStreamJsonWritesOneEventPerLineAndNothingElseOnStdout(): void
    {
        $profile = $this->serve([self::toolReply(), self::reply('Done listing.')]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-stream'), '--yolo', '--output-format', 'stream-json', 'List the files.'], $profile);

        self::assertSame(0, $run->status, $run->stderr);
        $events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", $run->stdout))));
        $types = array_column($events, 'type');
        self::assertSame('init', $types[0]);
        self::assertSame('result', end($types));
        self::assertContains('tool_start', $types);
        self::assertContains('tool_end', $types);
        self::assertContains('text', $types);
        self::assertLessThan(array_search('tool_end', $types, true), array_search('tool_start', $types, true));
        self::assertSame('list', $events[array_search('tool_start', $types, true)]['tool']);
        self::assertSame('Done listing.', implode('', array_column(array_filter($events, static fn (array $e): bool => $e['type'] === 'text'), 'text')));
        $result = end($events);
        self::assertSame('completed', $result['status']);
        self::assertSame($events[0]['session_id'], $result['session_id']);
        self::assertSame('qwen35-2b', $events[0]['model']);
    }

    public function testAnAnswerThatFitsTheSchemaIsPrintedAsJsonAndInTheResult(): void
    {
        $schema = ['type' => 'object', 'properties' => ['functions' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['functions']];
        $profile = $this->serve([self::reply("Sure:\n```json\n{\"functions\": [\"login\", \"logout\"]}\n```")]);

        $text = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-schema'), '--output-schema', json_encode($schema), 'List the functions.'], $profile);
        self::assertSame(0, $text->status, $text->stderr);
        self::assertSame(['functions' => ['login', 'logout']], Json::object($text->stdout));
        self::assertStringContainsString('"functions"', $this->chats()[0]['messages'][1]['content'], 'the schema is in the task');
        self::assertStringContainsString('JSON Schema', $this->chats()[0]['messages'][1]['content']);
    }

    public function testAnAnswerThatDoesNotFitIsAskedAgainAndThenGivenUpOnWithExitFour(): void
    {
        $schemaFile = Kit::folder('hl-schema-file') . '/schema.json';
        file_put_contents($schemaFile, json_encode(['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'required' => ['count']]));
        $profile = $this->serve([self::reply('{"count": "three"}'), self::reply('{"count": "four"}'), self::reply('no json at all')]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-schema-bad'), '--output-schema', $schemaFile, '--output-format', 'json', 'Count.'], $profile);

        self::assertSame(4, $run->status, $run->stderr);
        $result = Json::object($run->stdout) ?? [];
        self::assertSame('invalid_output', $result['status']);
        self::assertStringContainsString('the answer has no JSON value in it', (string) $result['reason']);
        self::assertArrayNotHasKey('structured_output', $result);
        self::assertCount(3, $this->chats(), 'the first answer and two more asks');
        $last = end($this->chats()[2]['messages']);
        self::assertStringContainsString('$.count: expected integer, got string', $this->chats()[1]['messages'][array_key_last($this->chats()[1]['messages'])]['content']);
        self::assertStringContainsString('Reply again with only the corrected JSON value', $last['content']);
    }

    public function testAnAnswerThatIsFixedOnTheSecondTryIsTheResult(): void
    {
        $profile = $this->serve([self::reply('{"count": "three"}'), self::reply('{"count": 3}')]);
        $schema = json_encode(['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'required' => ['count']]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-schema-fix'), '--output-schema', $schema, '--output-format', 'json', 'Count.'], $profile);

        self::assertSame(0, $run->status, $run->stderr);
        $result = Json::object($run->stdout) ?? [];
        self::assertSame('completed', $result['status']);
        self::assertSame(['count' => 3], $result['structured_output']);
    }

    public function testASchemaThatCannotBeEnforcedStopsTheRunBeforeTheModelIsAsked(): void
    {
        $profile = $this->serve([self::reply('never')]);

        $run = PhycodeRun::run(['run', '--cwd', Kit::folder('hl-schema-refused'), '--output-schema', '{"patternProperties":{"^a":{}}}', 'Go.'], $profile);

        self::assertSame(1, $run->status);
        self::assertStringContainsString("--output-schema: schema #: the keyword 'patternProperties' is not supported", $run->stderr);
        self::assertSame([], $this->chats());
    }

    public function testTheNewOptionsBelongToRunAndTheirValuesAreChecked(): void
    {
        $profile = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen35-2b'];

        foreach ([
            [['chat', '--model', 'x'], '--model is an option of run'],
            [['agents', '--max-turns', '3'], '--max-turns is an option of run'],
            [['run', '--max-turns', '0', 'x'], '--max-turns must be a whole number from 1 to 9999'],
            [['run', '--output-format', 'xml', 'x'], '--output-format must be text, json or stream-json'],
            [['run', '--json', '--output-format', 'stream-json', 'x'], '--json and --output-format say different formats'],
            [['run', '--resume', '../etc', 'x'], '--resume needs a session id'],
            [['run', '--fork', '../etc', 'x'], '--fork needs a session id'],
            [['run', '--fork', 'x1', '--continue', 'x'], '--fork starts from the one session it names'],
            [['chat', '--fork', 'x1'], '--fork is an option of run'],
            [['run', '--output-schema', '/nonexistent/schema.json', 'x'], "--output-schema: '/nonexistent/schema.json' is neither a JSON object nor a readable file"],
        ] as [$arguments, $message]) {
            $run = PhycodeRun::run($arguments, $profile);

            self::assertSame(1, $run->status, implode(' ', $arguments));
            self::assertStringContainsString($message, $run->stderr, implode(' ', $arguments));
        }
    }
}
