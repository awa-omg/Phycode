<?php

declare(strict_types=1);

namespace Phycode\Agent;

enum OutcomeStatus: string
{
    case Completed = 'completed';
    case Budget = 'budget';
    case Cancelled = 'cancelled';
    case Stuck = 'stuck';
    case Error = 'error';
}
