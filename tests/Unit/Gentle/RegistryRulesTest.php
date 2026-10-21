<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Gentle\RegistryRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryRulesTest extends TestCase
{
    public static function names(): iterable
    {
        yield 'the shared folder' => ['_shared', true];
        yield 'the registry skill' => ['skill-registry', true];
        yield 'an sdd skill' => ['sdd-init', true];
        yield 'a verify skill of the retired SDD' => ['sdd-verify', true];
        yield 'a name that only contains sdd' => ['my-sdd-helper', false];
        yield 'the bare prefix without a dash' => ['sdd', false];
        yield 'an ordinary skill' => ['go-testing', false];
        yield 'a name that starts with an underscore' => ['_draft', false];
    }

    #[DataProvider('names')]
    public function testOnlyTheRegistryExclusionsAreLeftOut(string $name, bool $excluded): void
    {
        self::assertSame($excluded, RegistryRules::excluded($name));
    }
}
