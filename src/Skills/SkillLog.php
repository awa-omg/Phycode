<?php

declare(strict_types=1);

namespace Phycode\Skills;

final readonly class SkillLog
{
    public const FILE = '.changes.jsonl';

    public function __construct(private string $folder)
    {
    }

    public function record(string $action, string $name, array $from = []): void
    {
        $file = $this->folder . '/' . self::FILE;
        if (is_link($file)) {
            throw new SkillException(self::FILE . ' is a symbolic link; phycode keeps its own files only in real files');
        }
        $entry = ['ts' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'action' => $action, 'name' => $name, 'scope' => 'phycode'];
        if ($from !== []) {
            $entry['from'] = $from;
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (!is_file($file)) {
            $handle = @fopen($file, 'xb');
            if ($handle !== false) {
                fclose($handle);
                @chmod($file, 0600);
            }
        }
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new SkillException("cannot write the change to {$file}");
        }
    }
}
