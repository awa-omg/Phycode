<?php

declare(strict_types=1);

namespace Phycode\Agent;

use Phycode\Support\PhycodeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class Frontmatter
{
    public static function split(string $document, string $path): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $document) ?: [];
        if (($lines[0] ?? '') !== '---') {
            return [[], $document];
        }

        $end = null;
        for ($index = 1, $count = count($lines); $index < $count; $index++) {
            if (rtrim($lines[$index]) === '---') {
                $end = $index;
                break;
            }
        }
        if ($end === null) {
            throw new PhycodeException("{$path}: the frontmatter starts with --- but never ends");
        }

        try {
            $data = Yaml::parse(implode("\n", array_slice($lines, 1, $end - 1))) ?? [];
        } catch (ParseException $error) {
            throw new PhycodeException("{$path}: invalid YAML in the frontmatter: " . $error->getMessage());
        }
        if (!is_array($data)) {
            throw new PhycodeException("{$path}: the frontmatter must be a set of fields");
        }

        return [$data, implode("\n", array_slice($lines, $end + 1))];
    }
}
