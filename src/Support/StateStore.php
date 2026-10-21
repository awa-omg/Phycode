<?php

declare(strict_types=1);

namespace Phycode\Support;

final class StateStore
{
    private const RECENT_LIMIT = 10;
    private const MODEL_ID = '#^[A-Za-z0-9._:/@+-]{1,160}$#D';

    public function __construct(private readonly string $file)
    {
    }

    public static function defaultFile(): string
    {
        $home = rtrim((string) (getenv('HOME') ?: '/root'), '/');
        $state = rtrim((string) (getenv('XDG_STATE_HOME') ?: $home . '/.local/state'), '/');

        return $state . '/phycode/state.json';
    }

    public function favorites(): array
    {
        return $this->read()['favorites'];
    }

    public function recents(): array
    {
        return $this->read()['recents'];
    }

    public function addRecent(string $profile, string $model): void
    {
        $state = $this->read();
        $recents = [['profile' => $profile, 'model' => $model], ...$state['recents']];
        $state['recents'] = array_slice(self::unique($recents), 0, self::RECENT_LIMIT);
        $this->write($state);
    }

    public function setFavorite(string $profile, string $model, bool $on): void
    {
        $state = $this->read();
        $pair = ['profile' => $profile, 'model' => $model];
        if ($on) {
            if (!in_array($pair, $state['favorites'], true)) {
                $state['favorites'][] = $pair;
            }
        } else {
            $state['favorites'] = array_values(array_filter($state['favorites'], static fn (array $item): bool => $item !== $pair));
        }
        $this->write($state);
    }

    public function customModels(string $profile): array
    {
        $models = [];
        foreach ($this->read()['custom'] as $pair) {
            if ($pair['profile'] === $profile) {
                $models[] = $pair['model'];
            }
        }

        return $models;
    }

    public function addCustomModel(string $profile, string $model): void
    {
        self::checkModelId($model);
        $state = $this->read();
        $state['custom'] = self::unique([...$state['custom'], ['profile' => $profile, 'model' => $model]]);
        $this->write($state);
    }

    public function removeCustomModel(string $profile, string $model): void
    {
        self::checkModelId($model);
        $state = $this->read();
        $pair = ['profile' => $profile, 'model' => $model];
        $state['custom'] = array_values(array_filter($state['custom'], static fn (array $item): bool => $item !== $pair));
        $this->write($state);
    }

    private function read(): array
    {
        $data = Json::object((string) (is_link($this->file) ? false : @file_get_contents($this->file))) ?? [];

        return [
            'favorites' => self::unique(self::pairs($data['favorites'] ?? [])),
            'recents' => array_slice(self::unique(self::pairs($data['recents'] ?? [])), 0, self::RECENT_LIMIT),
            'custom' => self::unique(array_filter(
                self::pairs($data['custom'] ?? []),
                static fn (array $pair): bool => preg_match(self::MODEL_ID, $pair['model']) === 1,
            )),
        ];
    }

    private static function checkModelId(string $model): void
    {
        if (preg_match(self::MODEL_ID, $model) !== 1) {
            throw new PhycodeException('a custom model id may use letters, digits and . _ : / @ + -, and is 1 to 160 characters long');
        }
    }

    private function write(array $state): void
    {
        if (is_link($this->file)) {
            throw new PhycodeException("cannot save the state: {$this->file} is a symbolic link");
        }
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PhycodeException("cannot create state folder {$directory}");
        }
        Files::writeAtomic($this->file, Json::encode($state, true), 0600);
    }

    private static function pairs(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }
        $pairs = [];
        foreach ($entries as $entry) {
            $profile = is_array($entry) ? ($entry['profile'] ?? null) : null;
            $model = is_array($entry) ? ($entry['model'] ?? null) : null;
            if (is_string($profile) && is_string($model) && $profile !== '' && $model !== '') {
                $pairs[] = ['profile' => $profile, 'model' => $model];
            }
        }

        return $pairs;
    }

    private static function unique(array $pairs): array
    {
        $seen = [];
        $unique = [];
        foreach ($pairs as $pair) {
            if (!isset($seen[$pair['profile']][$pair['model']])) {
                $seen[$pair['profile']][$pair['model']] = true;
                $unique[] = $pair;
            }
        }

        return $unique;
    }
}
