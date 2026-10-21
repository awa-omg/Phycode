<?php

declare(strict_types=1);

namespace Phycode\Skills;

use Phycode\Config\Config;
use Phycode\Gentle\GentleAi;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;

final class SkillIndex
{
    public const LIMIT = 40;
    public const DESCRIPTION_CHARS = 100;

    private const HEADER = 'Available skills (load one with skill(name) when its description matches the task; unload it with skill(name, unload=true) when you no longer need it):';

    private array $catalog = [];
    private array $notes = [];
    private array $entries = [];
    private bool $stale = true;

    public function __construct(
        private readonly string $cwd,
        private readonly ?string $home,
        private readonly string $userFolder,
    ) {
    }

    public function refresh(Config $config): void
    {
        $this->notes = [];
        $gentle = new GentleAi();
        $bin = null;
        try {
            $bin = $config->gentleAiBin();
        } catch (PhycodeException $error) {
            $this->notes[] = $error->getMessage();
            $gentle = null;
        }
        $this->catalog = SkillCatalog::discover($this->cwd, $this->home, $gentle, $bin, $this->notes, $this->userFolder);

        $this->entries = [];
        $titles = [];
        foreach ($this->catalog as $item) {
            if (SkillCatalog::projectPath($item['path'], $this->cwd, $this->home) || isset($titles[$item['title']])) {
                continue;
            }
            $titles[$item['title']] = true;
            $description = $item['category'] === SkillCatalog::GENTLE_AI
                ? $item['detail']
                : (SkillCatalog::summary($item['path'])['description'] ?? '');
            $this->entries[] = ['category' => $item['category'], 'title' => $item['title'], 'description' => $description];
        }
        $this->stale = false;
    }

    public function invalidate(): void
    {
        $this->stale = true;
    }

    public function stale(): bool
    {
        return $this->stale;
    }

    public function catalog(): array
    {
        return $this->catalog;
    }

    public function notes(): array
    {
        return $this->notes;
    }

    public function block(): ?string
    {
        if ($this->entries === []) {
            return null;
        }
        $lines = [self::HEADER];
        foreach (array_slice($this->entries, 0, self::LIMIT) as $entry) {
            $own = $entry['category'] === SkillCatalog::PHYCODE ? ' [own]' : '';
            $description = Text::oneLine($entry['description'], self::DESCRIPTION_CHARS);
            $lines[] = "- {$entry['title']}{$own}" . ($description === '' ? '' : ": {$description}");
        }
        $more = count($this->entries) - self::LIMIT;
        if ($more > 0) {
            $lines[] = "+{$more} more (see /skill)";
        }

        return implode("\n", $lines);
    }
}
