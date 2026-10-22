<?php

declare(strict_types=1);

namespace Phycode\Lsp;

final class LspFiles
{
    private const LANGUAGE_IDS = [
        'php' => 'php', 'go' => 'go', 'py' => 'python', 'rs' => 'rust', 'rb' => 'ruby', 'java' => 'java', 'kt' => 'kotlin',
        'cs' => 'csharp', 'swift' => 'swift', 'lua' => 'lua', 'zig' => 'zig', 'sh' => 'shellscript', 'bash' => 'shellscript',
        'c' => 'c', 'h' => 'c', 'cc' => 'cpp', 'cpp' => 'cpp', 'cxx' => 'cpp', 'hpp' => 'cpp',
        'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'jsx' => 'javascriptreact',
        'ts' => 'typescript', 'mts' => 'typescript', 'cts' => 'typescript', 'tsx' => 'typescriptreact',
        'json' => 'json', 'yaml' => 'yaml', 'yml' => 'yaml', 'md' => 'markdown', 'html' => 'html', 'css' => 'css', 'vue' => 'vue',
    ];

    public static function uri(string $path): string
    {
        return 'file://' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public static function path(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file:///')) {
            return null;
        }

        return rawurldecode(substr($uri, 7));
    }

    public static function languageId(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::LANGUAGE_IDS[$extension] ?? ($extension === '' ? 'plaintext' : $extension);
    }

    public static function lines(string $text): array
    {
        return preg_split('/\r\n|\r|\n/', $text) ?: [''];
    }
}
