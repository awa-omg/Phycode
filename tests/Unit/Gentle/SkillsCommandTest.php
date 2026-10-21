<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;
use Phycode\Gentle\GentleAi;
use Phycode\Session\Transcript;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\TestCase;

final class SkillsCommandTest extends TestCase
{
    private const SKILLS = '[{"name":"go-testing","scope":"user","description":"Go tests.","path":"/p/go-testing/SKILL.md"},{"name":"api","scope":"project","description":"REST APIs","path":"/p/api/SKILL.md"}]';

    private array $environment = [];
    private string $home;

    protected function setUp(): void
    {
        foreach (['PATH', 'HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'GENTLE_AI_HOME', 'PHYCODE_BASE_URL', 'PHYCODE_MODEL', 'PHYCODE_PROFILE'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        $this->home = Kit::folder('skills-home');
        $config = Kit::folder('skills-config');
        Kit::write($config . '/phycode/config.json', json_encode([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9/v1', 'model' => 'test-model', 'tool_mode' => 'none']],
        ], JSON_THROW_ON_ERROR));
        putenv('HOME=' . $this->home);
        putenv('XDG_CONFIG_HOME=' . $config);
        putenv('XDG_CACHE_HOME=' . Kit::folder('skills-cache'));
        foreach (['GENTLE_AI_HOME', 'PHYCODE_BASE_URL', 'PHYCODE_MODEL', 'PHYCODE_PROFILE'] as $name) {
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testSkillsPrintsEachSkillWithoutCallingAModel(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        FakeGentleAi::firstOnPath($bin);
        $ui = new RecordingUi();
        $session = $this->session($ui);
        $ui->events = [];

        self::assertTrue($session->handle('/skills'));

        self::assertSame(["go-testing  Go tests.\napi" . str_repeat(' ', 9) . 'REST APIs'], $this->outputs($ui));
        self::assertNull($session->lastStatus());
        self::assertSame([], array_values(array_filter($ui->events, static fn (array $event): bool => in_array($event[0], ['text', 'tool_start'], true))));
        self::assertCount(2, FakeGentleAi::calls($bin));
    }

    public function testSkillsSaysWhenGentleAiIsAbsent(): void
    {
        putenv('PATH=' . Kit::folder('no-gentle-ai'));
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skills'));
        self::assertSame([GentleAi::NOT_INSTALLED], $this->outputs($ui));
    }

    public function testSkillsShowsWhyTheRegistryCannotBeRead(): void
    {
        $bin = FakeGentleAi::install(['skillsExit' => 3]);
        FakeGentleAi::firstOnPath($bin);
        $ui = new RecordingUi();

        self::assertTrue($this->session($ui)->handle('/skills'));
        self::assertSame(['skills unavailable: gentle-ai skill-registry list exited with code 3'], $this->outputs($ui));
    }

    public function testSkillsIsAListedCommand(): void
    {
        $ui = new RecordingUi();
        $session = $this->session($ui);

        self::assertContains('/skills', array_column($session->commands(), 'name'));

        $session->handle('/help');
        $panels = array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'panel'));
        self::assertStringContainsString('/skills', (string) $panels[0][2]);
    }

    public function testSkillsWritesNothing(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        FakeGentleAi::firstOnPath($bin);

        $this->session(new RecordingUi())->handle('/skills');

        self::assertSame([], array_values(array_diff(scandir($this->home) ?: [], ['.', '..'])));
    }

    private function session(RecordingUi $ui): ChatSession
    {
        $kernel = new Kernel(Kit::folder('skills-project'), false, $ui, null);

        return new ChatSession($kernel, 'local', Kit::agent(), Transcript::memory());
    }

    private function outputs(RecordingUi $ui): array
    {
        $texts = array_filter($ui->events, static fn (array $event): bool => $event[0] === 'output');

        return array_values(array_map(static fn (array $event): string => (string) $event[1], $texts));
    }
}
