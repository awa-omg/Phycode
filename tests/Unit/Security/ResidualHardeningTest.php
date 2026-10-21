<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Security;

use Phycode\Llm\Ollama\OllamaClient;
use Phycode\Skills\SkillWriter;
use Phycode\Support\Text;
use Phycode\Tests\Support\Kit;
use Phycode\Tools\SkillManageTool;
use PHPUnit\Framework\TestCase;

final class ResidualHardeningTest extends TestCase
{
    public function testAnOllamaErrorDoesNotCarryTerminalSequences(): void
    {
        $method = new \ReflectionMethod(OllamaClient::class, 'httpError');
        $error = $method->invoke(null, 500, '{"error":"boom \u001b]52;c;aGk=\u0007 \u001b[2J done"}');

        self::assertStringNotContainsString("\x1b", $error->getMessage());
        self::assertStringContainsString('boom', $error->getMessage());
    }

    public function testAOneLineSummaryKeepsItsTextWhenATextIsNotUtf8(): void
    {
        self::assertSame('ok ? bad', Text::oneLine("ok \xff bad"));
    }

    public function testSkillsAreAskedAboutUnlessTheAutonomyIsAuto(): void
    {
        self::assertFalse((new SkillManageTool(new SkillWriter(Kit::folder('skills-default')), true))->autoApproved());
        self::assertTrue((new SkillManageTool(new SkillWriter(Kit::folder('skills-auto')), true, 'auto'))->autoApproved());
    }
}
