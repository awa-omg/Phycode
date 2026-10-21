<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class PromptHookTest extends TestCase
{
    private const SCRIPT = <<<'PHP'
<?php
$payload = json_decode(stream_get_contents(STDIN), true);
if (str_contains($payload['prompt'], 'password')) {
    fwrite(STDERR, 'no passwords in prompts');
    exit(2);
}
echo "branch: feature/x\n";
PHP;

    private SessionHarness $harness;
    private RecordingUi $ui;
    private string $script;

    protected function setUp(): void
    {
        $this->script = Kit::folder('prompt-hook') . '/hook.php';
        file_put_contents($this->script, self::SCRIPT);
        $this->harness = new SessionHarness([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text', 'context_limit' => 4096]],
            'hooks' => ['UserPromptSubmit' => [['command' => "'" . PHP_BINARY . "' '{$this->script}'"]]],
        ]);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testAPromptTheHookRefusesNeverReachesTheModel(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], replies: [ScriptedClient::text('never')]);
        $session = $this->harness->session($this->harness->kernel($provider, $this->ui));

        $session->handle('my password is hunter2');

        self::assertSame([], $provider->sent);
        $notices = array_map(static fn (array $event): string => $event[1] . ': ' . $event[2], array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'));
        self::assertContains('warn: the prompt was blocked by a hook: no passwords in prompts', $notices);
    }

    public function testTheContextOfTheHookGoesAfterThePromptInTheSameMessage(): void
    {
        $provider = new CountingProvider(['local' => 'qwen'], replies: [ScriptedClient::text('done')]);
        $session = $this->harness->session($this->harness->kernel($provider, $this->ui));

        $session->handle('fix the login bug');

        $messages = $provider->sent[0]['messages'];
        self::assertSame("fix the login bug\n\nUserPromptSubmit hook:\nbranch: feature/x", $messages[array_key_last($messages)]['content']);
    }
}
