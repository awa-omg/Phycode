<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class WebTurnTest extends TestCase
{
    private const BASE = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native'],
        ],
    ];

    private const WEB = ['web' => ['search' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:9']]];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::BASE);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testAWebSearchTriggerNamesTheSearchForItsTurnAndTheToolsStayTheSame(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('found'), ScriptedClient::text('still going')]);
        $session = $this->session($spy, self::WEB);

        $session->handle('(web search: rain in Lisbon) what is the weather');
        $session->handle('and tomorrow?');

        self::assertEqualsCanonicalizing(['web_fetch', 'web_search'], $this->web($spy->sent[0]['tools']));
        self::assertEqualsCanonicalizing(['web_fetch', 'web_search'], $this->web($spy->sent[1]['tools']), 'the next turn has the same tools, so the model knows what it can use');
        self::assertContains(['notice', 'info', 'web search ON · this turn'], $this->ui->events);
        self::assertSame(1, count(array_filter($this->ui->events, static fn (array $event): bool => ($event[0] ?? null) === 'notice' && str_starts_with((string) ($event[2] ?? ''), 'web search ON'))));
    }

    public function testTheModelReadsTheMessageWithoutTheTriggerButWithTheQuery(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('found')]);
        $session = $this->session($spy, self::WEB);

        $session->handle('(web search: rain in Lisbon) what is the weather');

        $sent = (string) array_values(array_filter($spy->sent[0]['messages'], static fn (array $message): bool => ($message['role'] ?? null) === 'user'))[0]['content'];
        self::assertStringContainsString('what is the weather', $sent);
        self::assertStringContainsString('Web search query: rain in Lisbon', $sent);
        self::assertStringNotContainsString('(web search', $sent);
    }

    public function testAWebFetchTriggerOffersTheFetchToolAndListsTheUrls(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('read it')]);
        $session = $this->session($spy, self::WEB);

        $session->handle('(web fetch https://example.com/docs) summarise this page');

        self::assertEqualsCanonicalizing(['web_fetch', 'web_search'], $this->web($spy->sent[0]['tools']));
        self::assertContains(['notice', 'info', 'web fetch ON · this turn'], $this->ui->events);
        self::assertContains(['notice', 'info', 'web fetch reads: https://example.com/docs'], $this->ui->events);
        $sent = (string) array_values(array_filter($spy->sent[0]['messages'], static fn (array $message): bool => ($message['role'] ?? null) === 'user'))[0]['content'];
        self::assertStringContainsString("Read these pages with web_fetch:\nhttps://example.com/docs", $sent);
    }

    public function testATriggerWithoutAWebConfigIsAWarningAndNoWebTool(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('no web here')]);
        $session = $this->session($spy, []);

        $session->handle('(web search) the news');

        self::assertSame([], $this->web($spy->sent[0]['tools']));
        self::assertContains(['notice', 'warn', 'web search is off: no search is in use; choose one with /web, or set web.search.backend in the config'], $this->ui->events);
        self::assertNotContains(['notice', 'info', 'web search ON · this turn'], $this->ui->events);
    }

    public function testWordsAskForTheSearchToolWithoutParentheses(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('found')]);
        $session = $this->session($spy, self::WEB);

        $session->handle('puedes hacer web search sobre la lluvia en Lisboa?');

        self::assertEqualsCanonicalizing(['web_fetch', 'web_search'], $this->web($spy->sent[0]['tools']));
        self::assertContains(['notice', 'info', 'web search ON · this turn'], $this->ui->events);
    }

    public function testAWordAskingForTheSearchWithoutAProviderIsTheSameWarning(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('no web here')]);
        $session = $this->session($spy, []);

        $session->handle('busca en internet las noticias de hoy');

        self::assertSame([], $this->web($spy->sent[0]['tools']));
        self::assertContains(['notice', 'warn', 'web search is off: no search is in use; choose one with /web, or set web.search.backend in the config'], $this->ui->events);
    }

    public function testATriggerInsideCodeDoesNothing(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('ok')]);
        $session = $this->session($spy, self::WEB);

        $session->handle('the docs show `(web search: x)` as an example');

        self::assertEqualsCanonicalizing(['web_fetch', 'web_search'], $this->web($spy->sent[0]['tools']), 'a trigger in code adds nothing: the tools are the same as in any other turn');
        self::assertSame([], array_values(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice')));
    }

    public function testTheSearchToolIsThereForAPlainMessageAndForAMisspelledOne(): void
    {
        $spy = new ToolSpy('native', [ScriptedClient::text('one'), ScriptedClient::text('two')]);
        $session = $this->session($spy, self::WEB);

        $session->handle('busca info de hoy');
        $session->handle('haz wwb search');

        self::assertEqualsCanonicalizing(['web_fetch', 'web_search'], $this->web($spy->sent[0]['tools']), 'a plain message gets the tools of the session');
        self::assertEqualsCanonicalizing(['web_fetch', 'web_search'], $this->web($spy->sent[1]['tools']));
        self::assertNotContains(['notice', 'info', 'web search ON · this turn'], $this->ui->events, 'only a trigger says that a turn asks for the search');
    }

    private function session(ToolSpy $spy, array $extra): ChatSession
    {
        file_put_contents($this->harness->configFile, json_encode($extra + self::BASE, JSON_THROW_ON_ERROR));

        return $this->harness->session($this->harness->kernel($spy, $this->ui));
    }

    private function web(array $tools): array
    {
        return array_values(array_intersect($tools, ['web_search', 'web_fetch']));
    }
}
