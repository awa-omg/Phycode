<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Config\Limits;
use Phycode\Lsp\LspServers;
use Phycode\Lsp\LspServerSpec;
use Phycode\Lsp\LspTimeouts;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Unit\Lsp\FakeLspServer;
use Phycode\Tools\LspTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

final class LspToolTest extends TestCase
{
    private const SOURCE = "<?php\nfinal class Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n\$é😀 = new Foo();\n";

    private string $root;
    private string $log;
    private ?LspServers $servers = null;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('lsp-tool'));
        $this->log = FakeLspServer::logFile();
        Kit::write($this->root . '/src/Foo.php', self::SOURCE);
        Kit::write($this->root . '/src/Bar.php', "<?php\n\$foo = new Foo();\n\$foo->bar();\n");
        Kit::write($this->root . '/notes.txt', "hello\n");
    }

    protected function tearDown(): void
    {
        $this->servers?->close();
        $this->servers = null;
    }

    public function testItIsAReadToolThatIsOnlyOfferedWhenAServerIsConfigured(): void
    {
        $empty = new LspTool(new LspServers([]));
        $configured = $this->tool();

        self::assertSame('lsp', $configured->name());
        self::assertSame(ToolCategory::Read, $configured->category());
        self::assertFalse($empty->isAvailable(Kit::policy()));
        self::assertTrue($configured->isAvailable(Kit::policy()));
        self::assertSame(['action', 'path'], $configured->parameters()['required']);
        self::assertSame(['definition', 'references', 'hover', 'document_symbols', 'workspace_symbols', 'diagnostics'], $configured->parameters()['properties']['action']['enum']);
    }

    public function testDefinitionShowsTheLocationAndTheLinesAroundIt(): void
    {
        $tool = $this->tool(['definition' => [['uri' => $this->uri('src/Foo.php'), 'range' => self::range(3, 20, 3, 23)]]]);

        $result = $this->ask($tool, ['action' => 'definition', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 8]);

        self::assertTrue($result->ok);
        self::assertSame("src/Foo.php:4:21\n     4      public function bar(): int\n     5      {\n     6          return 1;", $result->content);
        self::assertSame('fake', $result->meta['server']);
        $request = FakeLspServer::messagesFor($this->log, 'textDocument/definition')[0];
        self::assertSame(['textDocument' => ['uri' => $this->uri('src/Bar.php')], 'position' => ['line' => 1, 'character' => 7]], $request['params']);
    }

    public function testDefinitionUnderstandsLocationLinksAndSeveralLocations(): void
    {
        $tool = $this->tool(['definition' => [
            ['targetUri' => $this->uri('src/Foo.php'), 'targetRange' => self::range(1, 0, 7, 1), 'targetSelectionRange' => self::range(1, 12, 1, 15)],
            ['uri' => $this->uri('src/Bar.php'), 'range' => self::range(1, 0, 1, 4)],
        ]]);

        $result = $this->ask($tool, ['action' => 'definition', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 8]);

        self::assertStringContainsString("src/Bar.php:2:1\n     2  \$foo = new Foo();", $result->content);
        self::assertStringContainsString("src/Foo.php:2:13\n     2  final class Foo\n     3  {\n     4      public function bar(): int", $result->content);
    }

    public function testTheColumnOfTheModelIsConvertedToUtf16UnitsForTheServer(): void
    {
        $tool = $this->tool(['hover' => null]);

        $this->ask($tool, ['action' => 'hover', 'path' => 'src/Foo.php', 'line' => 9, 'column' => 4]);

        $request = FakeLspServer::messagesFor($this->log, 'textDocument/hover')[0];
        self::assertSame(['line' => 8, 'character' => 4], $request['params']['position'], '$, é, and the emoji of two units come before column 4');
    }

    public function testAPositionFromTheServerIsShownInCharactersNotUtf16Units(): void
    {
        $tool = $this->tool(['definition' => [['uri' => $this->uri('src/Foo.php'), 'range' => self::range(8, 6, 8, 9)]]]);

        $result = $this->ask($tool, ['action' => 'definition', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 8]);

        self::assertStringStartsWith('src/Foo.php:9:6', $result->content, 'unit 6 of the line is the 5th character, so column 6');
    }

    public function testReferencesAreSortedWithoutDuplicatesAndCounted(): void
    {
        $tool = $this->tool(['references' => [
            ['uri' => $this->uri('src/Bar.php'), 'range' => self::range(2, 0, 2, 4)],
            ['uri' => $this->uri('src/Foo.php'), 'range' => self::range(1, 12, 1, 15)],
            ['uri' => $this->uri('src/Bar.php'), 'range' => self::range(1, 15, 1, 18)],
            ['uri' => $this->uri('src/Bar.php'), 'range' => self::range(2, 0, 2, 4)],
        ]]);

        $result = $this->ask($tool, ['action' => 'references', 'path' => 'src/Foo.php', 'line' => 2, 'column' => 13]);

        self::assertSame("3 references:\nsrc/Bar.php:2:16: \$foo = new Foo();\nsrc/Bar.php:3:1: \$foo->bar();\nsrc/Foo.php:2:13: final class Foo", $result->content);
        $request = FakeLspServer::messagesFor($this->log, 'textDocument/references')[0];
        self::assertSame(['includeDeclaration' => true], $request['params']['context']);
    }

    public function testReferencesAreCappedInCountWithAMoreLine(): void
    {
        Kit::write($this->root . '/big.php', implode("\n", array_map(static fn (int $n): string => "\$value{$n} = {$n};", range(1, 60))) . "\n");
        $locations = array_map(fn (int $n): array => ['uri' => $this->uri('big.php'), 'range' => self::range($n, 0, $n, 3)], range(0, 59));
        $tool = $this->tool(['references' => $locations]);

        $result = $this->ask($tool, ['action' => 'references', 'path' => 'big.php', 'line' => 1, 'column' => 1]);

        self::assertStringStartsWith("60 references:\nbig.php:1:1: \$value1 = 1;", $result->content);
        self::assertStringContainsString('big.php:50:1: $value50 = 50;', $result->content);
        self::assertStringNotContainsString('big.php:51:1', $result->content);
        self::assertStringEndsWith('[10 more not shown; ask about a narrower position, symbol or query]', $result->content);
    }

    public function testTheOutputIsCappedInBytesToo(): void
    {
        $long = str_repeat('x', 150);
        Kit::write($this->root . '/wide.php', implode("\n", array_map(static fn (int $n): string => "\$v{$n} = '{$long}';", range(1, 40))) . "\n");
        $locations = array_map(fn (int $n): array => ['uri' => $this->uri('wide.php'), 'range' => self::range($n, 0, $n, 3)], range(0, 39));
        $tool = $this->tool(['references' => $locations]);

        $result = $this->ask($tool, ['action' => 'references', 'path' => 'wide.php', 'line' => 1, 'column' => 1], new Limits(toolOutputBytes: 1500));

        self::assertLessThanOrEqual(1500, strlen($result->content));
        self::assertMatchesRegularExpression('/\[\d+ more not shown/', $result->content);
        self::assertStringContainsString('wide.php:1:1:', $result->content);
    }

    public function testHoverShowsMarkupAndMarkedStrings(): void
    {
        $markup = $this->ask($this->tool(['hover' => ['contents' => ['kind' => 'markdown', 'value' => "```php\nfunction bar(): int\n```\nReturns one."]]]), ['action' => 'hover', 'path' => 'src/Bar.php', 'line' => 3, 'column' => 8]);
        $this->servers->close();
        $marked = $this->ask($this->tool(['hover' => ['contents' => ['plain text', ['language' => 'php', 'value' => 'int $x']]]]), ['action' => 'hover', 'path' => 'src/Bar.php', 'line' => 3, 'column' => 8]);

        self::assertSame("```php\nfunction bar(): int\n```\nReturns one.", $markup->content);
        self::assertSame("plain text\n\n```php\nint \$x\n```", $marked->content);
    }

    public function testHoverWithNothingToShowSaysWhereItLooked(): void
    {
        $result = $this->ask($this->tool(['hover' => null]), ['action' => 'hover', 'path' => 'src/Bar.php', 'line' => 3, 'column' => 8]);

        self::assertTrue($result->ok);
        self::assertSame('no hover information at src/Bar.php:3:8', $result->content);
    }

    public function testNothingFoundIsAnAnswerAndNotAnError(): void
    {
        $tool = $this->tool(['definition' => [], 'references' => null, 'documentSymbol' => null, 'symbol' => []]);

        self::assertSame('no definition found at src/Bar.php:2:1', $this->ask($tool, ['action' => 'definition', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 1])->content);
        self::assertSame('no references found at src/Bar.php:2:1', $this->ask($tool, ['action' => 'references', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 1])->content);
        self::assertSame('the server found no symbols in src/Bar.php', $this->ask($tool, ['action' => 'document_symbols', 'path' => 'src/Bar.php'])->content);
        self::assertSame("no symbols match 'zzz'", $this->ask($tool, ['action' => 'workspace_symbols', 'path' => 'src/Bar.php', 'query' => 'zzz'])->content);
    }

    public function testDocumentSymbolsShowTheTreeWithLineNumbers(): void
    {
        $tool = $this->tool(['documentSymbol' => [[
            'name' => 'Foo', 'kind' => 5, 'range' => self::range(1, 0, 7, 1), 'selectionRange' => self::range(1, 12, 1, 15),
            'children' => [['name' => 'bar', 'kind' => 6, 'detail' => '(): int', 'range' => self::range(3, 4, 6, 5), 'selectionRange' => self::range(3, 20, 3, 23)]],
        ]]]);

        $result = $this->ask($tool, ['action' => 'document_symbols', 'path' => 'src/Foo.php']);

        self::assertSame("     2  class Foo\n     4    method bar  (): int", $result->content);
    }

    public function testDocumentSymbolsAlsoAcceptTheFlatForm(): void
    {
        $tool = $this->tool(['documentSymbol' => [
            ['name' => 'bar', 'kind' => 6, 'containerName' => 'Foo', 'location' => ['uri' => $this->uri('src/Foo.php'), 'range' => self::range(3, 4, 6, 5)]],
        ]]);

        self::assertSame('     4  method bar (Foo)', $this->ask($tool, ['action' => 'document_symbols', 'path' => 'src/Foo.php'])->content);
    }

    public function testWorkspaceSymbolsShowWhereEachOneLives(): void
    {
        $tool = $this->tool(['symbol' => [
            ['name' => 'Foo', 'kind' => 5, 'location' => ['uri' => $this->uri('src/Foo.php'), 'range' => self::range(1, 12, 1, 15)]],
            ['name' => 'bar', 'kind' => 6, 'containerName' => 'Foo', 'location' => ['uri' => $this->uri('src/Foo.php')]],
        ]]);

        $result = $this->ask($tool, ['action' => 'workspace_symbols', 'path' => 'src/Bar.php', 'query' => 'Foo']);

        self::assertSame("class Foo  src/Foo.php:2:13\nmethod bar (Foo)  src/Foo.php", $result->content);
        self::assertSame(['query' => 'Foo'], FakeLspServer::messagesFor($this->log, 'workspace/symbol')[0]['params']);
    }

    public function testPushedDiagnosticsAreListedMostSevereFirst(): void
    {
        $tool = $this->tool([], ['publish' => [['diagnostics' => [
            ['range' => self::range(5, 8, 5, 9), 'severity' => 2, 'source' => 'fake', 'code' => 'W7', 'message' => "unused\nvariable"],
            ['range' => self::range(8, 0, 8, 2), 'severity' => 1, 'message' => 'syntax error'],
            ['range' => self::range(1, 12, 1, 15), 'message' => 'no severity means error', 'code' => 12],
        ]]]]);

        $result = $this->ask($tool, ['action' => 'diagnostics', 'path' => 'src/Foo.php']);

        self::assertSame(
            "3 diagnostics in src/Foo.php (error=2 warning=1):\n2:13 error [12] no severity means error\n9:1 error syntax error\n6:9 warning [fake W7] unused variable",
            $result->content,
        );
    }

    public function testPulledDiagnosticsAreUsedWhenTheServerOffersThem(): void
    {
        $tool = $this->tool([], [
            'capabilities' => ['diagnosticProvider' => ['interFileDependencies' => false, 'workspaceDiagnostics' => false]],
            'results' => ['textDocument/diagnostic' => ['kind' => 'full', 'items' => [['range' => self::range(0, 0, 0, 1), 'severity' => 3, 'message' => 'consider this']]]],
        ]);

        $result = $this->ask($tool, ['action' => 'diagnostics', 'path' => 'src/Foo.php']);

        self::assertSame("1 diagnostics in src/Foo.php (info=1):\n1:1 info consider this", $result->content);
        self::assertCount(0, FakeLspServer::messagesFor($this->log, 'textDocument/publishDiagnostics'));
    }

    public function testACleanFileAndASilentServerAreToldApart(): void
    {
        $clean = $this->ask($this->tool([], ['publish' => [['diagnostics' => []]]]), ['action' => 'diagnostics', 'path' => 'src/Foo.php']);
        $this->servers->close();
        $silent = $this->ask($this->tool(), ['action' => 'diagnostics', 'path' => 'src/Foo.php']);

        self::assertSame('no diagnostics in src/Foo.php', $clean->content);
        self::assertTrue($silent->ok);
        self::assertStringContainsString('no diagnostics were published for src/Foo.php in time', $silent->content);
        self::assertStringContainsString('call again to check', $silent->content);
    }

    public function testTheFirstQuestionWaitsForTheServerToLoadTheProjectAndLaterOnesDoNot(): void
    {
        $tool = $this->tool(['hover' => null], ['publish' => [['after' => 0.6, 'diagnostics' => []]]], new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.1, warmupSeconds: 3.0, shutdownSeconds: 1.0));

        $started = microtime(true);
        $this->ask($tool, ['action' => 'hover', 'path' => 'src/Foo.php', 'line' => 2, 'column' => 1]);
        $first = microtime(true) - $started;
        $started = microtime(true);
        $this->ask($tool, ['action' => 'hover', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 1]);
        $second = microtime(true) - $started;

        $methods = FakeLspServer::methods($this->log);
        self::assertLessThan(array_search('textDocument/hover', $methods, true), array_search('sent:textDocument/publishDiagnostics', $methods, true), 'the answer waited for the first diagnostics');
        self::assertGreaterThanOrEqual(0.55, $first);
        self::assertLessThan(0.5, $second);
    }

    public function testAskingForDiagnosticsDoesNotWaitTwice(): void
    {
        $tool = $this->tool([], ['publish' => [['after' => 0.3, 'diagnostics' => []]]], new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 2.0, diagnosticsSeconds: 2.0, diagnosticsQuietSeconds: 0.1, warmupSeconds: 5.0, shutdownSeconds: 1.0));

        $started = microtime(true);
        $result = $this->ask($tool, ['action' => 'diagnostics', 'path' => 'src/Foo.php']);

        self::assertSame('no diagnostics in src/Foo.php', $result->content);
        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testAnEditOnDiskIsSentToTheServerBeforeTheNextQuestion(): void
    {
        $tool = $this->tool(['hover' => null]);
        $args = ['action' => 'hover', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 1];

        $this->ask($tool, $args);
        $this->ask($tool, $args);
        Kit::write($this->root . '/src/Bar.php', "<?php\n\$changed = 1;\n");
        $this->ask($tool, $args);

        self::assertCount(1, FakeLspServer::messagesFor($this->log, 'textDocument/didOpen'));
        $changes = FakeLspServer::messagesFor($this->log, 'textDocument/didChange');
        self::assertCount(1, $changes);
        self::assertSame(2, $changes[0]['params']['textDocument']['version']);
        self::assertSame("<?php\n\$changed = 1;\n", $changes[0]['params']['contentChanges'][0]['text']);
    }

    public function testAnOpenFileEditedBySomeoneElseIsSyncedWhenAnotherFileIsAsked(): void
    {
        $tool = $this->tool(['hover' => null]);

        $this->ask($tool, ['action' => 'hover', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 1]);
        Kit::write($this->root . '/src/Bar.php', "<?php\n\$other = 2;\n");
        $this->ask($tool, ['action' => 'hover', 'path' => 'src/Foo.php', 'line' => 2, 'column' => 1]);

        $changes = FakeLspServer::messagesFor($this->log, 'textDocument/didChange');
        self::assertCount(1, $changes);
        self::assertSame($this->uri('src/Bar.php'), $changes[0]['params']['textDocument']['uri']);
    }

    public function testASourceFileOutsideTheProjectIsListedWithoutItsText(): void
    {
        $outside = Kit::folder('lsp-outside') . '/Stub.php';
        Kit::write($outside, "<?php\nclass Stub {}\n");
        Kit::write($this->root . '/.env', "SECRET=abcdef\n");
        $tool = $this->tool(['definition' => [
            ['uri' => 'file://' . $outside, 'range' => self::range(1, 6, 1, 10)],
            ['uri' => $this->uri('.env'), 'range' => self::range(0, 0, 0, 6)],
            ['uri' => 'phar:///x.phar/y.php', 'range' => self::range(0, 0, 0, 1)],
        ]]);

        $result = $this->ask($tool, ['action' => 'definition', 'path' => 'src/Bar.php', 'line' => 2, 'column' => 8]);

        self::assertStringContainsString("{$outside}:2:7", $result->content);
        self::assertStringContainsString('.env:1:1', $result->content);
        self::assertStringContainsString('phar:///x.phar/y.php:1:1', $result->content);
        self::assertStringNotContainsString('class Stub', $result->content);
        self::assertStringNotContainsString('SECRET', $result->content);
    }

    public function testBadArgumentsSayWhatToDo(): void
    {
        $tool = $this->tool();

        $unknown = $this->ask($tool, ['action' => 'rename', 'path' => 'src/Foo.php']);
        $noPosition = $this->ask($tool, ['action' => 'definition', 'path' => 'src/Foo.php']);
        $pastEnd = $this->ask($tool, ['action' => 'hover', 'path' => 'src/Foo.php', 'line' => 99, 'column' => 1]);
        $noQuery = $this->ask($tool, ['action' => 'workspace_symbols', 'path' => 'src/Foo.php']);

        self::assertFalse($unknown->ok);
        self::assertSame("unknown action 'rename'. Use one of: definition, references, hover, document_symbols, workspace_symbols, diagnostics", $unknown->content);
        self::assertSame('definition needs line and column, both starting at 1; read src/Foo.php to find the symbol', $noPosition->content);
        self::assertSame('line 99 is past the end of src/Foo.php (10 lines)', $pastEnd->content);
        self::assertSame('workspace_symbols needs a query: the name, or part of the name, of the symbols to find', $noQuery->content);
        self::assertSame([], FakeLspServer::messages($this->log), 'no server was started for a call that cannot work');
    }

    public function testAPathThatIsNotAFileOfTheProjectIsRefused(): void
    {
        $tool = $this->tool();

        $outside = $this->ask($tool, ['action' => 'document_symbols', 'path' => '/etc/passwd']);
        $missing = $this->ask($tool, ['action' => 'document_symbols', 'path' => 'src/Nope.php']);
        $folder = $this->ask($tool, ['action' => 'document_symbols', 'path' => 'src']);

        self::assertFalse($outside->ok);
        self::assertStringContainsString('path is outside the project', $outside->content);
        self::assertSame('not a file: src/Nope.php. Use list or grep to find files.', $missing->content);
        self::assertSame('not a file: src. Use list or grep to find files.', $folder->content);
    }

    public function testABinaryFileIsRefused(): void
    {
        file_put_contents($this->root . '/blob.php', "\0\1\2binary");

        $result = $this->ask($this->tool(), ['action' => 'document_symbols', 'path' => 'blob.php']);

        self::assertSame('binary file: blob.php', $result->content);
    }

    public function testAnExtensionNoServerHandlesNamesTheConfiguredOnes(): void
    {
        $result = $this->ask($this->tool(), ['action' => 'document_symbols', 'path' => 'notes.txt']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('no language server handles .txt files. Configured: fake (php)', $result->content);
    }

    public function testAServerThatIsNotInstalledSaysHowToFixIt(): void
    {
        $this->servers = new LspServers([new LspServerSpec('ghost', ['definitely-not-an-installed-language-server'], ['php'])], FakeLspServer::timeouts());

        $result = $this->ask(new LspTool($this->servers), ['action' => 'document_symbols', 'path' => 'src/Foo.php']);

        self::assertFalse($result->ok);
        self::assertStringContainsString("language server ghost is not installed: 'definitely-not-an-installed-language-server' was not found", $result->content);
        self::assertStringContainsString('lsp.ghost.command', $result->content);
    }

    public function testAnErrorOfTheServerIsAToolErrorWithItsMessage(): void
    {
        $tool = $this->tool(['hover' => null], ['errors' => ['textDocument/hover' => ['code' => -32603, 'message' => 'kaboom']]]);

        $result = $this->ask($tool, ['action' => 'hover', 'path' => 'src/Foo.php', 'line' => 2, 'column' => 1]);

        self::assertFalse($result->ok);
        self::assertStringContainsString('language server fake failed textDocument/hover: kaboom (code -32603)', $result->content);
    }

    public function testASlowServerGivesATimeoutErrorAndNotAHang(): void
    {
        $this->servers = new LspServers(
            [new LspServerSpec('fake', FakeLspServer::command(['log' => $this->log, 'silent' => ['textDocument/hover']]), ['php'])],
            new LspTimeouts(initializeSeconds: 5.0, requestSeconds: 0.4, warmupSeconds: 0.0, shutdownSeconds: 1.0),
        );
        $started = microtime(true);

        $result = $this->ask(new LspTool($this->servers), ['action' => 'hover', 'path' => 'src/Foo.php', 'line' => 2, 'column' => 1]);

        self::assertFalse($result->ok);
        self::assertStringContainsString('did not answer textDocument/hover within 0.4 s', $result->content);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testACrashedServerIsAnErrorOnceAndStartedAgainAfterwards(): void
    {
        $marker = Kit::folder('lsp-marker') . '/crashed';
        $tool = $this->tool(['hover' => null], ['crashOn' => 'textDocument/hover', 'crashOnce' => $marker, 'crashMessage' => 'out of memory']);
        $args = ['action' => 'hover', 'path' => 'src/Foo.php', 'line' => 2, 'column' => 1];

        $first = $this->ask($tool, $args);
        $second = $this->ask($tool, $args);

        self::assertFalse($first->ok);
        self::assertStringContainsString('exited with code 3 while waiting for textDocument/hover: out of memory', $first->content);
        self::assertTrue($second->ok);
        self::assertSame('no hover information at src/Foo.php:2:1', $second->content);
        self::assertCount(2, FakeLspServer::messagesFor($this->log, 'initialize'));
        self::assertCount(2, FakeLspServer::messagesFor($this->log, 'textDocument/didOpen'), 'the new server gets the file again');
    }

    public function testAFileThatHoldsSecretsAsksFirstAndIsNotReachedThroughALink(): void
    {
        Kit::write($this->root . '/.env', "SECRET=abcdef\n");
        symlink($this->root . '/.env', $this->root . '/link.php');
        $tool = $this->tool();

        self::assertTrue($tool->forcesApproval(['path' => '.env']));
        self::assertTrue($tool->forcesApproval(['path' => 'config/.env.production']));
        self::assertFalse($tool->forcesApproval(['path' => 'src/Foo.php']));
        $result = $this->ask($tool, ['action' => 'document_symbols', 'path' => 'link.php']);
        self::assertFalse($result->ok);
        self::assertStringContainsString('refused: link.php leads to .env', $result->content);
        self::assertSame([], FakeLspServer::messages($this->log));
    }

    private function tool(array $results = [], array $scenario = [], ?LspTimeouts $timeouts = null): LspTool
    {
        $names = ['definition' => 'textDocument/definition', 'references' => 'textDocument/references', 'hover' => 'textDocument/hover', 'documentSymbol' => 'textDocument/documentSymbol', 'symbol' => 'workspace/symbol'];
        $canned = [];
        foreach ($results as $key => $value) {
            $canned[$names[$key]] = $value;
        }
        $scenario['results'] = ($scenario['results'] ?? []) + $canned;
        $scenario['log'] = $this->log;
        $this->servers = new LspServers([new LspServerSpec('fake', FakeLspServer::command($scenario), ['php'])], $timeouts ?? FakeLspServer::timeouts());

        return new LspTool($this->servers);
    }

    private function ask(LspTool $tool, array $args, ?Limits $limits = null): ToolResult
    {
        $context = Kit::context($this->root, Kit::agent(), Kit::policy(), new RecordingUi(), limits: $limits);

        return $tool->execute($args, $context);
    }

    private function uri(string $relative): string
    {
        return 'file://' . $this->root . '/' . $relative;
    }

    private static function range(int $startLine, int $startCharacter, int $endLine, int $endCharacter): array
    {
        return ['start' => ['line' => $startLine, 'character' => $startCharacter], 'end' => ['line' => $endLine, 'character' => $endCharacter]];
    }
}
