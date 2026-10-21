<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserSettingsTest extends TestCase
{
    private const GLOBAL = ['profiles' => ['local' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'qwen']]];

    private function config(array $extra = []): Config
    {
        return Config::fromArrays(self::GLOBAL + $extra, null, [], '/nonexistent');
    }

    public function testTheDefaultsAreTokyoNightNoEditorNoDisabledToolsEffortNoneAndTheWindowOfTheModel(): void
    {
        $config = $this->config();

        self::assertSame('phycode', $config->uiTheme());
        self::assertNull($config->editorCommand());
        self::assertSame([], $config->disabledTools());
        self::assertSame('none', $config->effortDefault());
        self::assertNull($config->contextMax());
        self::assertNull($config->contextBuffer(), 'the buffer is the automatic one');
        self::assertTrue($config->autoCompact());
    }

    public function testTheSettingsAreReadFromTheConfig(): void
    {
        $config = $this->config([
            'ui' => ['theme' => 'solarized-dark', 'editor' => ' nvim '],
            'tools' => ['disabled' => ['bash', 'run_code', 'bash']],
            'effort' => ['default' => 'medium'],
            'context' => ['max' => 8192, 'buffer' => 500, 'auto_compact' => false],
        ]);

        self::assertSame('solarized-dark', $config->uiTheme());
        self::assertSame('nvim', $config->editorCommand());
        self::assertSame(['bash', 'run_code'], $config->disabledTools(), 'a name listed twice counts once');
        self::assertSame('medium', $config->effortDefault());
        self::assertSame([8192, 500, false], [$config->contextMax(), $config->contextBuffer(), $config->autoCompact()]);
    }

    public static function effortDefaults(): iterable
    {
        yield 'off, as older configs have it' => ['off', 'none'];
        yield 'on, as older configs have it' => ['on', 'medium'];
        foreach (['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'] as $level) {
            yield "the level {$level}" => [$level, $level];
        }
    }

    #[DataProvider('effortDefaults')]
    public function testTheEffortDefaultAcceptsEveryLevelAndTheAliases(string $value, string $level): void
    {
        self::assertSame($level, $this->config(['effort' => ['default' => $value]])->effortDefault());
    }

    public function testTheUiConfigKeepsItsShapeAndTheProjectCannotSetTheseSettings(): void
    {
        $config = $this->config(['ui' => ['theme' => 'paper', 'diff_view' => 'split']]);

        self::assertSame(['subagents' => ['auto_expand' => ['write', 'web']], 'diff_view' => 'split'], $config->uiConfig());
        self::assertSame('paper', $config->uiTheme());

        $project = Config::fromArrays(self::GLOBAL, ['ui' => ['theme' => 'mono'], 'tools' => ['disabled' => ['bash']]], [], '/nonexistent');
        self::assertSame('phycode', $project->uiTheme());
        self::assertSame([], $project->disabledTools());
        self::assertCount(2, $project->warnings);
    }

    public static function refusedSettings(): iterable
    {
        $tools = 'tools.disabled must be a list of built-in tool names';
        yield 'a theme that is not one' => [['ui' => ['theme' => 'dark']], 'ui.theme must be phycode, phycode-light, tokyo-night, catppuccin, gruvbox, rose-pine, nord, solarized-dark, paper or mono'];
        yield 'an editor that is a number' => [['ui' => ['editor' => 5]], 'ui.editor must be one line of text'];
        yield 'an editor that is blank' => [['ui' => ['editor' => '   ']], 'ui.editor must be one line of text'];
        yield 'an editor over two lines' => [['ui' => ['editor' => "nvim\nrm"]], 'ui.editor must be one line of text'];
        yield 'a tool that does not exist' => [['tools' => ['disabled' => ['shell']]], $tools];
        yield 'the list as a word' => [['tools' => ['disabled' => 'bash']], $tools];
        yield 'an unknown tools key' => [['tools' => ['enabled' => ['bash']]], 'tools.enabled is not a known setting'];
        yield 'an effort that is not a level' => [['effort' => ['default' => 'ultra']], 'effort.default must be none, minimal, low, medium, high, xhigh or max (off and on are also accepted)'];
        yield 'an unknown effort key' => [['effort' => ['level' => 'low']], 'effort.level is not a known setting'];
        yield 'a max below the minimum' => [['context' => ['max' => 100]], 'context.max must be an integer from 512 to 1000000'];
        yield 'a max as text' => [['context' => ['max' => '8k']], 'context.max must be an integer from 512 to 1000000'];
        yield 'a buffer below zero' => [['context' => ['buffer' => -1]], 'context.buffer must be an integer from 0 to 1000000'];
        yield 'an auto_compact that is not a flag' => [['context' => ['auto_compact' => 'yes']], 'context.auto_compact must be true or false'];
        yield 'the old cap key' => [['context' => ['cap' => 8192]], 'context.cap is not a known setting'];
        yield 'an unknown context key' => [['context' => ['limit' => 5]], 'context.limit is not a known setting'];
    }

    #[DataProvider('refusedSettings')]
    public function testTheLoaderRefusesAValueThatIsNotAValidSetting(array $extra, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        $this->config($extra);
    }
}
