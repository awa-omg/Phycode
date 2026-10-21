<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Security\PhpAdvisory;
use PHPUnit\Framework\TestCase;

final class PhpAdvisoryTest extends TestCase
{
    public function testABranchBeforeTheSupportedOnesIsEndOfLife(): void
    {
        self::assertStringContainsString('end of life', PhpAdvisory::problem('8.1.31') ?? '');
        self::assertStringContainsString('end of life', PhpAdvisory::problem('7.4.33', ['7.4' => '7.4.99']) ?? '');
    }

    public function testTheOldestSupportedBranchIsNotEndOfLife(): void
    {
        self::assertStringContainsString('no fixed release recorded', PhpAdvisory::problem('8.2.34', []) ?? '');
    }

    public function testAReleaseOfASupportedBranchIsUnfixedWhileItsBranchIsNotRecorded(): void
    {
        self::assertStringContainsString('no fixed release recorded', PhpAdvisory::problem('8.4.26', []) ?? '');
        self::assertStringContainsString('no fixed release recorded', PhpAdvisory::problem('8.5.11', ['8.4' => '8.4.27']) ?? '');
    }

    public function testAReleaseOlderThanTheFixIsReported(): void
    {
        self::assertStringContainsString('older than 8.4.27', PhpAdvisory::problem('8.4.26', ['8.4' => '8.4.27']) ?? '');
    }

    public function testTheFixedReleaseAndNewerOnesPass(): void
    {
        self::assertNull(PhpAdvisory::problem('8.4.27', ['8.4' => '8.4.27']));
        self::assertNull(PhpAdvisory::problem('8.4.30', ['8.4' => '8.4.27']));
    }
}
