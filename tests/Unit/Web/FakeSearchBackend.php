<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\SearchBackend;
use Phycode\Web\WebException;

final class FakeSearchBackend implements SearchBackend
{
    public array $asked = [];

    public array $limits = [];

    public function __construct(private readonly array $answers)
    {
    }

    public function search(string $query, int $limit): array
    {
        $this->asked[] = $query;
        $this->limits[] = $limit;
        $answer = $this->answers[$query] ?? [];
        if ($answer instanceof WebException) {
            throw $answer;
        }

        return array_slice($answer, 0, $limit);
    }

    public static function hit(string $url, string $title = '', string $snippet = ''): array
    {
        return ['title' => $title === '' ? $url : $title, 'url' => $url, 'snippet' => $snippet];
    }
}
