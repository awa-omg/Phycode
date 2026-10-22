<?php

declare(strict_types=1);

namespace Phycode\Context;

use Phycode\Security\PathException;
use Phycode\Security\SecretFiles;
use Phycode\Security\Workspace;
use Phycode\Tools\ReadTool;
use Phycode\Tools\ToolContext;

final class Mentions
{
    public const MAX_MENTIONS = 6;
    public const MAX_CHARS = 60000;
    public const MAX_ENTRIES = 100;
    private const TRAILING = ".,;:!?)]}>'\"";

    public function __construct(private readonly ReadTool $read = new ReadTool())
    {
    }

    public static function tokens(string $text): array
    {
        preg_match_all('/(?<![\w@\/.])@(?:"([^"\n]+)"|(?!@)(\S+))/u', $text, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $token = ($match[1] ?? '') !== '' ? $match[1] : rtrim($match[2] ?? '', self::TRAILING);
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    public static function existing(string $token, Workspace $workspace): ?string
    {
        $notes = [];

        return self::locate($token, $workspace, $notes);
    }

    public function attach(string $text, ToolContext $context): array
    {
        $blocks = [];
        $attached = [];
        $notes = [];
        $budget = self::MAX_CHARS;
        $seen = [];
        foreach (self::tokens($text) as $token) {
            $absolute = self::locate($token, $context->workspace, $notes);
            if ($absolute === null || isset($seen[$absolute])) {
                continue;
            }
            $seen[$absolute] = true;
            $relative = $context->workspace->relative($absolute);
            if (count($attached) >= self::MAX_MENTIONS) {
                $notes[] = 'only the first ' . self::MAX_MENTIONS . ' mentions are attached; the model can read the others';
                break;
            }
            if (SecretFiles::guarded($relative) || SecretFiles::guarded($token)) {
                $notes[] = "@{$token} was not attached: it usually holds secrets or phycode's own files";
                continue;
            }
            $block = is_dir($absolute) ? $this->folder($absolute, $relative) : $this->file($relative, $context);
            if ($block === null) {
                $notes[] = "@{$token} was not attached: it is not a text file the model can read; /image shows an image";
                continue;
            }
            if (strlen($block) > $budget) {
                $notes[] = "@{$token} was not attached: the files mentioned before it already fill the " . intdiv(self::MAX_CHARS, 1000) . ' KB limit';
                continue;
            }
            $budget -= strlen($block);
            $blocks[] = $block;
            $attached[] = $relative;
        }

        return ['text' => $blocks === [] ? $text : $text . "\n\n" . implode("\n\n", $blocks), 'attached' => $attached, 'notes' => $notes];
    }

    private static function locate(string $token, Workspace $workspace, array &$notes): ?string
    {
        try {
            $absolute = $workspace->resolve($token);
        } catch (PathException $error) {
            if (str_contains($token, '/')) {
                $notes[] = "@{$token} was not attached: " . $error->getMessage();
            }

            return null;
        }

        return file_exists($absolute) ? $absolute : null;
    }

    private function file(string $relative, ToolContext $context): ?string
    {
        $result = $this->read->execute(['path' => $relative], $context);
        if (!$result->ok || isset($result->meta['images'])) {
            return null;
        }

        return "Contents of {$relative}, mentioned by the user with @:\n" . $result->content;
    }

    private function folder(string $absolute, string $relative): string
    {
        $entries = [];
        foreach (scandir($absolute) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '.git' || SecretFiles::guarded($relative . '/' . $name)) {
                continue;
            }
            $entries[] = $name . (is_dir($absolute . '/' . $name) ? '/' : '');
        }
        $shown = array_slice($entries, 0, self::MAX_ENTRIES);
        $more = count($entries) - count($shown);

        return "Entries of {$relative}/, mentioned by the user with @:\n" . ($shown === [] ? '[empty folder]' : implode("\n", $shown)) . ($more > 0 ? "\n[{$more} more entries]" : '');
    }
}
