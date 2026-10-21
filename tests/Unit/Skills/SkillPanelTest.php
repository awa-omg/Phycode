<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Cli\ChatSession;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillPanelTest extends TestCase
{
    private const CONFIG = [
        'default_profile' => 'local',
        'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text']],
    ];

    private SessionHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::CONFIG);
        putenv('PATH=' . Kit::folder('no-gentle-ai'));
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testSkillListsTheProjectAndGlobalSkillsAndSaysWhatWasSkipped(): void
    {
        $project = (string) realpath($this->harness->cwd);
        $this->skill($project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $this->skill($project, '.claude/skills/api', 'api', 'REST APIs.');
        Kit::write("{$project}/.agents/skills/broken/SKILL.md", "---\nname: broken\n---\nNo description.\n");
        Kit::write("{$project}/.agents/skills/no-file/notes.txt", "not a skill\n");
        $this->skill($this->harness->home, '.agents/skills/review', 'review', 'Reviews code.');
        Kit::write("{$project}/skills-lock.json", json_encode([
            'version' => 1,
            'skills' => ['go-testing' => ['source' => 'owner/repo', 'sourceType' => 'github', 'computedHash' => 'abc']],
        ], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skill'));

        [$panel] = $this->listPanels($ui);
        self::assertSame('Skills', $panel['title']);
        self::assertSame(['Project', 'Project', 'Global'], array_column($panel['items'], 'category'));
        self::assertSame(['go-testing', 'api', 'review'], array_column($panel['items'], 'title'));
        self::assertSame(['owner/repo', 'REST APIs.', 'Reviews code.'], array_column($panel['items'], 'detail'));
        self::assertSame(
            '/skill NAME shows one skill. skipped 2 folders without a valid SKILL.md (name and description are required)',
            $panel['hint'],
        );
        self::assertSame([], $this->outputs($ui));
    }

    public function testSkillNameShowsTheSkillAsATextPanelWithItsFirstFortyLines(): void
    {
        $project = (string) realpath($this->harness->cwd);
        $body = implode("\n", array_map(static fn (int $number): string => "line {$number}", range(1, 50)));
        $this->skill($project, '.agents/skills/long', 'long', 'A long skill.', $body . "\n");
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skill long'));

        $panel = $this->textPanels($ui)[0];
        self::assertSame('Skill long', $panel['title']);
        self::assertStringStartsWith("name: long\ndescription: A long skill.\npath: {$project}/.agents/skills/long/SKILL.md\n\nline 1\n", $panel['text']);
        self::assertStringContainsString("line 40\n[... more lines not shown]", $panel['text']);
        self::assertStringNotContainsString('line 41', $panel['text']);
    }

    public function testASkillNameThatIsNotInstalledIsAnErrorNotice(): void
    {
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skill nowhere'));

        self::assertSame(['error: no skill named nowhere; /skill lists the installed skills'], $this->notices($ui));
        self::assertSame([], $this->textPanels($ui));
    }

    public function testWithNoSkillsThePanelSaysHowToInstallOne(): void
    {
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skill'));

        $panel = $this->textPanels($ui)[0];
        self::assertSame('Skills', $panel['title']);
        self::assertStringContainsString('npx skills add <owner/repo>', $panel['text']);
        self::assertSame([], $this->listPanels($ui));
    }

    public function testTheGentleAiRegistryIsListedUnderItsCategory(): void
    {
        $bin = FakeGentleAi::install(['skills' => json_encode([
            ['name' => 'branch-pr', 'scope' => 'project', 'description' => 'Opens a pull request.', 'path' => '/p/branch-pr/SKILL.md'],
        ], JSON_THROW_ON_ERROR)]);
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['gentle_ai' => ['bin' => $bin]], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skill'));

        [$panel] = $this->listPanels($ui);
        self::assertSame([['category' => 'gentle-ai', 'title' => 'branch-pr', 'detail' => 'Opens a pull request.', 'status' => '', 'path' => '/p/branch-pr/SKILL.md']], $panel['items']);
    }

    public function testAnUnusableGentleAiBinIsANoteAndTheProjectSkillsStillList(): void
    {
        $this->skill($this->harness->cwd, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        file_put_contents($this->harness->configFile, json_encode(self::CONFIG + ['gentle_ai' => ['bin' => '/nonexistent/phycode-gentle-ai']], JSON_THROW_ON_ERROR));
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skill'));

        [$panel] = $this->listPanels($ui);
        self::assertSame(['go-testing'], array_column($panel['items'], 'title'));
        self::assertStringContainsString('gentle_ai.bin is not an executable file: /nonexistent/phycode-gentle-ai', $panel['hint']);
    }

    public function testSkillsAreCommandsAndNotTurns(): void
    {
        $spy = new RequestSpy(['local' => 'qwen']);
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui, $spy)->handle('/skill'));
        self::assertTrue($this->session($ui, $spy)->handle('/skill nowhere'));

        self::assertSame([], $spy->sent, 'no model was asked');
    }

    private function session(RecordingUi $ui, ?RequestSpy $spy = null): ChatSession
    {
        return $this->harness->session($this->harness->kernel($spy ?? new RequestSpy(['local' => 'qwen']), $ui));
    }

    private function skill(string $root, string $folder, string $name, string $description, string $body = "Use it well.\n"): void
    {
        Kit::write("{$root}/{$folder}/SKILL.md", "---\nname: {$name}\ndescription: {$description}\n---\n{$body}");
    }

    private function listPanels(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): array => ['title' => $event[1], 'items' => $event[2], 'hint' => $event[3]],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'list_panel'),
        ));
    }

    private function textPanels(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): array => ['title' => $event[1], 'text' => $event[2]],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'panel'),
        ));
    }

    private function outputs(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): string => (string) $event[1],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'output'),
        ));
    }

    private function notices(RecordingUi $ui): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event[1] . ': ' . $event[2],
            array_filter($ui->events, static fn (array $event): bool => $event[0] === 'notice'),
        ));
    }
}
