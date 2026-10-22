<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Lsp;

use Phycode\Lsp\LspServers;
use Phycode\Lsp\LspServerSpec;
use Phycode\Security\Workspace;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class LspServersTest extends TestCase
{
    private ?LspServers $servers = null;
    private string $log = '';

    protected function tearDown(): void
    {
        $this->servers?->close();
        $this->servers = null;
    }

    public function testTheFirstServerThatHandlesTheExtensionAnswers(): void
    {
        $workspace = $this->workspace();
        $servers = $this->pool([
            new LspServerSpec('php', $this->command(), ['php']),
            new LspServerSpec('anything', $this->command()),
        ]);

        self::assertSame('php', $servers->clientFor($workspace->root . '/a.php', $workspace, new Cancellation())->name());
        self::assertSame('anything', $servers->clientFor($workspace->root . '/notes.txt', $workspace, new Cancellation())->name());
    }

    public function testAnExtensionNoServerHandlesListsWhatIsConfigured(): void
    {
        $workspace = $this->workspace();
        $servers = $this->pool([new LspServerSpec('php', $this->command(), ['php', 'phtml']), new LspServerSpec('go', $this->command(), ['go'])]);

        try {
            $servers->clientFor($workspace->root . '/main.rs', $workspace, new Cancellation());
            self::fail('no server handles rs');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('no language server handles .rs files', $error->getMessage());
            self::assertStringContainsString('php (php, phtml); go (go)', $error->getMessage());
            self::assertStringContainsString('lsp section of the global config', $error->getMessage());
        }
    }

    public function testAClientIsStartedOncePerServerAndRootAndReused(): void
    {
        $workspace = $this->workspace();
        $root = $workspace->root;
        Kit::write($root . '/pkg1/composer.json', '{}');
        Kit::write($root . '/pkg1/src/A.php', '<?php');
        Kit::write($root . '/pkg1/B.php', '<?php');
        Kit::write($root . '/pkg2/composer.json', '{}');
        Kit::write($root . '/pkg2/C.php', '<?php');
        Kit::write($root . '/loose/D.php', '<?php');
        $servers = $this->pool([new LspServerSpec('php', $this->command(), ['php'], ['composer.json'])]);
        $cancel = new Cancellation();

        $first = $servers->clientFor($root . '/pkg1/src/A.php', $workspace, $cancel);
        $second = $servers->clientFor($root . '/pkg1/B.php', $workspace, $cancel);
        $other = $servers->clientFor($root . '/pkg2/C.php', $workspace, $cancel);
        $loose = $servers->clientFor($root . '/loose/D.php', $workspace, $cancel);

        self::assertSame($first, $second);
        self::assertSame($root . '/pkg1', $first->root());
        self::assertSame($root . '/pkg2', $other->root());
        self::assertSame($root, $loose->root(), 'no marker on the way up: the project root');
        self::assertCount(3, FakeLspServer::messagesFor($this->log, 'initialize'));
        self::assertCount(3, array_unique(FakeLspServer::pids($this->log)));
    }

    public function testWithoutRootMarkersTheProjectRootIsTheRoot(): void
    {
        $workspace = $this->workspace();
        Kit::write($workspace->root . '/deep/er/A.php', '<?php');
        Kit::write($workspace->root . '/deep/composer.json', '{}');
        $servers = $this->pool([new LspServerSpec('php', $this->command(), ['php'])]);

        $client = $servers->clientFor($workspace->root . '/deep/er/A.php', $workspace, new Cancellation());

        self::assertSame($workspace->root, $client->root());
    }

    public function testTheRootOfAFileInAReadOnlyFolderStaysInsideThatFolder(): void
    {
        $root = Kit::folder('lsp-pool');
        $extra = Kit::folder('lsp-pool-extra');
        Kit::write($extra . '/lib/composer.json', '{}');
        Kit::write($extra . '/lib/src/L.php', '<?php');
        Kit::write($root . '/composer.json', '{}');
        $workspace = new Workspace($root, [$extra]);
        $servers = $this->pool([new LspServerSpec('php', $this->command(), ['php'], ['composer.json'])]);

        $client = $servers->clientFor(realpath($extra) . '/lib/src/L.php', $workspace, new Cancellation());

        self::assertSame(realpath($extra) . '/lib', $client->root());
    }

    public function testADeadServerIsReplacedOnTheNextCall(): void
    {
        $workspace = $this->workspace();
        $marker = Kit::folder('lsp-marker') . '/crashed';
        $servers = $this->pool([new LspServerSpec('php', $this->command(['crashOn' => 'textDocument/hover', 'crashOnce' => $marker]), ['php'])]);
        $cancel = new Cancellation();
        $client = $servers->clientFor($workspace->root . '/a.php', $workspace, $cancel);

        try {
            $client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///x']]);
            self::fail('the first server crashes');
        } catch (PhycodeException) {
        }
        $again = $servers->clientFor($workspace->root . '/a.php', $workspace, $cancel);

        self::assertNotSame($client, $again);
        self::assertTrue($again->alive());
        self::assertCount(2, FakeLspServer::messagesFor($this->log, 'initialize'));
    }

    public function testCloseShutsEveryServerDown(): void
    {
        $workspace = $this->workspace();
        $servers = $this->pool([new LspServerSpec('php', $this->command(), ['php']), new LspServerSpec('go', $this->command(), ['go'])]);
        $servers->clientFor($workspace->root . '/a.php', $workspace, new Cancellation());
        $servers->clientFor($workspace->root . '/a.go', $workspace, new Cancellation());
        $pids = FakeLspServer::pids($this->log);

        $servers->close();

        self::assertCount(2, $pids);
        self::assertCount(2, FakeLspServer::messagesFor($this->log, 'shutdown'));
        self::assertCount(2, FakeLspServer::messagesFor($this->log, 'exit'));
        foreach ($pids as $pid) {
            self::assertFalse(FakeLspServer::running($pid));
        }
    }

    public function testAServerThatIsNotInstalledIsNotKept(): void
    {
        $workspace = $this->workspace();
        $servers = $this->pool([new LspServerSpec('ghost', ['definitely-not-an-installed-language-server'], ['php'])]);

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('language server ghost is not installed');

        $servers->clientFor($workspace->root . '/a.php', $workspace, new Cancellation());
    }

    public function testThePoolIsEmptyWithoutSpecs(): void
    {
        self::assertTrue((new LspServers([]))->isEmpty());
        self::assertFalse($this->pool([new LspServerSpec('php', $this->command(), ['php'])])->isEmpty());
    }

    private function workspace(): Workspace
    {
        return new Workspace(Kit::folder('lsp-pool'));
    }

    private function command(array $scenario = []): array
    {
        $this->log = $this->log === '' ? FakeLspServer::logFile() : $this->log;

        return FakeLspServer::command($scenario + ['log' => $this->log, 'results' => ['textDocument/hover' => null]]);
    }

    private function pool(array $specs): LspServers
    {
        $this->servers = new LspServers($specs, FakeLspServer::timeouts());

        return $this->servers;
    }
}
