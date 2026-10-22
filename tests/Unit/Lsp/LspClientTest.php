<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Lsp;

use Phycode\Lsp\LspClient;
use Phycode\Lsp\LspRpcException;
use Phycode\Lsp\LspTimeouts;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class LspClientTest extends TestCase
{
    private array $clients = [];
    private string $log = '';

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
    }

    public function testTheHandshakeSendsInitializeThenInitializedAndCarriesTheClientDetails(): void
    {
        $root = Kit::folder('lsp-root');
        $client = $this->started([], $root, ['storagePath' => '/var/cache/intelephense']);

        $client->close();

        $messages = FakeLspServer::messages($this->log);
        self::assertSame(['initialize', 'initialized', 'shutdown', 'exit'], FakeLspServer::methods($this->log));
        $initialize = $messages[0]['params'];
        self::assertSame(1, $messages[0]['id']);
        self::assertSame(getmypid(), $initialize['processId']);
        self::assertSame(['name' => 'phycode', 'version' => '9.9.9'], $initialize['clientInfo']);
        self::assertSame('file://' . $root, $initialize['rootUri']);
        self::assertSame([['uri' => 'file://' . $root, 'name' => basename($root)]], $initialize['workspaceFolders']);
        self::assertSame(['positionEncodings' => ['utf-16']], $initialize['capabilities']['general']);
        self::assertTrue($initialize['capabilities']['textDocument']['diagnostic']['dynamicRegistration']);
        self::assertTrue($initialize['capabilities']['workspace']['configuration']);
        self::assertSame(['storagePath' => '/var/cache/intelephense'], $initialize['initializationOptions']);
    }

    public function testFramingSurvivesTinyChunksAndMultiByteBodies(): void
    {
        $text = 'é😀日本語 ' . str_repeat('xyz', 300);
        $client = $this->started(['chunkBytes' => 7, 'results' => ['textDocument/hover' => ['contents' => $text]]], null, [], new LspTimeouts());

        $result = $client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///x'], 'position' => ['line' => 0, 'character' => 0]]);

        self::assertSame(['contents' => $text], $result);
    }

    public function testTheServerRequestsAreAnswered(): void
    {
        $client = $this->started(['serverRequests' => [
            ['id' => 9001, 'method' => 'workspace/configuration', 'params' => ['items' => [['section' => 'a'], ['section' => 'b']]]],
            ['id' => 'token', 'method' => 'window/workDoneProgress/create', 'params' => ['token' => 'x']],
            ['id' => 3, 'method' => 'workspace/applyEdit', 'params' => ['edit' => []]],
            ['id' => 4, 'method' => 'vendor/unknown'],
        ]]);

        $this->settle($client);
        $client->close();

        $replies = [];
        foreach (FakeLspServer::messages($this->log) as $message) {
            if (!isset($message['method']) && isset($message['id'])) {
                $replies[$message['id']] = $message;
            }
        }
        self::assertSame([null, null], $replies[9001]['result']);
        self::assertNull($replies['token']['result']);
        self::assertArrayHasKey('result', $replies['token']);
        self::assertFalse($replies[3]['result']['applied']);
        self::assertSame(-32601, $replies[4]['error']['code']);
    }

    public function testARequestThatIsNeverAnsweredTimesOutAndIsCancelled(): void
    {
        $client = $this->started(['silent' => ['textDocument/hover']], timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 0.4, shutdownSeconds: 1.0));
        $started = microtime(true);

        try {
            $client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///x']]);
            self::fail('the request had to time out');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('language server fake did not answer textDocument/hover within 0.4 s', $error->getMessage());
        }

        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertTrue($client->alive(), 'a slow answer does not kill the server');
        $client->close();
        $hover = FakeLspServer::messagesFor($this->log, 'textDocument/hover')[0];
        $cancel = FakeLspServer::messagesFor($this->log, '$/cancelRequest')[0];
        self::assertSame($hover['id'], $cancel['params']['id']);
    }

    public function testACrashingServerGivesAClearErrorWithItsRedactedStderr(): void
    {
        $client = $this->started(['crashOn' => 'textDocument/hover', 'crashMessage' => 'boom with sk-abcdefgh12345678']);

        try {
            $client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///x']]);
            self::fail('the server crashed');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('language server fake exited with code 3 while waiting for textDocument/hover', $error->getMessage());
            self::assertStringContainsString('boom with [redacted]', $error->getMessage());
            self::assertStringNotContainsString('sk-abcdefgh12345678', $error->getMessage());
            self::assertStringContainsString('started again on the next lsp call', $error->getMessage());
        }

        self::assertFalse($client->alive());
        self::assertFalse(FakeLspServer::running(FakeLspServer::pids($this->log)[0]));
    }

    public function testCloseSendsShutdownThenExitAndTheProcessEnds(): void
    {
        $client = $this->started();
        $pid = FakeLspServer::pids($this->log)[0];
        self::assertTrue(FakeLspServer::running($pid));

        $client->close();
        $client->close();

        self::assertSame(['initialize', 'initialized', 'shutdown', 'exit'], FakeLspServer::methods($this->log));
        self::assertFalse(FakeLspServer::running($pid));
        self::assertFalse($client->alive());
    }

    public function testAServerThatIgnoresExitAndTerminateIsKilled(): void
    {
        $client = $this->started(['ignoreExit' => true, 'ignoreTerm' => true]);
        $pid = FakeLspServer::pids($this->log)[0];
        $started = microtime(true);

        $client->close();

        self::assertFalse(FakeLspServer::running($pid));
        self::assertLessThan(6.0, microtime(true) - $started);
    }

    public function testDocumentsAreOpenedChangedAndClosedFromDisk(): void
    {
        $root = Kit::folder('lsp-root');
        $file = $root . '/a.php';
        Kit::write($file, "<?php\necho 1;\n");
        $client = $this->started([], $root);

        $client->syncDocument($file, "<?php\necho 1;\n");
        $client->syncDocument($file, "<?php\necho 1;\n");
        Kit::write($file, "<?php\necho 2;\n");
        $client->refreshDocuments();
        $client->refreshDocuments();
        unlink($file);
        $client->refreshDocuments();
        $this->settle($client);

        $opens = FakeLspServer::messagesFor($this->log, 'textDocument/didOpen');
        $changes = FakeLspServer::messagesFor($this->log, 'textDocument/didChange');
        $closes = FakeLspServer::messagesFor($this->log, 'textDocument/didClose');
        self::assertCount(1, $opens);
        self::assertSame(['uri' => 'file://' . $file, 'languageId' => 'php', 'version' => 1, 'text' => "<?php\necho 1;\n"], $opens[0]['params']['textDocument']);
        self::assertCount(1, $changes);
        self::assertSame(['uri' => 'file://' . $file, 'version' => 2], $changes[0]['params']['textDocument']);
        self::assertSame([['text' => "<?php\necho 2;\n"]], $changes[0]['params']['contentChanges']);
        self::assertCount(1, $closes);
        self::assertSame('file://' . $file, $closes[0]['params']['textDocument']['uri']);
    }

    public function testAServerThatTracksNoChangesGetsTheDocumentClosedAndOpenedAgain(): void
    {
        $file = Kit::folder('lsp-root') . '/a.go';
        $client = $this->started(['capabilities' => ['textDocumentSync' => ['openClose' => true, 'change' => 0]]]);

        $client->syncDocument($file, 'package a');
        $client->syncDocument($file, 'package b');
        $this->settle($client);

        self::assertSame(['textDocument/didOpen', 'textDocument/didClose', 'textDocument/didOpen'], array_slice(FakeLspServer::methods($this->log), 2, 3));
        $reopened = FakeLspServer::messagesFor($this->log, 'textDocument/didOpen')[1];
        self::assertSame(2, $reopened['params']['textDocument']['version']);
        self::assertSame('go', $reopened['params']['textDocument']['languageId']);
    }

    public function testPushedDiagnosticsWaitForAQuietPeriodAndSkipStaleVersions(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $stale = [['message' => 'stale', 'range' => self::range()]];
        $fresh = [['message' => 'fresh', 'range' => self::range()]];
        $client = $this->started(
            ['publish' => [['version' => 0, 'diagnostics' => $stale], ['after' => 0.5, 'diagnostics' => $fresh]]],
            timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 3.0, diagnosticsQuietSeconds: 0.2, shutdownSeconds: 1.0),
        );
        $client->syncDocument($file, '<?php');

        $found = $client->diagnostics($file);

        self::assertSame('push', $found['via']);
        self::assertSame($fresh, $found['items']);
    }

    public function testAnUnchangedDocumentKeepsItsPublishedDiagnosticsAndAChangeDropsThem(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $items = [['message' => 'problem', 'range' => self::range()]];
        $client = $this->started(['publish' => [['diagnostics' => $items]]]);
        $client->syncDocument($file, '<?php');
        self::assertSame($items, $client->diagnostics($file)['items']);

        $client->syncDocument($file, '<?php');
        $again = $client->diagnostics($file);
        $client->syncDocument($file, '<?php echo 1;');
        $afterChange = $client->diagnostics($file);

        self::assertSame($items, $again['items']);
        self::assertSame($items, $afterChange['items']);
        self::assertCount(1, FakeLspServer::messagesFor($this->log, 'textDocument/didOpen'));
        $this->settle($client);
        self::assertCount(1, FakeLspServer::messagesFor($this->log, 'textDocument/didChange'));
    }

    public function testNothingPublishedInTimeIsReportedWithoutAWayOfDelivery(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $client = $this->started([], timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 0.5, diagnosticsQuietSeconds: 0.1, shutdownSeconds: 1.0));
        $client->syncDocument($file, '<?php');

        self::assertSame(['items' => [], 'via' => null], $client->diagnostics($file));
    }

    public function testPullDiagnosticsAreUsedWhenTheServerAdvertisesThem(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $items = [['message' => 'pulled', 'range' => self::range()]];
        $client = $this->started([
            'capabilities' => ['diagnosticProvider' => ['interFileDependencies' => false, 'workspaceDiagnostics' => false]],
            'results' => ['textDocument/diagnostic' => ['kind' => 'full', 'items' => $items]],
        ]);
        $client->syncDocument($file, '<?php');

        self::assertSame(['items' => $items, 'via' => 'pull'], $client->diagnostics($file));

        $request = FakeLspServer::messagesFor($this->log, 'textDocument/diagnostic')[0];
        self::assertSame(['textDocument' => ['uri' => 'file://' . $file]], $request['params']);
    }

    public function testAPullRegistrationMadeAfterInitializedIsHonoured(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $items = [['message' => 'pulled late', 'range' => self::range()]];
        $client = $this->started(['registerPull' => true, 'results' => ['textDocument/diagnostic' => ['kind' => 'full', 'items' => $items]]]);
        $client->syncDocument($file, '<?php');

        $found = $client->diagnostics($file);
        $client->close();

        self::assertSame(['items' => $items, 'via' => 'pull'], $found);
        $reply = array_values(array_filter(FakeLspServer::messages($this->log), static fn (array $message): bool => ($message['id'] ?? null) === 'reg-1'));
        self::assertNull($reply[0]['result']);
    }

    public function testPullRefusedWithMethodNotFoundFallsBackToPushAndIsNotTriedAgain(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $items = [['message' => 'pushed', 'range' => self::range()]];
        $client = $this->started([
            'capabilities' => ['diagnosticProvider' => ['interFileDependencies' => false, 'workspaceDiagnostics' => false]],
            'errors' => ['textDocument/diagnostic' => ['code' => -32601, 'message' => 'no pull here']],
            'publish' => [['diagnostics' => $items]],
        ]);
        $client->syncDocument($file, '<?php');
        $first = $client->diagnostics($file);
        $client->syncDocument($file, '<?php echo 1;');
        $second = $client->diagnostics($file);

        self::assertSame(['items' => $items, 'via' => 'push'], $first);
        self::assertSame(['items' => $items, 'via' => 'push'], $second);
        self::assertCount(1, FakeLspServer::messagesFor($this->log, 'textDocument/diagnostic'));
    }

    public function testAPullThatTheServerCancelledIsRetried(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $items = [['message' => 'finally', 'range' => self::range()]];
        $client = $this->started([
            'capabilities' => ['diagnosticProvider' => ['interFileDependencies' => false, 'workspaceDiagnostics' => false]],
            'errors' => ['textDocument/diagnostic' => ['code' => -32802, 'message' => 'still analysing', 'times' => 2]],
            'results' => ['textDocument/diagnostic' => ['kind' => 'full', 'items' => $items]],
        ]);
        $client->syncDocument($file, '<?php');

        self::assertSame(['items' => $items, 'via' => 'pull'], $client->diagnostics($file));
        self::assertCount(3, FakeLspServer::messagesFor($this->log, 'textDocument/diagnostic'));
    }

    public function testWarmUpWaitsOnceForTheFirstDiagnosticsOfTheServer(): void
    {
        $first = Kit::folder('lsp-root') . '/a.php';
        $second = Kit::folder('lsp-root') . '/b.php';
        $items = [['message' => 'loaded', 'range' => self::range()]];
        $client = $this->started(
            ['publish' => [['after' => 0.5, 'diagnostics' => $items]]],
            timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.1, warmupSeconds: 3.0, shutdownSeconds: 1.0),
        );
        $client->syncDocument($first, '<?php');

        $started = microtime(true);
        $client->warmUp($first);
        $waited = microtime(true) - $started;
        $client->syncDocument($second, '<?php');
        $started = microtime(true);
        $client->warmUp($second);

        self::assertGreaterThanOrEqual(0.45, $waited);
        self::assertLessThan(0.3, microtime(true) - $started, 'only the first document of a server is waited for');
        self::assertSame($items, $client->diagnostics($first)['items']);
    }

    public function testWarmUpAlsoWaitsForTheWorkDoneProgressOfTheServerToEnd(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $client = $this->started(
            [
                'serverRequests' => [['id' => 'p1', 'method' => 'window/workDoneProgress/create', 'params' => ['token' => 'indexing']]],
                'notifications' => [
                    ['after' => 0.0, 'method' => '$/progress', 'params' => ['token' => 'indexing', 'value' => ['kind' => 'begin', 'title' => 'Indexing']]],
                    ['after' => 0.9, 'method' => '$/progress', 'params' => ['token' => 'indexing', 'value' => ['kind' => 'end']]],
                ],
                'publish' => [['after' => 0.1, 'diagnostics' => []]],
            ],
            timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.1, warmupSeconds: 3.0, shutdownSeconds: 1.0),
        );
        $client->syncDocument($file, '<?php');

        $started = microtime(true);
        $client->warmUp($file);

        self::assertGreaterThanOrEqual(0.85, microtime(true) - $started);
        $this->settle($client);
        $methods = FakeLspServer::methods($this->log);
        self::assertContains('sent:window/workDoneProgress/create', $methods);
        self::assertLessThan(array_search('textDocument/hover', $methods, true), array_search('sent:$/progress', array_reverse($methods, true), true));
    }

    public function testWarmUpDoesNotWaitForProgressThatNeverEndsLongerThanItsBound(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $client = $this->started(
            ['notifications' => [['after' => 0.0, 'method' => '$/progress', 'params' => ['token' => 'forever', 'value' => ['kind' => 'begin', 'title' => 'Checking']]]], 'publish' => [['diagnostics' => []]]],
            timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.1, warmupSeconds: 0.8, shutdownSeconds: 1.0),
        );
        $client->syncDocument($file, '<?php');

        $started = microtime(true);
        $client->warmUp($file);

        self::assertLessThan(1.8, microtime(true) - $started);
        self::assertGreaterThanOrEqual(0.7, microtime(true) - $started);
    }

    public function testWarmUpGivesUpAfterItsBoundAndIgnoresAnErrorOfThePull(): void
    {
        $file = Kit::folder('lsp-root') . '/a.php';
        $silent = $this->started([], timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.1, warmupSeconds: 0.4, shutdownSeconds: 1.0));
        $silent->syncDocument($file, '<?php');
        $started = microtime(true);
        $silent->warmUp($file);
        self::assertLessThan(1.5, microtime(true) - $started);

        $failing = $this->started(
            ['capabilities' => ['diagnosticProvider' => ['interFileDependencies' => false, 'workspaceDiagnostics' => false]], 'errors' => ['textDocument/diagnostic' => ['code' => -32603, 'message' => 'not yet']]],
            timeouts: new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.1, warmupSeconds: 1.0, shutdownSeconds: 1.0),
        );
        $failing->syncDocument($file, '<?php');
        $failing->warmUp($file);

        self::assertTrue($failing->alive());
    }

    public function testAPositionEncodingOtherThanUtf16IsRefused(): void
    {
        $this->log = FakeLspServer::logFile();
        $client = FakeLspServer::client(['log' => $this->log, 'capabilities' => ['positionEncoding' => 'utf-8']]);

        try {
            $client->start();
            self::fail('utf-8 is not offered');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('chose the position encoding utf-8, but phycode offers only utf-16', $error->getMessage());
        }

        self::assertFalse(FakeLspServer::running(FakeLspServer::pids($this->log)[0]));
    }

    public function testAProgramThatIsNotInstalledSaysSoAndNamesTheSetting(): void
    {
        $client = new LspClient('ghost', ['definitely-not-an-installed-language-server'], Kit::folder('lsp-root'));

        try {
            $client->start();
            self::fail('the program does not exist');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('language server ghost is not installed', $error->getMessage());
            self::assertStringContainsString('lsp.ghost.command', $error->getMessage());
        }
    }

    public function testAFailedInitializeCarriesTheMessageOfTheServer(): void
    {
        $this->log = FakeLspServer::logFile();
        $client = FakeLspServer::client(['log' => $this->log, 'initializeError' => 'licence key missing']);

        try {
            $client->start();
            self::fail('initialize failed');
        } catch (LspRpcException $error) {
            self::assertStringContainsString('failed initialize: licence key missing (code -32603)', $error->getMessage());
        }

        self::assertFalse(FakeLspServer::running(FakeLspServer::pids($this->log)[0]));
    }

    public function testAnErrorResponseCarriesTheMethodTheMessageAndTheCode(): void
    {
        $client = $this->started(['errors' => ['textDocument/hover' => ['code' => -32603, 'message' => 'kaboom']]]);

        try {
            $client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///x']]);
            self::fail('the server answered with an error');
        } catch (LspRpcException $error) {
            self::assertSame(-32603, $error->getCode());
            self::assertStringContainsString('language server fake failed textDocument/hover: kaboom (code -32603)', $error->getMessage());
        }
        self::assertTrue($client->alive());
    }

    public function testACancelledCallStopsWaiting(): void
    {
        $client = $this->started(['silent' => ['textDocument/hover']]);
        $cancel = new Cancellation();
        $cancel->cancel();

        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage('was cancelled while waiting for textDocument/hover');

        $client->bind($cancel)->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///x']]);
    }

    public function testOutputThatIsNotFramedIsAProtocolError(): void
    {
        $this->log = FakeLspServer::logFile();
        $client = FakeLspServer::client(['log' => $this->log, 'garbage' => true]);

        try {
            $client->start();
            self::fail('the server does not speak the protocol');
        } catch (PhycodeException $error) {
            self::assertStringContainsString('sent a message without a Content-Length header', $error->getMessage());
        }
    }

    private function started(array $scenario = [], ?string $root = null, array $options = [], ?LspTimeouts $timeouts = null): LspClient
    {
        $this->log = FakeLspServer::logFile();
        $scenario['log'] = $this->log;
        $scenario['results'] = ($scenario['results'] ?? []) + ['textDocument/hover' => null];
        $client = FakeLspServer::client($scenario, $root, $options, $timeouts);
        $client->start();
        $this->clients[] = $client;

        return $client;
    }

    private function settle(LspClient $client): void
    {
        $client->request('textDocument/hover', ['textDocument' => ['uri' => 'file:///settle'], 'position' => ['line' => 0, 'character' => 0]]);
    }

    private static function range(): array
    {
        return ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]];
    }
}
