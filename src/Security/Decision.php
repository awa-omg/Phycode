<?php

declare(strict_types=1);

namespace Phycode\Security;

enum Decision: string
{
    case AllowOnce = 'once';
    case AllowSession = 'session';
    case Deny = 'deny';
}
