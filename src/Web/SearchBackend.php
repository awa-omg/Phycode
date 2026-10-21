<?php

declare(strict_types=1);

namespace Phycode\Web;

interface SearchBackend
{
    public function search(string $query, int $limit): array;
}
