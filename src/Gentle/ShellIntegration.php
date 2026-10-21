<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Support\Entry;
use Phycode\Support\Files;
use Phycode\Support\PhycodeException;
use Phycode\Ui\ConsoleUi;
use Phycode\Ui\Ui;

final class ShellIntegration
{
    public const START = '# >>> phycode >>>';
    public const END = '# <<< phycode <<<';

    private const BLOCK = '/^# >>> phycode >>>[ \t]*\n.*?^# <<< phycode <<<[ \t]*(?:\n|\z)/ms';
    private const START_LINE = '/^# >>> phycode >>>[ \t]*$/m';
    private const END_LINE = '/^# <<< phycode <<<[ \t]*$/m';

    public function __construct(
        private readonly string $home,
        private readonly string $binDirectory,
        private readonly ?string $shell,
        private readonly ?string $zdotdir = null,
    ) {
    }

    public static function defaultBinDirectory(): string
    {
        return Entry::binFolder();
    }

    public static function cli(array $words, ?Ui $ui = null): int
    {
        $ui ??= new ConsoleUi(STDOUT, STDERR, STDIN, false, false);
        $action = $words[0] ?? 'status';
        if (count($words) > 1 || !in_array($action, ['status', 'install', 'uninstall'], true)) {
            $ui->notice('error', 'usage: phycode shell status, phycode shell install or phycode shell uninstall');

            return 1;
        }
        $shell = new self(
            (string) (self::environmentValue('HOME') ?? '/root'),
            self::defaultBinDirectory(),
            self::environmentValue('SHELL'),
            self::environmentValue('ZDOTDIR'),
        );
        try {
            $text = match ($action) {
                'status' => $shell->status(),
                'install' => $shell->install(new Approval($ui, static fn (string $title, string $text) => $ui->panel($title, $text))),
                default => $shell->uninstall(new Approval($ui, static fn (string $title, string $text) => $ui->panel($title, $text))),
            };
        } catch (PhycodeException $error) {
            $ui->notice('error', $error->getMessage());

            return 1;
        }
        $ui->output($text);

        return 0;
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function file(): string
    {
        $name = $this->shell === null || $this->shell === '' ? '' : basename($this->shell);

        return match ($name) {
            'zsh' => ($this->zdotdir !== null && str_starts_with($this->zdotdir, '/') ? rtrim($this->zdotdir, '/') : rtrim($this->home, '/')) . '/.zshrc',
            'bash' => rtrim($this->home, '/') . '/.bashrc',
            default => throw new PhycodeException(
                ($name === '' ? 'the login shell is not known ($SHELL is empty)' : "phycode shell supports zsh and bash, not {$name}")
                . '. Add this line to your profile yourself: export PATH=' . self::quote($this->binDirectory) . ':"$PATH"',
            ),
        };
    }

    public static function block(string $directory): string
    {
        if (preg_match('/[\r\n]/', $directory) === 1) {
            throw new PhycodeException('the bin folder has a line break in its name; the shell block cannot hold it');
        }

        return self::START . "\n" . 'export PATH=' . self::quote($directory) . ':"$PATH"' . "\n" . self::END . "\n";
    }

    public static function findBlock(string $content): ?string
    {
        $starts = preg_match_all(self::START_LINE, $content);
        $ends = preg_match_all(self::END_LINE, $content);
        if ($starts === 0 && $ends === 0) {
            return null;
        }
        if ($starts !== 1 || $ends !== 1 || preg_match(self::BLOCK, $content, $match) !== 1) {
            throw new PhycodeException('the phycode block in the shell file is damaged: it needs one start marker, then one end marker. Nothing was changed; fix or remove it by hand.');
        }

        return $match[0];
    }

    public static function withBlock(string $content, string $block): ?string
    {
        $existing = self::findBlock($content);
        if ($existing === null) {
            if ($content !== '' && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }

            return $content . $block;
        }
        if ($existing === $block) {
            return null;
        }

        return substr_replace($content, $block, (int) strpos($content, $existing), strlen($existing));
    }

    public static function withoutBlock(string $content): ?string
    {
        $existing = self::findBlock($content);
        if ($existing === null) {
            return null;
        }

        return substr_replace($content, '', (int) strpos($content, $existing), strlen($existing));
    }

    public function status(): string
    {
        $shell = $this->shell === null || $this->shell === '' ? 'not known ($SHELL is empty)' : basename($this->shell);
        try {
            $file = $this->file();
        } catch (PhycodeException $error) {
            return "shell: {$shell}\n{$error->getMessage()}";
        }
        try {
            [$content] = $this->read($file);
            $found = self::findBlock($content);
            $block = match (true) {
                $found === null => 'not installed',
                $found === self::block($this->binDirectory) => 'installed for this bin folder',
                default => 'installed for another folder; /shell install replaces it',
            };
        } catch (PhycodeException $error) {
            $block = "unreadable: {$error->getMessage()}";
        }

        return implode("\n", ["shell: {$shell}", "file: {$file}", "block: {$block}", "bin: {$this->binDirectory}"]);
    }

    public function install(Approval $approve): string
    {
        $file = $this->file();
        [$content, $mode] = $this->read($file);
        $block = self::block($this->binDirectory);
        $new = self::withBlock($content, $block);
        if ($new === null) {
            return "{$file} has the phycode block for {$this->binDirectory} already; nothing changed.";
        }
        $notes = $content !== '' && !str_ends_with($content, "\n") ? ["a line break is added after the last line of {$file}"] : [];
        if (!$approve('/shell install', self::details($file, self::findBlock($content), $block, $notes), "add the phycode PATH block to {$file}")) {
            return 'not approved: nothing was changed.';
        }
        Files::writeAtomic($file, $new, $mode);

        return "added the phycode PATH block to {$file}. Open a new shell, or run: source {$file}";
    }

    public function uninstall(Approval $approve): string
    {
        $file = $this->file();
        [$content, $mode] = $this->read($file);
        $old = self::findBlock($content);
        if ($old === null) {
            return "{$file} has no phycode block; nothing to remove.";
        }
        $new = (string) self::withoutBlock($content);
        if (!$approve('/shell uninstall', self::details($file, $old, null, []), "remove the phycode PATH block from {$file}")) {
            return 'not approved: nothing was changed.';
        }
        Files::writeAtomic($file, $new, $mode);

        return "removed the phycode PATH block from {$file}.";
    }

    private static function quote(string $text): string
    {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }

    private static function details(string $file, ?string $removed, ?string $added, array $notes): string
    {
        $lines = ["file: {$file}"];
        foreach (self::lines($removed) as $line) {
            $lines[] = "- {$line}";
        }
        foreach (self::lines($added) as $line) {
            $lines[] = "+ {$line}";
        }
        foreach ($notes as $note) {
            $lines[] = "note: {$note}";
        }

        return implode("\n", $lines);
    }

    private static function lines(?string $block): array
    {
        return $block === null ? [] : explode("\n", rtrim($block, "\n"));
    }

    private function read(string $file): array
    {
        if (is_link($file)) {
            throw new PhycodeException("cannot change {$file}: it is a symbolic link");
        }
        if (!file_exists($file)) {
            return ['', 0644];
        }
        if (!is_file($file)) {
            throw new PhycodeException("cannot change {$file}: it is not a regular file");
        }
        $content = @file_get_contents($file);
        if (!is_string($content)) {
            throw new PhycodeException("cannot read {$file}");
        }
        $mode = fileperms($file);

        return [$content, is_int($mode) ? $mode & 0777 : 0644];
    }
}
