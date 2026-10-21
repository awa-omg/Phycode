<?php

declare(strict_types=1);

namespace Phycode\Llm;

interface ClientProvider
{
    public function forProfile(string $name, ?string $model = null, ?string $thinking = null): ChatClient;

    public function toolMode(string $name, ?string $model = null, ?string $thinking = null): string;
}
