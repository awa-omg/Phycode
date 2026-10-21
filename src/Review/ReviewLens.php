<?php

declare(strict_types=1);

namespace Phycode\Review;

enum ReviewLens: string
{
    case Risk = 'risk';
    case Resilience = 'resilience';
    case Readability = 'readability';
    case Reliability = 'reliability';

    public function agent(): string
    {
        return 'reviewer-' . $this->value;
    }
}
