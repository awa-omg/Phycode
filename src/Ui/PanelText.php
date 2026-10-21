<?php

declare(strict_types=1);

namespace Phycode\Ui;

use Phycode\Support\Text;

final class PanelText
{
    public static function text(string $title, string $text): string
    {
        return $title . "\n" . rtrim($text, "\n");
    }

    public static function list(string $title, array $items, string $hint = ''): string
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[(string) ($item['category'] ?? '')][] = $item;
        }

        $lines = [$title];
        foreach ($groups as $category => $group) {
            if ((string) $category !== '') {
                $lines[] = (string) $category;
            }
            $width = max(array_map(static fn (array $item): int => mb_strlen(self::field($item, 'title', 100)), $group));
            foreach ($group as $item) {
                $name = self::field($item, 'title', 100);
                $status = self::field($item, 'status', 20);
                $badge = self::field($item, 'badge', 40);
                $line = '  ' . ($status === '' ? '' : "[{$status}] ") . $name . str_repeat(' ', $width - mb_strlen($name));
                $line .= '  ' . self::field($item, 'detail', 200) . ($badge === '' ? '' : "  ({$badge})");
                $lines[] = rtrim($line);
            }
        }
        if ($hint !== '') {
            $lines[] = '';
            $lines[] = $hint;
        }

        return implode("\n", $lines);
    }

    private static function field(array $item, string $key, int $max): string
    {
        return Text::oneLine((string) ($item[$key] ?? ''), $max);
    }
}
