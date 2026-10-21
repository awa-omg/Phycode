<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Skills;

use Phycode\Config\Config;
use Phycode\Skills\SkillCatalog;
use Phycode\Skills\SkillIndex;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use PHPUnit\Framework\TestCase;

final class SkillIndexTest extends TestCase
{
    private const HEADER = 'Available skills (load one with skill(name) when its description matches the task; unload it with skill(name, unload=true) when you no longer need it):';

    private string $project;
    private string $home;
    private string $configDir;

    protected function setUp(): void
    {
        $this->project = Kit::folder('index-project');
        $this->home = Kit::folder('index-home');
        $this->configDir = Kit::folder('index-config') . '/phycode';
    }

    private function index(): SkillIndex
    {
        return new SkillIndex($this->project, $this->home, SkillCatalog::userFolder($this->configDir));
    }

    private function config(?string $bin = null): Config
    {
        $bin ??= FakeGentleAi::install(['skills' => '[]']);

        return Config::fromArrays(['profiles' => ['p' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'm']], 'gentle_ai' => ['bin' => $bin]], null, [], $this->configDir);
    }

    private function skill(string $root, string $folder, string $name, string $description): void
    {
        Kit::write("{$root}/{$folder}/SKILL.md", "---\nname: {$name}\ndescription: {$description}\n---\nBody.\n");
    }

    public function testTheBlockHasTheHeaderAndOneLinePerSkillInTheOrderTheToolResolvesThem(): void
    {
        $this->skill($this->home, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $this->skill($this->configDir . '/skills', 'api-review', 'api-review', 'Reviews APIs.');
        $bin = FakeGentleAi::install(['skills' => json_encode([['name' => 'pdf', 'scope' => 'user', 'description' => 'PDFs.', 'path' => '/p/pdf/SKILL.md']], JSON_THROW_ON_ERROR)]);
        $index = $this->index();

        $index->refresh($this->config($bin));

        self::assertSame(self::HEADER . "\n- go-testing: Go tests.\n- api-review [own]: Reviews APIs.\n- pdf: PDFs.", $index->block());
    }

    public function testADescriptionIsCutToOneHundredCharactersWithAnEllipsis(): void
    {
        $this->skill($this->home, '.agents/skills/long', 'long', str_repeat('word ', 60));
        $index = $this->index();

        $index->refresh($this->config());

        $line = explode("\n", (string) $index->block())[1];
        $description = substr($line, strlen('- long: '));
        self::assertLessThanOrEqual(SkillIndex::DESCRIPTION_CHARS, mb_strlen($description));
        self::assertStringEndsWith('…', $description);
    }

    public function testTheListIsCappedAtFortyEntriesAndTheRestIsCounted(): void
    {
        for ($number = 1; $number <= 45; $number++) {
            $this->skill($this->home, sprintf('.agents/skills/s%02d', $number), sprintf('s%02d', $number), "Skill {$number}.");
        }
        $index = $this->index();

        $index->refresh($this->config());

        $lines = explode("\n", (string) $index->block());
        self::assertCount(1 + SkillIndex::LIMIT + 1, $lines, 'the header, forty entries and the count');
        self::assertSame('+5 more (see /skill)', end($lines));
        self::assertSame('- s40: Skill 40.', $lines[SkillIndex::LIMIT]);
    }

    public function testWithoutSkillsThereIsNoBlock(): void
    {
        $index = $this->index();

        $index->refresh($this->config());

        self::assertNull($index->block());
    }

    public function testAnEntryTwoSourcesShareIsListedOnceWithTheSourceThatResolvesFirst(): void
    {
        $this->skill($this->home, '.agents/skills/go-testing', 'go-testing', 'From the home folder.');
        $this->skill($this->configDir . '/skills', 'go-testing', 'go-testing', 'Saved by phycode.');
        $index = $this->index();

        $index->refresh($this->config());

        self::assertSame(self::HEADER . "\n- go-testing: From the home folder.", $index->block());
    }

    public function testASkillOfTheProjectIsNotOfferedToTheModel(): void
    {
        $this->skill($this->project, '.agents/skills/from-repo', 'from-repo', 'Written by someone else.');
        $this->skill($this->home, '.agents/skills/mine', 'mine', 'Mine.');
        $index = $this->index();

        $index->refresh($this->config());

        self::assertSame(self::HEADER . "\n- mine: Mine.", $index->block());
    }

    public function testGentleAiIsAskedOncePerRefreshAndNotWhenTheBlockIsBuilt(): void
    {
        $bin = FakeGentleAi::install(['skills' => json_encode([['name' => 'pdf', 'scope' => 'user', 'description' => 'PDFs.', 'path' => '/p/pdf/SKILL.md']], JSON_THROW_ON_ERROR)]);
        $config = $this->config($bin);
        $index = $this->index();
        self::assertTrue($index->stale(), 'nothing is listed before the first refresh');

        $index->refresh($config);
        $index->block();
        $index->block();
        self::assertFalse($index->stale());
        self::assertCount(1, FakeGentleAi::calls($bin));

        $index->invalidate();
        self::assertTrue($index->stale());
        $index->refresh($config);
        self::assertCount(2, FakeGentleAi::calls($bin));
    }

    public function testAGentleAiBinaryThatIsNotThereIsANoteAndTheFoldersStillList(): void
    {
        $this->skill($this->project, '.agents/skills/go-testing', 'go-testing', 'Go tests.');
        $config = Config::fromArrays(['profiles' => ['p' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'm']], 'gentle_ai' => ['bin' => '/nonexistent/phycode-gentle-ai']], null, [], $this->configDir);
        $index = $this->index();

        $index->refresh($config);

        self::assertSame(['go-testing'], array_column($index->catalog(), 'title'));
        self::assertSame(['gentle_ai.bin is not an executable file: /nonexistent/phycode-gentle-ai'], $index->notes());
    }
}
