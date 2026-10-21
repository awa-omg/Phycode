<?php

declare(strict_types=1);

namespace Phycode\Config;

final readonly class Limits
{
    private const KEYS = [
        'tool_output_bytes' => 'toolOutputBytes',
        'subagent_result_bytes' => 'subagentResultBytes',
        'read_max_lines' => 'readMaxLines',
        'bash_timeout' => 'bashTimeout',
        'max_bash_timeout' => 'maxBashTimeout',
        'primary_max_turns' => 'primaryMaxTurns',
        'subagent_max_turns' => 'subagentMaxTurns',
        'subagent_timeout' => 'subagentTimeout',
        'max_tasks_per_turn' => 'maxTasksPerTurn',
        'max_tasks_per_run' => 'maxTasksPerRun',
        'loop_repeat_limit' => 'loopRepeatLimit',
        'agents_md_bytes' => 'agentsMdBytes',
        'background_jobs' => 'backgroundJobs',
        'background_lifetime' => 'backgroundLifetime',
    ];

    public function __construct(
        public int $toolOutputBytes = 8192,
        public int $subagentResultBytes = 4096,
        public int $readMaxLines = 200,
        public int $bashTimeout = 120,
        public int $maxBashTimeout = 600,
        public int $primaryMaxTurns = 40,
        public int $subagentMaxTurns = 8,
        public int $subagentTimeout = 300,
        public int $maxTasksPerTurn = 3,
        public int $maxTasksPerRun = 10,
        public int $loopRepeatLimit = 3,
        public int $agentsMdBytes = 6144,
        public int $backgroundJobs = 8,
        public int $backgroundLifetime = 3600,
    ) {
    }

    public static function fromArray(array $data, array &$warnings): self
    {
        $arguments = [];
        foreach ($data as $key => $value) {
            $property = self::KEYS[$key] ?? null;
            if ($property === null) {
                $warnings[] = "unknown limits key '{$key}' ignored";
                continue;
            }
            if (!is_int($value) || $value < 1) {
                throw new ConfigException("limits.{$key} must be a positive integer");
            }
            $arguments[$property] = $value;
        }

        return new self(...$arguments);
    }
}
