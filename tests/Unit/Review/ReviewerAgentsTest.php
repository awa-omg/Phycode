<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Review;

use Phycode\Agent\AgentRegistry;
use Phycode\Review\ReviewLens;
use Phycode\Tests\Support\Kit;
use PHPUnit\Framework\TestCase;

final class ReviewerAgentsTest extends TestCase
{
    public function testEachLensHasAReadOnlyBuiltInReviewer(): void
    {
        $registry = AgentRegistry::load(Kit::folder('reviewer-project'), Kit::folder('reviewer-root'), '/nonexistent', '/nonexistent', Kit::builtinAgents());

        foreach (ReviewLens::cases() as $lens) {
            $agent = $registry->get($lens->agent());
            self::assertNotNull($agent, "{$lens->agent()} is defined");
            self::assertTrue($agent->isSubagent());
            self::assertSame('builtin', $agent->source);
            self::assertSame(['read', 'list', 'grep'], $agent->tools);
            self::assertSame(['write', 'exec'], $agent->deniedCategories);
            self::assertSame([], $agent->warnings);
        }
    }

    public function testTheRefuterIsReadOnlyToo(): void
    {
        $registry = AgentRegistry::load(Kit::folder('reviewer-project'), Kit::folder('reviewer-root'), '/nonexistent', '/nonexistent', Kit::builtinAgents());

        $refuter = $registry->get('reviewer-refuter');
        self::assertNotNull($refuter);
        self::assertSame(['read', 'list', 'grep'], $refuter->tools);
        self::assertSame(['write', 'exec'], $refuter->deniedCategories);
        self::assertSame([], $refuter->warnings);
    }
}
