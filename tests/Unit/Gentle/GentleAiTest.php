<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Exec\ProcessRunner;
use Phycode\Gentle\GentleAi;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GentleAiTest extends TestCase
{
    private const SKILLS = '[{"name":"go-testing","scope":"user","description":"Go tests. Trigger: writing Go tests","path":"/home/u/.claude/skills/go-testing/SKILL.md","license":"MIT","metadata":{"version":"1.0"}},{"name":"api","scope":"project","description":"","path":"/work/skills/api/SKILL.md","future_field":true}]';

    private array $environment = [];
    private string $home;

    protected function setUp(): void
    {
        foreach (['PATH', 'HOME', 'GENTLE_AI_HOME'] as $name) {
            $this->environment[$name] = getenv($name);
        }
        $this->home = Kit::folder('gentle-home');
        putenv('HOME=' . $this->home);
        putenv('GENTLE_AI_HOME');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testDetectFindsTheGentleAiOnThePath(): void
    {
        $bin = FakeGentleAi::install();
        FakeGentleAi::firstOnPath($bin);

        self::assertSame($bin, (new GentleAi())->detect());
    }

    public function testDetectReturnsNullWhenGentleAiIsAbsent(): void
    {
        putenv('PATH=' . Kit::folder('no-gentle-ai'));

        self::assertNull((new GentleAi())->detect());
    }

    public function testDetectUsesTheConfiguredBinaryAndIgnoresPath(): void
    {
        $bin = FakeGentleAi::install();
        putenv('PATH=' . Kit::folder('no-gentle-ai'));

        self::assertSame($bin, (new GentleAi())->detect($bin));
        self::assertNull((new GentleAi())->detect('/nonexistent/gentle-ai'));
    }

    public function testDetectResolvesABareNameOnThePath(): void
    {
        $bin = FakeGentleAi::install();
        FakeGentleAi::firstOnPath($bin);

        self::assertSame($bin, (new GentleAi())->detect('gentle-ai'));
    }

    public function testDetectRejectsAConfiguredFileThatIsNotExecutable(): void
    {
        $file = Kit::folder('plain-file') . '/gentle-ai';
        Kit::write($file, "#!/bin/sh\n");
        chmod($file, 0644);

        self::assertNull((new GentleAi())->detect($file));
    }

    public static function versionLines(): iterable
    {
        yield 'release' => ['gentle-ai 4.2.0', '4.2.0'];
        yield 'dev build' => ['gentle-ai dev', 'dev'];
        yield 'with a v' => ['gentle-ai v4.2.0', 'v4.2.0'];
        yield 'not a version' => ['gentle-ai is ready', null];
        yield 'another program' => ['version 4.2.0', null];
    }

    #[DataProvider('versionLines')]
    public function testVersionReadsTheVersionFromTheFirstLine(string $printed, ?string $expected): void
    {
        $bin = FakeGentleAi::install(['version' => $printed]);

        self::assertSame($expected, (new GentleAi())->version($bin));
        self::assertSame(['1|version'], FakeGentleAi::calls($bin));
    }

    public function testVersionIsNullWhenTheCommandFails(): void
    {
        $bin = FakeGentleAi::install(['versionExit' => 1]);

        self::assertNull((new GentleAi())->version($bin));
    }

    public function testVersionIsNullWhenTheCommandHangs(): void
    {
        $this->requireTimeoutCommand();
        $bin = FakeGentleAi::install(['sleep' => 3]);
        FakeGentleAi::firstOnPath($bin);

        self::assertNull((new GentleAi(versionTimeout: 1))->version($bin));
    }

    public function testSkillsReadsEachEntryAndIgnoresUnknownFields(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);

        $skills = (new GentleAi())->skills($bin, Kit::folder('project'), $reason);

        self::assertNull($reason);
        self::assertSame([
            ['name' => 'go-testing', 'scope' => 'user', 'description' => 'Go tests. Trigger: writing Go tests', 'path' => '/home/u/.claude/skills/go-testing/SKILL.md'],
            ['name' => 'api', 'scope' => 'project', 'description' => '', 'path' => '/work/skills/api/SKILL.md'],
        ], $skills);
    }

    public function testSkillsRunsTheRegistryListInTheProjectFolder(): void
    {
        $bin = FakeGentleAi::install(['skills' => '[]']);
        $project = Kit::folder('project');

        (new GentleAi())->skills($bin, $project);

        self::assertSame(["1|skill-registry list --json --cwd {$project}"], FakeGentleAi::calls($bin));
    }

    public function testSkillsIsEmptyAndSaysWhyWhenTheCommandFails(): void
    {
        $bin = FakeGentleAi::install(['skillsExit' => 2, 'stderr' => "registry locked\nsecond line"]);

        self::assertSame([], (new GentleAi())->skills($bin, Kit::folder('project'), $reason));
        self::assertSame('gentle-ai skill-registry list exited with code 2: registry locked', $reason);
    }

    public function testATokenInTheErrorOfTheCommandIsNotInTheReason(): void
    {
        $bin = FakeGentleAi::install(['skillsExit' => 1, 'stderr' => 'registry refused Bearer abc123 token']);

        self::assertSame([], (new GentleAi())->skills($bin, Kit::folder('project'), $reason));
        self::assertSame('gentle-ai skill-registry list exited with code 1: registry refused Bearer [redacted] token', $reason);
    }

    public function testTheSecretsOfTheCallerAreTakenOutOfTheReason(): void
    {
        $bin = FakeGentleAi::install(['skillsExit' => 2, 'stderr' => 'the key s3cret-value was refused']);

        self::assertSame([], (new GentleAi())->skills($bin, Kit::folder('project'), $reason, ['s3cret-value']));
        self::assertSame('gentle-ai skill-registry list exited with code 2: the key [redacted] was refused', $reason);
    }

    public function testSkillsSaysWhenTheOutputIsNotJson(): void
    {
        $bin = FakeGentleAi::install(['skills' => 'No skills found.']);

        self::assertSame([], (new GentleAi())->skills($bin, Kit::folder('project'), $reason));
        self::assertSame('gentle-ai skill-registry list did not print JSON', $reason);
    }

    public function testSkillsSaysWhenTheOutputIsNotAList(): void
    {
        $bin = FakeGentleAi::install(['skills' => '{"skills":[]}']);

        self::assertSame([], (new GentleAi())->skills($bin, Kit::folder('project'), $reason));
        self::assertSame('gentle-ai skill-registry list did not print a JSON array', $reason);
    }

    public function testSkillsWithNoEntriesHasNoReason(): void
    {
        $bin = FakeGentleAi::install(['skills' => '[]']);

        self::assertSame([], (new GentleAi())->skills($bin, Kit::folder('project'), $reason));
        self::assertNull($reason);
    }

    public function testSkillsStopsAtItsTimeout(): void
    {
        $this->requireTimeoutCommand();
        $bin = FakeGentleAi::install(['sleep' => 3]);
        FakeGentleAi::firstOnPath($bin);

        self::assertSame([], (new GentleAi(skillsTimeout: 1))->skills($bin, Kit::folder('project'), $reason));
        self::assertSame('gentle-ai skill-registry list timed out after 1 s', $reason);
    }

    public function testSkillsRemovesControlCharactersFromItsText(): void
    {
        $bin = FakeGentleAi::install(['skills' => '[{"name":"evil\u001b[2J","scope":"user","description":"\u001b]0;title\u0007ok","path":"/p"}]']);

        $skills = (new GentleAi())->skills($bin, Kit::folder('project'));

        self::assertCount(1, $skills);
        self::assertSame(0, preg_match('/\p{Cc}/u', $skills[0]['name'] . $skills[0]['description']));
        self::assertStringEndsWith('ok', $skills[0]['description']);
    }

    public function testStateReturnsTheFileAndKeepsUnknownKeys(): void
    {
        $this->writeState('{"installed_agents":["claude-code","opencode"],"components":["engram"],"persona":"gentleman","rdd_mode":"on","future":{"x":1}}');

        $state = (new GentleAi())->state($reason);

        self::assertNull($reason);
        self::assertSame(['claude-code', 'opencode'], $state['installed_agents']);
        self::assertSame(['engram'], $state['components']);
        self::assertSame('gentleman', $state['persona']);
        self::assertSame('on', $state['rdd_mode']);
        self::assertSame(['x' => 1], $state['future']);
    }

    public function testStateExplainsAMissingFile(): void
    {
        self::assertNull((new GentleAi())->state($reason));
        self::assertSame("no state file at {$this->home}/.gentle-ai/state.json", $reason);
    }

    public function testStateExplainsInvalidJson(): void
    {
        $this->writeState('{"installed_agents": [');

        self::assertNull((new GentleAi())->state($reason));
        self::assertStringStartsWith("invalid JSON in {$this->home}/.gentle-ai/state.json: ", (string) $reason);
    }

    public static function notObjects(): iterable
    {
        yield 'list' => ['[]'];
        yield 'null' => ['null'];
        yield 'string' => ['"text"'];
    }

    #[DataProvider('notObjects')]
    public function testStateRejectsAnythingThatIsNotAnObject(string $content): void
    {
        $this->writeState($content);

        self::assertNull((new GentleAi())->state($reason));
        self::assertSame("{$this->home}/.gentle-ai/state.json does not hold a JSON object", $reason);
    }

    public function testStateFileIsInTheHomeFolderByDefault(): void
    {
        self::assertSame("{$this->home}/.gentle-ai/state.json", (new GentleAi())->stateFile());
    }

    public function testStateFileFollowsGentleAiHomeWhenItIsAbsolute(): void
    {
        putenv('GENTLE_AI_HOME=/srv/gentle-state/');

        self::assertSame('/srv/gentle-state/state.json', (new GentleAi())->stateFile());
    }

    public function testARelativeGentleAiHomeIsIgnored(): void
    {
        putenv('GENTLE_AI_HOME=relative/state');

        self::assertSame("{$this->home}/.gentle-ai/state.json", (new GentleAi())->stateFile());
    }

    public function testDoctorExplainsHowToInstallWithoutRunningAnything(): void
    {
        putenv('PATH=' . Kit::folder('no-gentle-ai'));

        $lines = (new GentleAi())->doctorLines();

        self::assertSame([GentleAi::NOT_INSTALLED], $lines);
        self::assertStringContainsString('not installed', $lines[0]);
        self::assertStringContainsString('never runs remote installers', $lines[0]);
        self::assertStringNotContainsString('curl', $lines[0]);
    }

    public function testDoctorSummarisesAnInstalledGentleAi(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        FakeGentleAi::firstOnPath($bin);
        $this->writeState('{"installed_agents":["claude-code","opencode"]}');

        self::assertSame(["gentle-ai: v4.2.0 at {$bin}; state: ok (2 agents) at {$this->home}/.gentle-ai/state.json; skills: 2"], (new GentleAi())->doctorLines(null, Kit::folder('project')));
    }

    public function testDoctorCountsTheRowsTheRegistryLists(): void
    {
        $bin = FakeGentleAi::install(['skills' => '[{"name":"sdd-init","scope":"user","description":"Starts a change.","path":"/a"},{"name":"sdd-apply","scope":"user","description":"","path":"/b"},{"name":"go-testing","scope":"user","description":"Go tests.","path":"/c"}]']);

        self::assertSame(["gentle-ai: v4.2.0 at {$bin}; state: unavailable (no state file at {$this->home}/.gentle-ai/state.json); skills: 3"], (new GentleAi())->doctorLines($bin, Kit::folder('project')));
    }

    public function testDoctorSaysWhatIsUnavailable(): void
    {
        $bin = FakeGentleAi::install(['version' => 'gentle-ai dev', 'skillsExit' => 1]);

        self::assertSame([
            "gentle-ai: dev at {$bin}; state: unavailable (no state file at {$this->home}/.gentle-ai/state.json); skills: unavailable (gentle-ai skill-registry list exited with code 1); warning: a development build, which cannot be compared with the pin v4.2.0",
        ], (new GentleAi())->doctorLines($bin, Kit::folder('project')));
    }

    public function testDoctorShowsAnUnknownVersionAndASingularAgent(): void
    {
        $bin = FakeGentleAi::install(['versionExit' => 1, 'skills' => '[]']);
        $this->writeState('{"installed_agents":["claude-code"]}');

        self::assertSame(["gentle-ai: version unknown at {$bin}; state: ok (1 agent) at {$this->home}/.gentle-ai/state.json; skills: 0; warning: the version could not be read; the pin is v4.2.0"], (new GentleAi())->doctorLines($bin, Kit::folder('project')));
    }

    public static function versionNotes(): iterable
    {
        yield 'the pin' => ['4.2.0', null];
        yield 'the pin with a v' => ['v4.2.0', null];
        yield 'the version before' => ['4.0.0', 'the pin is v4.2.0; this binary reports 4.0.0'];
        yield 'a later one' => ['4.3.0', 'the pin is v4.2.0; this binary reports 4.3.0'];
        yield 'a development build' => ['dev', 'a development build, which cannot be compared with the pin v4.2.0'];
        yield 'unknown' => [null, 'the version could not be read; the pin is v4.2.0'];
    }

    #[DataProvider('versionNotes')]
    public function testAnotherVersionIsOnlyAWarning(?string $version, ?string $note): void
    {
        self::assertSame($note, GentleAi::versionNote($version));
    }

    public function testTheRowsOfTheRegistryAreReadAsTheRegistryListsThem(): void
    {
        $bin = FakeGentleAi::install(['skills' => '[{"name":"_shared","scope":"user","description":"","path":"/a"},{"name":"sdd-verify","scope":"user","description":"","path":"/c"},{"name":"go-testing","scope":"user","description":"Go tests.","path":"/e"}]']);

        self::assertSame(['_shared', 'sdd-verify', 'go-testing'], array_column((new GentleAi())->skills($bin, Kit::folder('project')), 'name'));
    }

    public function testEveryCallSetsTheTwoSwitchesAndNothingElse(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        $project = Kit::folder('project');
        $gentle = new GentleAi();

        $gentle->version($bin);
        $gentle->skills($bin, $project);

        self::assertSame([
            'GENTLE_AI_NO_SELF_UPDATE=1 GENTLE_AI_TELEMETRY=0 GENTLE_AI_HOME=unset CWD=' . getcwd(),
            "GENTLE_AI_NO_SELF_UPDATE=1 GENTLE_AI_TELEMETRY=0 GENTLE_AI_HOME=unset CWD={$project}",
        ], FakeGentleAi::environment($bin));
    }

    public function testTheStatusRunsOnlyVersionAndTheRegistryList(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        $project = Kit::folder('project');
        $this->writeState('{"installed_agents":["claude-code"],"rdd_mode":"on"}');

        $status = (new GentleAi())->status($bin, $project);

        self::assertSame('4.2.0', $status->version);
        self::assertSame([['version'], ['skill-registry', 'list', '--json', '--cwd', $project]], FakeGentleAi::argv($bin));
        self::assertSame('on', $status->reviewMode());
    }

    public function testTheGentleAiHomeOfTheCallIsPassedOnWhenItIsAbsolute(): void
    {
        $bin = FakeGentleAi::install();
        $home = Kit::folder('gentle-state');
        putenv("GENTLE_AI_HOME={$home}");

        (new GentleAi())->version($bin);

        self::assertSame(["GENTLE_AI_NO_SELF_UPDATE=1 GENTLE_AI_TELEMETRY=0 GENTLE_AI_HOME={$home} CWD=" . getcwd()], FakeGentleAi::environment($bin));
    }

    public function testNothingIsWrittenToTheHomeFolder(): void
    {
        $bin = FakeGentleAi::install(['skills' => self::SKILLS]);
        FakeGentleAi::firstOnPath($bin);
        $gentle = new GentleAi();

        $gentle->detect();
        $gentle->version($bin);
        $gentle->skills($bin, Kit::folder('project'));
        $gentle->state();
        $gentle->doctorLines(null, Kit::folder('project'));

        self::assertSame([], array_values(array_diff(scandir($this->home) ?: [], ['.', '..'])));
    }

    private function writeState(string $json): void
    {
        Kit::write($this->home . '/.gentle-ai/state.json', $json);
    }

    private function requireTimeoutCommand(): void
    {
        if (ProcessRunner::which('timeout') === null) {
            self::markTestSkipped('GNU timeout is not installed');
        }
    }
}
