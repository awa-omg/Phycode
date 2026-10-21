<?php

declare(strict_types=1);

namespace Phycode\Gentle;

use Phycode\Exec\ProcessRunner;
use Phycode\Support\PhycodeException;
use Phycode\Support\Redaction;

final readonly class GentleAi
{
    public const NOT_INSTALLED = 'gentle-ai: not installed. Install it from the official README (https://github.com/Gentleman-Programming/gentle-ai); phycode never runs remote installers.';

    public const PINNED = '4.2.0';

    private const ENVIRONMENT = ['GENTLE_AI_NO_SELF_UPDATE' => '1', 'GENTLE_AI_TELEMETRY' => '0'];

    public function __construct(
        private int $versionTimeout = 5,
        private int $skillsTimeout = 10,
        private ProcessRunner $processes = new ProcessRunner(),
    ) {
    }

    public function detect(?string $configuredBin = null): ?string
    {
        $bin = trim((string) $configuredBin);
        if ($bin === '') {
            return ProcessRunner::which('gentle-ai');
        }
        if (!str_contains($bin, '/')) {
            return ProcessRunner::which($bin);
        }

        return is_file($bin) && is_executable($bin) ? $bin : null;
    }

    public function version(string $bin): ?string
    {
        try {
            $output = $this->execute('gentle-ai version', [$bin, 'version'], self::workingDirectory(), $this->versionTimeout, 4096);
        } catch (PhycodeException) {
            return null;
        }

        return preg_match('/^gentle-ai\s+(dev|v?\d\S*)\s*$/m', $output, $match) === 1 ? $match[1] : null;
    }

    public function stateFile(): string
    {
        $override = getenv('GENTLE_AI_HOME');
        if (is_string($override) && str_starts_with($override, '/')) {
            return rtrim($override, '/') . '/state.json';
        }

        return rtrim((string) (getenv('HOME') ?: '/root'), '/') . '/.gentle-ai/state.json';
    }

    public function state(?string &$reason = null): ?array
    {
        $reason = null;
        $path = $this->stateFile();
        if (!is_file($path)) {
            $reason = "no state file at {$path}";

            return null;
        }
        $raw = is_readable($path) ? @file_get_contents($path) : false;
        if (!is_string($raw)) {
            $reason = "cannot read {$path}";

            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            $reason = "invalid JSON in {$path}: {$error->getMessage()}";

            return null;
        }
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            $reason = "{$path} does not hold a JSON object";

            return null;
        }

        return $data;
    }

    public function skills(string $bin, ?string $cwd = null, ?string &$reason = null, array $secrets = []): array
    {
        $reason = null;
        $directory = $cwd ?? self::workingDirectory();
        try {
            $output = $this->execute('gentle-ai skill-registry list', [$bin, 'skill-registry', 'list', '--json', '--cwd', $directory], $directory, $this->skillsTimeout, 2_000_000, $secrets);
        } catch (PhycodeException $error) {
            $reason = $error->getMessage();

            return [];
        }
        try {
            $rows = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $reason = 'gentle-ai skill-registry list did not print JSON';

            return [];
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            $reason = 'gentle-ai skill-registry list did not print a JSON array';

            return [];
        }

        $skills = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? self::text($row['name'] ?? null) : '';
            if ($name === '') {
                continue;
            }
            $skills[] = [
                'name' => $name,
                'scope' => self::text($row['scope'] ?? null),
                'description' => self::text($row['description'] ?? null),
                'path' => self::text($row['path'] ?? null),
            ];
        }

        return $skills;
    }

    public function doctorLines(?string $configuredBin = null, ?string $cwd = null, array $secrets = []): array
    {
        $status = $this->status($configuredBin, $cwd, $secrets);

        return [$status->bin === null ? self::NOT_INSTALLED : $status->doctorLine()];
    }

    public function status(?string $configuredBin = null, ?string $cwd = null, array $secrets = []): GentleStatus
    {
        $bin = $this->detect($configuredBin);
        $version = null;
        $skills = [];
        $skillsReason = null;
        if ($bin !== null) {
            $version = $this->version($bin);
            $skills = $this->skills($bin, $cwd, $skillsReason, $secrets);
        }
        $state = $this->state($stateReason);

        return new GentleStatus($bin, $version, $state, $stateReason, $this->stateFile(), $skills, $skillsReason);
    }

    public static function versionNote(?string $version): ?string
    {
        if ($version === null) {
            return 'the version could not be read; the pin is v' . self::PINNED;
        }
        if ($version === 'dev') {
            return 'a development build, which cannot be compared with the pin v' . self::PINNED;
        }
        if (ltrim($version, 'v') === self::PINNED) {
            return null;
        }

        return 'the pin is v' . self::PINNED . "; this binary reports {$version}";
    }

    public static function environment(): array
    {
        $home = getenv('GENTLE_AI_HOME');

        return is_string($home) && str_starts_with($home, '/') ? [...self::ENVIRONMENT, 'GENTLE_AI_HOME' => $home] : self::ENVIRONMENT;
    }

    private function execute(string $label, array $argv, string $directory, int $timeout, int $maxBytes, array $secrets = []): string
    {
        $result = $this->processes->run($argv, $directory, $timeout, $maxBytes, null, self::environment());
        if ($result->interrupted !== null) {
            throw new PhycodeException("{$label} timed out after {$timeout} s");
        }
        if (!$result->succeeded()) {
            $detail = Redaction::text(self::text(explode("\n", trim($result->stderr), 2)[0]), $secrets);

            throw new PhycodeException("{$label} exited with code {$result->exitCode}" . ($detail === '' ? '' : ": {$detail}"));
        }

        return $result->stdout;
    }

    private static function workingDirectory(): string
    {
        return getcwd() ?: sys_get_temp_dir();
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(preg_replace('/\p{Cc}+/u', ' ', $value) ?? '');
    }
}
