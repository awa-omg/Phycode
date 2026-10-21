<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Gentle\GentleAi;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class GentleGateTest extends TestCase
{
    private const SKILLS = '[{"name":"go-testing","scope":"user","description":"Go tests.","path":"/a/SKILL.md"},{"name":"api","scope":"project","description":"REST","path":"/b/SKILL.md"}]';

    private array $environment = [];
    private string $home;

    protected function setUp(): void
    {
        foreach (['PATH', 'HOME', 'GENTLE_AI_HOME'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        $this->home = Kit::folder('gate-home');
        putenv('HOME=' . $this->home);
        putenv('GENTLE_AI_HOME');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testThePinnedVersionNeedsNoWarning(): void
    {
        $bin = FakeGentleAi::install(['version' => 'gentle-ai 4.2.0', 'skills' => self::SKILLS]);

        $status = (new GentleAi())->status($bin, Kit::folder('project'));

        self::assertSame('4.2.0', $status->version);
        self::assertNull($status->versionNote());
        self::assertStringNotContainsString('warning', $status->doctorLine());
        self::assertStringStartsWith("gentle-ai: v4.2.0 at {$bin} (pinned v4.2.0)\nstate: ", $status->panel());
        self::assertStringNotContainsString('warning', $status->panel());
    }

    public function testAnOlderBinaryIsWarnedAboutAndStillReadTheRegistry(): void
    {
        $bin = FakeGentleAi::install(['version' => 'gentle-ai 4.0.0', 'skills' => self::SKILLS]);
        $project = Kit::folder('project');

        $status = (new GentleAi())->status($bin, $project);

        self::assertSame('the pin is v4.2.0; this binary reports 4.0.0', $status->versionNote());
        self::assertSame(['go-testing', 'api'], array_column($status->skills, 'name'), 'a warning never blocks the registry read');
        self::assertSame(
            ["gentle-ai: v4.0.0 at {$bin}; state: unavailable (no state file at {$this->home}/.gentle-ai/state.json); skills: 2; warning: the pin is v4.2.0; this binary reports 4.0.0"],
            (new GentleAi())->doctorLines($bin, $project),
        );
        self::assertStringContainsString("warning: the pin is v4.2.0; this binary reports 4.0.0\n", $status->panel());
    }

    public function testAMissingBinaryIsNotInstalledAndNothingIsRun(): void
    {
        putenv('PATH=' . Kit::folder('no-gentle-ai'));
        $gentle = new GentleAi();

        $status = $gentle->status(null, Kit::folder('project'));

        self::assertNull($status->bin);
        self::assertSame([GentleAi::NOT_INSTALLED], $gentle->doctorLines(null, Kit::folder('project')));
        self::assertSame(GentleAi::NOT_INSTALLED, $status->panel());
    }

    public function testAConfiguredBinaryThatIsMissingIsNotFallenBackFrom(): void
    {
        $fallback = FakeGentleAi::install(['skills' => self::SKILLS]);
        FakeGentleAi::firstOnPath($fallback);

        $status = (new GentleAi())->status('/nonexistent/gentle-ai', Kit::folder('project'));

        self::assertNull($status->bin, 'a configured binary that does not exist is not replaced by one on PATH');
        self::assertSame([], FakeGentleAi::calls($fallback));
    }

    public function testTheStateIsReadFromGentleAiHomeWhenItIsAbsoluteAndIsNeverWritten(): void
    {
        $state = Kit::folder('gentle-state');
        Kit::write($state . '/state.json', '{"installed_agents":["claude-code","opencode"],"persona":"gentleman","rdd_mode":"off"}');
        putenv("GENTLE_AI_HOME={$state}");
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        $before = file_get_contents($state . '/state.json');

        $status = (new GentleAi())->status($bin, Kit::folder('project'));

        self::assertSame($state . '/state.json', $status->stateFile);
        self::assertSame('off', $status->reviewMode());
        self::assertStringContainsString("state: ok at {$state}/state.json (2 agents)\n", $status->panel());
        self::assertStringContainsString("gentle-ai rdd_mode: off (from state.json; /review does not use it)\n", $status->panel());
        self::assertStringContainsString("gentle-ai persona: gentleman (from state.json; /persona does not change it)\n", $status->panel());
        self::assertSame($before, file_get_contents($state . '/state.json'));
    }

    public function testTheReviewModeIsNotSetWithoutTheKey(): void
    {
        Kit::write($this->home . '/.gentle-ai/state.json', '{"installed_agents":["codex"]}');
        $bin = FakeGentleAi::install(['skills' => '[]']);

        $status = (new GentleAi())->status($bin, Kit::folder('project'));

        self::assertSame('not set', $status->reviewMode());
        self::assertStringContainsString('gentle-ai rdd_mode: not set (from state.json; /review does not use it)', $status->panel());
        self::assertStringContainsString('gentle-ai persona: none (from state.json; /persona does not change it)', $status->panel());
    }

    public function testAStateFileThatCannotBeReadIsSaidAndDoesNotStopTheStatus(): void
    {
        Kit::write($this->home . '/.gentle-ai/state.json', '{"installed_agents": [');
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);

        $status = (new GentleAi())->status($bin, Kit::folder('project'));

        self::assertNull($status->state);
        self::assertSame('unknown', $status->reviewMode());
        self::assertStringContainsString('state: unavailable (invalid JSON in ', $status->panel());
        self::assertSame(['go-testing', 'api'], array_column($status->skills, 'name'));
    }

    public function testARegistryThatCannotBeListedIsSaidWithItsReason(): void
    {
        $bin = FakeGentleAi::install(['skillsExit' => 1, 'stderr' => 'registry locked']);

        $status = (new GentleAi())->status($bin, Kit::folder('project'));

        self::assertStringContainsString('skills: unavailable (gentle-ai skill-registry list exited with code 1: registry locked)', $status->panel());
        self::assertSame([], $status->skills);
    }
}
