<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Agent\RunPolicy;
use Phycode\Security\Decision;
use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\ReadTool;
use Phycode\Tools\WriteTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkspaceAndPermissionsTest extends TestCase
{
    public function testPathsInsideTheRootResolveEvenWhenMissing(): void
    {
        $root = Kit::folder('ws');
        $workspace = new Workspace($root);

        self::assertSame(realpath($root) . '/src/new/file.php', $workspace->resolve('src/new/file.php'));
        self::assertSame(realpath($root) . '/a.txt', $workspace->resolve('./a.txt'));
    }

    public static function escapingPaths(): iterable
    {
        yield 'parent folder' => ['../outside.txt'];
        yield 'deep parent folder' => ['src/../../outside.txt'];
        yield 'absolute path elsewhere' => ['/etc/passwd'];
        yield 'empty path' => [''];
        yield 'nul byte' => ["a\0b"];
    }

    #[DataProvider('escapingPaths')]
    public function testPathsOutsideTheRootAreRefused(string $path): void
    {
        $this->expectException(PathException::class);
        (new Workspace(Kit::folder('ws')))->resolve($path);
    }

    public function testSymbolicLinkLeavingTheRootIsRefused(): void
    {
        $root = Kit::folder('ws');
        $outside = Kit::folder('outside');
        symlink($outside, $root . '/link');

        $this->expectException(PathException::class);
        (new Workspace($root))->resolve('link/secret.txt');
    }

    public function testDanglingSymbolicLinkIsRefusedInsteadOfBeingFollowed(): void
    {
        $root = Kit::folder('ws');
        symlink('/tmp/opencode/does-not-exist-' . bin2hex(random_bytes(3)), $root . '/dangling');

        $this->expectException(PathException::class);
        (new Workspace($root))->resolve('dangling');
    }

    public function testGitAndPhycodeFoldersAreNotWritable(): void
    {
        $workspace = new Workspace(Kit::folder('ws'));

        foreach (['.git/config', '.phycode/config.json'] as $path) {
            try {
                $workspace->assertWritable($workspace->resolve($path));
                self::fail("{$path} should not be writable");
            } catch (PathException) {
                self::assertTrue(true);
            }
        }
        $workspace->assertWritable($workspace->resolve('src/app.php'));
    }

    public function testReadsAreAlwaysAllowed(): void
    {
        $ui = new RecordingUi();
        $context = Kit::context(Kit::folder('ws'), Kit::agent(), Kit::policy('ask'), $ui);

        self::assertNull((new \Phycode\Security\Permissions($ui))->check(new ReadTool(), $context, 'read'));
        self::assertSame([], $ui->events);
    }

    public function testReadonlyPolicyRefusesWritesEvenInYoloMode(): void
    {
        $ui = new RecordingUi();
        $context = Kit::context(Kit::folder('ws'), Kit::agent('plan'), Kit::policy('yolo', null, ['write']), $ui);

        $reason = (new \Phycode\Security\Permissions($ui))->check(new WriteTool(), $context, 'write');

        self::assertNotNull($reason);
        self::assertStringContainsString('read-only', $reason);
        self::assertSame([], $ui->events, 'a refused action is not even offered for confirmation');
    }

    public function testAskModeAsksOnceAndRemembersAllowSession(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::AllowSession];
        $permissions = new \Phycode\Security\Permissions($ui);
        $context = Kit::context(Kit::folder('ws'), Kit::agent(), Kit::policy('ask'), $ui);

        self::assertNull($permissions->check(new WriteTool(), $context, 'write a.txt'));
        self::assertNull($permissions->check(new WriteTool(), $context, 'write b.txt'));
        self::assertCount(1, array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
    }

    public function testDenialReturnsAReason(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $context = Kit::context(Kit::folder('ws'), Kit::agent(), Kit::policy('ask'), $ui);

        $reason = (new \Phycode\Security\Permissions($ui))->check(new WriteTool(), $context, 'write a.txt');

        self::assertSame('the user denied this action', $reason);
    }

    public function testWithoutATerminalNothingIsAskedAndTheActionIsRefused(): void
    {
        $ui = new RecordingUi();
        $ui->terminal = false;
        $context = Kit::context(Kit::folder('ws'), Kit::agent(), Kit::policy('ask'), $ui);

        $reason = (new \Phycode\Security\Permissions($ui))->check(new WriteTool(), $context, 'write a.txt');

        self::assertNotNull($reason);
        self::assertStringContainsString('--yolo', $reason);
        self::assertSame([], array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm'));
    }

    public function testYoloModeAllowsWithoutAsking(): void
    {
        $ui = new RecordingUi();
        $context = Kit::context(Kit::folder('ws'), Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), $ui);

        self::assertNull((new \Phycode\Security\Permissions($ui))->check(new WriteTool(), $context, 'write a.txt'));
        self::assertSame([], $ui->events);
    }
}
