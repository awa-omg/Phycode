<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Cli\ChatSession;
use Phycode\Cli\PromptTemplates;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\ScriptedClient;
use Phycode\Tests\Support\SessionHarness;
use PHPUnit\Framework\TestCase;

final class PromptTemplateTest extends TestCase
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
    private string $root;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        $this->ui = new RecordingUi();
        $this->root = (string) realpath($this->harness->cwd);
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testTheArgumentsReplaceThePlaceholderOrGoToTheEnd(): void
    {
        $this->template('review.md', "Review \$ARGUMENTS carefully.\n");
        $this->template('explain.md', "Explain the change.\n");
        $templates = new PromptTemplates($this->root, []);

        self::assertSame('Review src/a.php carefully.', $templates->prompt('/review src/a.php'));
        self::assertSame("Explain the change.\n\nthe diff", $templates->prompt('/explain the diff'));
        self::assertSame('Explain the change.', $templates->prompt('/explain'));
        self::assertNull($templates->prompt('/reviewer src/a.php'), 'a name is a whole word');
    }

    public function testAFileWhoseNameIsNotLettersDigitsAndHyphensIsLeftOutWithAWarning(): void
    {
        $this->template('my prompt.md', 'Do it.');
        $this->template('under_score.md', 'Do it.');
        $templates = new PromptTemplates($this->root, []);

        self::assertSame([
            'the prompt template my prompt.md is not used: its name may only have letters, digits and hyphens',
            'the prompt template under_score.md is not used: its name may only have letters, digits and hyphens',
        ], $templates->warnings());
        self::assertSame([], $templates->commands());
        self::assertNull($templates->prompt('/under_score'));
    }

    public function testATemplateCannotTakeTheWordOfACommand(): void
    {
        $this->template('help.md', 'Show the help.');
        $templates = new PromptTemplates($this->root, ['/help']);

        self::assertSame(['the prompt template help.md is not used: /help is already a command'], $templates->warnings());
        self::assertNull($templates->prompt('/help'));
        self::assertSame([], $templates->commands());
    }

    public function testAnEmptyFileAndASymbolicLinkAreLeftOut(): void
    {
        $this->template('blank.md', " \n\t\n");
        $outside = $this->harness->home . '/outside.md';
        file_put_contents($outside, 'Text outside the project.');
        symlink($outside, $this->root . '/.phycode/prompts/linked.md');
        $templates = new PromptTemplates($this->root, []);

        self::assertSame([
            'the prompt template blank.md is not used: it is empty',
            'the prompt template linked.md is not used: it is a symbolic link',
        ], $templates->warnings());
        self::assertNull($templates->prompt('/linked'), 'the target of a link is never read');
    }

    public function testAProjectWithoutTemplatesHasNoCommandsAndNoWarnings(): void
    {
        $templates = new PromptTemplates($this->root, []);

        self::assertSame([], $templates->commands());
        self::assertSame([], $templates->warnings());
        self::assertNull($templates->prompt('/review'));
    }

    public function testATemplateIsSentAsATurnAndListedWithTheCommands(): void
    {
        $this->template('summarise.md', "Summarise \$ARGUMENTS in three lines.\n");
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('done')]);
        $session = $this->session($provider);

        self::assertTrue($session->isCommand('/summarise the auth module'));
        self::assertTrue($session->handle('/summarise the auth module'));

        self::assertSame('Summarise the auth module in three lines.', $this->lastUserMessage($provider));
        self::assertSame('completed', $session->lastStatus());
        self::assertContains('/summarise [ARGUMENTS]', array_column($session->commands(), 'name'));
        $session->handle('/help');
        self::assertStringContainsString('/summarise [ARGUMENTS]', $this->panel('Help'));
    }

    public function testABuiltInCommandWinsAndTheWarningComesWhenTheSessionLoads(): void
    {
        $this->template('status.md', 'Tell me the status.');
        $provider = new CountingProvider(self::MODELS);
        $session = $this->session($provider);

        self::assertSame(['warn: the prompt template status.md is not used: /status is already a command'], $this->notices());

        $session->handle('/status');

        self::assertSame([], $provider->sent, 'the built-in command answers, and no turn is sent');
        self::assertStringContainsString('profile', $this->panel('Status'));
    }

    public function testTheTextOfATemplateIsNotReadAgainAsACommand(): void
    {
        $this->template('loop.md', '/status');
        $provider = new CountingProvider(self::MODELS, replies: [ScriptedClient::text('ok')]);
        $session = $this->session($provider);

        $session->handle('/loop');

        self::assertSame('/status', $this->lastUserMessage($provider), 'the text is the turn');
        self::assertNotContains('Status', array_column(array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'panel'), 1));
    }

    private function template(string $file, string $text): void
    {
        $folder = $this->root . '/.phycode/prompts';
        if (!is_dir($folder)) {
            mkdir($folder, 0700, true);
        }
        file_put_contents($folder . '/' . $file, $text);
    }

    private function session(CountingProvider $provider): ChatSession
    {
        return $this->harness->session($this->harness->kernel($provider, $this->ui));
    }

    private function lastUserMessage(CountingProvider $provider): string
    {
        $messages = $provider->sent[0]['messages'];

        return (string) $messages[array_key_last($messages)]['content'];
    }

    private function panel(string $title): string
    {
        foreach ($this->ui->events as $event) {
            if ($event[0] === 'panel' && $event[1] === $title) {
                return (string) $event[2];
            }
        }
        self::fail("no panel {$title}");
    }

    private function notices(): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($this->ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }
}
