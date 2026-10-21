<?php

declare(strict_types=1);

namespace Phycode\Acp;

use Phycode\Support\PhycodeException;

final class AcpError extends PhycodeException
{
    public const INVALID_PARAMS = -32602;
    public const METHOD_NOT_FOUND = -32601;
    public const INTERNAL = -32603;

    public function __construct(string $message, public readonly int $rpcCode = self::INTERNAL)
    {
        parent::__construct($message);
    }
}
