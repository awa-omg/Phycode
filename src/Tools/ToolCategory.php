<?php

declare(strict_types=1);

namespace Phycode\Tools;

enum ToolCategory: string
{
    case Read = 'read';
    case Write = 'write';
    case Exec = 'exec';
    case Delegate = 'delegate';
}
