<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Config\Config;
use Phycode\Gentle\Approval;
use Phycode\Gentle\GlobalConfig;
use Phycode\Gentle\McpPresets;
use Phycode\Security\Decision;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpPresetsTest extends TestCase
{
    private string $file;
    private string $original;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $folder = Kit::folder('mcp-presets');
        $this->file = $folder . '/config.json';
        $this->original = json_encode([
            'default_profile' => 'local',
            'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9/v1', 'model' => 'test-model', 'tool_mode' => 'none']],
            'skills' => ['enabled' => true],
            'mcp_servers' => ['docs' => ['command' => ['/usr/bin/docs-mcp'], 'enabled' => true, 'approval' => 'ask']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        Kit::write($this->file, $this->original);
        chmod($this->file, 0640);
        $this->ui = new RecordingUi();
    }

    public static function commands(): iterable
    {
        yield 'engram by its absolute path' => ['engram', '/usr/local/bin/engram', ['/usr/local/bin/engram', 'mcp', '--tools=agent']];
        yield 'engram by its name when it is not found' => ['engram', null, ['engram', 'mcp', '--tools=agent']];
        yield 'context7 pinned to 2.2.5' => ['context7', null, ['npx', '-y', '--package=@upstash/context7-mcp@2.2.5', '--', 'context7-mcp']];
        yield 'codegraph' => ['codegraph', null, ['codegraph', 'serve', '--mcp']];
    }

    #[DataProvider('commands')]
    public function testEachPresetIsTheCommandVerifiedInTheGentleAiSource(string $name, ?string $engram, array $expected): void
    {
        self::assertSame($expected, McpPresets::command($name, $engram));
        self::assertSame(['command' => $expected, 'enabled' => true, 'approval' => 'ask'], McpPresets::entry($name, $engram));
    }

    public function testAnUnknownPresetIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('no MCP preset named nope; the presets are engram, context7, codegraph');

        McpPresets::command('nope');
    }

    public function testContext7IsWrittenAfterAYesAndTheOtherKeysStay(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];

        $message = McpPresets::add(new GlobalConfig($this->file), 'context7', null, $this->approval());

        self::assertSame("added mcp_servers.context7 to {$this->file}. Restart phycode to load it; /mcp then lists its tools.", $message);
        $data = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['command' => ['npx', '-y', '--package=@upstash/context7-mcp@2.2.5', '--', 'context7-mcp'], 'enabled' => true, 'approval' => 'ask'], $data['mcp_servers']['context7']);
        self::assertSame(['command' => ['/usr/bin/docs-mcp'], 'enabled' => true, 'approval' => 'ask'], $data['mcp_servers']['docs']);
        self::assertSame(['enabled' => true], $data['skills']);
        self::assertSame('local', $data['default_profile']);
        self::assertSame($this->original, file_get_contents($this->file . '.bak'), 'the old file is kept as .bak');
        self::assertSame(0640, fileperms($this->file) & 0777, 'the mode of the config is kept');
    }

    public function testTheApprovalShowsTheEntryAndThatNothingElseChanges(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];

        McpPresets::add(new GlobalConfig($this->file), 'codegraph', null, $this->approval());

        $panel = $this->ui->events[0];
        self::assertSame('panel', $panel[0]);
        self::assertSame('/mcp preset codegraph', $panel[1]);
        self::assertStringContainsString("add this entry to mcp_servers in {$this->file} (the old file is kept as {$this->file}.bak):", (string) $panel[2]);
        self::assertStringContainsString('"command": [', (string) $panel[2]);
        self::assertStringContainsString('"codegraph"', (string) $panel[2]);
        self::assertStringContainsString('nothing else in the file changes', (string) $panel[2]);
        self::assertSame('add the codegraph MCP server to the global config', $this->ui->requests[0]->summary);
    }

    public function testWithoutAYesTheFileIsNotTouched(): void
    {
        $this->ui->decisions = [Decision::Deny];

        $message = McpPresets::add(new GlobalConfig($this->file), 'engram', '/usr/bin/engram', $this->approval());

        self::assertSame('not approved: nothing was changed.', $message);
        self::assertSame($this->original, file_get_contents($this->file));
        self::assertFileDoesNotExist($this->file . '.bak');
    }

    public function testAnEntryThatIsAlreadyThereChangesNothingAndAsksNothing(): void
    {
        $data = json_decode($this->original, true, 512, JSON_THROW_ON_ERROR);
        $data['mcp_servers']['engram'] = McpPresets::entry('engram', '/usr/bin/engram');
        Kit::write($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $before = file_get_contents($this->file);

        $message = McpPresets::add(new GlobalConfig($this->file), 'engram', '/usr/bin/engram', $this->approval());

        self::assertSame("mcp_servers.engram is already set to this preset in {$this->file}; nothing changed.", $message);
        self::assertSame($before, file_get_contents($this->file));
        self::assertSame([], $this->ui->requests);
    }

    public function testAnEntryWithTheSameNameAndAnotherCommandIsNeverReplaced(): void
    {
        $data = json_decode($this->original, true, 512, JSON_THROW_ON_ERROR);
        $data['mcp_servers']['engram'] = ['command' => ['/opt/mine/engram', 'serve'], 'enabled' => true, 'approval' => 'ask'];
        Kit::write($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $before = file_get_contents($this->file);

        try {
            McpPresets::add(new GlobalConfig($this->file), 'engram', '/usr/bin/engram', $this->approval());
            self::fail('an entry with another command must not be replaced');
        } catch (PhycodeException $error) {
            self::assertSame("mcp_servers.engram is already set in {$this->file} to another entry. Nothing changed; edit that file to change it.", $error->getMessage());
        }
        self::assertSame($before, file_get_contents($this->file));
        self::assertSame([], $this->ui->requests);
    }

    public function testTheChangedFileStillLoadsWithTheNewServer(): void
    {
        $this->ui->decisions = [Decision::AllowOnce];
        McpPresets::add(new GlobalConfig($this->file), 'context7', null, $this->approval());

        $config = Config::load(dirname($this->file), dirname($this->file), []);

        self::assertSame(['npx', '-y', '--package=@upstash/context7-mcp@2.2.5', '--', 'context7-mcp'], $config->mcpServers()['context7']['command']);
        self::assertSame(['/usr/bin/docs-mcp'], $config->mcpServers()['docs']['command']);
    }

    public function testAChangeTheLoaderWouldRefuseIsNotSaved(): void
    {
        $data = json_decode($this->original, false, 512, JSON_THROW_ON_ERROR);
        $data->mcp_servers->{'bad name!'} = (object) ['command' => ['x']];

        try {
            (new GlobalConfig($this->file))->validate($data);
            self::fail('the loader refuses a name with a space');
        } catch (PhycodeException $error) {
            self::assertStringStartsWith('the change is refused: mcp_servers name', $error->getMessage());
        }
    }

    public function testAMissingConfigIsNamedAndNothingIsCreated(): void
    {
        $missing = dirname($this->file) . '/absent/config.json';

        try {
            McpPresets::add(new GlobalConfig($missing), 'context7', null, $this->approval());
            self::fail('a missing config cannot be changed');
        } catch (PhycodeException $error) {
            self::assertSame("cannot read {$missing}: create the global config first", $error->getMessage());
        }
        self::assertFileDoesNotExist($missing);
    }

    public function testASymbolicLinkConfigIsRefused(): void
    {
        $link = dirname($this->file) . '/linked.json';
        symlink($this->file, $link);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("cannot change {$link}: it is a symbolic link");

        McpPresets::add(new GlobalConfig($link), 'context7', null, $this->approval());
    }

    public function testMcpServersThatIsNotAnObjectIsRefused(): void
    {
        Kit::write($this->file, json_encode(['default_profile' => 'local', 'profiles' => ['local' => ['base_url' => 'http://127.0.0.1:9/v1', 'model' => 'm', 'tool_mode' => 'none']], 'mcp_servers' => []], JSON_THROW_ON_ERROR));

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("mcp_servers in {$this->file} must be an object");

        McpPresets::add(new GlobalConfig($this->file), 'context7', null, $this->approval());
    }

    private function approval(): Approval
    {
        return new Approval($this->ui, fn (string $title, string $text) => $this->ui->panel($title, $text));
    }
}
