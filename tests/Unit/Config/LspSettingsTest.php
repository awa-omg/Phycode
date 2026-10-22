<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Config;

use Phycode\Config\Config;
use Phycode\Config\ConfigException;
use Phycode\Lsp\LspServerSpec;
use PHPUnit\Framework\TestCase;

final class LspSettingsTest extends TestCase
{
    private const GLOBAL = [
        'default_profile' => 'home',
        'profiles' => ['home' => ['base_url' => 'https://192.168.1.10:8080', 'model' => 'qwen', 'api_key_env' => 'HOME_KEY']],
    ];

    public function testWithoutTheSectionThereAreNoServers(): void
    {
        self::assertSame([], Config::fromArrays(self::GLOBAL, null, [], '/nonexistent')->lspServers());
        self::assertSame([], Config::fromArrays(self::GLOBAL + ['lsp' => []], null, [], '/nonexistent')->lspServers());
    }

    public function testAServerKeepsItsCommandAndTakesTheDefaults(): void
    {
        $servers = Config::fromArrays(self::GLOBAL + ['lsp' => ['gopls' => ['command' => ['gopls', 'serve']]]], null, [], '/nonexistent')->lspServers();

        self::assertSame(['gopls'], array_keys($servers));
        self::assertEquals(new LspServerSpec('gopls', ['gopls', 'serve'], [], [], []), $servers['gopls']);
    }

    public function testEveryOptionIsParsedAndTheExtensionsAreNormalised(): void
    {
        $servers = Config::fromArrays(self::GLOBAL + ['lsp' => ['intelephense' => [
            'command' => ['intelephense', '--stdio'],
            'extensions' => ['.PHP', 'phtml'],
            'root_markers' => ['composer.json', '.git'],
            'initialization_options' => ['storagePath' => '/tmp/intelephense'],
        ]]], null, [], '/nonexistent')->lspServers();

        $spec = $servers['intelephense'];
        self::assertSame(['php', 'phtml'], $spec->extensions);
        self::assertSame(['composer.json', '.git'], $spec->rootMarkers);
        self::assertSame(['storagePath' => '/tmp/intelephense'], $spec->initializationOptions);
        self::assertTrue($spec->handles('/p/src/A.php'));
        self::assertTrue($spec->handles('/p/view.PHTML'));
        self::assertFalse($spec->handles('/p/a.go'));
    }

    public function testAServerWithoutExtensionsHandlesEveryFile(): void
    {
        $spec = new LspServerSpec('any', ['x']);

        self::assertTrue($spec->handles('/p/a.go'));
        self::assertTrue($spec->handles('/p/Makefile'));
    }

    public function testAnInvalidSectionIsRefusedWithTheSettingAndTheFix(): void
    {
        $cases = [
            'not an object' => [['lsp' => ['a', 'b']], 'lsp must be an object'],
            'a server that is not an object' => [['lsp' => ['gopls' => 'gopls']], 'lsp.gopls must be an object'],
            'no command' => [['lsp' => ['gopls' => []]], 'lsp.gopls.command must be a list of 1 to 32 strings, the program first'],
            'a command that is a string' => [['lsp' => ['gopls' => ['command' => 'gopls']]], 'lsp.gopls.command must be a list'],
            'an empty command' => [['lsp' => ['gopls' => ['command' => []]]], 'lsp.gopls.command must be a list'],
            'a blank program' => [['lsp' => ['gopls' => ['command' => [' ']]]], 'lsp.gopls.command must be a list of non-empty strings'],
            'a command part that is not a string' => [['lsp' => ['gopls' => ['command' => ['gopls', 1]]]], 'lsp.gopls.command must be a list of non-empty strings'],
            'an unknown key' => [['lsp' => ['gopls' => ['command' => ['gopls'], 'args' => []]]], 'lsp.gopls.args is not a known setting'],
            'a bad name' => [['lsp' => ['go pls' => ['command' => ['gopls']]]], "lsp name 'go pls' may use only letters, digits"],
            'extensions that are a string' => [['lsp' => ['a' => ['command' => ['a'], 'extensions' => 'php']]], 'lsp.a.extensions must be a list of up to 32 file extensions'],
            'an extension with a slash' => [['lsp' => ['a' => ['command' => ['a'], 'extensions' => ['a/b']]]], 'lsp.a.extensions must be a list'],
            'a root marker with a slash' => [['lsp' => ['a' => ['command' => ['a'], 'root_markers' => ['src/composer.json']]]], 'lsp.a.root_markers must be a list of up to 32 file names'],
            'options that are a list' => [['lsp' => ['a' => ['command' => ['a'], 'initialization_options' => [1, 2]]]], 'lsp.a.initialization_options must be an object'],
        ];

        foreach ($cases as $label => [$extra, $message]) {
            try {
                Config::fromArrays(self::GLOBAL + $extra, null, [], '/nonexistent');
                self::fail("{$label} was accepted");
            } catch (ConfigException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $label);
            }
        }
    }

    public function testTheProjectConfigCannotSetTheServers(): void
    {
        $config = Config::fromArrays(self::GLOBAL, ['lsp' => ['evil' => ['command' => ['sh', '-c', 'id']]]], [], '/nonexistent');

        self::assertSame([], $config->lspServers());
        self::assertContains("project config key 'lsp' ignored: only the global config can set it", $config->warnings);
    }

    public function testTheToolCanBeDisabledByName(): void
    {
        self::assertContains('lsp', Config::BUILTIN_TOOLS);
        self::assertSame(['lsp'], Config::fromArrays(self::GLOBAL + ['tools' => ['disabled' => ['lsp']]], null, [], '/nonexistent')->disabledTools());
    }
}
