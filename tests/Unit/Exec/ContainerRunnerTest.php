<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\ContainerRunner;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\FakeRuntime;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContainerRunnerTest extends TestCase
{
    private const IMAGE = 'debian:trixie-slim';
    private const VARIABLES = ['PATH', 'DOCKER_HOST', 'CONTAINER_HOST', 'XDG_RUNTIME_DIR'];

    private FakeRuntime $fake;
    private string $root;
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
        }
        $this->fake = FakeRuntime::install();
        $this->root = realpath(Kit::folder('project'));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testRuntimeMustBePodmanOrDocker(): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("sandbox runtime must be 'podman' or 'docker', not 'nerdctl'");
        new ContainerRunner(new ProcessRunner(), 'nerdctl', self::IMAGE, 'none', $this->root);
    }

    public function testNetworkMustBeNoneOrBridge(): void
    {
        $this->expectException(PhycodeException::class);
        new ContainerRunner(new ProcessRunner(), 'podman', self::IMAGE, 'host', $this->root);
    }

    public function testMissingRuntimeIsReported(): void
    {
        putenv('PATH=' . Kit::folder('no-runtime'));

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage("sandbox runtime 'podman' is not installed");
        new ContainerRunner(new ProcessRunner(), 'podman', self::IMAGE, 'none', $this->root);
    }

    public static function invalidImages(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['debian trixie'];
        yield 'option' => ['--privileged'];
    }

    #[DataProvider('invalidImages')]
    public function testImageIsOneNameThatIsNotAnOption(string $image): void
    {
        $this->expectException(PhycodeException::class);
        new ContainerRunner(new ProcessRunner(), 'podman', $image, 'none', $this->root);
    }

    public function testRootMustBeAnExistingFolder(): void
    {
        $this->expectException(PhycodeException::class);
        new ContainerRunner(new ProcessRunner(), 'podman', self::IMAGE, 'none', $this->root . '/missing');
    }

    public function testRootPathWithCommasOrColonsIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        new ContainerRunner(new ProcessRunner(), 'podman', self::IMAGE, 'none', Kit::folder('a,b'));
    }

    public function testWorkingFolderOutsideTheRootIsRefused(): void
    {
        $outside = Kit::folder('outside');
        symlink($outside, $this->root . '/escape');
        $runner = $this->runner();

        foreach ([$outside, $this->root . '/escape', $this->root . '/..'] as $cwd) {
            try {
                $runner->run(['pwd'], $cwd, 5, 4096);
                self::fail("{$cwd} should be refused");
            } catch (PhycodeException) {
                self::assertSame([], $this->fake->log(), 'no container may start for a refused folder');
            }
        }
    }

    public function testEmptyCommandIsRefused(): void
    {
        $this->expectException(PhycodeException::class);
        $this->runner()->run([], $this->root, 5, 4096);
    }

    public function testAvailableAndImageExistsAskTheRuntime(): void
    {
        self::assertTrue(ContainerRunner::available('docker'));
        self::assertFalse(ContainerRunner::available('nerdctl'));
        self::assertTrue(ContainerRunner::imageExists('podman', self::IMAGE, new ProcessRunner()));

        $this->fake->behave('if [ "$1" = image ]; then exit 1; fi');
        self::assertFalse(ContainerRunner::imageExists('podman', self::IMAGE, new ProcessRunner()));
    }

    public function testContainerGetsTheRequestedNetworkAndNoCapabilities(): void
    {
        $this->runner()->run(['bash', '-c', 'echo hi'], $this->root, 5, 4096);

        $log = $this->fake->log();
        self::assertSame('run', $log[0]);
        self::assertSame('none', self::valueAfter($log, '--network'));
        self::assertContains('--cap-drop=ALL', $log);
        self::assertContains('--security-opt=no-new-privileges', $log);
        self::assertContains('--pull=never', $log);
    }

    public function testRootIsMountedAsWorkspaceAndTheWorkdirFollowsTheFolder(): void
    {
        mkdir($this->root . '/sub');
        $this->runner()->run(['pwd'], $this->root . '/sub', 5, 4096);

        $log = $this->fake->log();
        self::assertContains('--volume=' . $this->root . ':/workspace', $log);
        self::assertContains('--workdir=/workspace/sub', $log);
    }

    public static function existingFolders(): iterable
    {
        yield 'none' => [[]];
        yield 'git' => [['.git']];
        yield 'phycode' => [['.phycode']];
        yield 'both' => [['.git', '.phycode']];
    }

    #[DataProvider('existingFolders')]
    public function testProtectedFoldersAreMountedReadOnlyAndMissingOnesAreRemovedAfterwards(array $existing): void
    {
        mkdir($this->root . '/src');
        foreach ($existing as $folder) {
            mkdir($this->root . '/' . $folder);
        }
        $this->runner()->run(['pwd'], $this->root, 5, 4096);

        $folders = ['.git', '.phycode', '.claude'];
        $expected = array_map(
            fn (string $folder): string => "--mount=type=bind,source={$this->root}/{$folder},target=/workspace/{$folder},readonly",
            $folders,
        );
        self::assertSame($expected, self::mounts($this->fake->log()));
        foreach ($folders as $folder) {
            self::assertSame(in_array($folder, $existing, true), is_dir($this->root . '/' . $folder), $folder);
        }
    }

    public function testUserNamespaceFlagIsOnlyAddedForRootlessPodman(): void
    {
        $this->runner('podman')->run(['pwd'], $this->root, 5, 4096);
        self::assertSame(posix_geteuid() !== 0, in_array('--userns=keep-id', $this->fake->log(), true));

        $this->fake->clear();
        $this->runner('docker')->run(['pwd'], $this->root, 5, 4096);
        self::assertNotContains('--userns=keep-id', $this->fake->log());
    }

    public function testCommandComesAfterTheImage(): void
    {
        $this->runner()->run(['bash', '-c', 'echo hi'], $this->root, 5, 4096);

        $log = $this->fake->log();
        self::assertSame('run', $log[0]);
        self::assertSame([self::IMAGE, 'bash', '-c', 'echo hi'], array_slice($log, -4));
    }

    public function testOutputOfTheRuntimeIsReturned(): void
    {
        $result = $this->runner()->run(['bash', '-c', 'echo hi'], $this->root, 5, 4096);

        self::assertTrue($result->succeeded());
        self::assertSame("fake-ok\n", $result->stdout);
    }

    public function testRuntimeClientVariablesReachTheRuntimeProcess(): void
    {
        putenv('CONTAINER_HOST=ssh://remote.example/run');
        $this->fake->behave('echo "$CONTAINER_HOST" >> "${0%/*}/client.log"');

        $this->runner()->run(['pwd'], $this->root, 5, 4096);

        self::assertSame(['ssh://remote.example/run'], $this->fake->lines('client.log'));
    }

    public function testTimeoutStopsTheContainerByName(): void
    {
        putenv('DOCKER_HOST=tcp://remote.example:2375');
        $this->fake->behave('echo "$DOCKER_HOST" >> "${0%/*}/host.log"; if [ "$1" = run ]; then sleep 30; fi');
        $started = microtime(true);

        $result = $this->runner()->run(['sleep', '30'], $this->root, 1, 4096);

        self::assertLessThan(10.0, microtime(true) - $started);
        self::assertSame('timeout', $result->interrupted);
        $log = $this->fake->log();
        self::assertSame(['rm', '-f', self::valueAfter($log, '--name')], array_slice($log, -3));
        self::assertSame(['tcp://remote.example:2375', 'tcp://remote.example:2375'], $this->fake->lines('host.log'));
    }

    public function testCancellationAlsoRemovesTheContainer(): void
    {
        $this->fake->behave('if [ "$1" = run ]; then sleep 30; fi');
        $cancel = new Cancellation();
        $cancel->cancel();

        $result = $this->runner()->run(['sleep', '30'], $this->root, 10, 4096, $cancel);

        self::assertSame('cancelled', $result->interrupted);
        self::assertSame(['rm', '-f'], array_slice($this->fake->log(), -3, 2));
    }

    private function runner(string $runtime = 'podman'): ContainerRunner
    {
        return new ContainerRunner(new ProcessRunner(), $runtime, self::IMAGE, 'none', $this->root);
    }

    private static function valueAfter(array $argv, string $flag): ?string
    {
        $index = array_search($flag, $argv, true);

        return $index === false ? null : ($argv[$index + 1] ?? null);
    }

    private static function mounts(array $argv): array
    {
        return array_values(array_filter($argv, static fn (string $arg): bool => str_starts_with($arg, '--mount=')));
    }
}
