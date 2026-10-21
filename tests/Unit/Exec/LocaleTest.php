<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Exec;

use Phycode\Exec\Locale;
use Phycode\Exec\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    public function testACommandRunsWithAUtf8LocaleThatTheSystemKnows(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'locale charmap 2>&1; printf "%s" "$LANG"'], sys_get_temp_dir(), 5, 4096);

        self::assertSame(0, $result->exitCode);
        self::assertStringNotContainsString('Cannot set', $result->stdout);
        self::assertStringNotContainsString('cannot change locale', $result->stdout);
        self::assertStringContainsString(Locale::variables()['LANG'], $result->stdout);
    }
}
