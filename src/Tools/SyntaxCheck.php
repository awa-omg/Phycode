<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Exec\ProcessRunner;
use Phycode\Support\Files;
use Phycode\Support\Text;

final class SyntaxCheck
{
    public static function commit(
        string $file,
        string $content,
        ?string $previous,
        ?int $mode,
        string $message,
        ToolContext $context,
    ): ToolResult {
        Files::writeAtomic($file, $content, $mode);
        $report = self::run($file, $context);
        if ($report !== null && !$report['ok'] && $report['blocking']) {
            if ($previous === null) {
                @unlink($file);
            } else {
                Files::writeAtomic($file, $previous, $mode);
            }

            return ToolResult::fail(
                "not saved, the file was left as it was. The new content has a syntax error ({$report['label']}): {$report['detail']}",
            );
        }

        return ToolResult::ok($report === null ? $message : $message . "\n" . self::line($report));
    }

    public static function run(string $file, ToolContext $context): ?array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $program = match ($extension) {
            'php', 'json' => PHP_BINARY,
            'py' => ProcessRunner::which('python3'),
            'js', 'mjs', 'cjs' => ProcessRunner::which('node'),
            default => null,
        };
        if ($program === null) {
            return null;
        }

        $command = match ($extension) {
            'php' => [$program, '-l', $file],
            'py' => [$program, '-c', 'import ast, sys; ast.parse(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1])', $file],
            'json' => [$program, '-r', 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);', $file],
            default => [$program, '--check', $file],
        };
        $label = match ($extension) {
            'php' => 'php -l',
            'py' => 'python3',
            'json' => 'json',
            default => 'node --check',
        };

        $result = $context->processes->run($command, $context->workspace->root, 15, 4000, $context->cancel);

        return [
            'label' => $label,
            'ok' => $result->succeeded(),
            'detail' => $result->succeeded() ? '' : Text::oneLine(trim($result->stdout . "\n" . $result->stderr), 400),
            'blocking' => $extension === 'php' || $extension === 'py',
        ];
    }

    public static function line(array $report): string
    {
        return $report['ok']
            ? "syntax check ({$report['label']}): ok"
            : "syntax check FAILED ({$report['label']}): {$report['detail']}";
    }
}
