<?php

declare(strict_types=1);

namespace Phycode\Mcp;

use Phycode\Support\PhycodeException;

final class McpRpcException extends PhycodeException
{
    public function __construct(string $message, public readonly ?int $rpcCode = null, public readonly mixed $data = null, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
