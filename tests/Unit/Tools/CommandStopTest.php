<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Agent\RunPolicy;
use Phycode\Exec\ProcessRunner;
use Phycode\Support\Cancellation;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\BashTool;
use Phycode\Tools\RunCodeTool;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class CommandStopTest extends TestCase
{
    protected function tearDown(): void
    {
        Cancellation::setPoll(null);
    }

    public function testACancelledBashCommandStopsTheSleepItStarted(): void
    {
        $root = Kit::folder('bash-stop');
        $context = $this->context($root, $this->cancelWhenStarted($root));

        $result = (new BashTool())->execute(['command' => 'sleep 60 & echo $! > child.tmp; mv child.tmp child.pid; wait'], $context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('interrupted (cancelled)', $result->content);
        self::assertTrue(self::gone($root . '/child.pid'), 'the sleep 60 that bash started is gone');
    }

    public function testACancelledRunCodeProgramStopsTheProcessesItStarted(): void
    {
        if (ProcessRunner::which('python3') === null) {
            self::markTestSkipped('python3 is not installed');
        }
        $root = Kit::folder('run-code-stop');
        $context = $this->context($root, $this->cancelWhenStarted($root));
        $code = "import subprocess\nchild = subprocess.Popen(['sleep', '60'])\nwith open('child.tmp', 'w') as file:\n    file.write(str(child.pid))\n"
            . "import os\nos.replace('child.tmp', 'child.pid')\nchild.wait()\n";

        $result = (new RunCodeTool())->execute(['language' => 'python', 'code' => $code], $context);

        self::assertFalse($result->ok);
        self::assertStringContainsString('interrupted (cancelled)', $result->content);
        self::assertTrue(self::gone($root . '/child.pid'), 'the sleep 60 that the program started is gone');
    }

    private function context(string $root, Cancellation $cancel): ToolContext
    {
        return Kit::context($root, Kit::agent(), RunPolicy::primary(Kit::agent(), 'yolo'), new RecordingUi(), null, $cancel);
    }

    private function cancelWhenStarted(string $root): Cancellation
    {
        $cancel = new Cancellation();
        Cancellation::setPoll(static function () use ($cancel, $root): void {
            if (is_file($root . '/child.pid')) {
                $cancel->cancel('interrupted by the user');
            }
        });

        return $cancel;
    }

    private static function gone(string $pidFile): bool
    {
        $pid = (int) file_get_contents($pidFile);
        for ($attempt = 0; $attempt < 100 && posix_kill($pid, 0); $attempt++) {
            usleep(20000);
        }

        return !posix_kill($pid, 0);
    }
}
