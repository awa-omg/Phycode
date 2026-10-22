<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class MentionsSessionTest extends TestCase
{
    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'native']]]);
        $this->ui = new RecordingUi();
        Kit::write($this->harness->cwd . '/src/Foo.php', "<?php\necho 1;\n");
        Kit::write($this->harness->cwd . '/.env', "TOKEN=abc\n");
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    private function notices(): array
    {
        return array_values(array_map(static fn (array $event): string => $event[1] . ': ' . $event[2], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice')));
    }

    public function testAMentionInTheMiddleOfAMessageSendsTheFileWithIt(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('It prints 1.')]);
        $session = $this->harness->session($this->harness->kernel($provider, $this->ui));

        $session->handle('what does @src/Foo.php print?');

        $sent = (string) $provider->sent[0]['messages'][1]['content'];
        self::assertStringStartsWith('what does @src/Foo.php print?', $sent);
        self::assertStringContainsString("Contents of src/Foo.php, mentioned by the user with @:\n     1  <?php\n     2  echo 1;", $sent);
        self::assertContains('info: attached src/Foo.php', $this->notices());
        self::assertSame('completed', $session->lastStatus());
    }

    public function testAMessageThatStartsWithAPathIsAMessageAndNotACallToAnAgent(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('Fine.')]);
        $session = $this->harness->session($this->harness->kernel($provider, $this->ui));

        $session->handle('@src/Foo.php is this file fine?');

        self::assertStringContainsString('Contents of src/Foo.php', (string) $provider->sent[0]['messages'][1]['content']);
        self::assertSame('build', $session->primary()->name);
        self::assertStringNotContainsString('You are the subagent', (string) $provider->sent[0]['messages'][0]['content']);
    }

    public function testAnAgentNameAtTheStartStillCallsTheAgentAndSendsNoFile(): void
    {
        $provider = new RequestSpy(['local' => 'qwen'], [], [ScriptedClient::text('Found nothing.')], ['local' => 'native']);
        $session = $this->harness->session($this->harness->kernel($provider, $this->ui));

        $session->handle('@explore Find where the config is read in this project, starting from @src/Foo.php.');

        self::assertStringContainsString('You are the subagent "explore"', (string) $provider->sent[0]['messages'][0]['content']);
        self::assertStringNotContainsString('Contents of src/Foo.php', json_encode($provider->sent, JSON_THROW_ON_ERROR));
    }

    public function testASecretFileIsNotSentAndTheUserSeesWhy(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], ['native'], replies: [ScriptedClient::text('No.')]);
        $session = $this->harness->session($this->harness->kernel($provider, $this->ui));

        $session->handle('read @.env for me');

        self::assertStringNotContainsString('TOKEN=abc', json_encode($provider->sent, JSON_THROW_ON_ERROR));
        self::assertContains("warn: @.env was not attached: it usually holds secrets or phycode's own files", $this->notices());
    }
}
