<?php

declare(strict_types=1);

namespace Phycode\Skills;

use Phycode\Gentle\GentleAi;

final readonly class SkillRegistry
{
    public function __construct(
        private GentleAi $gentle = new GentleAi(),
        private ?string $configuredBin = null,
        private ?string $cwd = null,
    ) {
    }

    public function skills(?string &$reason = null): array
    {
        $bin = $this->gentle->detect($this->configuredBin);
        if ($bin === null) {
            $reason = GentleAi::NOT_INSTALLED;

            return [];
        }

        $rows = $this->gentle->skills($bin, $this->cwd, $reason);

        return array_map(
            static fn (array $row): Skill => new Skill($row['name'], $row['description'], $row['path']),
            $rows,
        );
    }

    public function stateFile(): string
    {
        return $this->gentle->stateFile();
    }
}
