<?php

declare(strict_types=1);

namespace Phycode\Tools;

use Phycode\Agent\AgentDefinition;
use Phycode\Agent\RunPolicy;
use Phycode\Checkpoint\CheckpointStore;
use Phycode\Config\Limits;
use Phycode\Exec\CommandRunner;
use Phycode\Exec\ProcessRunner;
use Phycode\Security\Permissions;
use Phycode\Security\Workspace;
use Phycode\Session\Transcript;
use Phycode\Support\Cancellation;
use Phycode\Ui\Ui;

final readonly class ToolContext
{
    public function __construct(
        public Workspace $workspace,
        public Permissions $permissions,
        public ProcessRunner $processes,
        public Ui $ui,
        public Transcript $transcript,
        public Limits $limits,
        public AgentDefinition $agent,
        public RunPolicy $policy,
        public Cancellation $cancel,
        public string $profile,
        public ?Delegator $delegator,
        public ChangeLog $changes,
        public CommandRunner $sandbox,
        public ?CheckpointStore $checkpoints = null,
        public array $secrets = [],
        public ?string $model = null,
        public ?string $thinking = null,
    ) {
    }

    public function forAgent(AgentDefinition $agent, RunPolicy $policy, Cancellation $cancel, string $profile, CommandRunner $sandbox, ?string $model = null, ?string $thinking = null): self
    {
        return new self(
            $this->workspace,
            $this->permissions,
            $this->processes,
            $this->ui,
            $this->transcript,
            $this->limits,
            $agent,
            $policy,
            $cancel,
            $profile,
            $this->delegator,
            $this->changes,
            $sandbox,
            $this->checkpoints,
            $this->secrets,
            $model,
            $thinking,
        );
    }

    public function depth(): int
    {
        return $this->policy->depth;
    }
}
