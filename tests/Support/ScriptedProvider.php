<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

use Phycode\Llm\ChatClient;
use Phycode\Llm\ClientProvider;

final class ScriptedProvider implements ClientProvider
{
    public function __construct(private array $clients, private string $mode = 'native')
    {
    }

    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient
    {
        return $this->clients[$name];
    }

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string
    {
        return $this->mode;
    }
}
