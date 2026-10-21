<?php

declare(strict_types=1);

namespace Phycode\Session;

use Phycode\Security\PathException;
use Phycode\Security\Workspace;
use Phycode\Support\Files;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;
use Phycode\Support\Text;
use Phycode\Ui\Ui;

final class Transcript
{
    private const MAX_FIELD_BYTES = 8192;

    private $handle;

    private function __construct(public readonly string $path, $handle)
    {
        $this->handle = $handle;
    }

    public static function open(string $directory, ?Ui $ui = null): self
    {
        $refusal = self::refusal($directory);
        if ($refusal !== null) {
            self::report($refusal, $ui);

            return self::memory();
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PhycodeException("cannot create session folder {$directory}");
        }
        $project = dirname($directory);
        if (basename($project) === '.phycode' && !is_file($project . '/.gitignore') && !is_link($project . '/.gitignore')) {
            file_put_contents($project . '/.gitignore', "*\n");
        }
        $path = $directory . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.jsonl';
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new PhycodeException("cannot open session log {$path}");
        }
        chmod($path, 0600);

        return new self($path, $handle);
    }

    public static function memory(): self
    {
        return new self('memory', fopen('php://memory', 'w+b'));
    }

    private static function refusal(string $directory): ?string
    {
        $phycode = dirname($directory);
        if (basename($phycode) !== '.phycode' || !is_dir(dirname($phycode))) {
            return null;
        }
        $root = dirname($phycode);
        $relative = '.phycode/' . basename($directory);
        try {
            (new Workspace($root))->resolve($relative);
        } catch (PathException) {
            return "transcripts are not written: {$relative} leads outside the project or nowhere, through a symbolic link. This session has no transcript file.";
        }
        try {
            Files::runtimeFolder($root, basename($directory));
        } catch (PathException $error) {
            return "transcripts are not written: {$error->getMessage()}. This session has no transcript file.";
        }

        return null;
    }

    private static function report(string $message, ?Ui $ui): void
    {
        if ($ui !== null) {
            $ui->notice('warn', $message);

            return;
        }
        error_log('phycode: ' . $message);
    }

    public function event(string $type, array $data, string $agent, int $depth): void
    {
        $line = Json::encode([
            'time' => date('c'),
            'type' => $type,
            'agent' => $agent,
            'depth' => $depth,
            'data' => $this->limit($data),
        ]);
        flock($this->handle, LOCK_EX);
        fwrite($this->handle, $line . "\n");
        fflush($this->handle);
        flock($this->handle, LOCK_UN);
    }

    public function contents(): string
    {
        rewind($this->handle);

        return (string) stream_get_contents($this->handle);
    }

    private function limit(mixed $value): mixed
    {
        if (is_string($value)) {
            return strlen($value) > self::MAX_FIELD_BYTES ? Text::clip($value, self::MAX_FIELD_BYTES) : $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->limit($item), $value);
        }

        return $value;
    }
}
