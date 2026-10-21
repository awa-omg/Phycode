<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigEditor;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigEditorTest extends TestCase
{
    private const CONFIG = <<<'JSON'
    {
        "default_profile": "local",
        "profiles": {
            "local": {"base_url": "http://127.0.0.1:8082", "model": "qwen-old"}
        },
        "permissions": {"mode": "ask"},
        "mcp_servers": {
            "docs": {"command": ["npx", "docs-mcp"], "enabled": true, "approval": "ask"}
        },
        "note": "Ñandú / listo"
    }
    JSON;

    private string $folder;
    private string $file;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('config-editor');
        $this->file = $this->folder . '/config.json';
        Kit::write($this->file, self::CONFIG);
    }

    public function testTheItemsListEverySettingWithItsCurrentValue(): void
    {
        $items = $this->items();

        self::assertSame(['key' => 'ui.theme', 'label' => 'Theme', 'value' => 'phycode', 'kind' => 'choice', 'choices' => ['phycode', 'phycode-light', 'tokyo-night', 'catppuccin', 'gruvbox', 'rose-pine', 'nord', 'solarized-dark', 'paper', 'mono'], 'category' => 'UI'], $items['ui.theme']);
        self::assertSame(['key' => 'ui.editor', 'label' => 'Editor command', 'value' => '', 'kind' => 'text', 'category' => 'UI'], $items['ui.editor']);
        self::assertSame(['auto', 'split', 'unified'], $items['ui.diff_view']['choices']);
        self::assertSame(['ask', 'accept_edits', 'plan', 'dont_ask', 'yolo'], $items['permissions']['choices']);
        self::assertSame('ask', $items['permissions']['value'], 'the object form is read');
        self::assertSame(['none', 'searxng', 'brave', 'tavily', 'exa', 'perplexity', 'ollama', 'firecrawl'], $items['web.search.backend']['choices']);
        self::assertSame('none', $items['web.search.backend']['value']);
        self::assertSame(['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], $items['effort.default']['choices']);
        self::assertSame(['key' => 'context.max', 'label' => 'Context max', 'value' => 'off', 'kind' => 'text', 'category' => 'Model'], $items['context.max']);
        self::assertSame('auto', $items['context.buffer']['value']);
        self::assertSame(['key' => 'context.auto_compact', 'label' => 'Autocompact', 'value' => true, 'kind' => 'bool', 'category' => 'Model'], $items['context.auto_compact']);
        self::assertSame(['key' => 'skills.enabled', 'label' => 'Skills enabled', 'value' => true, 'kind' => 'bool', 'category' => 'Skills'], $items['skills.enabled']);
        self::assertSame(['key' => 'subagents', 'label' => 'Subagents enabled', 'value' => true, 'kind' => 'bool', 'category' => 'Agents'], $items['subagents']);
        self::assertSame(['key' => 'mcp.docs.enabled', 'label' => 'Enable MCP server docs', 'value' => true, 'kind' => 'bool', 'category' => 'MCP'], $items['mcp.docs.enabled']);
        foreach (Config::BUILTIN_TOOLS as $tool) {
            self::assertSame(['key' => "tools.disabled.{$tool}", 'label' => "Disable {$tool}", 'value' => false, 'kind' => 'bool', 'category' => 'Tools'], $items["tools.disabled.{$tool}"]);
        }
    }

    public function testEveryItemHasAKnownKindAndChoicesOnlyWhenItIsAChoice(): void
    {
        foreach ($this->items() as $item) {
            self::assertContains($item['kind'], ['bool', 'choice', 'text'], $item['key']);
            self::assertSame($item['kind'] === 'choice', array_key_exists('choices', $item), $item['key']);
            self::assertNotSame('', $item['category']);
        }
    }

    public function testTheItemsFollowTheConfigAfterAChange(): void
    {
        $this->editor()->set('ui.theme', 'paper');
        $this->editor()->set('tools.disabled.bash', true);

        $items = $this->items();

        self::assertSame('paper', $items['ui.theme']['value']);
        self::assertTrue($items['tools.disabled.bash']['value']);
        self::assertFalse($items['tools.disabled.read']['value']);
    }

    public static function validChanges(): iterable
    {
        yield 'theme' => ['ui.theme', 'paper', static fn (Config $c): string => $c->uiTheme()];
        yield 'editor' => ['ui.editor', ' code --wait ', static fn (Config $c): ?string => $c->editorCommand()];
        yield 'an empty editor is unset' => ['ui.editor', '', static fn (Config $c): ?string => $c->editorCommand()];
        yield 'diff view' => ['ui.diff_view', 'unified', static fn (Config $c): string => $c->uiConfig()['diff_view']];
        yield 'permissions' => ['permissions', 'plan', static fn (Config $c): string => $c->permissionMode()];
        yield 'effort' => ['effort.default', 'high', static fn (Config $c): string => $c->effortDefault()];
        yield 'a context max' => ['context.max', '4096', static fn (Config $c): ?int => $c->contextMax()];
        yield 'a context max as a number' => ['context.max', 2048, static fn (Config $c): ?int => $c->contextMax()];
        yield 'no context max' => ['context.max', 'off', static fn (Config $c): ?int => $c->contextMax()];
        yield 'a context buffer' => ['context.buffer', '500', static fn (Config $c): ?int => $c->contextBuffer()];
        yield 'an automatic context buffer' => ['context.buffer', 'auto', static fn (Config $c): ?int => $c->contextBuffer()];
        yield 'autocompact off' => ['context.auto_compact', false, static fn (Config $c): bool => $c->autoCompact()];
        yield 'skills' => ['skills.enabled', false, static fn (Config $c): bool => $c->skillsEnabled()];
        yield 'subagents' => ['subagents', false, static fn (Config $c): bool => $c->subagentsEnabled];
        yield 'a disabled tool' => ['tools.disabled.bash', true, static fn (Config $c): array => $c->disabledTools()];
        yield 'an MCP server' => ['mcp.docs.enabled', false, static fn (Config $c): bool => $c->mcpServers()['docs']['enabled']];
    }

    #[DataProvider('validChanges')]
    public function testAValidChangeIsSavedAndReadBackByTheLoader(string $key, mixed $value, callable $read): void
    {
        $this->editor()->set($key, $value);

        $expected = match ($key) {
            'ui.theme' => 'paper',
            'ui.editor' => $value === '' ? null : 'code --wait',
            'ui.diff_view' => 'unified',
            'permissions' => 'plan',
            'effort.default' => 'high',
            'context.max' => $value === 'off' ? null : (int) $value,
            'context.buffer' => $value === 'auto' ? null : (int) $value,
            'context.auto_compact' => false,
            'skills.enabled', 'subagents', 'mcp.docs.enabled' => false,
            'tools.disabled.bash' => ['bash'],
        };
        self::assertSame($expected, $read($this->reload()));
    }

    public function testAChangeKeepsEveryOtherKeyAndItsPlaceAndAddsANewSectionLast(): void
    {
        $this->editor()->set('ui.theme', 'paper');

        $expected = json_decode(self::CONFIG, true, 512, JSON_THROW_ON_ERROR);
        $expected['ui'] = ['theme' => 'paper'];
        self::assertSame($expected, $this->decode(), 'assertSame also compares the key order');
    }

    public function testAChangeInAnExistingSectionKeepsItsOtherKeys(): void
    {
        Kit::write($this->file, json_encode([
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:8082', 'model' => 'qwen']],
            'ui' => ['diff_view' => 'split', 'theme' => 'mono', 'subagents' => ['auto_expand' => ['web']]],
        ], JSON_THROW_ON_ERROR));

        $this->editor()->set('ui.theme', 'paper');

        self::assertSame(['diff_view' => 'split', 'theme' => 'paper', 'subagents' => ['auto_expand' => ['web']]], $this->decode()['ui']);
    }

    public function testThePermissionsObjectKeepsItsShape(): void
    {
        $this->editor()->set('permissions', 'accept_edits');

        self::assertSame(['mode' => 'accept_edits'], $this->decode()['permissions']);
    }

    public function testAPermissionsNameStaysAName(): void
    {
        Kit::write($this->file, str_replace('{"mode": "ask"}', '"ask"', self::CONFIG));

        $this->editor()->set('permissions', 'plan');

        self::assertSame('plan', $this->decode()['permissions']);
    }

    public function testADisabledToolIsListedOnceAndEnablingItRemovesIt(): void
    {
        $this->editor()->set('tools.disabled.bash', true);
        $this->editor()->set('tools.disabled.bash', true);
        self::assertSame(['bash'], $this->decode()['tools']['disabled']);

        $this->editor()->set('tools.disabled.bash', false);
        self::assertSame([], $this->decode()['tools']['disabled']);
    }

    public function testTheWebSkillAndAskToolsCanBeSwitchedOff(): void
    {
        foreach (['ask_user', 'skill', 'web_fetch', 'web_search'] as $tool) {
            $this->editor()->set("tools.disabled.{$tool}", true);
        }

        self::assertSame(['ask_user', 'skill', 'web_fetch', 'web_search'], $this->reload()->disabledTools());
        self::assertTrue($this->items()['tools.disabled.web_search']['value']);
    }

    public function testEveryBuiltinToolCanBeSwitchedOffAndOnAgain(): void
    {
        foreach (Config::BUILTIN_TOOLS as $tool) {
            $this->editor()->set("tools.disabled.{$tool}", true);
        }
        self::assertSame(Config::BUILTIN_TOOLS, $this->reload()->disabledTools());

        foreach (Config::BUILTIN_TOOLS as $tool) {
            $this->editor()->set("tools.disabled.{$tool}", false);
        }
        self::assertSame([], $this->reload()->disabledTools());
    }

    public function testTheWebBackendCreatesTheWebSectionWhenItIsMissing(): void
    {
        $this->editor()->set('web.search.backend', 'none');

        self::assertSame(['search' => ['backend' => 'none']], $this->decode()['web']);
        self::assertNotNull($this->reload()->webConfig());
    }

    public function testAnEffortAliasIsSavedAsItsLevel(): void
    {
        $this->editor()->set('effort.default', 'off');
        self::assertSame('none', $this->reload()->effortDefault());

        $this->editor()->set('effort.default', 'on');
        self::assertSame('medium', $this->reload()->effortDefault());

        $this->editor()->set('effort.default', 'xhigh');
        self::assertSame('xhigh', $this->reload()->effortDefault());
    }

    public static function refusedChanges(): iterable
    {
        yield 'a theme that is not one' => ['ui.theme', 'dark', 'ui.theme must be phycode, phycode-light, tokyo-night, catppuccin, gruvbox, rose-pine, nord, solarized-dark, paper or mono'];
        yield 'a theme that is not text' => ['ui.theme', 3, 'ui.theme must be phycode, phycode-light, tokyo-night, catppuccin, gruvbox, rose-pine, nord, solarized-dark, paper or mono'];
        yield 'an editor over two lines' => ['ui.editor', "nvim\nrm -rf", 'ui.editor must be one line of text'];
        yield 'a diff view that is not one' => ['ui.diff_view', 'side', 'ui.diff_view must be auto, split or unified'];
        yield 'a permissions mode that is not one' => ['permissions', 'root', 'permissions must be ask, accept_edits, plan, dont_ask or yolo'];
        yield 'an effort that is not one' => ['effort.default', 'ultra', 'effort.default must be none, minimal, low, medium, high, xhigh or max (off and on are also accepted)'];
        yield 'a max below the minimum' => ['context.max', 100, 'context.max must be off or a number of tokens from 512 to 1000000'];
        yield 'a max as words' => ['context.max', 'lots', 'context.max must be off or a number of tokens from 512 to 1000000'];
        yield 'a buffer below zero' => ['context.buffer', -1, 'context.buffer must be auto or a number of tokens from 0 to 1000000'];
        yield 'an autocompact that is not a flag' => ['context.auto_compact', 'yes', 'context.auto_compact must be true or false'];
        yield 'a backend that is not one' => ['web.search.backend', 'google', 'web.search.backend must be none, searxng, brave, tavily, exa, perplexity, ollama or firecrawl'];
        yield 'a backend without its key file' => ['web.search.backend', 'brave', 'the change to web.search.backend is refused: web.search.api_key_file is required when web.search.backend is brave'];
        yield 'a flag that is text' => ['skills.enabled', 'yes', 'skills.enabled must be true or false'];
        yield 'a subagents flag that is a number' => ['subagents', 1, 'subagents must be true or false'];
        yield 'a tool that does not exist' => ['tools.disabled.shell', true, 'tools.disabled.shell: shell is not a built-in tool'];
        yield 'an MCP server that is not configured' => ['mcp.nope.enabled', true, 'no MCP server named nope is configured'];
        yield 'an MCP flag that is text' => ['mcp.docs.enabled', 'no', 'mcp.docs.enabled must be true or false'];
        yield 'a key that is not a setting' => ['profiles', [], "'profiles' is not a setting /config can change"];
    }

    #[DataProvider('refusedChanges')]
    public function testARefusedChangeLeavesTheFileAsItWasAndMakesNoBackup(string $key, mixed $value, string $message): void
    {
        try {
            $this->editor()->set($key, $value);
            self::fail('the change should have been refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame(self::CONFIG, file_get_contents($this->file));
        self::assertFileDoesNotExist($this->file . '.bak');
    }

    public function testAFileThatPhycodeCannotLoadIsNotWrittenTo(): void
    {
        Kit::write($this->file, str_replace('http://127.0.0.1:8082', 'ftp://127.0.0.1:8082', self::CONFIG));
        $before = file_get_contents($this->file);

        try {
            $this->editor()->set('ui.theme', 'paper');
            self::fail('a change that leaves the config unloadable should have been refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString("the change to ui.theme is refused: profile 'local': base_url must be", $error->getMessage());
        }
        self::assertSame($before, file_get_contents($this->file));
        self::assertFileDoesNotExist($this->file . '.bak');
    }

    public function testTheBackupHoldsTheFileAsItWas(): void
    {
        $this->editor()->set('ui.theme', 'paper');

        self::assertSame(self::CONFIG, file_get_contents($this->file . '.bak'));
    }

    public function testTheModeOfTheFileAndItsBackupIsKept(): void
    {
        chmod($this->file, 0640);

        $this->editor()->set('ui.theme', 'paper');

        self::assertSame(0640, fileperms($this->file) & 0777);
        self::assertSame(0640, fileperms($this->file . '.bak') & 0777);
    }

    public function testASymbolicLinkIsRefusedAndItsTargetIsNotChanged(): void
    {
        $target = Kit::folder('config-editor-target') . '/config.json';
        Kit::write($target, self::CONFIG);
        $link = Kit::folder('config-editor-link') . '/config.json';
        symlink($target, $link);

        try {
            (new ConfigEditor($link))->set('ui.theme', 'paper');
            self::fail('a config file that is a link must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('symbolic link', $error->getMessage());
        }
        self::assertSame(self::CONFIG, file_get_contents($target));
        self::assertFileDoesNotExist($link . '.bak');
    }

    public function testAMissingFileIsRefusedAndNoFileIsCreated(): void
    {
        $missing = Kit::folder('config-editor-absent') . '/config.json';

        try {
            (new ConfigEditor($missing))->set('ui.theme', 'paper');
            self::fail('a missing config must be refused');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('cannot read', $error->getMessage());
        }
        self::assertFileDoesNotExist($missing);
    }

    public function testNoTemporaryFileIsLeftNextToTheConfig(): void
    {
        $this->editor()->set('ui.theme', 'paper');
        $this->editor()->set('ui.theme', 'mono');

        self::assertSame(['config.json', 'config.json.bak'], array_values(array_diff(scandir($this->folder) ?: [], ['.', '..'])));
    }

    private function editor(): ConfigEditor
    {
        return new ConfigEditor($this->file);
    }

    private function items(): array
    {
        $byKey = [];
        foreach ($this->editor()->items($this->reload()) as $item) {
            $byKey[$item['key']] = $item;
        }

        return $byKey;
    }

    private function reload(): Config
    {
        return Config::fromArrays($this->decode(), null, [], $this->folder);
    }

    private function decode(): array
    {
        return json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
    }
}
