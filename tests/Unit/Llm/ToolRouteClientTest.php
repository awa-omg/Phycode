<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Config\Profile;
use Phycode\Llm\ChatOptions;
use Phycode\Llm\EffortMapping;
use Phycode\Llm\LlmException;
use Phycode\Llm\OpenAiClient;
use Phycode\Llm\Responses\ResponsesClient;
use Phycode\Llm\ToolRouteClient;
use Phycode\Tests\Support\ProtocolServer;
use PHPUnit\Framework\TestCase;

final class ToolRouteClientTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'What is the weather in Madrid?']];
    private const WEATHER = [[
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Get the current weather for a city.',
            'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        ],
    ]];
    private const REFUSAL = "Function tools with reasoning_effort are not supported for gpt-6-luna in /v1/chat/completions. To use function tools, use /v1/responses or set reasoning_effort to 'none'.";

    private ?ProtocolServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        EffortMapping::setOpenAiHost(null);
    }

    private function serve(array $chatSteps, array $fields = []): Profile
    {
        $this->server?->stop();
        $this->server = new ProtocolServer(['chat' => [
            '/v1/chat/completions' => $chatSteps,
            '/v1/responses' => [['fixture' => 'responses/tool.sse']],
        ]]);

        return $this->server->profile('chat', array_merge(['protocol' => 'openai', 'model' => 'gpt-6-luna', 'thinking' => 'low'], $fields));
    }

    private static function route(Profile $profile): ToolRouteClient
    {
        return new ToolRouteClient(new OpenAiClient($profile), new ResponsesClient($profile->withProtocol('openai-responses')));
    }

    private static function refusal(): array
    {
        $error = ['message' => self::REFUSAL, 'type' => 'invalid_request_error', 'param' => 'reasoning_effort'];

        return ['status' => 400, 'body' => json_encode(['error' => $error], JSON_THROW_ON_ERROR), 'type' => 'application/json'];
    }

    private static function options(array &$events): ChatOptions
    {
        return new ChatOptions(
            onEvent: static function (string $type, string $text) use (&$events): void {
                $events[] = [$type, $text];
            },
            overrides: ['reasoning_effort' => 'low'],
        );
    }

    private static function progress(array $events): array
    {
        return array_column(array_filter($events, static fn (array $event): bool => $event[0] === 'progress'), 1);
    }

    public function testTheRefusalIsTheAnswerOfChatCompletionsAndTheRequestIsNotSentAgainWithoutTheLevel(): void
    {
        $profile = $this->serve([self::refusal()]);
        $events = [];

        try {
            (new OpenAiClient($profile))->chat(self::MESSAGES, self::WEATHER, self::options($events));
            self::fail('the refusal must end the request of Chat Completions');
        } catch (LlmException $error) {
            self::assertStringStartsWith('HTTP 400: Function tools with reasoning_effort', $error->getMessage());
        }

        $requests = $this->server->requests();
        self::assertCount(1, $requests, 'no second request without reasoning_effort');
        self::assertSame('low', $requests[0]['json']['reasoning_effort']);
        self::assertSame([], self::progress($events), 'and nothing says that the request was changed');
    }

    public function testARefusedToolRequestIsSentToResponsesWithItsMessagesToolsAndLevel(): void
    {
        $profile = $this->serve([self::refusal()]);
        $events = [];

        $response = self::route($profile)->chat(self::MESSAGES, self::WEATHER, self::options($events));

        self::assertSame('get_weather', $response->toolCalls[0]->name);
        $requests = $this->server->requests();
        self::assertSame(['/chat/v1/chat/completions', '/chat/v1/responses'], array_column($requests, 'path'));
        self::assertSame('low', $requests[0]['json']['reasoning_effort']);
        self::assertSame(self::WEATHER, $requests[0]['json']['tools']);
        self::assertSame(['effort' => 'low'], $requests[1]['json']['reasoning']);
        self::assertSame(self::WEATHER[0]['function']['name'], $requests[1]['json']['tools'][0]['name'], 'the tools go in the flat Responses form');
        self::assertSame([['type' => 'message', 'role' => 'user', 'content' => 'What is the weather in Madrid?']], $requests[1]['json']['input']);
        self::assertCount(1, self::progress($events));
        self::assertStringContainsString('/v1/responses', self::progress($events)[0]);
    }

    public function testTheRouteIsKeptForTheRestOfTheSessionAndAnnouncedOnce(): void
    {
        $profile = $this->serve([self::refusal()]);
        $client = self::route($profile);
        $events = [];

        $client->chat(self::MESSAGES, self::WEATHER, self::options($events));
        $events = [];
        $client->chat(self::MESSAGES, self::WEATHER, self::options($events));

        self::assertSame(['/chat/v1/chat/completions', '/chat/v1/responses', '/chat/v1/responses'], array_column($this->server->requests(), 'path'));
        self::assertSame([], self::progress($events), 'the second turn is not announced again');
    }

    public function testAnotherRefusalOfAFieldIsLeftOutAsItWasAndTheRouteIsNotUsed(): void
    {
        $profile = $this->serve([
            ['status' => 400, 'body' => json_encode(['error' => ['message' => "Unknown parameter: 'top_k'."]], JSON_THROW_ON_ERROR), 'type' => 'application/json'],
            ['fixture' => 'llama/text.sse'],
        ]);
        $events = [];

        $response = self::route($profile)->chat(self::MESSAGES, [], self::options($events));

        self::assertSame('The three primary colours are red, blue, and yellow.', $response->content);
        $requests = $this->server->requests();
        self::assertSame(['/chat/v1/chat/completions', '/chat/v1/chat/completions'], array_column($requests, 'path'));
        self::assertArrayNotHasKey('top_k', $requests[1]['json']);
        self::assertSame(["the server does not take top_k: the request was changed and sent again, and stays changed for this session"], self::progress($events));
    }

    public function testAnyOtherErrorEndsTheTurnWithoutTheRoute(): void
    {
        $profile = $this->serve([
            ['status' => 400, 'body' => json_encode(['error' => ['message' => 'Invalid request.']], JSON_THROW_ON_ERROR), 'type' => 'application/json'],
        ]);
        $events = [];

        try {
            self::route($profile)->chat(self::MESSAGES, self::WEATHER, self::options($events));
            self::fail('an invalid request ends the turn');
        } catch (LlmException $error) {
            self::assertSame('HTTP 400: Invalid request.', $error->getMessage());
        }

        self::assertSame(['/chat/v1/chat/completions'], array_column($this->server->requests(), 'path'), 'Responses is not asked');
        self::assertSame([], self::progress($events));
    }

    public function testAModelThatTakesNoToolsOnChatCompletionsGoesToResponsesWithoutAskingIt(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $profile = $this->serve([['fixture' => 'llama/text.sse']], ['model' => 'gpt-6-astra']);
        $events = [];

        $response = self::route($profile)->chat(self::MESSAGES, self::WEATHER, self::options($events));

        self::assertSame('get_weather', $response->toolCalls[0]->name);
        self::assertSame(['/chat/v1/responses'], array_column($this->server->requests(), 'path'), 'Chat Completions is not asked');
        self::assertSame('low', $this->server->requests()[0]['json']['reasoning']['effort'], 'Astra has no none, so the level sent is low');
        self::assertSame([], self::progress($events), 'the table is not a switch of the session, so nothing is announced');
    }

    public function testGpt6LunaWithToolsAtLowGoesToResponses(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $profile = $this->serve([['fixture' => 'llama/text.sse']]);
        $events = [];

        self::route($profile)->chat(self::MESSAGES, self::WEATHER, self::options($events));

        self::assertSame(['/chat/v1/responses'], array_column($this->server->requests(), 'path'));
        self::assertSame(['effort' => 'low'], $this->server->requests()[0]['json']['reasoning']);
    }

    public function testGpt6LunaWithToolsAtNoneStaysOnChatCompletions(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $profile = $this->serve([['fixture' => 'llama/text.sse']], ['thinking' => 'none']);
        $events = [];

        $response = self::route($profile)->chat(self::MESSAGES, self::WEATHER, self::options($events));

        self::assertSame('The three primary colours are red, blue, and yellow.', $response->content);
        self::assertSame(['/chat/v1/chat/completions'], array_column($this->server->requests(), 'path'), 'Luna takes tools on Chat Completions at none');
    }

    public function testAModelThatTakesNoChatCompletionsGoesToResponsesWithoutTools(): void
    {
        EffortMapping::setOpenAiHost('127.0.0.1');
        $profile = $this->serve([['fixture' => 'llama/text.sse']], ['model' => 'gpt-5.5-pro', 'thinking' => 'high']);
        $events = [];

        self::route($profile)->chat(self::MESSAGES, [], self::options($events));

        self::assertSame(['/chat/v1/responses'], array_column($this->server->requests(), 'path'));
    }

    public function testARouteThatWasKeptStartsOnResponsesAndAnnouncesNothing(): void
    {
        $profile = $this->serve([['fixture' => 'llama/text.sse']]);
        $client = new ToolRouteClient(new OpenAiClient($profile), new ResponsesClient($profile->withProtocol('openai-responses')), true);
        $events = [];

        $client->chat(self::MESSAGES, self::WEATHER, self::options($events));

        self::assertSame(['/chat/v1/responses'], array_column($this->server->requests(), 'path'), 'the kept route is used from the first request');
        self::assertSame([], self::progress($events));
    }

    public function testTheRefusalSwitchIsReportedOnceToTheCallback(): void
    {
        $profile = $this->serve([self::refusal()]);
        $switched = 0;
        $client = new ToolRouteClient(
            new OpenAiClient($profile),
            new ResponsesClient($profile->withProtocol('openai-responses')),
            false,
            static function () use (&$switched): void {
                $switched++;
            },
        );
        $events = [];

        $client->chat(self::MESSAGES, self::WEATHER, self::options($events));
        $client->chat(self::MESSAGES, self::WEATHER, self::options($events));

        self::assertSame(1, $switched, 'the switch is kept once, when it happens');
    }
}
