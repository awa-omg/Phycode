<?php

declare(strict_types=1);

namespace Phycode\Gentle;

final readonly class GentleStatus
{
    public function __construct(
        public ?string $bin,
        public ?string $version,
        public ?array $state,
        public ?string $stateReason,
        public string $stateFile,
        public array $skills,
        public ?string $skillsReason,
    ) {
    }

    public function doctorLine(): string
    {
        $state = $this->state === null
            ? "state: unavailable ({$this->stateReason})"
            : sprintf('state: ok (%d %s) at %s', $this->agents(), $this->agents() === 1 ? 'agent' : 'agents', $this->stateFile);
        $skills = $this->skillsReason === null ? 'skills: ' . count($this->skills) : "skills: unavailable ({$this->skillsReason})";
        $line = "gentle-ai: {$this->shownVersion()} at {$this->bin}; {$state}; {$skills}";
        $note = $this->versionNote();

        return $note === null ? $line : "{$line}; warning: {$note}";
    }

    public function panel(): string
    {
        if ($this->bin === null) {
            return GentleAi::NOT_INSTALLED;
        }
        $note = $this->versionNote();
        $lines = [
            "gentle-ai: {$this->shownVersion()} at {$this->bin} (pinned v" . GentleAi::PINNED . ')',
            ...($note === null ? [] : ["warning: {$note}"]),
            $this->state === null
                ? "state: unavailable ({$this->stateReason})"
                : sprintf('state: ok at %s (%d %s)', $this->stateFile, $this->agents(), $this->agents() === 1 ? 'agent' : 'agents'),
            $this->skillsReason === null
                ? 'skills: ' . count($this->skills) . ' (from skill-registry list)'
                : "skills: unavailable ({$this->skillsReason})",
            "gentle-ai rdd_mode: {$this->reviewMode()} (from state.json; /review does not use it)",
            "gentle-ai persona: {$this->persona()} (from state.json; /persona does not change it)",
            'commands, each asks for approval first: /gentle install, sync, upgrade, uninstall, refresh, tui',
        ];

        return implode("\n", $lines);
    }

    public function versionNote(): ?string
    {
        return GentleAi::versionNote($this->version);
    }

    public function reviewMode(): string
    {
        if ($this->state === null) {
            return 'unknown';
        }
        $mode = $this->state['rdd_mode'] ?? '';

        return is_string($mode) && ($mode === 'on' || $mode === 'off') ? $mode : 'not set';
    }

    private function persona(): string
    {
        if ($this->state === null) {
            return 'unknown';
        }
        $persona = $this->state['persona'] ?? '';

        return is_string($persona) && $persona !== '' ? $persona : 'none';
    }

    private function agents(): int
    {
        $agents = $this->state['installed_agents'] ?? [];

        return is_array($agents) ? count($agents) : 0;
    }

    private function shownVersion(): string
    {
        return match (true) {
            $this->version === null => 'version unknown',
            preg_match('/^\d/', $this->version) === 1 => "v{$this->version}",
            default => $this->version,
        };
    }
}
