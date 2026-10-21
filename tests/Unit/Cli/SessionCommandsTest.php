<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Llm\LlmException;
use Phycode\Session\SessionStore;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class SessionCommandsTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096],
        ],
    ];

    private const MODELS = ['local' => 'qwen'];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testATurnIsSavedWithItsSettingsAndTheFirstQuestionCutTo60Characters(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS, replies: [ScriptedClient::text('an answer')]));

        $session->handle(str_repeat('a', 70));

        [$entry] = $session->listSessions();
        self::assertSame([str_repeat('a', 60), 3, true], [$entry['title'], $entry['messages'], $entry['current']]);
        $saved = $this->saved($entry['id']);
        self::assertSame(['id', 'title', 'createdAt', 'updatedAt', 'profile', 'model', 'thinking', 'todos', 'messages'], array_keys($saved));
        self::assertSame(['local', 'qwen', 'none'], [$saved['profile'], $saved['model'], $saved['thinking']]);
        self::assertSame(['system', 'user', 'assistant'], array_column($saved['messages'], 'role'));
    }

    public function testTheTitleIsTheFirstQuestionOnOneLine(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS, replies: [ScriptedClient::text('ok')]));

        $session->handle("first line\n   second line");

        self::assertSame('first line second line', $session->listSessions()[0]['title']);
    }

    public function testASessionThatFailsIsSavedToo(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS, replies: [new LlmException('connection to the model server failed: refused')]));

        $session->handle('a question that fails');

        self::assertSame('error', $session->lastStatus());
        $saved = $this->saved($session->listSessions()[0]['id']);
        self::assertSame(['system', 'user'], array_column($saved['messages'], 'role'));
        self::assertSame('a question that fails', $saved['title']);
    }

    public function testANewSessionIsEmptyAndTheOldOneIsKept(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS, replies: [ScriptedClient::text('ok')]));
        $session->handle('first question');
        $first = $session->listSessions()[0]['id'];

        $session->newSession();

        $sessions = $session->listSessions();
        self::assertCount(2, $sessions);
        self::assertSame([true, 1, ''], [$sessions[0]['current'], $sessions[0]['messages'], $sessions[0]['title']]);
        self::assertSame([$first, false, 3, 'first question'], [$sessions[1]['id'], $sessions[1]['current'], $sessions[1]['messages'], $sessions[1]['title']]);
    }

    public function testSwitchingRestoresTheSavedMessagesAndSettingsOfThatSession(): void
    {
        $provider = new CountingProvider(self::MODELS, replies: [
            ScriptedClient::text('alpha answer'),
            ScriptedClient::text('beta answer'),
            ScriptedClient::text('gamma answer'),
        ]);
        $session = $this->session($provider);
        $session->handle('alpha question');
        $alpha = $session->listSessions()[0]['id'];
        $session->newSession();
        $session->switchModel('local', 'mistral', 'on');
        $session->handle('beta question');
        $beta = array_values(array_filter($session->listSessions(), static fn (array $saved): bool => $saved['current']))[0]['id'];

        $session->switchSession($alpha);

        self::assertSame(['local', 'qwen', 'none'], [$session->profile(), $session->model(), $session->thinking()]);
        self::assertTrue(self::entry($session->listSessions(), $alpha)['current']);
        $session->handle('gamma question');
        $request = $provider->sent[2];
        self::assertSame(['qwen', 'none'], [$request['model'], $request['thinking']]);
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($request['messages'], 'role'));
        self::assertSame(['alpha question', 'alpha answer', 'gamma question'], array_column(array_slice($request['messages'], 1), 'content'));

        $session->switchSession($beta);

        self::assertSame(['local', 'mistral', 'medium'], [$session->profile(), $session->model(), $session->thinking()]);
    }

    public function testAnUnknownOrMalformedIdIsRefusedAndNothingChanges(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        $session->switchModel('local', 'mistral', 'on');

        self::assertSame('no session named ses-missing; /sessions lists the saved ones', self::refusal(fn () => $session->switchSession('ses-missing')));
        self::assertSame('malformed session id; /sessions lists the ids', self::refusal(fn () => $session->switchSession('../secret')));
        self::assertSame(['local', 'mistral', 'medium'], [$session->profile(), $session->model(), $session->thinking()]);
    }

    public function testAFileThatIsNotASessionIsNotListedAndIsRefused(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        file_put_contents($this->root() . '/.phycode/sessions/broken.json', '{"id":"broken"}');

        self::assertNotContains('broken', array_column($session->listSessions(), 'id'));
        self::assertSame('session broken cannot be read: its file is not a saved session', self::refusal(fn () => $session->switchSession('broken')));
    }

    public function testASessionOfAProfileThatIsGoneIsRefused(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS));
        (new SessionStore($this->root()))->save([
            'id' => 'ses-gone',
            'title' => 'old',
            'createdAt' => SessionStore::now(),
            'updatedAt' => SessionStore::now(),
            'profile' => 'gone',
            'model' => 'x',
            'thinking' => 'off',
            'messages' => [['role' => 'system', 'content' => 's']],
        ]);

        self::assertStringContainsString("unknown profile 'gone'", self::refusal(fn () => $session->switchSession('ses-gone')));
        self::assertSame(['local', 'qwen'], [$session->profile(), $session->model()]);
    }

    public function testTheSessionsCommandListsSwitchesAndStarts(): void
    {
        $session = $this->session(new CountingProvider(self::MODELS, replies: [ScriptedClient::text('ok')]));
        $session->handle('first question');
        $first = $session->listSessions()[0]['id'];

        $session->handle('/sessions');
        [$panel] = $this->listPanels();
        self::assertSame('Sessions', $panel['title']);
        self::assertSame([$first], array_column($panel['items'], 'title'));
        self::assertSame(['current'], array_column($panel['items'], 'badge'));

        $session->handle('/sessions new');
        $session->handle('/sessions switch ' . $first);
        $session->handle('/sessions switch ses-missing');
        $session->handle('/sessions switch');
        $session->handle('/sessions bogus');

        self::assertCount(2, $session->listSessions());
        self::assertTrue(self::entry($session->listSessions(), $first)['current']);
        $notices = $this->notices();
        self::assertStringStartsWith('info: started session ', $notices[0]);
        self::assertSame("info: switched to session {$first}", $notices[1]);
        self::assertSame(
            [
                'error: no session named ses-missing; /sessions lists the saved ones',
                'error: usage: /sessions [switch ID|new]',
                'error: usage: /sessions [switch ID|new]',
            ],
            array_slice($notices, 2),
        );
    }

    public function testTheKeyOfAProfileIsNotWrittenToTheSession(): void
    {
        $key = 'fakekey-7f3a9c2e51b8d4';
        $keyFile = $this->harness->home . '/key.txt';
        file_put_contents($keyFile, $key);
        file_put_contents($this->harness->configFile, json_encode(['default_profile' => 'local', 'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'api_key_file' => $keyFile],
        ]], JSON_THROW_ON_ERROR));
        $session = $this->session(new CountingProvider(self::MODELS, replies: [ScriptedClient::text('ok')]));

        $session->handle("use {$key} for the call");

        $file = (string) file_get_contents($this->root() . '/.phycode/sessions/' . $session->listSessions()[0]['id'] . '.json');
        self::assertStringNotContainsString($key, $file);
        self::assertStringContainsString('use [redacted] for the call', $file);
    }

    public function testASymbolicLinkSessionsFolderDoesNotStopTheChat(): void
    {
        symlink(Kit::folder('elsewhere'), $this->root() . '/.phycode');
        $session = $this->session(new CountingProvider(self::MODELS, replies: [ScriptedClient::text('ok')]));

        $session->handle('first question');

        self::assertSame('completed', $session->lastStatus());
        self::assertStringContainsString('symbolic link', self::refusal(fn () => $session->listSessions()));
        $session->handle('/sessions');
        self::assertStringContainsString('symbolic link', $this->notices()[0]);
    }

    private function session(CountingProvider $provider): ChatSession
    {
        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    private function root(): string
    {
        return (string) realpath($this->harness->cwd);
    }

    private function saved(string $id): array
    {
        return json_decode((string) file_get_contents($this->root() . '/.phycode/sessions/' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function entry(array $sessions, string $id): array
    {
        foreach ($sessions as $session) {
            if ($session['id'] === $id) {
                return $session;
            }
        }
        self::fail("no session {$id}");
    }

    private static function refusal(\Closure $action): string
    {
        try {
            $action();
        } catch (PhycodeException $error) {
            return $error->getMessage();
        }
        self::fail('the action was not refused');
    }

    private function listPanels(): array
    {
        return array_values(array_map(
            static fn (array $event): array => ['title' => $event[1], 'items' => $event[2]],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'list_panel'),
        ));
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }
}
