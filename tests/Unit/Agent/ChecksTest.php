<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Agent;

use Phycode\Agent\Checks;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ChecksTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(Kit::folder('checks'));
        Kit::write($this->root . '/ok.php', "<?php\necho 1;\n");
        Kit::write($this->root . '/bad.php', "<?php\necho 1\n}\n");
        Kit::write($this->root . '/notes.md', "# notes\n");
        Kit::write($this->root . '/fmt.php', "<?php\nfile_put_contents(__DIR__ . '/calls.log', json_encode(array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\nif (!is_file(\$argv[1])) { exit(3); }\nfile_put_contents(\$argv[1], strtoupper(file_get_contents(\$argv[1])));\n");
        Kit::write($this->root . '/noop.php', "<?php\nfile_put_contents(__DIR__ . '/order.log', 'noop ' . basename(\$argv[1]) . \"\\n\", FILE_APPEND);\n");
    }

    private function check(string $match, string $command, string $kind = 'check', string $name = 'c', int $timeout = 10): array
    {
        return ['name' => $name, 'match' => $match, 'command' => $command, 'kind' => $kind, 'timeout' => $timeout];
    }

    private function php(string $arguments): string
    {
        return "'" . PHP_BINARY . "' {$arguments}";
    }

    public function testACheckThatPassesIsOneLineAndOneThatFailsShowsWhatItFound(): void
    {
        $checks = new Checks([$this->check('*.php', $this->php('-l {file}'), 'check', 'lint')]);

        self::assertSame(['check lint: ok'], $checks->after('ok.php', $this->root));

        $notes = $checks->after('bad.php', $this->root);
        self::assertCount(1, $notes);
        self::assertStringStartsWith('check lint failed on bad.php (exit 255):', $notes[0]);
        self::assertStringContainsString('bad.php', $notes[0]);
        self::assertMatchesRegularExpression('/syntax error|Parse error/i', $notes[0]);
    }

    public function testOnlyTheChecksThatMatchTheFileRunByItsPathOrItsName(): void
    {
        $checks = new Checks([
            $this->check('*.php', $this->php('-l {file}'), 'check', 'php'),
            $this->check('*.md', $this->php('-r "echo 1;" {file}'), 'check', 'md'),
            $this->check('src/*.js', $this->php('-v'), 'check', 'js'),
        ]);
        Kit::write($this->root . '/src/deep/a.php', "<?php\n");

        self::assertSame(['check php: ok'], $checks->after('src/deep/a.php', $this->root), 'a glob on the name matches in any folder');
        self::assertSame(['check md: ok'], $checks->after('notes.md', $this->root));
        self::assertSame([], $checks->after('missing.php', $this->root), 'a file that is not there is not checked');
        self::assertSame([], (new Checks())->after('ok.php', $this->root));
    }

    public function testAFormatterRewritesTheFileAndTheModelIsToldToReadItAgain(): void
    {
        $checks = new Checks([$this->check('*.md', $this->php($this->root . '/fmt.php {file}'), 'format', 'upper')]);

        $notes = $checks->after('notes.md', $this->root);

        self::assertSame("# NOTES\n", file_get_contents($this->root . '/notes.md'));
        self::assertSame(['format upper rewrote notes.md; read it again before the next edit, because the text changed'], $notes);
        self::assertSame(['format upper: notes.md was already formatted'], $checks->after('notes.md', $this->root), 'a second run changes nothing');
    }

    public function testFormattersRunBeforeChecksWhateverTheOrderOfTheConfig(): void
    {
        $order = $this->root . '/order.log';
        $checks = new Checks([
            $this->check('*.md', $this->php($this->root . '/noop.php {file}'), 'check', 'second'),
            $this->check('*.md', $this->php($this->root . '/fmt.php {file}'), 'format', 'first'),
        ]);

        $notes = $checks->after('notes.md', $this->root);

        self::assertStringStartsWith('format first', $notes[0]);
        self::assertSame('check second: ok', $notes[1]);
        self::assertFileExists($order);
    }

    public function testAPathThatStartsWithADashIsNotAnOptionOfTheProgram(): void
    {
        Kit::write($this->root . '/-odd.md', "x\n");
        $checks = new Checks([$this->check('*.md', $this->php($this->root . '/fmt.php {file}'), 'format', 'upper')]);

        $checks->after('-odd.md', $this->root);

        self::assertSame([['./-odd.md']], array_map(static fn (string $line): array => json_decode($line, true), array_slice(file($this->root . '/calls.log') ?: [], 0, 1)));
        self::assertSame("X\n", file_get_contents($this->root . '/-odd.md'));
    }

    public function testAFormatterThatFailsAndAProgramThatIsNotThereAreReportedNotRaised(): void
    {
        $failing = new Checks([$this->check('*.md', $this->php($this->root . '/fmt.php ./nothing-here.txt'), 'format', 'upper')]);
        self::assertStringStartsWith('format upper failed on notes.md (exit 3)', $failing->after('notes.md', $this->root)[0]);

        $missing = new Checks([$this->check('*.php', 'no-such-program-phycode {file}', 'check', 'ghost')]);
        self::assertStringStartsWith('check ghost could not run:', $missing->after('ok.php', $this->root)[0]);
    }

    public function testACheckThatRunsPastItsTimeLimitIsStoppedAndReported(): void
    {
        $checks = new Checks([$this->check('*.php', $this->php('-r "sleep(5);" {file}'), 'check', 'slow', 1)]);
        $started = microtime(true);

        $notes = $checks->after('ok.php', $this->root);

        self::assertSame(['check slow timed out after 1 seconds on ok.php'], $notes);
        self::assertLessThan(4.5, microtime(true) - $started);
    }
}
