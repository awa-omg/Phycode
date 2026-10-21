<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Gentle;

use Phycode\Tests\Support\Kit;

final class FakeGentleAi
{
    private const SCRIPT = <<<'SH'
#!/bin/sh
printf '%s|%s\n' "$GENTLE_AI_NO_SELF_UPDATE" "$*" >> "${0%/*}/calls.log"
for argument in "$@"; do printf '[%s]' "$argument"; done >> "${0%/*}/argv.log"
printf '\n' >> "${0%/*}/argv.log"
printf 'GENTLE_AI_NO_SELF_UPDATE=%s GENTLE_AI_TELEMETRY=%s GENTLE_AI_HOME=%s CWD=%s\n' "${GENTLE_AI_NO_SELF_UPDATE-unset}" "${GENTLE_AI_TELEMETRY-unset}" "${GENTLE_AI_HOME-unset}" "$(pwd)" >> "${0%/*}/env.log"
@SLEEP@
case "$1" in
  version)
    printf '%s\n' @VERSION@
    exit @VERSION_EXIT@
    ;;
  skill-registry)
    @STDERR@
    printf '%s\n' @SKILLS@
    exit @SKILLS_EXIT@
    ;;
esac
printf '%s\n' @OTHER@
exit @OTHER_EXIT@
SH;

    public static function install(array $behaviour = []): string
    {
        $stderr = (string) ($behaviour['stderr'] ?? '');
        $sleep = (int) ($behaviour['sleep'] ?? 0);
        $script = strtr(self::SCRIPT, [
            '@SLEEP@' => $sleep > 0 ? "sleep {$sleep}" : ':',
            '@VERSION@' => self::quote((string) ($behaviour['version'] ?? 'gentle-ai 4.2.0')),
            '@VERSION_EXIT@' => (string) ($behaviour['versionExit'] ?? 0),
            '@SKILLS@' => self::quote((string) ($behaviour['skills'] ?? '[]')),
            '@SKILLS_EXIT@' => (string) ($behaviour['skillsExit'] ?? 0),
            '@STDERR@' => $stderr === '' ? ':' : "printf '%s\\n' " . self::quote($stderr) . ' >&2',
            '@OTHER@' => self::quote((string) ($behaviour['other'] ?? 'ran')),
            '@OTHER_EXIT@' => (string) ($behaviour['otherExit'] ?? 0),
        ]);
        $path = Kit::folder('fake-gentle-ai') . '/gentle-ai';
        file_put_contents($path, $script . "\n");
        chmod($path, 0755);

        return $path;
    }

    public static function firstOnPath(string $binary): void
    {
        $directories = array_filter([dirname($binary), (string) getenv('PATH')], static fn (string $directory): bool => $directory !== '');
        putenv('PATH=' . implode(PATH_SEPARATOR, $directories));
    }

    public static function calls(string $binary): array
    {
        return self::lines($binary, 'calls.log');
    }

    public static function argv(string $binary): array
    {
        return array_map(
            static function (string $line): array {
                preg_match_all('/\[([^\]]*)\]/', $line, $words);

                return $words[1];
            },
            self::lines($binary, 'argv.log'),
        );
    }

    public static function environment(string $binary): array
    {
        return self::lines($binary, 'env.log');
    }

    private static function lines(string $binary, string $name): array
    {
        $log = dirname($binary) . '/' . $name;
        if (!is_file($log)) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", (string) file_get_contents($log)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private static function quote(string $text): string
    {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }
}
