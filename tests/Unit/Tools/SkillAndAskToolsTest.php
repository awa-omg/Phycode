<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Gentle\GentleAi;
use Phycode\Skills\SkillLoader;
use Phycode\Skills\SkillRegistry;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Unit\Gentle\FakeGentleAi;
use Phycode\Tools\AskUserTool;
use Phycode\Tools\SkillTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SkillAndAskToolsTest extends TestCase
{
    private string $folder;
    private string $skillFile;
    private ToolContext $context;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('skill-tool');
        $this->skillFile = $this->folder . '/go-testing/SKILL.md';
        Kit::write($this->skillFile, "---\nname: go-testing\ndescription: Write Go tests.\n---\nUse table tests.\n");
        $this->context = Kit::context($this->folder, Kit::agent(), Kit::policy(), new RecordingUi());
    }

    private function skillTool(bool $enabled = true): SkillTool
    {
        $bin = FakeGentleAi::install(['skills' => json_encode([[
            'name' => 'go-testing',
            'scope' => 'user',
            'description' => 'Write Go tests.',
            'path' => $this->skillFile,
        ]], JSON_THROW_ON_ERROR)]);

        return new SkillTool(new SkillLoader(new SkillRegistry(new GentleAi(), $bin)), $enabled);
    }

    public function testTheSkillFileIsReturnedAsItIs(): void
    {
        $result = $this->skillTool()->execute(['name' => 'go-testing'], $this->context);

        self::assertTrue($result->ok);
        self::assertSame(file_get_contents($this->skillFile), $result->content);
    }

    public function testAnUnknownSkillIsAFailure(): void
    {
        $result = $this->skillTool()->execute(['name' => 'go-missing'], $this->context);

        self::assertFalse($result->ok);
        self::assertSame('no skill named go-missing', $result->content);
    }

    public function testAPathLikeNameIsRefusedBeforeGentleAiIsAsked(): void
    {
        $result = $this->skillTool()->execute(['name' => '../go-testing'], $this->context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('cannot be empty or contain a slash', $result->content);
    }

    public function testTheSkillToolIsAReadOfferedOnlyWhenSkillsAreEnabled(): void
    {
        $policy = Kit::policy();

        self::assertSame(ToolCategory::Read, $this->skillTool()->category());
        self::assertTrue($this->skillTool(true)->isAvailable($policy));
        self::assertFalse($this->skillTool(false)->isAvailable($policy));
        self::assertSame('skill: go-testing', $this->skillTool()->summarize(['name' => 'go-testing']));
    }

    private function asked(RecordingUi $ui): array
    {
        return array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'ask'));
    }

    public function testAskUserReturnsTheAnswerAndPassesTheOptions(): void
    {
        $ui = new RecordingUi();
        $ui->answers = ['yes, the staging one'];
        $context = Kit::context($this->folder, Kit::agent(), Kit::policy(), $ui);

        $result = (new AskUserTool(true))->execute(['question' => 'Which database?', 'options' => ['staging', 'production']], $context);

        self::assertTrue($result->ok);
        self::assertSame('The user answered: yes, the staging one', $result->content);
        self::assertSame([['ask', 'Which database?', ['staging', 'production']]], $this->asked($ui));
    }

    public function testAnAnswerThatIsNotGivenIsSaidSo(): void
    {
        $ui = new RecordingUi();
        $ui->answers = [null];
        $context = Kit::context($this->folder, Kit::agent(), Kit::policy(), $ui);

        $result = (new AskUserTool(true))->execute(['question' => 'Continue?'], $context);

        self::assertTrue($result->ok);
        self::assertSame('the user gave no answer', $result->content);
    }

    public function testWithoutAPersonToAnswerNoOneIsAsked(): void
    {
        $ui = new RecordingUi();
        $ui->terminal = false;
        $context = Kit::context($this->folder, Kit::agent(), Kit::policy(), $ui);

        $result = (new AskUserTool(true))->execute(['question' => 'Continue?'], $context);

        self::assertFalse($result->ok);
        self::assertSame('no user is available to answer', $result->content);
        self::assertSame([], $this->asked($ui));
    }

    public static function badArguments(): iterable
    {
        yield 'no question' => [[], "missing required argument 'question'"];
        yield 'an empty question' => [['question' => '  '], "argument 'question' must have 1 to 1000 bytes"];
        yield 'too many options' => [['question' => 'x', 'options' => ['a', 'b', 'c', 'd', 'e', 'f', 'g']], "argument 'options' must be a list of up to 6 strings"];
        yield 'an option that is not text' => [['question' => 'x', 'options' => [1]], "argument 'options' must hold strings of 1 to 200 bytes"];
    }

    #[DataProvider('badArguments')]
    public function testBadArgumentsAreRefusedBeforeTheUserIsAsked(array $arguments, string $message): void
    {
        $ui = new RecordingUi();
        $ui->answers = ['never'];
        $context = Kit::context($this->folder, Kit::agent(), Kit::policy(), $ui);

        try {
            (new AskUserTool(true))->execute($arguments, $context);
            self::fail('the arguments should have been refused');
        } catch (PhycodeException $error) {
            self::assertSame($message, $error->getMessage());
        }
        self::assertSame([], $this->asked($ui));
    }

    public function testAskUserIsOfferedOnlyWhenEnabled(): void
    {
        $policy = Kit::policy();

        self::assertTrue((new AskUserTool(true))->isAvailable($policy));
        self::assertFalse((new AskUserTool(false))->isAvailable($policy));
        self::assertSame(ToolCategory::Read, (new AskUserTool(true))->category());
        self::assertSame('ask_user: Which database?', (new AskUserTool(true))->summarize(['question' => 'Which database?']));
    }
}
