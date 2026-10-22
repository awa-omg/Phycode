<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Agent\RunPolicy;
use Phycode\Cli\Kernel;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tests\Support\RequestSpy;
use Phycode\Tests\Support\SessionHarness;
use Phycode\Tests\Unit\Lsp\FakeLspServer;
use Phycode\Tools\LspTool;
use PHPUnit\Framework\TestCase;

final class KernelLspTest extends TestCase
{
    private const BASE = [
        'default_profile' => 'local',
        'profiles' => [
            'local' => ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen', 'tool_mode' => 'text'],
        ],
    ];

    private SessionHarness $harness;
    private RecordingUi $ui;

    protected function setUp(): void
    {
        $this->harness = new SessionHarness(self::BASE);
        $this->ui = new RecordingUi();
    }

    protected function tearDown(): void
    {
        $this->harness->end();
    }

    public function testTheLspToolIsOfferedOnlyWhenTheGlobalConfigNamesAServer(): void
    {
        $this->configure([]);
        $without = $this->kernel();
        self::assertTrue($without->lsp->isEmpty());
        self::assertNull($without->tools->get('lsp'));
        self::assertNotContains('lsp', $this->offered($without));

        $this->configure(['lsp' => ['fake' => ['command' => FakeLspServer::command(), 'extensions' => ['php']]]]);
        $with = $this->kernel();

        self::assertFalse($with->lsp->isEmpty());
        self::assertInstanceOf(LspTool::class, $with->tools->get('lsp'));
        self::assertContains('lsp', $this->offered($with));
        $names = array_column(array_column($with->tools->specs(RunPolicy::primary(Kit::agent(), 'yolo')), 'function'), 'name');
        self::assertContains('lsp', $names);
    }

    public function testTheToolsDisabledListTakesTheLspToolAway(): void
    {
        $this->configure(['lsp' => ['fake' => ['command' => FakeLspServer::command()]], 'tools' => ['disabled' => ['lsp']]]);

        self::assertNotContains('lsp', $this->offered($this->kernel()));
    }

    public function testAProjectConfigCannotAddALanguageServer(): void
    {
        $this->configure([]);
        mkdir($this->harness->cwd . '/.phycode');
        file_put_contents($this->harness->cwd . '/.phycode/config.json', json_encode(['lsp' => ['evil' => ['command' => ['sh', '-c', 'id']]]]));

        $kernel = $this->kernel();

        self::assertTrue($kernel->lsp->isEmpty());
        self::assertNotContains('lsp', $this->offered($kernel));
    }

    public function testTheSessionAsksARealServerProcessAndShutsItDownWhenItEnds(): void
    {
        $log = FakeLspServer::logFile();
        $scenario = ['log' => $log, 'results' => ['textDocument/hover' => ['contents' => 'kernel answer']]];
        $this->configure(['lsp' => ['fake' => ['command' => FakeLspServer::command($scenario), 'extensions' => ['php']]]]);
        Kit::write($this->harness->cwd . '/src/A.php', "<?php\necho 1;\n");
        $kernel = $this->kernel();
        $context = Kit::context($this->harness->cwd, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), $this->ui);

        $result = $kernel->tools->get('lsp')->execute(['action' => 'hover', 'path' => 'src/A.php', 'line' => 2, 'column' => 1], $context);
        $pid = FakeLspServer::pids($log)[0];
        self::assertTrue(FakeLspServer::running($pid));
        $kernel->lsp->close();

        self::assertSame('kernel answer', $result->content);
        self::assertSame(['shutdown', 'exit'], array_slice(FakeLspServer::methods($log), -2));
        self::assertFalse(FakeLspServer::running($pid));
    }

    private function configure(array $settings): void
    {
        file_put_contents($this->harness->configFile, json_encode($settings + self::BASE, JSON_THROW_ON_ERROR));
    }

    private function kernel(): Kernel
    {
        return $this->harness->kernel(new RequestSpy(['local' => 'qwen']), $this->ui);
    }

    private function offered(Kernel $kernel): array
    {
        return $kernel->tools->available(RunPolicy::primary(Kit::agent(), 'yolo'));
    }
}
