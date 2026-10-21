<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tools;

use Phycode\Config\Limits;
use Phycode\Exec\CommandRunner;
use Phycode\Exec\ProcessResult;
use Phycode\Exec\ProcessRunner;
use Phycode\Security\Decision;
use Phycode\Security\Permissions;
use Phycode\Security\Workspace;
use Phycode\Session\Transcript;
use Phycode\Support\Cancellation;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\RecordingUi;
use Phycode\Tools\ChangeLog;
use Phycode\Tools\CustomTool;
use Phycode\Tools\ToolCategory;
use Phycode\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

final class CustomToolTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = Kit::folder('custom-tool');
    }

    private function program(string $code): string
    {
        $path = $this->folder . '/tool-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($path, "<?php\n{$code}\n");

        return $path;
    }

    private function context(RecordingUi $ui, ?CommandRunner $sandbox = null, ?string $mode = null): ToolContext
    {
        if ($sandbox === null) {
            return Kit::context($this->folder, Kit::agent(), Kit::policy($mode ?? 'yolo'), $ui);
        }

        return new ToolContext(
            new Workspace($this->folder),
            new Permissions($ui),
            new ProcessRunner(),
            $ui,
            Transcript::memory(),
            new Limits(),
            Kit::agent(),
            Kit::policy($mode ?? 'yolo'),
            new Cancellation(),
            'test',
            null,
            new ChangeLog(),
            $sandbox,
        );
    }

    public function testTheToolIsAnExecToolNamedAfterTheConfigEntry(): void
    {
        $tool = new CustomTool('lint', 'Lint the project', [PHP_BINARY, '-v'], false);

        self::assertSame('custom__lint', $tool->name());
        self::assertSame('Lint the project', $tool->description());
        self::assertSame(ToolCategory::Exec, $tool->category());
        self::assertTrue($tool->parameters()['additionalProperties']);
    }

    public function testTheSummaryShowsTheCommandAndTheArgumentsSoTheApprovalSaysWhatRuns(): void
    {
        $tool = new CustomTool('lint', 'Lint', ['npm', 'run', 'lint'], false);

        self::assertSame('custom__lint: npm run lint {"path":"src"}', $tool->summarize(['path' => 'src']));
        self::assertSame('custom__lint: npm run lint', $tool->summarize([]));
    }

    public function testWithStdinSetTheArgumentsArrivesAsJson(): void
    {
        $program = $this->program('echo stream_get_contents(STDIN);');
        $tool = new CustomTool('echo', 'Echo', [PHP_BINARY, $program], true);

        $result = $tool->execute(['path' => 'src', 'depth' => 2], $this->context(new RecordingUi()));

        self::assertTrue($result->ok);
        self::assertStringContainsString("exit code: 0\n" . '{"path":"src","depth":2}', $result->content);
    }

    public function testWithoutStdinTheProgramGetsNothingOnItsInput(): void
    {
        $program = $this->program('echo strlen(stream_get_contents(STDIN));');
        $tool = new CustomTool('count', 'Count', [PHP_BINARY, $program], false);

        $result = $tool->execute(['path' => 'src'], $this->context(new RecordingUi()));

        self::assertTrue($result->ok);
        self::assertStringContainsString("exit code: 0\n0", $result->content);
    }

    public function testANonZeroExitIsAFailureWithTheCode(): void
    {
        $tool = new CustomTool('fail', 'Fail', [PHP_BINARY, $this->program('fwrite(STDERR, "bad input"); exit(3);')], false);

        $result = $tool->execute([], $this->context(new RecordingUi()));

        self::assertFalse($result->ok);
        self::assertStringContainsString('exit code: 3', $result->content);
        self::assertStringContainsString('bad input', $result->content);
    }

    public function testTheCommandIsNeverRunThroughAShell(): void
    {
        $tool = new CustomTool('print', 'Print', ['printf', '%s', '$(echo injected)'], false);

        $result = $tool->execute([], $this->context(new RecordingUi()));

        self::assertTrue($result->ok);
        self::assertStringContainsString('$(echo injected)', $result->content);
        self::assertStringNotContainsString("exit code: 0\ninjected", $result->content);
    }

    public function testAProgramThatIsNotThereIsAFailure(): void
    {
        $tool = new CustomTool('missing', 'Missing', ['phycode-no-such-custom-program'], false);

        $result = $tool->execute([], $this->context(new RecordingUi()));

        self::assertFalse($result->ok);
        self::assertSame('cannot start phycode-no-such-custom-program: it is not on PATH', $result->content);
    }

    public function testAContainerSandboxCannotRunIt(): void
    {
        $sandbox = new class implements CommandRunner {
            public function run(array $argv, string $cwd, int $timeout, int $maxBytes, ?Cancellation $cancel = null, array $env = []): ProcessResult
            {
                return new ProcessResult(0, '', '', null, 0.0);
            }
        };
        $tool = new CustomTool('lint', 'Lint', [PHP_BINARY, '-v'], false);

        try {
            $tool->validate([], $this->context(new RecordingUi(), $sandbox));
            self::fail('a container sandbox must refuse the custom tool');
        } catch (PhycodeException $error) {
            self::assertSame('custom__lint runs on the host, and this run uses a container sandbox, so it cannot run here', $error->getMessage());
        }
    }

    public function testEachCallIsApprovedLikeBash(): void
    {
        $ui = new RecordingUi();
        $ui->decisions = [Decision::Deny];
        $tool = new CustomTool('lint', 'Lint', [PHP_BINARY, '-v'], false);

        $reason = (new Permissions($ui))->check($tool, $this->context($ui, null, 'ask'), $tool->summarize([]));

        self::assertSame('the user denied this action', $reason);
        self::assertSame([['confirm', 'custom__lint', 'build']], array_values(array_filter($ui->events, static fn (array $event): bool => $event[0] === 'confirm')));
    }

    public function testInYoloTheCallRunsWithoutAnApproval(): void
    {
        $ui = new RecordingUi();
        $tool = new CustomTool('lint', 'Lint', [PHP_BINARY, '-v'], false);

        self::assertNull((new Permissions($ui))->check($tool, $this->context($ui, null, 'yolo'), $tool->summarize([])));
        self::assertSame([], $ui->events);
    }
}
