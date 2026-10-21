<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Cli;

use Phycode\Support\Vault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeysCommandTest extends TestCase
{
    private const PROFILE = ['base_url' => 'http://127.0.0.1:9', 'model' => 'qwen'];

    public function testSetReadsTheKeyFromThePipeAndStoresItWithoutPrintingIt(): void
    {
        $key = 'sk-test-PIPE-' . bin2hex(random_bytes(6));

        $run = PhycodeRun::run(['keys', 'set', 'cloud'], self::PROFILE, $key . "\n");

        self::assertSame(0, $run->status, $run->stderr);
        self::assertSame('', $run->stdout);
        self::assertSame("key stored for profile cloud\n", $run->stderr);
        self::assertStringNotContainsString($key, $run->stdout . $run->stderr);
        $folder = $run->home . '/config/phycode';
        self::assertSame(0600, fileperms("{$folder}/master.key") & 0777);
        self::assertSame(0600, fileperms("{$folder}/keys.json") & 0777);
        self::assertStringNotContainsString($key, (string) file_get_contents("{$folder}/keys.json"));
        self::assertSame($key, (new Vault($folder))->get('cloud'));
    }

    public function testListShowsTheProfileNamesAndNeverAKey(): void
    {
        $home = PhycodeRun::run(['keys', 'set', 'cloud'], self::PROFILE, 'sk-test-LIST-123456')->home;
        PhycodeRun::run(['keys', 'set', 'local'], self::PROFILE, "sk-test-LOCAL-123456\n", [], [], $home);

        $run = PhycodeRun::run(['keys', 'list'], self::PROFILE, '', [], [], $home);

        self::assertSame(0, $run->status, $run->stderr);
        self::assertSame("cloud\nlocal\n", $run->stdout);
        self::assertStringNotContainsString('sk-test', $run->stdout . $run->stderr);
    }

    public function testRemoveDeletesTheKeyAndRemovingItAgainIsAnError(): void
    {
        $home = PhycodeRun::run(['keys', 'set', 'cloud'], self::PROFILE, "sk-test-RM-123456\n")->home;

        $removed = PhycodeRun::run(['keys', 'rm', 'cloud'], self::PROFILE, '', [], [], $home);
        self::assertSame(0, $removed->status, $removed->stderr);
        self::assertSame("key removed for profile cloud\n", $removed->stderr);

        $list = PhycodeRun::run(['keys', 'list'], self::PROFILE, '', [], [], $home);
        self::assertSame(0, $list->status);
        self::assertSame('', $list->stdout);
        self::assertSame("no keys are stored\n", $list->stderr);

        $again = PhycodeRun::run(['keys', 'rm', 'cloud'], self::PROFILE, '', [], [], $home);
        self::assertSame(1, $again->status);
        self::assertSame("phycode: no key is stored for profile cloud\n", $again->stderr);
    }

    public static function misuses(): iterable
    {
        yield 'no action' => [[]];
        yield 'set without a profile' => [['set']];
        yield 'set with two profiles' => [['set', 'cloud', 'local']];
        yield 'an action that does not exist' => [['show', 'cloud']];
        yield 'list with a profile' => [['list', 'cloud']];
        yield 'rm without a profile' => [['rm']];
        yield 'an empty profile name' => [['set', '']];
    }

    #[DataProvider('misuses')]
    public function testAMisuseOfTheCommandExitsWithTwo(array $arguments): void
    {
        $run = PhycodeRun::run(['keys', ...$arguments], self::PROFILE, "sk-test-USAGE-123456\n");

        self::assertSame(2, $run->status, $run->stderr);
        self::assertSame("usage: phycode keys set <profile> | phycode keys list | phycode keys rm <profile>\n", $run->stderr);
        self::assertSame('', $run->stdout);
    }

    public function testAnEmptyKeyIsAnErrorAndNothingIsStored(): void
    {
        $run = PhycodeRun::run(['keys', 'set', 'cloud'], self::PROFILE, "  \n");

        self::assertSame(1, $run->status);
        self::assertSame("phycode: no API key was given\n", $run->stderr);
        self::assertFileDoesNotExist($run->home . '/config/phycode/keys.json');
    }

    public function testAKeyOfTwoLinesIsRefusedWithoutBeingShown(): void
    {
        $run = PhycodeRun::run(['keys', 'set', 'cloud'], self::PROFILE, "sk-test-first\nsk-test-second\n");

        self::assertSame(1, $run->status);
        self::assertSame("phycode: an API key is one line of text\n", $run->stderr);
        self::assertStringNotContainsString('sk-test', $run->stdout . $run->stderr);
        self::assertFileDoesNotExist($run->home . '/config/phycode/master.key');
    }
}
