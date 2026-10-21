<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Skills\SkillException;
use Phycode\Skills\SkillLog;
use Phycode\Skills\SkillWriter;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class SkillLogTest extends TestCase
{
    private const SECRET = 'PRIVATE-BODY-TEXT';

    private string $config;
    private string $skills;
    private SkillWriter $writer;

    protected function setUp(): void
    {
        $this->config = Kit::folder('skill-log') . '/phycode';
        $this->skills = $this->config . '/skills';
        $this->writer = new SkillWriter($this->config);
    }

    private function entries(): array
    {
        $lines = file("{$this->skills}/" . SkillLog::FILE, FILE_IGNORE_NEW_LINES) ?: [];

        return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
    }

    public function testEachChangeIsOneLineWithTheTimeActionNameAndScopeAndNoContent(): void
    {
        $this->writer->apply($this->writer->create('go-testing', 'Write Go tests.', self::SECRET . "\n"));
        $this->writer->apply($this->writer->writeFile('go-testing', 'references/a.md', "x\n"));

        $entries = $this->entries();

        self::assertSame(['create', 'write_file'], array_column($entries, 'action'));
        self::assertSame(['go-testing', 'go-testing'], array_column($entries, 'name'));
        self::assertSame(['phycode', 'phycode'], array_column($entries, 'scope'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $entries[0]['ts']);
        self::assertArrayNotHasKey('from', $entries[0], 'only a merge names the skills it joined');
        self::assertStringNotContainsString(self::SECRET, (string) file_get_contents("{$this->skills}/" . SkillLog::FILE), 'no content of a skill is written');
    }

    public function testTheRegistryIsCreatedWithModeZeroSixHundred(): void
    {
        $this->writer->apply($this->writer->create('go-testing', 'Write Go tests.', "Body.\n"));

        self::assertSame(0600, fileperms("{$this->skills}/" . SkillLog::FILE) & 0777);
    }

    public function testArchiveRemoveAndPatchAreRecordedInOrder(): void
    {
        $this->writer->apply($this->writer->create('go-testing', 'Write Go tests.', "Use tables.\n"));
        $this->writer->apply($this->writer->patch('go-testing', '', 'Use tables.', 'Use maps.', false));
        $this->writer->apply($this->writer->archive('go-testing'));
        $this->writer->apply($this->writer->restore('go-testing'));

        self::assertSame(['create', 'patch', 'archive', 'restore'], array_column($this->entries(), 'action'));
    }

    public function testAMergeRecordsTheSkillsItJoined(): void
    {
        $this->writer->apply($this->writer->create('api-rest', 'REST.', "A.\n"));
        $this->writer->apply($this->writer->create('api-errors', 'Errors.', "B.\n"));
        $this->writer->apply($this->writer->merge('api', ['api-rest', 'api-errors'], "---\nname: api\ndescription: Joined.\n---\nAB.\n"));

        $merge = $this->entries()[2];

        self::assertSame(['merge', 'api', ['api-rest', 'api-errors']], [$merge['action'], $merge['name'], $merge['from']]);
    }

    public function testAChangeThatSucceededIsKeptWhenTheRegistryCannotBeWritten(): void
    {
        mkdir($this->skills, 0700, true);
        symlink(Kit::folder('skill-log-target') . '/elsewhere', "{$this->skills}/" . SkillLog::FILE);

        $message = $this->writer->apply($this->writer->create('go-testing', 'Write Go tests.', "Body.\n"));

        self::assertFileExists("{$this->skills}/go-testing/SKILL.md");
        self::assertStringContainsString('(the change is kept, but the registry was not written: ', $message);
    }

    public function testALogThatIsALinkIsRefusedByTheLogItself(): void
    {
        mkdir($this->skills, 0700, true);
        symlink(Kit::folder('skill-log-link') . '/x', "{$this->skills}/" . SkillLog::FILE);

        $this->expectException(SkillException::class);
        $this->expectExceptionMessage(SkillLog::FILE . ' is a symbolic link');

        (new SkillLog($this->skills))->record('create', 'go-testing');
    }
}
