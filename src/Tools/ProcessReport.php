<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Exec\ProcessResult;

final class ProcessReport
{
    public static function toResult(ProcessResult $result): ToolResult
    {
        $lines = [];
        if ($result->interrupted !== null) {
            $lines[] = 'interrupted (' . $result->interrupted . ') after ' . round($result->seconds, 1) . ' seconds';
        }
        $lines[] = "exit code: {$result->exitCode}";
        if (trim($result->stdout) !== '') {
            $lines[] = rtrim($result->stdout);
        }
        if (trim($result->stderr) !== '') {
            $lines[] = "[stderr]\n" . rtrim($result->stderr);
        }

        $content = implode("\n", $lines);

        return $result->succeeded()
            ? ToolResult::ok($content, ['exit' => $result->exitCode])
            : ToolResult::fail($content, ['exit' => $result->exitCode]);
    }
}
