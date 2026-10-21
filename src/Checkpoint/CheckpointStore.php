<?php

declare(strict_types=1);

namespace Phycode\Checkpoint;

use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Support\Files;
use Phycode\Support\Json;

final class CheckpointStore
{
    private const TURN_ID = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/';
    private const DIGEST = '/\A[0-9a-f]{64}\z/';

    private bool $opening = false;
    private ?string $begun = null;

    public function __construct(private readonly Workspace $workspace)
    {
    }

    public function begin(string $turnId): void
    {
        $this->opening = true;
        self::checkTurnId($turnId);
        $index = $this->load();
        foreach ($index['turns'] as $turn) {
            if ($turn['id'] === $turnId) {
                throw new CheckpointException("turn {$turnId} already has a checkpoint");
            }
        }
        $this->opening = false;
        $this->begun = $turnId;
    }

    public function record(string $path, ?string $previous): void
    {
        if ($this->opening) {
            throw new CheckpointException('this turn has no checkpoint, because its begin did not finish');
        }
        $index = $this->load();
        if ($this->begun === null) {
            return;
        }
        $position = $this->current($index);
        if ($position !== null && $index['turns'][$position]['undone']) {
            return;
        }
        $key = $this->workspace->relative($this->entry($path));
        if (preg_match('//u', $key) !== 1) {
            throw new CheckpointException('cannot checkpoint a path that is not valid UTF-8, because the index could not keep it exactly');
        }
        $dropped = false;
        if ($position === null) {
            $kept = array_values(array_filter($index['turns'], static fn (array $turn): bool => !$turn['undone']));
            $dropped = count($kept) !== count($index['turns']);
            $index['turns'] = [...$kept, ['id' => $this->begun, 'undone' => false, 'files' => []]];
            $position = count($index['turns']) - 1;
        }
        foreach ($index['turns'][$position]['files'] as $known) {
            if ($known['path'] === $key) {
                return;
            }
        }
        $index['turns'][$position]['files'][] = [
            'path' => $key,
            'before' => $previous === null ? null : $this->store($previous),
            'after' => null,
        ];
        $this->save($index);
        if ($dropped) {
            $this->collect($index);
        }
    }

    public function changedFiles(string $turnId): array
    {
        $index = $this->load();
        $turn = $index['turns'][$this->position($index, $turnId)];

        return array_map(static fn (array $file): string => $file['path'], $turn['files']);
    }

    public function turns(): array
    {
        return array_map(
            static fn (array $turn): array => ['id' => $turn['id'], 'undone' => $turn['undone']],
            $this->load()['turns'],
        );
    }

    public function turnContents(string $turnId): array
    {
        $index = $this->load();
        $turn = $index['turns'][$this->position($index, $turnId)];
        $contents = [];
        foreach ($turn['files'] as $file) {
            if ($turn['undone']) {
                $after = $file['after'] === null ? null : $this->read($file['after']);
            } else {
                $absolute = $this->entry($file['path']);
                $after = is_file($absolute) ? $this->contents($absolute) : null;
            }
            $contents[] = [
                'path' => $file['path'],
                'before' => $file['before'] === null ? null : $this->read($file['before']),
                'after' => $after,
            ];
        }

        return $contents;
    }

    public function undo(string $turnId): void
    {
        $index = $this->load();
        $position = $this->position($index, $turnId);
        if ($index['turns'][$position]['undone']) {
            throw new CheckpointException("turn {$turnId} is already undone");
        }
        $latest = $this->lastApplied($index);
        if ($latest !== $position) {
            throw new CheckpointException("turn {$turnId} is not the latest change; undo {$index['turns'][$latest]['id']} first, or rewind to {$turnId}");
        }
        $this->revert($index, $position);
    }

    public function redo(string $turnId): void
    {
        $index = $this->load();
        $position = $this->position($index, $turnId);
        if (!$index['turns'][$position]['undone']) {
            throw new CheckpointException("turn {$turnId} is not undone, so there is nothing to redo");
        }
        $next = $this->firstUndone($index);
        if ($next !== $position) {
            throw new CheckpointException("turn {$turnId} is not the next to redo; redo {$index['turns'][$next]['id']} first");
        }
        $steps = [];
        foreach ($index['turns'][$position]['files'] as $file) {
            $steps[] = [
                'absolute' => $this->entry($file['path']),
                'content' => $file['after'] === null ? null : $this->read($file['after']),
            ];
        }
        foreach ($steps as $step) {
            $this->restore($step['absolute'], $step['content']);
        }
        $index['turns'][$position]['undone'] = false;
        $this->save($index);
    }

    public function rewind(string $turnId): void
    {
        $index = $this->load();
        $position = $this->position($index, $turnId);
        while (($latest = $this->lastApplied($index)) !== null && $latest >= $position) {
            $index = $this->revert($index, $latest);
        }
    }

    private function revert(array $index, int $position): array
    {
        $steps = [];
        foreach ($index['turns'][$position]['files'] as $file) {
            $absolute = $this->entry($file['path']);
            $steps[] = [
                'absolute' => $absolute,
                'before' => $file['before'] === null ? null : $this->read($file['before']),
                'current' => is_file($absolute) ? $this->contents($absolute) : null,
            ];
        }
        foreach ($steps as $number => $step) {
            $index['turns'][$position]['files'][$number]['after'] = $step['current'] === null ? null : $this->store($step['current']);
        }
        foreach ($steps as $step) {
            $this->restore($step['absolute'], $step['before']);
        }
        $index['turns'][$position]['undone'] = true;
        $this->save($index);

        return $index;
    }

    private function entry(string $path): string
    {
        $absolute = $this->workspace->resolveEntry($path);
        if (is_link($absolute)) {
            throw new PathException("{$path} is a symbolic link; checkpoints cover regular files only");
        }
        if (file_exists($absolute) && !is_file($absolute)) {
            throw new CheckpointException("{$path} is not a regular file; checkpoints cover regular files only");
        }

        return $absolute;
    }

    private function restore(string $absolute, ?string $content): void
    {
        if ($content === null) {
            if (is_file($absolute) && !@unlink($absolute)) {
                throw new CheckpointException("cannot remove {$absolute}");
            }

            return;
        }
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new CheckpointException("cannot create the folder of {$absolute}");
        }
        $mode = is_file($absolute) ? fileperms($absolute) & 0777 : null;
        Files::writeAtomic($absolute, $content, $mode);
    }

    private function contents(string $absolute): string
    {
        $content = @file_get_contents($absolute);
        if (!is_string($content)) {
            throw new CheckpointException("cannot read {$absolute}");
        }

        return $content;
    }

    private function store(string $content): string
    {
        $digest = hash('sha256', $content);
        $file = $this->prepare() . '/blobs/' . $digest;
        if (!is_file($file)) {
            Files::writeAtomic($file, $content, 0600);
        }

        return $digest;
    }

    private function read(string $digest): string
    {
        $file = $this->folder() . '/blobs/' . $digest;
        $content = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($content)) {
            throw new CheckpointException("the saved content {$digest} is missing from the checkpoints; no file was changed");
        }

        return $content;
    }

    private function load(): array
    {
        $file = $this->folder() . '/index.json';
        if (is_link($file)) {
            throw new CheckpointException("{$file} is a symbolic link; the checkpoint index must be a regular file");
        }
        if (!is_file($file)) {
            return ['version' => 1, 'turns' => []];
        }
        $data = Json::object((string) @file_get_contents($file));
        if (!self::isIndex($data)) {
            throw new CheckpointException("the checkpoint index {$file} cannot be read; no file was changed. Move it aside to start with an empty history");
        }

        return $data;
    }

    private function save(array $index): void
    {
        Files::writeAtomic($this->prepare() . '/index.json', Json::encode($index, true), 0600);
    }

    private function collect(array $index): void
    {
        $referenced = [];
        foreach ($index['turns'] as $turn) {
            foreach ($turn['files'] as $file) {
                foreach ([$file['before'], $file['after']] as $digest) {
                    if ($digest !== null) {
                        $referenced[$digest] = true;
                    }
                }
            }
        }
        $blobs = $this->folder() . '/blobs';
        if (!is_dir($blobs)) {
            return;
        }
        foreach (scandir($blobs) ?: [] as $name) {
            if (preg_match(self::DIGEST, $name) === 1 && !isset($referenced[$name])) {
                @unlink($blobs . '/' . $name);
            }
        }
    }

    private function folder(): string
    {
        return Files::runtimeFolder($this->workspace->root, 'checkpoints');
    }

    private function prepare(): string
    {
        $folder = $this->folder();
        $project = dirname($folder);
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new CheckpointException("cannot create {$folder}");
        }
        if (!is_file($project . '/.gitignore') && !is_link($project . '/.gitignore')
            && @file_put_contents($project . '/.gitignore', "*\n") !== 2) {
            throw new CheckpointException("cannot write {$project}/.gitignore, so the checkpoints could be committed");
        }
        $blobs = $folder . '/blobs';
        if (is_link($blobs)) {
            throw new PathException("{$blobs} is a symbolic link; phycode keeps its own files only in real folders");
        }
        if (!is_dir($blobs) && !mkdir($blobs, 0700, true) && !is_dir($blobs)) {
            throw new CheckpointException("cannot create {$blobs}");
        }

        return $folder;
    }

    private function current(array $index): ?int
    {
        $last = count($index['turns']) - 1;
        if ($last < 0 || $index['turns'][$last]['id'] !== $this->begun) {
            return null;
        }

        return $last;
    }

    private function position(array $index, string $turnId): int
    {
        foreach ($index['turns'] as $position => $turn) {
            if ($turn['id'] === $turnId) {
                return $position;
            }
        }

        throw new CheckpointException("no checkpoint for turn {$turnId}");
    }

    private function lastApplied(array $index): ?int
    {
        for ($position = count($index['turns']) - 1; $position >= 0; $position--) {
            if (!$index['turns'][$position]['undone']) {
                return $position;
            }
        }

        return null;
    }

    private function firstUndone(array $index): ?int
    {
        foreach ($index['turns'] as $position => $turn) {
            if ($turn['undone']) {
                return $position;
            }
        }

        return null;
    }

    private static function checkTurnId(string $turnId): void
    {
        if (preg_match(self::TURN_ID, $turnId) !== 1) {
            throw new CheckpointException('invalid turn id: use up to 128 letters, digits, dots, colons, dashes or underscores, starting with a letter or digit');
        }
    }

    private static function isIndex(?array $data): bool
    {
        if ($data === null || ($data['version'] ?? null) !== 1 || !is_array($data['turns'] ?? null) || !array_is_list($data['turns'])) {
            return false;
        }
        foreach ($data['turns'] as $turn) {
            if (!is_array($turn) || !is_string($turn['id'] ?? null) || !is_bool($turn['undone'] ?? null)
                || !is_array($turn['files'] ?? null) || !array_is_list($turn['files'])) {
                return false;
            }
            foreach ($turn['files'] as $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null)
                    || !self::isDigestOrNull($file, 'before') || !self::isDigestOrNull($file, 'after')) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isDigestOrNull(array $file, string $key): bool
    {
        if (!array_key_exists($key, $file)) {
            return false;
        }

        return $file[$key] === null || (is_string($file[$key]) && preg_match(self::DIGEST, $file[$key]) === 1);
    }
}
