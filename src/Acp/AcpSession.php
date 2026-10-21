<?php

declare(strict_types=1);

namespace Phycode\Acp;

use Phycode\Cli\ChatSession;
use Phycode\Cli\Kernel;

final readonly class AcpSession
{
    public function __construct(public Kernel $kernel, public ChatSession $chat, public AcpUi $ui)
    {
    }

    public function id(): string
    {
        return $this->chat->id();
    }
}
