<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Review\ReviewSettings;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewSettingsTest extends TestCase
{
    private const PROFILES = ['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen']]];

    private string $file;

    protected function setUp(): void
    {
        $this->file = Kit::folder('review-settings') . '/config.json';
    }

    public function testTheModeIsEnabledWhenTheConfigSaysNothing(): void
    {
        $settings = new ReviewSettings($this->file);
        self::assertTrue($settings->enabled(), 'no file at all');

        $this->write(self::PROFILES);
        self::assertTrue($settings->enabled());
        self::assertFalse($settings->configured());
    }

    public function testADisabledModeIsRead(): void
    {
        $this->write(self::PROFILES + ['review' => ['mode' => 'disabled']]);

        $settings = new ReviewSettings($this->file);

        self::assertFalse($settings->enabled());
        self::assertTrue($settings->configured());
    }

    public function testAModeThatIsNeitherEnabledNorDisabledFailsClosed(): void
    {
        $this->write(self::PROFILES + ['review' => ['mode' => 'maybe']]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('review.mode must be enabled or disabled');

        (new ReviewSettings($this->file))->enabled();
    }

    public function testSettingTheModeKeepsEveryOtherKeyAndKeepsABackup(): void
    {
        $original = json_encode(self::PROFILES + ['ui' => ['theme' => 'paper']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        file_put_contents($this->file, $original);

        (new ReviewSettings($this->file))->setMode(false);

        $saved = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['mode' => 'disabled'], $saved['review']);
        self::assertSame('local', $saved['default_profile']);
        self::assertSame('paper', $saved['ui']['theme']);
        self::assertSame($original, file_get_contents($this->file . '.bak'));
        self::assertFalse((new ReviewSettings($this->file))->enabled());

        (new ReviewSettings($this->file))->setMode(true);
        self::assertTrue((new ReviewSettings($this->file))->enabled());
    }

    public function testWithoutAConfigFileNothingIsCreatedAndTheMessageSaysHowToCreateIt(): void
    {
        try {
            (new ReviewSettings($this->file))->setMode(true);
            self::fail('the mode was set without a config file');
        } catch (PhycodeException $error) {
            self::assertStringContainsString("echo '{}' > " . escapeshellarg($this->file), $error->getMessage());
            self::assertStringEndsWith('then run /review mode on again', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->file);

        file_put_contents($this->file, '{}');
        (new ReviewSettings($this->file))->setMode(false);

        self::assertFalse((new ReviewSettings($this->file))->enabled(), 'the file the message describes is enough');
    }

    public function testASymbolicLinkConfigIsNotWritten(): void
    {
        $target = Kit::folder('review-link-target') . '/config.json';
        file_put_contents($target, json_encode(self::PROFILES, JSON_THROW_ON_ERROR));
        symlink($target, $this->file);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('is a symbolic link');

        (new ReviewSettings($this->file))->setMode(false);
    }

    public function testAConfigThatIsNotJsonIsNotWritten(): void
    {
        file_put_contents($this->file, '{"profiles": ');

        try {
            (new ReviewSettings($this->file))->setMode(false);
            self::fail('the change was written');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('is not valid JSON', $error->getMessage());
        }
        self::assertSame('{"profiles": ', file_get_contents($this->file));
    }

    public function testTheTestCommandIsTheOneOfTheProjectFolder(): void
    {
        $project = Kit::folder('review-project');
        $this->write(self::PROFILES + ['review' => ['test' => [realpath($project) => ['vendor/bin/phpunit', '--testsuite', 'unit']]]]);

        $settings = new ReviewSettings($this->file);

        self::assertSame(['vendor/bin/phpunit', '--testsuite', 'unit'], $settings->testCommand($project));
        self::assertNull($settings->testCommand(Kit::folder('review-other-project')));
    }

    public static function malformedTests(): iterable
    {
        yield 'a list instead of a map' => [true, ['vendor/bin/phpunit']];
        yield 'a string instead of a list' => [false, 'vendor/bin/phpunit'];
        yield 'an empty list' => [false, []];
        yield 'a part that is not a string' => [false, ['vendor/bin/phpunit', 3]];
    }

    #[DataProvider('malformedTests')]
    public function testAMalformedTestCommandIsRefused(bool $listInsteadOfMap, mixed $command): void
    {
        $project = Kit::folder('review-malformed-project');
        $this->write(self::PROFILES + ['review' => ['test' => $listInsteadOfMap ? $command : [realpath($project) => $command]]]);

        $this->expectException(PhycodeException::class);

        (new ReviewSettings($this->file))->testCommand($project);
    }

    private function write(array $config): void
    {
        file_put_contents($this->file, json_encode($config, JSON_THROW_ON_ERROR));
    }
}
