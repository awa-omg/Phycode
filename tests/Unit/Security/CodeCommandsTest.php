<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\CodeCommands;
use Phycode\Security\HardLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeCommandsTest extends TestCase
{
    public static function destructive(): iterable
    {
        yield 'os.system' => ['python', 'import os' . "\n" . 'os.system("rm -rf /")'];
        yield 'subprocess with a list' => ['python', "import subprocess\nsubprocess.run(['rm', '-rf', '/home'], check=True)"];
        yield 'subprocess with shell' => ['python', "subprocess.call('sudo rm -rf --no-preserve-root /tmp/x', shell=True)"];
        yield 'an f-string' => ['python', "os.popen(f'rm -rf /usr {x}')"];
        yield 'shutil.rmtree of the root' => ['python', "import shutil\nshutil.rmtree('/')"];
        yield 'rmtree of the home' => ['python', "from shutil import rmtree\nrmtree(os.path.expanduser('~'))"];
        yield 'rmtree of Path.home()' => ['python', "shutil.rmtree(Path.home(), ignore_errors=True)"];
        yield 'execSync' => ['javascript', "require('child_process').execSync('rm -rf ~/')"];
        yield 'spawn with a list' => ['javascript', "const { spawn } = require('child_process'); spawn('rm', ['-rf', '/etc'])"];
        yield 'fs.rmSync' => ['javascript', "fs.rmSync('/usr', { recursive: true, force: true })"];
        yield 'fs.promises.rm of the home' => ['javascript', "await fs.promises.rm(os.homedir(), { recursive: true })"];
        yield 'a zx template' => ['javascript', 'await $`rm -rf /`'];
    }

    #[DataProvider('destructive')]
    public function testADestructiveCallInTheProgramIsFoundAndRefused(string $language, string $code): void
    {
        $refused = array_filter(CodeCommands::of($language, $code), static fn (string $command): bool => HardLine::reason('run_code: ' . $command, sys_get_temp_dir()) !== null);

        self::assertNotSame([], $refused, $code);
    }

    public static function ordinary(): iterable
    {
        yield 'a print of the text' => ['python', 'print("rm -rf /")'];
        yield 'a command in the project' => ['python', "subprocess.run(['rm', '-rf', 'build'])"];
        yield 'rmtree of a project folder' => ['python', "shutil.rmtree('dist')"];
        yield 'a test run' => ['javascript', "execSync('npm test', { stdio: 'inherit' })"];
        yield 'rmSync of a temporary folder' => ['javascript', "fs.rmSync('/tmp/phycode-code-test', { recursive: true })"];
        yield 'a string that only names rmtree' => ['python', 'doc = "call shutil.rmtree(path) to delete"'];
    }

    #[DataProvider('ordinary')]
    public function testAnOrdinaryProgramIsNotRefused(string $language, string $code): void
    {
        $refused = array_filter(CodeCommands::of($language, $code), static fn (string $command): bool => HardLine::reason('run_code: ' . $command, sys_get_temp_dir()) !== null);

        self::assertSame([], array_values($refused), $code);
    }

    public function testAnUnknownLanguageGivesNothing(): void
    {
        self::assertSame([], CodeCommands::of('ruby', 'system("rm -rf /")'));
    }
}
