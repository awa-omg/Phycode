<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\AskUserTool;
use Phycode\Tools\CustomTool;
use Phycode\Tools\SkillManageTool;
use Phycode\Tools\SkillTool;
use Phycode\Tools\Tool;
use Phycode\Tools\ToolRegistry;
use Phycode\Tools\ToolSettings;
use Phycode\Tools\WebFetchTool;
use Phycode\Tools\WebSearchTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolSettingsTest extends TestCase
{
    private string $folder;
    private string $configFile;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('tool-settings');
        $this->configFile = $this->folder . '/config.json';
    }

    private function write(array $config): void
    {
        file_put_contents($this->configFile, json_encode($config, JSON_THROW_ON_ERROR));
    }

    private function config(array $extra = []): Config
    {
        return Config::fromArrays(['default_profile' => 'home', 'profiles' => ['home' => ['base_url' => 'https://192.168.1.10:8080', 'model' => 'qwen', 'api_key_env' => 'HOME_KEY']]] + $extra, null, [], $this->folder);
    }

    private function optional(Config $config, RecordingUi $ui, ?ToolSettings $settings = null): array
    {
        $tools = ($settings ?? ToolSettings::fromFile($this->configFile))->optionalTools($config, $ui, $this->folder);
        $named = [];
        foreach ($tools as $tool) {
            $named[$tool->name()] = $tool;
        }

        return $named;
    }

    private function web(Config $config): array
    {
        $named = [];
        foreach (ToolSettings::fromFile($this->configFile)->webTools($config, $this->folder . '/cache') as $tool) {
            $named[$tool->name()] = $tool;
        }

        return $named;
    }

    public function testAMissingFileGivesNoSettings(): void
    {
        $settings = ToolSettings::fromFile($this->folder . '/absent.json');

        self::assertSame([], $settings->disabled);
        self::assertSame([], $settings->custom);
    }

    public function testTheDisabledToolsAndTheCustomToolsAreRead(): void
    {
        $this->write([
            'tools' => ['disabled' => ['bash', 'web_fetch']],
            'custom_tools' => [
                ['name' => 'lint', 'description' => 'Lint the code', 'command' => ['php', '-l', 'src/a.php'], 'stdin' => true],
                ['name' => 'fmt', 'description' => 'Format the code', 'command' => ['gofmt', '-l']],
            ],
        ]);

        $settings = ToolSettings::fromFile($this->configFile);

        self::assertSame(['bash', 'web_fetch'], $settings->disabled);
        self::assertSame([
            ['name' => 'lint', 'description' => 'Lint the code', 'command' => ['php', '-l', 'src/a.php'], 'stdin' => true],
            ['name' => 'fmt', 'description' => 'Format the code', 'command' => ['gofmt', '-l'], 'stdin' => false],
        ], $settings->custom);
    }

    public static function badSettings(): iterable
    {
        yield 'tools as a list' => [['tools' => ['bash']], 'tools must be an object'];
        yield 'an unknown tools key' => [['tools' => ['enabled' => ['bash']]], 'tools.enabled is not a known setting'];
        yield 'disabled as text' => [['tools' => ['disabled' => 'bash']], 'tools.disabled must be a list of tool names'];
        yield 'a name with a slash' => [['tools' => ['disabled' => ['a/b']]], 'tools.disabled must be a list of tool names'];
        yield 'custom tools as an object' => [['custom_tools' => ['name' => 'x']], 'custom_tools must be a list of tools'];
        yield 'an unknown key in a tool' => [['custom_tools' => [['name' => 'x', 'description' => 'd', 'command' => ['a'], 'shell' => true]]], 'custom_tools[0].shell is not a known setting'];
        yield 'a name that starts with a digit' => [['custom_tools' => [['name' => '1x', 'description' => 'd', 'command' => ['a']]]], 'custom_tools[0].name must start with a letter'];
        yield 'a name that is too long' => [['custom_tools' => [['name' => str_repeat('a', 49), 'description' => 'd', 'command' => ['a']]]], 'custom_tools[0].name must start with a letter'];
        yield 'a name listed twice' => [['custom_tools' => [['name' => 'x', 'description' => 'd', 'command' => ['a']], ['name' => 'x', 'description' => 'e', 'command' => ['b']]]], 'custom tool x is listed twice'];
        yield 'an empty description' => [['custom_tools' => [['name' => 'x', 'description' => ' ', 'command' => ['a']]]], 'custom_tools[0].description must be text of 1 to 500 bytes'];
        yield 'an empty command' => [['custom_tools' => [['name' => 'x', 'description' => 'd', 'command' => []]]], 'custom_tools[0].command must be a list of 1 to 32 strings, the program first'];
        yield 'a command written as one string' => [['custom_tools' => [['name' => 'x', 'description' => 'd', 'command' => 'php -l']]], 'custom_tools[0].command must be a list of 1 to 32 strings, the program first'];
        yield 'a command part that is not text' => [['custom_tools' => [['name' => 'x', 'description' => 'd', 'command' => ['php', 3]]]], 'custom_tools[0].command must be a list of non-empty strings without NUL bytes'];
        yield 'stdin as text' => [['custom_tools' => [['name' => 'x', 'description' => 'd', 'command' => ['a'], 'stdin' => 'yes']]], 'custom_tools[0].stdin must be true or false'];
    }

    #[DataProvider('badSettings')]
    public function testAWrongShapeIsRefusedWithThePlaceOfTheProblem(array $config, string $message): void
    {
        $this->write($config);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        ToolSettings::fromFile($this->configFile);
    }

    public function testAFileThatIsNotJsonIsRefused(): void
    {
        file_put_contents($this->configFile, '{"tools": ');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('is not valid JSON');

        ToolSettings::fromFile($this->configFile);
    }

    public function testWithoutWebTheSkillAskAndCustomToolsAreOffered(): void
    {
        $this->write(['custom_tools' => [['name' => 'lint', 'description' => 'Lint', 'command' => ['php', '-v']]]]);

        $tools = $this->optional($this->config(), new RecordingUi());

        self::assertSame(['skill', 'skill_manage', 'ask_user', 'custom__lint'], array_keys($tools));
        self::assertInstanceOf(SkillTool::class, $tools['skill']);
        self::assertInstanceOf(SkillManageTool::class, $tools['skill_manage']);
        self::assertInstanceOf(CustomTool::class, $tools['custom__lint']);
        self::assertTrue($tools['ask_user']->isAvailable(Kit::policy()));
        self::assertTrue($tools['custom__lint']->isAvailable(Kit::policy()));
    }

    public function testTheWebToolsFollowTheWebSettings(): void
    {
        $searching = $this->web($this->config(['web' => ['search' => ['backend' => 'searxng', 'url' => 'http://127.0.0.1:8888']]]));
        $fetchOnly = $this->web($this->config(['web' => ['search' => ['backend' => 'none']]]));
        $none = $this->web($this->config());

        self::assertInstanceOf(WebSearchTool::class, $searching['web_search']);
        self::assertTrue($searching['web_search']->isAvailable(Kit::policy()));
        self::assertInstanceOf(WebFetchTool::class, $searching['web_fetch']);
        self::assertFalse($fetchOnly['web_search']->isAvailable(Kit::policy()), 'no backend, no search');
        self::assertTrue($fetchOnly['web_fetch']->isAvailable(Kit::policy()));
        self::assertArrayNotHasKey('web_search', $none);
        self::assertArrayNotHasKey('web_fetch', $none);
    }

    public function testSkillsOffInTheConfigSwitchTheSkillToolOff(): void
    {
        $tools = $this->optional($this->config(['skills' => ['enabled' => false]]), new RecordingUi());

        self::assertFalse($tools['skill']->isAvailable(Kit::policy()));
    }

    public function testAGentleAiBinaryThatIsNotThereSwitchesSkillsOff(): void
    {
        $tools = $this->optional($this->config(['gentle_ai' => ['bin' => '/nonexistent/gentle-ai']]), new RecordingUi());

        self::assertFalse($tools['skill']->isAvailable(Kit::policy()));
    }

    public function testAskUserIsOfferedOnlyInAnInteractiveSession(): void
    {
        $headless = new RecordingUi();
        $headless->terminal = false;

        $interactive = $this->optional($this->config(), new RecordingUi());

        self::assertInstanceOf(AskUserTool::class, $interactive['ask_user']);
        self::assertTrue($interactive['ask_user']->isAvailable(Kit::policy()));
        self::assertFalse($this->optional($this->config(), $headless)['ask_user']->isAvailable(Kit::policy()), 'no person to answer, no tool');
    }

    public function testADisabledToolIsHiddenFromTheRegistryAndTheCustomToolIsNamedAfterItsEntry(): void
    {
        $this->write([
            'tools' => ['disabled' => ['skill', 'custom__lint']],
            'custom_tools' => [['name' => 'lint', 'description' => 'Lint', 'command' => ['php', '-v']]],
        ]);
        $settings = ToolSettings::fromFile($this->configFile);
        $registry = new ToolRegistry($settings->disabled);
        foreach ($this->optional($this->config(), new RecordingUi(), $settings) as $tool) {
            $registry->register($tool);
        }

        $names = $registry->available(Kit::policy());

        self::assertSame(['skill_manage', 'ask_user'], $names);
    }

    public function testSkillsOffInTheConfigSwitchSkillManageOffToo(): void
    {
        $tools = $this->optional($this->config(['skills' => ['enabled' => false]]), new RecordingUi());

        self::assertFalse($tools['skill_manage']->isAvailable(Kit::policy()));
    }

    public function testADisabledSkillManageIsHiddenFromTheRegistry(): void
    {
        $this->write(['tools' => ['disabled' => ['skill_manage']]]);
        $settings = ToolSettings::fromFile($this->configFile);
        $registry = new ToolRegistry($settings->disabled);
        foreach ($this->optional($this->config(), new RecordingUi(), $settings) as $tool) {
            $registry->register($tool);
        }

        self::assertSame(['skill', 'ask_user'], $registry->available(Kit::policy()));
        self::assertNotNull($registry->get('skill_manage'), 'it stays registered, and AgentRunner refuses a call to a tool that is not available');
    }
}
