<?php

declare(strict_types=1);

namespace Phycode\Skills;

final class SkillScanner
{
    private const SHELL = '(?:sudo\s+)?(?:\S*\/)?(?:env\s+)?(?:ba|z|da|k|mk|a|fi|tc|c)?sh\b';
    private const PATTERNS = [
        ['a download piped into a shell', '/\b(?:curl|wget)\b[^\n]*\|\s*' . self::SHELL . '/i'],
        ['a base64 decode piped into a shell', '/\bbase64\s+(?:-d|--decode|-D)\b[^\n]*\|\s*' . self::SHELL . '/i'],
        ['a pipe into a shell', '/\|\s*' . self::SHELL . '(?!\s*\|)/i'],
        ['a delete of the root or the home folder', '/\brm\b(?=[^\n]*\s(?:--recursive|-[a-z]*r))(?=[^\n]*\s(?:--force|-[a-z]*f))[^\n]*?\s(?:\/\*?|~\/?|\$\{?HOME\}?\/?)(?![\w.\/-])/i'],
        ['a read of ~/.ssh', '/(?:~|\$\{?HOME\}?|\/home\/[^\s\/]+|\/root)\/\.ssh\b/i'],
        ['a read of .aws/credentials', '/\.aws\/credentials\b/i'],
        ['printenv', '/\bprintenv\b/i'],
        ['env piped to a command', '/\benv\s*\|/i'],
        ['a secret or a variable sent out by curl, wget or nc', '/\b(?:curl|wget|nc|ncat|netcat)\b[^\n]*(?:\$\{?[a-z_][a-z0-9_]*\}?|\.env\b|\.aws\/credentials|\.ssh\/|\.netrc\b)/i'],
        ['an instruction to ignore the instructions', '/\bignore\s+(?:(?:all|any|previous|prior|above|your)\s+){1,2}(?:instructions|rules|guidelines|prompts?)\b/i'],
        ['an instruction to disregard the system prompt', '/\bdisregard\s+(?:the\s+)?system\s+prompt\b/i'],
        ['an instruction to hide things from the user', '/\bdo\s+not\s+tell\s+the\s+user\b/i'],
    ];

    public static function check(string $text): void
    {
        foreach (self::PATTERNS as [$risk, $pattern]) {
            $found = preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE);
            if ($found === false) {
                throw new SkillException('the text could not be checked for risky patterns, so it is not saved');
            }
            if ($found === 1) {
                $line = substr_count(substr($text, 0, $match[0][1]), "\n") + 1;

                throw new SkillException("the text has {$risk} at line {$line}; skill_manage does not save it");
            }
        }
    }
}
